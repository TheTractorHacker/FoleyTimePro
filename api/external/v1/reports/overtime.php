<?php
/**
 * GET /api/external/v1/reports/overtime
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * One row per employee per WEEK — the series Power BI trends. Weeks are keyed by
 * their week-start date (Mon or Sun, per ?week_start=), and overtime is split into
 * its daily and weekly components so a consumer can see WHICH rule produced the OT.
 *
 * Params: start, end, employee_id, employee_email, office, include_inactive,
 *         rounding, week_start, ot_weekly, ot_daily, dt_daily, tz, format,
 *         limit, offset, only_overtime.
 *
 * Two invariants hold on every emitted row, and the code below derives the values
 * so that they hold in the PUBLISHED (2-decimal) numbers, not merely in theory:
 *   (1) daily_overtime_hours + weekly_overtime_hours == overtime_hours
 *   (2) regular_hours + overtime_hours + double_time_hours == worked_hours
 * Taking HoursCalculator's already-rounded weekly components and re-deriving
 * overtime/regular from them is what makes (1) and (2) exact: rounding a sum and
 * summing the rounded parts can disagree by a cent, and a payroll spreadsheet that
 * does not foot is a support ticket.
 *
 * Aggregation happens in PHP, not SQL: timepunches.TotalHours is not trustworthy
 * (one insert path writes a literal 0 and admin edits do not always recompute it),
 * so hours are always recomputed from the raw TIME columns via HoursCalculator.
 *
 * A row exists only for a week in which the employee has at least one punch —
 * a week with no punches is not a fact this report has an opinion about.
 *
 * `totals` describes the ENTIRE filtered result set, not just the returned page,
 * so paging through a large range never changes the grand totals.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

/**
 * The canonical boolean parser for a report-specific parameter ReportParams does
 * not own. Kept as a closure rather than a named function so two endpoints loaded
 * in the same process can never collide in the global namespace.
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
 * Turn ONE employee's punches into their weekly rows.
 *
 * Called as soon as the result-set cursor leaves that employee, so only a single
 * employee's punches are ever resident. Materializing the whole company's punch
 * history first (fetch_all over a 400-day range) is the one way this endpoint
 * could plausibly exhaust memory on a large roster.
 */
$emitEmployeeWeeks = static function (
    int $employeeId,
    array $employee,
    array $punches,
    ReportParams $params,
    string $start,
    string $end,
    bool $onlyOvertime,
    array &$rows,
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

    // days_worked counts a DATE once, and only when it produced paid time. A day
    // whose only punch is still open contributes 0 hours and is not a worked day
    // here — meta.warnings reports those separately so the gap stays visible
    // instead of silently rolling into a low weekly total.
    $daysWorkedByWeek = [];
    foreach ($split['days'] as $d) {
        if ($d['hours'] > 0) {
            $wk = $d['week_start'];
            $daysWorkedByWeek[$wk] = ($daysWorkedByWeek[$wk] ?? 0) + 1;
        }
    }

    foreach ($split['weeks'] as $wkKey => $w) {
        $isPartial = HoursCalculator::isPartialWeek($w['week_start'], $w['week_end'], $start, $end);
        // Flagged BEFORE the only_overtime filter: a partial week's OT can be
        // understated all the way to zero, which is exactly the case where the
        // filter hides the row and the integrator never learns why.
        if ($isPartial) {
            $anyPartialWeek = true;
        }

        $dailyOt  = (float)$w['daily_overtime'];   // already rounded to 2dp
        $weeklyOt = (float)$w['weekly_overtime'];  // already rounded to 2dp
        $overtime = round($dailyOt + $weeklyOt, 2);          // invariant (1)
        $double   = (float)$w['double_time'];
        $worked   = (float)$w['hours'];
        $regular  = round($worked - $overtime - $double, 2); // invariant (2)
        if ($regular < 0) {
            // Only reachable if two components each rounded up past the day total;
            // a negative regular column would be worse than a lost cent.
            $regular = 0.0;
        }

        if ($onlyOvertime && ($overtime + $double) <= 0) {
            continue;
        }

        $rows[] = [
            'employee_id'           => $employeeId,
            'employee_email'        => $employee['email'],
            'employee_name'         => $employee['name'],
            'office'                => $employee['office'],
            'week_start'            => $w['week_start'],
            'week_end'              => $w['week_end'],
            'is_partial_week'       => $isPartial,
            'days_worked'           => (int)($daysWorkedByWeek[$wkKey] ?? 0),
            'worked_hours'          => $worked,
            'regular_hours'         => $regular,
            'overtime_hours'        => $overtime,
            'double_time_hours'     => $double,
            'daily_overtime_hours'  => $dailyOt,
            'weekly_overtime_hours' => $weeklyOt,
            'ot_threshold_weekly'   => $params->otWeekly(),
            'ot_threshold_daily'    => $params->otDaily(),
        ];
    }
};

try {
    ReportBootstrap::begin('/api/external/v1/reports/overtime', 'reports:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'rounding', 'week_start', 'ot_weekly', 'ot_daily', 'dt_daily',
        'limit', 'offset', 'only_overtime',
    ]);

    // Declared in $accepted above so it is not reported as an ignored param, but
    // parsed here because it is specific to this report.
    $onlyOvertime = $parseBoolParam($_GET, 'only_overtime', false);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    // One pass over the punches. The user columns ride along so employees with no
    // punches never reach the row builder and no second roster query is needed.
    // The ORDER BY leads with the employee-identifying columns, which does double
    // duty: every employee's punches arrive contiguously (so they can be folded
    // and discarded one employee at a time) and the emitted rows are already in
    // their published order, since splitOvertime ksorts the weeks within each
    // employee. u.ID tie-breaks the name sort — two people can share a name.
    $filter = $params->buildUserFilter('u');
    $sql = "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut,
                   u.Email, u.FirstName, u.LastName, u.Office
            FROM timepunches tp
            JOIN users u ON u.ID = tp.EmployeeID
            WHERE tp.Date BETWEEN ? AND ?"
        . ($filter['sql'] !== '' ? " AND {$filter['sql']}" : '')
        . " ORDER BY u.LastName ASC, u.FirstName ASC, tp.EmployeeID ASC, tp.Date ASC";

    $types  = 'ss' . $filter['types'];
    $values = array_merge([$start, $end], $filter['values']);

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows           = [];
    $anyPartialWeek = false;
    $openPunches    = 0;

    $currentId       = null;
    $currentEmployee = [];
    $currentPunches  = [];

    while ($p = $res->fetch_assoc()) {
        $eid = (int)$p['EmployeeID'];
        if ($currentId !== null && $eid !== $currentId) {
            $emitEmployeeWeeks(
                $currentId, $currentEmployee, $currentPunches, $params,
                $start, $end, $onlyOvertime, $rows, $anyPartialWeek, $openPunches
            );
            $currentPunches = [];
        }
        if ($eid !== $currentId) {
            $currentId       = $eid;
            $currentEmployee = [
                'email'  => $p['Email']  !== null && $p['Email']  !== '' ? (string)$p['Email']  : null,
                'name'   => trim((string)$p['FirstName'] . ' ' . (string)$p['LastName']),
                'office' => $p['Office'] !== null && $p['Office'] !== '' ? (string)$p['Office'] : null,
            ];
        }
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
        $emitEmployeeWeeks(
            $currentId, $currentEmployee, $currentPunches, $params,
            $start, $end, $onlyOvertime, $rows, $anyPartialWeek, $openPunches
        );
    }
    $res->free();
    $stmt->close();

    // Totals span the whole filtered result set, deliberately NOT just the page:
    // a grand total that changes as you page is a grand total nobody can use.
    $sumWorked   = 0.0;
    $sumRegular  = 0.0;
    $sumOvertime = 0.0;
    $sumDouble   = 0.0;
    $employees   = [];
    $weeks       = [];
    $withOt      = [];
    foreach ($rows as $r) {
        $sumWorked   += $r['worked_hours'];
        $sumRegular  += $r['regular_hours'];
        $sumOvertime += $r['overtime_hours'];
        $sumDouble   += $r['double_time_hours'];
        $employees[$r['employee_id']] = true;
        $weeks[$r['week_start']]      = true;
        if ($r['overtime_hours'] > 0 || $r['double_time_hours'] > 0) {
            $withOt[$r['employee_id']] = true;
        }
    }

    $totals = [
        'employees'               => count($employees),
        'weeks'                   => count($weeks),   // distinct calendar weeks, not rows
        'worked_hours'            => round($sumWorked, 2),
        'regular_hours'           => round($sumRegular, 2),
        'overtime_hours'          => round($sumOvertime, 2),
        'double_time_hours'       => round($sumDouble, 2),
        'employees_with_overtime' => count($withOt),
    ];

    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    $warnings = [];
    if ($anyPartialWeek) {
        $warnings[] = HoursCalculator::PARTIAL_WEEK_WARNING;
    }
    if ($openPunches > 0) {
        $warnings[] = $openPunches . ' punch(es) in this range have no clock-out. They contribute '
            . '0 worked hours and are not counted in days_worked, so the affected weeks are understated.';
    }

    $meta = $params->meta('overtime', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);
    // Report-specific filter, appended to the shared filters block rather than
    // replacing it, so the published key order of meta itself never shifts.
    $meta['filters']['only_overtime'] = $onlyOvertime;

    // Pinned so a zero-row CSV still carries a header row for the importer, and
    // so column order can never drift with the first row's key order.
    $csvColumns = [
        'employee_id', 'employee_email', 'employee_name', 'office',
        'week_start', 'week_end', 'is_partial_week', 'days_worked',
        'worked_hours', 'regular_hours', 'overtime_hours', 'double_time_hours',
        'daily_overtime_hours', 'weekly_overtime_hours',
        'ot_threshold_weekly', 'ot_threshold_daily',
    ];

    Output::send(
        ['rows' => $page, 'meta' => $meta, 'totals' => $totals],
        $params,
        'overtime',
        $csvColumns
    );

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/overtime] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
