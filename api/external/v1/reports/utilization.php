<?php
/**
 * GET /api/external/v1/reports/utilization
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * Scheduled-vs-worked capacity analysis: how much of the schedule an employee, an
 * employee-week, or an office actually consumed.
 *
 * Params: start, end (required), employee_id, employee_email, office,
 *         include_inactive, rounding, week_start, ot_weekly, ot_daily, dt_daily,
 *         tz, format, limit, offset, plus:
 *
 *   group_by        "employee" (default) | "employee_week" | "office"
 *   scheduled_only  bool (default false) — drop employees with no active assigned
 *                   shift, which is what you want for a clean utilization KPI.
 *
 * DENOMINATOR RULES — the part that decides whether the KPI is trustworthy:
 *   - scheduled_hours comes from the employee's ONE active shift (user_shifts is
 *     UNIQUE on UserID), expanded over the days named in shifts.DaysOfWeek that
 *     fall inside [start, end], minus LunchDuration, overnight-safe.
 *   - An approved time-off day is NOT scheduled capacity: that date's hours move
 *     into time_off_hours and leave the denominator, so a week of approved
 *     vacation reads as "nothing scheduled" rather than as 0% utilization.
 *   - utilization_pct, overtime_pct and attendance_rate are NULL — never 0 — when
 *     their denominator is 0. A 0 stand-in silently drags a Power BI average down,
 *     and once emitted there is no way for the consumer to tell the two cases
 *     apart. JSON null survives to CSV as an empty cell, which averages correctly.
 *   - An employee with no active assigned shift keeps has_schedule=false,
 *     scheduled_days/scheduled_hours 0 and utilization_pct null; their worked hours
 *     are still reported. meta.warnings states how many such employees exist so a
 *     BI user cannot misread the headline number as company-wide utilization.
 *
 * Worked hours are recomputed from the raw TIME columns — timepunches.TotalHours
 * is not trustworthy (one insert path writes a literal 0 and admin edits do not
 * always recompute it) — rounded once per day, then split into regular / overtime
 * / double-time by the same allocator /summary, /overtime and /payroll use, so the
 * worked side of this report always reconciles against them.
 *
 * ROW SHAPE PER GROUPING: group_by=employee is ROSTER shaped — every employee
 * matching the filters gets a row, including one who neither worked nor was
 * scheduled, because a missing row looks like a filter mistake while a zero row
 * looks like something to chase. group_by=employee_week instead emits a row only
 * for a week in which the employee has worked hours, scheduled days or time off;
 * an employee with none of those in the range contributes no week rows at all
 * (they still count in `totals.employees`).
 *
 * `totals` describes the ENTIRE filtered population, not just the returned page,
 * and is identical across all three group_by modes for the same filters: it is
 * accumulated per employee, before the rows are shaped and paged.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

/**
 * Boolean parser for a report-specific parameter ReportParams does not own.
 * A closure, not a named function, so two endpoints loaded in one process can
 * never collide in the global namespace.
 */
$parseBoolParam = static function (array $get, string $name, bool $default): bool {
    if (!array_key_exists($name, $get)) {
        return $default;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = strtolower(trim((string)$raw));
    if ($v === '') {
        return $default;
    }
    if (in_array($v, ['1', 'true', 'yes'], true))  return true;
    if (in_array($v, ['0', 'false', 'no'], true))  return false;

    $echo = htmlspecialchars(substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '', 0, 64), ENT_QUOTES, 'UTF-8');
    ApiResponse::error(
        'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are 1/0, true/false, yes/no.',
        400
    );
};

/** Same idea for a fixed-vocabulary parameter: one specific 400 naming what is accepted. */
$parseEnumParam = static function (array $get, string $name, array $allowed, string $default): string {
    if (!array_key_exists($name, $get)) {
        return $default;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = strtolower(trim((string)$raw));
    if ($v === '') {
        return $default;
    }
    if (in_array($v, $allowed, true)) {
        return $v;
    }
    $echo = htmlspecialchars(substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '', 0, 64), ENT_QUOTES, 'UTF-8');
    ApiResponse::error(
        'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are '
        . implode(', ', array_map(static fn($a) => '"' . $a . '"', $allowed)) . '.',
        400
    );
};

/**
 * A percentage, or null when there is no denominator.
 *
 * Returning null rather than 0 is the single most important output decision in
 * this endpoint: "we could not measure this" and "we measured zero" are different
 * facts, and only null preserves that difference through a spreadsheet average.
 */
$pct = static function (float $numerator, float $denominator): ?float {
    if ($denominator <= 0) {
        return null;
    }
    return round($numerator / $denominator * 100, 2);
};

$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

/**
 * Fold ONE employee's punches, shift and approved time off into per-bucket
 * aggregates. The bucket key is the week-start date when group_by=employee_week,
 * and '' (a single whole-range bucket) otherwise — so the calendar/scheduling
 * logic below is written once and never forks per grouping.
 *
 * Called as soon as the punch cursor leaves an employee, so only one employee's
 * raw punches are ever resident and what is retained per employee is this small
 * aggregate rather than up to 400 days of punch rows.
 *
 * @param array       $punchRows     that employee's punches inside [start, end]
 * @param array|null  $shift         their ACTIVE shift row, or null when unscheduled
 * @param array       $timeOffDates  date => ['type','hours','request_id'], already clipped to the range
 * @return array{buckets: array<string, array>, open_punch_count: int, any_partial_week: bool}
 */
$computeEmployeeAggregate = static function (
    array $punchRows,
    ?array $shift,
    array $timeOffDates,
    ReportParams $params,
    string $start,
    string $end,
    bool $byWeek
): array {
    $buckets = [];

    $ensure = static function (string $key) use (&$buckets, $params): void {
        if (isset($buckets[$key])) {
            return;
        }
        $buckets[$key] = [
            'week_start'      => $key !== '' ? $key : null,
            'week_end'        => $key !== '' ? HoursCalculator::weekEndFor($key, $params->weekStart()) : null,
            'scheduled_days'  => 0,
            'scheduled_hours' => 0.0,
            'days_worked'     => 0,
            'regular'         => 0.0,
            'overtime'        => 0.0,
            'double_time'     => 0.0,
            'time_off_days'   => 0,
            'time_off_hours'  => 0.0,
            'absent_days'     => 0,
        ];
    };

    $keyFor = static function (string $date) use ($byWeek, $params): string {
        return $byWeek ? HoursCalculator::weekStartFor($date, $params->weekStart()) : '';
    };

    // Order matters: round once per day, THEN allocate overtime from the rounded
    // daily figures. Allocating first and rounding after would make these numbers
    // disagree with admin/summary.php and with every sibling report.
    $daily = HoursCalculator::dailyHoursFromPunches($punchRows, $params->rounding());

    $hoursByDate = [];
    foreach ($daily['days'] as $date => $d) {
        $hoursByDate[$date] = (float)$d['hours'];
    }

    $split = HoursCalculator::splitOvertime(
        $hoursByDate,
        $params->otDaily(),
        $params->dtDaily(),
        $params->otWeekly(),
        $params->weekStart()
    );

    $anyPartial = false;
    foreach ($split['weeks'] as $wk => $w) {
        // Checked for EVERY grouping, not just employee_week: weekly overtime is
        // bucketed by calendar week no matter how the rows are shaped, so a week
        // hanging outside the range understates OT in an employee row too.
        if (HoursCalculator::isPartialWeek($w['week_start'], $w['week_end'], $start, $end)) {
            $anyPartial = true;
        }
        if (!$byWeek) {
            continue;
        }
        $ensure((string)$wk);
        $buckets[(string)$wk]['regular']     += (float)$w['regular'];
        $buckets[(string)$wk]['overtime']    += (float)$w['overtime'];
        $buckets[(string)$wk]['double_time'] += (float)$w['double_time'];
    }
    if (!$byWeek) {
        // The whole-range bucket always exists so an employee who worked nothing
        // still gets a row — a missing row looks like a filter mistake, a zero row
        // looks like something to chase.
        $ensure('');
        $buckets['']['regular']     += (float)$split['totals']['regular'];
        $buckets['']['overtime']    += (float)$split['totals']['overtime'];
        $buckets['']['double_time'] += (float)$split['totals']['double_time'];
    }

    // days_worked counts a DATE once, and only when it produced paid time. A day
    // whose only punch is still open contributes 0 hours and is reported through
    // open punches instead, so it never masquerades as a day worked.
    foreach ($split['days'] as $date => $d) {
        if ($d['hours'] <= 0) {
            continue;
        }
        $key = $byWeek ? (string)$d['week_start'] : '';
        $ensure($key);
        $buckets[$key]['days_worked']++;
    }

    // Scheduled capacity, minus approved time off. scheduledDaysBetween() returns
    // [] for an unscheduled employee, so has_schedule=false naturally yields
    // scheduled_days 0 / scheduled_hours 0 without a special case.
    foreach (ScheduleCalculator::scheduledDaysBetween($shift, $start, $end) as $date) {
        if (isset($timeOffDates[$date])) {
            continue;   // approved time off — accounted for in the loop below
        }
        $key = $keyFor($date);
        $ensure($key);
        $buckets[$key]['scheduled_days']++;
        $buckets[$key]['scheduled_hours'] += ScheduleCalculator::scheduledHoursForDate($shift, $date);

        // Absent = scheduled, not on approved time off, and no punch ROW at all.
        // Keyed off $daily['days'] rather than worked hours on purpose: an employee
        // who is still clocked in has an open punch and 0 hours, and calling that
        // an absence would be plainly wrong.
        if (!isset($daily['days'][$date])) {
            $buckets[$key]['absent_days']++;
        }
    }

    foreach ($timeOffDates as $date => $t) {
        $date  = (string)$date;
        $hours = $t['hours'];
        if ($hours === null) {
            // Multi-day request: HoursRequested is the span total and cannot be
            // divided without inventing per-day numbers, so fall back to that
            // date's scheduled hours — 0 for an unscheduled day, never a guessed 8.
            $hours = ScheduleCalculator::scheduledHoursForDate($shift, $date);
        }
        $key = $keyFor($date);
        $ensure($key);
        // Every covered date counts as a time-off day, including one that is not a
        // scheduled work day (an approved Saturday). Those contribute 0 hours
        // unless the request itself carried HoursRequested, and they never enter
        // the attendance denominator, so counting them cannot distort a rate.
        $buckets[$key]['time_off_days']++;
        $buckets[$key]['time_off_hours'] += (float)$hours;
    }

    ksort($buckets);

    return [
        'buckets'          => $buckets,
        'open_punch_count' => (int)$daily['open_punch_count'],
        'any_partial_week' => $anyPartial,
    ];
};

try {
    ReportBootstrap::begin('/api/external/v1/reports/utilization', 'reports:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'rounding', 'week_start', 'ot_weekly', 'ot_daily', 'dt_daily',
        'limit', 'offset', 'group_by', 'scheduled_only',
    ]);

    // Declared in $accepted above so they are not reported as ignored params, but
    // parsed here because they are specific to this report.
    $groupBy       = $parseEnumParam($_GET, 'group_by', ['employee', 'employee_week', 'office'], 'employee');
    $scheduledOnly = $parseBoolParam($_GET, 'scheduled_only', false);
    $byWeek        = $groupBy === 'employee_week';

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    $filter = $params->buildUserFilter('u');

    // ------------------------------------------------------------------ roster
    // Deliberately NOT paginated in SQL. Office rollups and the weighted totals
    // both need the whole filtered population; taking a LIMIT here would make the
    // grand totals change as an integrator pages, which is the one thing a grand
    // total must never do. Pagination happens on the shaped rows instead.
    $rosterWhere = $filter['sql'] !== '' ? $filter['sql'] : '1=1';
    $stmt = $conn->prepare(
        "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office, u.JobTitle
         FROM users u
         WHERE $rosterWhere
         ORDER BY u.LastName ASC, u.FirstName ASC, u.ID ASC"
    );
    if ($filter['types'] !== '') {
        $stmt->bind_param($filter['types'], ...$filter['values']);
    }
    $stmt->execute();
    $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rosterIds = array_map(static fn($u) => (int)$u['ID'], $roster);

    // At most one ACTIVE shift per user; an employee whose shift was deactivated is
    // absent from this map and is therefore treated as unscheduled rather than
    // silently keeping a stale denominator.
    $shifts  = $rosterIds === [] ? [] : ScheduleCalculator::loadShiftsForUsers($conn, $rosterIds);
    $timeOff = $rosterIds === [] ? [] : ScheduleCalculator::loadApprovedTimeOff($conn, $rosterIds, $start, $end);

    // ------------------------------------------------------------------ punches
    // One pass, ordered by employee so each employee's punches arrive contiguously
    // and can be folded and discarded before the next employee starts. The same
    // buildUserFilter() predicate is reused, so this can never see an employee the
    // roster query did not.
    $sql = "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut
            FROM timepunches tp
            JOIN users u ON u.ID = tp.EmployeeID
            WHERE tp.Date BETWEEN ? AND ?"
        . ($filter['sql'] !== '' ? " AND {$filter['sql']}" : '')
        . " ORDER BY tp.EmployeeID ASC, tp.Date ASC, tp.id ASC";

    $types  = 'ss' . $filter['types'];
    $values = array_merge([$start, $end], $filter['values']);

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $res = $stmt->get_result();

    $aggregates     = [];
    $currentId      = null;
    $currentPunches = [];

    while ($p = $res->fetch_assoc()) {
        $eid = (int)$p['EmployeeID'];
        if ($currentId !== null && $eid !== $currentId) {
            $aggregates[$currentId] = $computeEmployeeAggregate(
                $currentPunches, $shifts[$currentId] ?? null, $timeOff[$currentId] ?? [],
                $params, $start, $end, $byWeek
            );
            $currentPunches = [];
        }
        $currentId = $eid;
        // Only the columns HoursCalculator reads are retained.
        $currentPunches[] = [
            'Date'       => $p['Date'],
            'TimeIN'     => $p['TimeIN'],
            'LunchStart' => $p['LunchStart'],
            'LunchEnd'   => $p['LunchEnd'],
            'TimeOut'    => $p['TimeOut'],
        ];
    }
    if ($currentId !== null) {
        $aggregates[$currentId] = $computeEmployeeAggregate(
            $currentPunches, $shifts[$currentId] ?? null, $timeOff[$currentId] ?? [],
            $params, $start, $end, $byWeek
        );
    }
    $res->free();
    $stmt->close();

    // --------------------------------------------------------------- build rows
    $rows      = [];
    $officeAgg = [];

    $employeeCount           = 0;
    $noScheduleCount         = 0;
    $zeroScheduledCount      = 0;
    $excludedByScheduledOnly = 0;
    $openPunches             = 0;
    $anyPartialWeekOt        = false;
    $anyPartialWeekRow       = false;

    $sumScheduled = 0.0;
    $sumWorked    = 0.0;
    $sumOvertime  = 0.0;
    $sumTimeOff   = 0.0;
    // Weighted-utilization accumulators — see the totals block for why these are
    // not simply $sumWorked / $sumScheduled.
    $denScheduled = 0.0;
    $denWorked    = 0.0;

    foreach ($roster as $u) {
        $eid         = (int)$u['ID'];
        $shift       = $shifts[$eid] ?? null;
        $hasSchedule = $shift !== null;

        if ($scheduledOnly && !$hasSchedule) {
            $excludedByScheduledOnly++;
            continue;
        }

        // An employee with no punches in the range still needs their schedule and
        // time off expanded — that is exactly the under-utilized person this report
        // exists to surface.
        $agg = $aggregates[$eid] ?? $computeEmployeeAggregate(
            [], $shift, $timeOff[$eid] ?? [], $params, $start, $end, $byWeek
        );

        $employeeCount++;
        $openPunches += (int)$agg['open_punch_count'];
        if ($agg['any_partial_week']) {
            $anyPartialWeekOt = true;
        }
        if (!$hasSchedule) {
            $noScheduleCount++;
        }

        $email     = $nullIfBlank($u['Email']);
        $name      = trim((string)$u['FirstName'] . ' ' . (string)$u['LastName']);
        $office    = $nullIfBlank($u['Office']);
        $jobTitle  = $nullIfBlank($u['JobTitle']);
        $shiftName = $hasSchedule ? $nullIfBlank($shift['ShiftName'] ?? null) : null;

        $empScheduled = 0.0;
        $empWorked    = 0.0;
        $empRegular   = 0.0;
        $empOvertime  = 0.0;
        $empDouble    = 0.0;
        $empTimeOff   = 0.0;

        foreach ($agg['buckets'] as $b) {
            $regular = round((float)$b['regular'], 2);
            $ot      = round((float)$b['overtime'], 2);
            $dt      = round((float)$b['double_time'], 2);
            // Derived from the published components, the same convention
            // /payroll uses, so regular + overtime + double_time always equals
            // worked_hours in the emitted 2-decimal numbers.
            $worked    = round($regular + $ot + $dt, 2);
            $scheduled = round((float)$b['scheduled_hours'], 2);
            $timeOffHr = round((float)$b['time_off_hours'], 2);

            $isPartial = false;
            if ($b['week_start'] !== null) {
                $isPartial = HoursCalculator::isPartialWeek(
                    (string)$b['week_start'], (string)$b['week_end'], $start, $end
                );
                if ($isPartial) {
                    $anyPartialWeekRow = true;
                }
            }

            $empScheduled += $scheduled;
            $empWorked    += $worked;
            $empRegular   += $regular;
            $empOvertime  += $ot;
            $empDouble    += $dt;
            $empTimeOff   += $timeOffHr;

            if ($groupBy === 'office') {
                continue;   // rolled up after the employee loop, from the totals above
            }

            $row = [
                'employee_id'    => $eid,
                'employee_email' => $email,
                'employee_name'  => $name,
                'office'         => $office,
                'job_title'      => $jobTitle,
                'shift_name'     => $shiftName,
                'has_schedule'   => $hasSchedule,
            ];
            if ($byWeek) {
                $row['week_start']      = (string)$b['week_start'];
                $row['week_end']        = (string)$b['week_end'];
                $row['is_partial_week'] = $isPartial;
            }
            $row += [
                'scheduled_days'    => (int)$b['scheduled_days'],
                'scheduled_hours'   => $scheduled,
                'days_worked'       => (int)$b['days_worked'],
                'worked_hours'      => $worked,
                'regular_hours'     => $regular,
                'overtime_hours'    => $ot,
                'double_time_hours' => $dt,
                'time_off_days'     => (int)$b['time_off_days'],
                'time_off_hours'    => $timeOffHr,
                'absent_days'       => (int)$b['absent_days'],
                'utilization_pct'   => $pct($worked, $scheduled),
                'overtime_pct'      => $pct($ot, $worked),
                // days_worked can include an unscheduled day (someone came in on a
                // Saturday), so this rate can legitimately exceed 100.
                'attendance_rate'   => $pct((float)$b['days_worked'], (float)$b['scheduled_days']),
                'variance_hours'    => round($worked - $scheduled, 2),
            ];
            $rows[] = $row;
        }

        if ($groupBy === 'office') {
            $key = ($office === null) ? 'Unassigned' : (string)$office;
            if (!isset($officeAgg[$key])) {
                $officeAgg[$key] = [
                    'office'                  => $key,
                    'employees'               => 0,
                    'employees_with_schedule' => 0,
                    'scheduled_hours'         => 0.0,
                    'worked_hours'            => 0.0,
                    'regular_hours'           => 0.0,
                    'overtime_hours'          => 0.0,
                    'double_time_hours'       => 0.0,
                    'time_off_hours'          => 0.0,
                    'den_scheduled'           => 0.0,
                    'den_worked'              => 0.0,
                ];
            }
            $officeAgg[$key]['employees']++;
            $officeAgg[$key]['scheduled_hours']   += $empScheduled;
            $officeAgg[$key]['worked_hours']      += $empWorked;
            $officeAgg[$key]['regular_hours']     += $empRegular;
            $officeAgg[$key]['overtime_hours']    += $empOvertime;
            $officeAgg[$key]['double_time_hours'] += $empDouble;
            $officeAgg[$key]['time_off_hours']    += $empTimeOff;
            if ($hasSchedule) {
                $officeAgg[$key]['employees_with_schedule']++;
                if ($empScheduled > 0) {
                    $officeAgg[$key]['den_scheduled'] += $empScheduled;
                    $officeAgg[$key]['den_worked']    += $empWorked;
                }
            }
        }

        // Population totals, accumulated per EMPLOYEE rather than per row, so the
        // totals block is identical whichever group_by shaped the rows.
        $sumScheduled += $empScheduled;
        $sumWorked    += $empWorked;
        $sumOvertime  += $empOvertime;
        $sumTimeOff   += $empTimeOff;

        if ($hasSchedule && $empScheduled > 0) {
            $denScheduled += $empScheduled;
            $denWorked    += $empWorked;
        } elseif ($hasSchedule) {
            // Has a shift, but nothing scheduled inside this range (their working
            // days all fall outside it, or are covered by approved time off).
            // Their worked hours must stay out of the weighted numerator too,
            // otherwise hours with no denominator behind them inflate the KPI.
            $zeroScheduledCount++;
        }
    }

    if ($groupBy === 'office') {
        ksort($officeAgg);   // stable alphabetical order; "Unassigned" sorts with the rest
        foreach ($officeAgg as $o) {
            $scheduled = round((float)$o['scheduled_hours'], 2);
            $worked    = round((float)$o['worked_hours'], 2);
            $ot        = round((float)$o['overtime_hours'], 2);
            $rows[] = [
                'office'                  => (string)$o['office'],
                'employees'               => (int)$o['employees'],
                // Not in the original field list, but utilization_pct below is
                // computed over scheduled employees only — without this column a
                // consumer cannot tell how much of the office it describes.
                'employees_with_schedule' => (int)$o['employees_with_schedule'],
                'scheduled_hours'         => $scheduled,
                'worked_hours'            => $worked,
                'regular_hours'           => round((float)$o['regular_hours'], 2),
                'overtime_hours'          => $ot,
                'double_time_hours'       => round((float)$o['double_time_hours'], 2),
                'time_off_hours'          => round((float)$o['time_off_hours'], 2),
                // Numerator and denominator both come from the employees who have
                // scheduled hours here. Dividing the office's FULL worked_hours by
                // its scheduled_hours would credit the unscheduled employees' hours
                // against a denominator they contributed nothing to.
                'utilization_pct'         => $pct((float)$o['den_worked'], (float)$o['den_scheduled']),
                'overtime_pct'            => $pct($ot, $worked),
                // Uses the two published columns, so the arithmetic on the row can
                // be checked by subtracting them in a spreadsheet.
                'variance_hours'          => round($worked - $scheduled, 2),
            ];
        }
    }

    // ------------------------------------------------------------------- totals
    $totals = [
        'employees'       => $employeeCount,
        'scheduled_hours' => round($sumScheduled, 2),
        'worked_hours'    => round($sumWorked, 2),
        'overtime_hours'  => round($sumOvertime, 2),
        'time_off_hours'  => round($sumTimeOff, 2),
        // WEIGHTED, not the mean of the per-row percentages. Averaging percentages
        // gives a part-timer scheduled 4 hours the same weight as a full-timer
        // scheduled 40, so one idle part-timer can move the company number more
        // than a whole idle crew. sum(worked)/sum(scheduled) is the actual share of
        // scheduled capacity that was consumed. Only employees that HAVE a schedule
        // with hours in this range are in either side of the ratio.
        'utilization_pct' => $pct($denWorked, $denScheduled),
    ];

    // --------------------------------------------------------------- pagination
    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    // ------------------------------------------------------------------ warnings
    $warnings = [];
    if ($anyPartialWeekOt) {
        // The shared constant, not a local paraphrase: every endpoint on this
        // surface has to word this understatement identically.
        $warnings[] = HoursCalculator::PARTIAL_WEEK_WARNING;
    }
    if ($byWeek && $anyPartialWeekRow) {
        $warnings[] = 'Scheduled hours in a partial week are clipped to the requested range, so '
            . 'utilization_pct for rows with is_partial_week=true is computed against a partial denominator.';
    }
    if ($scheduledOnly) {
        if ($excludedByScheduledOnly > 0) {
            $warnings[] = 'scheduled_only=true excluded ' . $excludedByScheduledOnly
                . ' employee(s) with no active assigned shift from this report entirely, '
                . 'including from totals.';
        }
    } elseif ($noScheduleCount > 0) {
        $warnings[] = $noScheduleCount . ' of ' . $employeeCount . ' employee(s) have no active assigned '
            . 'shift (has_schedule=false). Their worked hours are reported but they contribute nothing to '
            . 'the utilization denominator, so totals.utilization_pct describes only the employees that do '
            . 'have a schedule. Pass scheduled_only=true to drop them from the report entirely.';
    }
    if ($zeroScheduledCount > 0) {
        $warnings[] = $zeroScheduledCount . ' employee(s) have an active shift but 0 scheduled hours in '
            . 'this range (their working days fall outside it, or are covered by approved time off); they '
            . 'are excluded from totals.utilization_pct so hours with no denominator cannot inflate it.';
    }
    if ($openPunches > 0) {
        $warnings[] = $openPunches . ' punch(es) in this range have no clock-out. They contribute 0 worked '
            . 'hours and are not counted in days_worked, but the day is not counted as an absence either.';
    }

    $meta = $params->meta('utilization', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);
    // Report-specific filters, appended to the shared filters block rather than
    // replacing it, so the published key order of meta itself never shifts.
    $meta['filters']['group_by']       = $groupBy;
    $meta['filters']['scheduled_only'] = $scheduledOnly;

    // Pinned so a zero-row CSV still carries a header row for the importer, and so
    // column order can never drift with the first row's key order.
    if ($groupBy === 'office') {
        $csvColumns = [
            'office', 'employees', 'employees_with_schedule', 'scheduled_hours', 'worked_hours',
            'regular_hours', 'overtime_hours', 'double_time_hours', 'time_off_hours',
            'utilization_pct', 'overtime_pct', 'variance_hours',
        ];
    } else {
        $csvColumns = ['employee_id', 'employee_email', 'employee_name', 'office', 'job_title',
            'shift_name', 'has_schedule'];
        if ($byWeek) {
            array_push($csvColumns, 'week_start', 'week_end', 'is_partial_week');
        }
        array_push(
            $csvColumns,
            'scheduled_days', 'scheduled_hours', 'days_worked', 'worked_hours', 'regular_hours',
            'overtime_hours', 'double_time_hours', 'time_off_days', 'time_off_hours', 'absent_days',
            'utilization_pct', 'overtime_pct', 'attendance_rate', 'variance_hours'
        );
    }

    Output::send(
        ['rows' => $page, 'meta' => $meta, 'totals' => $totals],
        $params,
        'utilization',
        $csvColumns
    );

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/utilization] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
