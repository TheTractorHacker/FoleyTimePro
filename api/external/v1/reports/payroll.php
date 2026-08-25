<?php
/**
 * GET /api/external/v1/reports/payroll
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * One row per employee for the requested pay period, shaped to be handed straight
 * to payroll / QuickBooks: worked hours already split into regular / overtime /
 * double-time, approved time off broken out by category, and the paid total.
 *
 * Params: start, end, employee_id, employee_email, office, include_inactive,
 *         rounding, week_start, ot_weekly, ot_daily, dt_daily, tz, format,
 *         limit, offset, include_timeoff.
 *
 * ROSTER SEMANTICS: a row is emitted for EVERY employee matching the filter, even
 * one with no punches and no time off. Payroll needs to see the zero — a missing
 * row is indistinguishable from an employee who was filtered out, and "who didn't
 * report any hours" is the first question asked before a pay run. Narrow the
 * population with employee_id / office to get only people who worked.
 *
 * Hours are recomputed from the raw TIME columns (timepunches.TotalHours is not
 * trustworthy: one insert path writes a literal 0 and admin edits do not always
 * recompute it). Weekly overtime is still bucketed by calendar week inside the
 * period, so a pay period that does not align to week boundaries carries the
 * standard partial-week warning — see meta.warnings.
 *
 * Row invariants, true in the PUBLISHED 2-decimal numbers, not merely in theory:
 *   regular_hours + overtime_hours + double_time_hours  == total_worked_hours
 *   vacation + sick + personal + other_timeoff          == total_timeoff_hours
 *   total_worked_hours + total_timeoff_hours            == total_paid_hours
 *
 * `totals` describes the ENTIRE filtered result set, not just the returned page,
 * so paging through a large roster never changes the grand totals.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

/**
 * Boolean parser for a report-specific parameter ReportParams does not own. A
 * closure, not a named function, so two endpoints loaded in one process can never
 * collide in the global namespace.
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

/**
 * Fold ONE employee's punches down to the handful of numbers a payroll row needs.
 *
 * Called as soon as the punch cursor leaves that employee, so only a single
 * employee's punches are ever resident; what survives is a five-key aggregate.
 * Materializing every punch for the whole roster over a 400-day period first is
 * the one way this endpoint could plausibly exhaust memory.
 */
$foldEmployeePunches = static function (
    int $employeeId,
    array $punches,
    ReportParams $params,
    string $start,
    string $end,
    array &$aggregates,
    bool &$anyPartialWeek,
    int &$openPunches
): void {
    // Rounding is applied ONCE, to the summed day, by dailyHoursFromPunches.
    $daily = HoursCalculator::dailyHoursFromPunches($punches, $params->rounding());
    $openPunches += $daily['open_punch_count'];

    $hoursByDate = [];
    foreach ($daily['days'] as $date => $d) {
        $hoursByDate[$date] = $d['hours'];
    }

    $split = HoursCalculator::splitOvertime(
        $hoursByDate,
        $params->otDaily(),
        $params->dtDaily(),
        $params->otWeekly(),
        $params->weekStart()
    );

    foreach ($split['weeks'] as $w) {
        if (HoursCalculator::isPartialWeek($w['week_start'], $w['week_end'], $start, $end)) {
            $anyPartialWeek = true;
            break;
        }
    }

    // A day whose only punch is still open yields 0 hours and is not a worked
    // day; the open_punches column is how that gap stays visible on the row.
    $daysWorked = 0;
    foreach ($split['days'] as $d) {
        if ($d['hours'] > 0) {
            $daysWorked++;
        }
    }

    $aggregates[$employeeId] = [
        'regular'      => (float)$split['totals']['regular'],
        'overtime'     => (float)$split['totals']['overtime'],
        'double_time'  => (float)$split['totals']['double_time'],
        'days_worked'  => $daysWorked,
        'open_punches' => (int)$daily['open_punch_count'],
    ];
};

try {
    ReportBootstrap::begin('/api/external/v1/reports/payroll', 'reports:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'rounding', 'week_start', 'ot_weekly', 'ot_daily', 'dt_daily',
        'limit', 'offset', 'include_timeoff',
    ]);

    // Declared in $accepted above so it is not reported as an ignored param, but
    // parsed here because it is specific to this report.
    $includeTimeoff = $parseBoolParam($_GET, 'include_timeoff', true);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    $filter = $params->buildUserFilter('u');

    // 1) The roster. This defines the rows; the same predicate is reused for the
    //    punch query below so the two can never describe different populations.
    //    u.ID tie-breaks the name sort — two employees can share a display name,
    //    and without a unique tail the same row can surface on two pages.
    $rosterSql = "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office, u.JobTitle
                  FROM users u"
        . ($filter['sql'] !== '' ? " WHERE {$filter['sql']}" : '')
        . " ORDER BY u.LastName ASC, u.FirstName ASC, u.ID ASC";

    $stmt = $conn->prepare($rosterSql);
    if ($filter['types'] !== '') {
        // bind_param() rejects an empty type string, so bind only when the filter
        // actually contributed placeholders.
        $stmt->bind_param($filter['types'], ...$filter['values']);
    }
    $stmt->execute();
    $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rosterIds = [];
    foreach ($roster as $r) {
        $rosterIds[] = (int)$r['ID'];
    }

    // 2) Punches for that population, streamed and folded one employee at a time.
    //    ORDER BY tp.EmployeeID guarantees each employee's punches are contiguous.
    $aggregates     = [];
    $anyPartialWeek = false;
    $openPunches    = 0;

    if ($rosterIds !== []) {
        $punchSql = "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut
                     FROM timepunches tp
                     JOIN users u ON u.ID = tp.EmployeeID
                     WHERE tp.Date BETWEEN ? AND ?"
            . ($filter['sql'] !== '' ? " AND {$filter['sql']}" : '')
            . " ORDER BY tp.EmployeeID ASC, tp.Date ASC";

        $types  = 'ss' . $filter['types'];
        $values = array_merge([$start, $end], $filter['values']);

        $stmt = $conn->prepare($punchSql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $res = $stmt->get_result();

        $currentId      = null;
        $currentPunches = [];
        while ($p = $res->fetch_assoc()) {
            $eid = (int)$p['EmployeeID'];
            if ($currentId !== null && $eid !== $currentId) {
                $foldEmployeePunches(
                    $currentId, $currentPunches, $params, $start, $end,
                    $aggregates, $anyPartialWeek, $openPunches
                );
                $currentPunches = [];
            }
            $currentId        = $eid;
            $currentPunches[] = [
                'Date'       => $p['Date'],
                'TimeIN'     => $p['TimeIN'],
                'LunchStart' => $p['LunchStart'],
                'LunchEnd'   => $p['LunchEnd'],
                'TimeOut'    => $p['TimeOut'],
            ];
        }
        if ($currentId !== null) {
            $foldEmployeePunches(
                $currentId, $currentPunches, $params, $start, $end,
                $aggregates, $anyPartialWeek, $openPunches
            );
        }
        $res->free();
        $stmt->close();
    }

    // 3) Approved time off, expanded per date and clipped to the period by
    //    ScheduleCalculator — a request straddling the period boundary therefore
    //    contributes only its in-period days. Shifts are loaded only to resolve
    //    the per-date hours of MULTI-day requests, where HoursRequested is the
    //    span total and cannot be divided without inventing numbers.
    $timeOff = [];
    $shifts  = [];
    if ($includeTimeoff && $rosterIds !== []) {
        $timeOff = ScheduleCalculator::loadApprovedTimeOff($conn, $rosterIds, $start, $end);
        if ($timeOff !== []) {
            $shifts = ScheduleCalculator::loadShiftsForUsers($conn, $rosterIds);
        }
    }

    $zero = [
        'regular'      => 0.0,
        'overtime'     => 0.0,
        'double_time'  => 0.0,
        'days_worked'  => 0,
        'open_punches' => 0,
    ];

    $rows = [];
    foreach ($roster as $u) {
        $eid = (int)$u['ID'];
        $agg = $aggregates[$eid] ?? $zero;

        $regular = (float)$agg['regular'];
        $ot      = (float)$agg['overtime'];
        $dt      = (float)$agg['double_time'];
        // Derived from the published components so the row always foots.
        $worked  = round($regular + $ot + $dt, 2);

        $vacation = 0.0;
        $sick     = 0.0;
        $personal = 0.0;
        $other    = 0.0;
        foreach ($timeOff[$eid] ?? [] as $date => $t) {
            $h = $t['hours'];
            if ($h === null) {
                // Multi-day request: fall back to that date's scheduled hours, and
                // 0 for an unscheduled employee/day rather than a guessed 8.
                $h = ScheduleCalculator::scheduledHoursForDate($shifts[$eid] ?? null, (string)$date);
            }
            $h = (float)$h;
            if ($h <= 0) {
                continue;
            }
            switch (strtolower((string)$t['type'])) {
                case 'vacation': $vacation += $h; break;
                case 'sick':     $sick     += $h; break;
                case 'personal': $personal += $h; break;
                default:         $other    += $h; break;  // 'Other', and any future enum value
            }
        }
        $vacation     = round($vacation, 2);
        $sick         = round($sick, 2);
        $personal     = round($personal, 2);
        $other        = round($other, 2);
        $timeoffTotal = round($vacation + $sick + $personal + $other, 2);

        $rows[] = [
            'employee_id'         => $eid,
            'employee_email'      => $u['Email']    !== null && $u['Email']    !== '' ? (string)$u['Email']    : null,
            'employee_name'       => trim((string)$u['FirstName'] . ' ' . (string)$u['LastName']),
            'office'              => $u['Office']   !== null && $u['Office']   !== '' ? (string)$u['Office']   : null,
            'job_title'           => $u['JobTitle'] !== null && $u['JobTitle'] !== '' ? (string)$u['JobTitle'] : null,
            'pay_period_start'    => $start,
            'pay_period_end'      => $end,
            'regular_hours'       => $regular,
            'overtime_hours'      => $ot,
            'double_time_hours'   => $dt,
            'total_worked_hours'  => $worked,
            'vacation_hours'      => $vacation,
            'sick_hours'          => $sick,
            'personal_hours'      => $personal,
            'other_timeoff_hours' => $other,
            'total_timeoff_hours' => $timeoffTotal,
            'total_paid_hours'    => round($worked + $timeoffTotal, 2),
            'days_worked'         => (int)$agg['days_worked'],
            'open_punches'        => (int)$agg['open_punches'],
        ];
    }

    // Totals span the whole filtered result set, deliberately NOT just the page:
    // a grand total that changes as you page is a grand total nobody can use.
    $sumRegular = 0.0;
    $sumOt      = 0.0;
    $sumDt      = 0.0;
    $sumWorked  = 0.0;
    $sumTimeoff = 0.0;
    $sumPaid    = 0.0;
    foreach ($rows as $r) {
        $sumRegular += $r['regular_hours'];
        $sumOt      += $r['overtime_hours'];
        $sumDt      += $r['double_time_hours'];
        $sumWorked  += $r['total_worked_hours'];
        $sumTimeoff += $r['total_timeoff_hours'];
        $sumPaid    += $r['total_paid_hours'];
    }

    $totals = [
        'employees'           => count($rows),
        'regular_hours'       => round($sumRegular, 2),
        'overtime_hours'      => round($sumOt, 2),
        'double_time_hours'   => round($sumDt, 2),
        'total_worked_hours'  => round($sumWorked, 2),
        'total_timeoff_hours' => round($sumTimeoff, 2),
        'total_paid_hours'    => round($sumPaid, 2),
    ];

    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    $warnings = [];
    if ($anyPartialWeek) {
        $warnings[] = HoursCalculator::PARTIAL_WEEK_WARNING;
    }
    if ($openPunches > 0) {
        $warnings[] = $openPunches . ' punch(es) in this period have no clock-out. They contribute '
            . '0 worked hours and are not counted in days_worked; see the open_punches column.';
    }
    if (!$includeTimeoff) {
        $warnings[] = 'include_timeoff=false: the time-off columns are reported as 0 and were not '
            . 'computed. total_paid_hours therefore equals total_worked_hours.';
    }

    $meta = $params->meta('payroll', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);
    // Report-specific filter, appended to the shared filters block rather than
    // replacing it, so the published key order of meta itself never shifts.
    $meta['filters']['include_timeoff'] = $includeTimeoff;

    // Pinned so a zero-row CSV still carries a header row for the importer, and
    // so column order can never drift with the first row's key order.
    $csvColumns = [
        'employee_id', 'employee_email', 'employee_name', 'office', 'job_title',
        'pay_period_start', 'pay_period_end',
        'regular_hours', 'overtime_hours', 'double_time_hours', 'total_worked_hours',
        'vacation_hours', 'sick_hours', 'personal_hours', 'other_timeoff_hours',
        'total_timeoff_hours', 'total_paid_hours', 'days_worked', 'open_punches',
    ];

    Output::send(
        ['rows' => $page, 'meta' => $meta, 'totals' => $totals],
        $params,
        'payroll',
        $csvColumns
    );

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/payroll] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
