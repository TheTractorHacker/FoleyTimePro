<?php
/**
 * GET /api/external/v1/reports/summary
 * Authorization: Bearer <api-key>   (scope: reports:read)
 *
 * The headline report: one row per employee for the whole range, with regular /
 * overtime / double-time already allocated.
 *
 * Unlike /api/external/v1/reports/hours — which is a fact table and only emits
 * employee-days that actually have punches — this report is ROSTER shaped: every
 * employee matching the filters gets a row, including one who worked nothing in
 * the range (days_worked 0, first_punch_date null). That is deliberate. A payroll
 * run needs to see the person who never clocked in; an absent row looks like a
 * filter mistake, a zero row looks like a problem to chase. `include_inactive`
 * still governs whether locked-out accounts appear at all.
 *
 * All hours are recomputed from the raw TIME columns (timepunches.TotalHours is
 * not trustworthy), rounded once per day per the `rounding` policy, and then fed
 * to HoursCalculator::splitOvertime() — the same allocation every other report on
 * this surface uses, so /summary, /overtime and /payroll can never disagree.
 *
 * Query parameters: start, end (required), employee_id, employee_email, office,
 * include_inactive, rounding, week_start, ot_weekly, ot_daily, dt_daily, tz,
 * format, limit, offset.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

try {
    ReportBootstrap::begin('/api/external/v1/reports/summary', 'reports:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office',
        'include_inactive', 'rounding', 'week_start', 'ot_weekly', 'ot_daily',
        'dt_daily', 'limit', 'offset',
    ]);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    // The roster query filters on users only; the date range belongs to the punch
    // lookup. buildUserFilter() returns '' when nothing applies (include_inactive
    // with no employee/office filter), hence the 1=1 anchor.
    $filter      = $params->buildUserFilter('u');
    $userWhere   = $filter['sql'] !== '' ? $filter['sql'] : '1=1';
    $userTypes   = $filter['types'];
    $userValues  = $filter['values'];

    // ---------------------------------------------------------------- counting
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users u WHERE $userWhere");
    if ($userTypes !== '') {
        $stmt->bind_param($userTypes, ...$userValues);
    }
    $stmt->execute();
    $totalCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // ------------------------------------------------------------ page of users
    // u.ID tie-breaks the name sort: two employees can share a display name, and
    // without a unique tail the same row can surface on two different pages.
    $limit  = $params->limit();
    $offset = $params->offset();

    $stmt = $conn->prepare(
        "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office, u.JobTitle
         FROM users u
         WHERE $userWhere
         ORDER BY u.FirstName, u.LastName, u.ID
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param($userTypes . 'ii', ...array_merge($userValues, [$limit, $offset]));
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ------------------------------------------------------------ punch detail
    // Fetched only for the employees on this page, so a deep offset never pulls
    // the whole company's punches.
    $punchesByEmployee = [];
    if ($users !== []) {
        $ids = array_map(static fn($u) => (int)$u['ID'], $users);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $conn->prepare(
            "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut
             FROM timepunches tp
             WHERE tp.Date BETWEEN ? AND ?
               AND tp.EmployeeID IN ($ph)
             ORDER BY tp.EmployeeID, tp.Date, tp.TimeIN, tp.id"
        );
        $stmt->bind_param('ss' . str_repeat('i', count($ids)), ...array_merge([$start, $end], $ids));
        $stmt->execute();
        $res = $stmt->get_result();
        while ($p = $res->fetch_assoc()) {
            $punchesByEmployee[(int)$p['EmployeeID']][] = $p;
        }
        $res->free();
        $stmt->close();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Lunch seconds actually DEDUCTED from worked time, mirroring
     * HoursCalculator::punchWorkedSeconds() anchoring and clamping exactly.
     * Derived here rather than as (span - worked) because the calculator clamps a
     * punch longer than 24h, and that clamp would be misreported as a huge lunch.
     */
    $lunchSecondsFor = static function (array $p): int {
        $in  = HoursCalculator::timeToSeconds($p['TimeIN'] ?? null);
        $out = HoursCalculator::timeToSeconds($p['TimeOut'] ?? null);
        if ($in === null || $out === null) {
            return 0;   // open punch: nothing was deducted, because nothing was worked
        }
        $ls = HoursCalculator::timeToSeconds($p['LunchStart'] ?? null);
        $le = HoursCalculator::timeToSeconds($p['LunchEnd'] ?? null);
        if ($ls === null || $le === null) {
            return 0;   // half a lunch is unusable; the calculator subtracts nothing either
        }
        if ($out < $in) { $out += 86400; }   // overnight shift
        if ($ls  < $in) { $ls  += 86400; }
        if ($le  < $ls) { $le  += 86400; }
        return (int)min(max(0, $le - $ls), $out - $in);
    };

    $nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

    // --------------------------------------------------------------- build rows
    $rows        = [];
    $anyPartial  = false;
    $totDays     = 0;
    $totWorked   = 0.0;
    $totRegular  = 0.0;
    $totOvertime = 0.0;
    $totDouble   = 0.0;
    $totOpen     = 0;

    foreach ($users as $u) {
        $empId  = (int)$u['ID'];
        $punchRows = $punchesByEmployee[$empId] ?? [];

        // Order matters: round once per day, THEN allocate overtime from the
        // rounded daily figures. Allocating first and rounding after would make
        // these numbers disagree with admin/summary.php.
        $daily = HoursCalculator::dailyHoursFromPunches($punchRows, $params->rounding());

        $hoursByDate = [];
        $daysWorked  = 0;
        foreach ($daily['days'] as $date => $d) {
            $hoursByDate[$date] = (float)$d['hours'];
            // A day whose only punch is still open contributed no worked time; it
            // is reported through open_punches, not as a day worked, so
            // avg_hours_per_day is never diluted by a day with nothing in it.
            if ($d['hours'] > 0) { $daysWorked++; }
        }

        $split = HoursCalculator::splitOvertime(
            $hoursByDate,
            $params->otDaily(),
            $params->dtDaily(),
            $params->otWeekly(),
            $params->weekStart()
        );
        $totals = $split['totals'];

        $hasPartialWeek = false;
        foreach ($split['weeks'] as $w) {
            if (HoursCalculator::isPartialWeek($w['week_start'], $w['week_end'], $start, $end)) {
                $hasPartialWeek = true;
                break;
            }
        }
        if ($hasPartialWeek) { $anyPartial = true; }

        $lunchSec = 0;
        foreach ($punchRows as $p) { $lunchSec += $lunchSecondsFor($p); }

        $workedHours = (float)$totals['hours'];
        $openPunches = (int)$daily['open_punch_count'];

        // ksort()ed by dailyHoursFromPunches, so first/last key are min/max date.
        $dates = array_keys($daily['days']);

        $rows[] = [
            'employee_id'       => $empId,
            'employee_email'    => $nullIfBlank($u['Email']),
            'employee_name'     => trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? '')),
            'office'            => $nullIfBlank($u['Office']),
            'job_title'         => $nullIfBlank($u['JobTitle']),
            'days_worked'       => $daysWorked,
            'worked_hours'      => $workedHours,
            'regular_hours'     => (float)$totals['regular'],
            'overtime_hours'    => (float)$totals['overtime'],
            'double_time_hours' => (float)$totals['double_time'],
            'lunch_hours'       => round($lunchSec / 3600, 2),
            // null, not 0: an employee with no days worked has no average, and a
            // zero would quietly drag any downstream mean down.
            'avg_hours_per_day' => $daysWorked > 0 ? round($workedHours / $daysWorked, 2) : null,
            'first_punch_date'  => $dates === [] ? null : (string)$dates[0],
            'last_punch_date'   => $dates === [] ? null : (string)$dates[count($dates) - 1],
            'open_punches'      => $openPunches,
            'has_partial_week'  => $hasPartialWeek,
        ];

        $totDays     += $daysWorked;
        $totWorked   += $workedHours;
        $totRegular  += (float)$totals['regular'];
        $totOvertime += (float)$totals['overtime'];
        $totDouble   += (float)$totals['double_time'];
        $totOpen     += $openPunches;
    }

    // ------------------------------------------------------------------- output
    $rowCount = count($rows);
    $warnings = [];

    if ($anyPartial) {
        // The shared constant, not a local paraphrase: every endpoint on this
        // surface has to word this understatement identically.
        $warnings[] = HoursCalculator::PARTIAL_WEEK_WARNING;
    }
    if ($offset > 0 || ($offset + $rowCount) < $totalCount) {
        $warnings[] = 'Totals cover only the ' . $rowCount . ' employee(s) returned by this request, not all '
            . $totalCount . ' matching the filters. Page through the full result set to total it.';
    }
    if ($totOpen > 0) {
        $warnings[] = $totOpen . ' open punch(es) (clocked in, never clocked out) contributed 0 worked hours; '
            . 'see each row\'s open_punches.';
    }

    $meta = $params->meta('summary', [
        'row_count'   => $rowCount,
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);

    $data = [
        'rows'   => $rows,
        'meta'   => $meta,
        'totals' => [
            'employees'         => $rowCount,
            'days_worked'       => $totDays,
            'worked_hours'      => round($totWorked, 2),
            'regular_hours'     => round($totRegular, 2),
            'overtime_hours'    => round($totOvertime, 2),
            'double_time_hours' => round($totDouble, 2),
        ],
    ];

    // Pinned so a zero-row CSV still has a header and the column order is stable.
    Output::send($data, $params, 'summary', [
        'employee_id', 'employee_email', 'employee_name', 'office', 'job_title',
        'days_worked', 'worked_hours', 'regular_hours', 'overtime_hours',
        'double_time_hours', 'lunch_hours', 'avg_hours_per_day',
        'first_punch_date', 'last_punch_date', 'open_punches', 'has_partial_week',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/summary] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
