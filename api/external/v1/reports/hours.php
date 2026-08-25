<?php
/**
 * GET /api/external/v1/reports/hours
 * Authorization: Bearer <api-key>   (scope: reports:read)
 *
 * The fact table for BI: one row per employee per DAY that has punch activity in
 * [start, end]. Everything else in the report surface (summary, overtime,
 * utilization) is an aggregation of these numbers, so this endpoint is the place
 * an integrator reconciles against when a headline figure looks wrong.
 *
 * Days with no punch row at all are absent by design — this is a fact table, not
 * a calendar. /api/external/v1/reports/summary is the roster-shaped view that
 * shows an employee who worked zero days.
 *
 * Hours are recomputed from the raw TIME columns, never read from
 * timepunches.TotalHours: one insert path writes a literal 0 and admin edits do
 * not always recompute it. The stored value is still surfaced, unrounded and
 * unsummed-over-days, as `stored_total_hours` purely so a migration can be
 * audited — never use it for math.
 *
 * Query parameters: start, end (required), employee_id, employee_email, office,
 * include_inactive, rounding, tz, format, limit, offset.
 *
 * Row:
 *   {
 *     "employee_id": 42, "employee_email": "tech@foleyit.com",
 *     "employee_name": "Dana Reyes", "office": "Springfield", "job_title": "Tech",
 *     "date": "2026-08-03", "day_of_week": "Mon",
 *     "first_in": "08:02:00", "last_out": "16:31:00", "punch_count": 1,
 *     "worked_hours": 7.98, "lunch_hours": 0.5, "span_hours": 8.48,
 *     "stored_total_hours": 8.0, "has_open_punch": false, "warnings": []
 *   }
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

try {
    ReportBootstrap::begin('/api/external/v1/reports/hours', 'reports:read');

    // Deliberately NOT accepting week_start/ot_*: this report emits raw daily
    // facts and never splits overtime, and an accepted-but-unused parameter would
    // make an integrator think their OT rules were applied. Unlisted parameters
    // come back in meta.ignored_params instead.
    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office',
        'include_inactive', 'rounding', 'limit', 'offset',
    ]);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    $filter     = $params->buildUserFilter('u');
    $where      = 'tp.Date BETWEEN ? AND ?' . ($filter['sql'] !== '' ? ' AND ' . $filter['sql'] : '');
    $baseTypes  = 'ss' . $filter['types'];
    $baseValues = array_merge([$start, $end], $filter['values']);

    // ---------------------------------------------------------------- counting
    // The real unpaginated employee-day count. Derived-table COUNT rather than
    // COUNT(DISTINCT ...) so it stays correct if the grouping key ever grows.
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM (
             SELECT tp.EmployeeID
             FROM timepunches tp
             JOIN users u ON u.ID = tp.EmployeeID
             WHERE $where
             GROUP BY tp.EmployeeID, tp.Date
         ) d"
    );
    $stmt->bind_param($baseTypes, ...$baseValues);
    $stmt->execute();
    $totalCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // ------------------------------------------------------------- page of keys
    // Pagination is over employee-DAYS, so the page is selected as a set of
    // (EmployeeID, Date) keys first and the punches are fetched for exactly those
    // keys. Fetching by employee instead would drag in every day of the range for
    // an employee who only contributes one row to the page.
    //
    // ORDER BY carries u.ID as a tie-break: two employees can share a display
    // name, and without a unique tail the same row can appear on two pages.
    // Every non-aggregated column is in the GROUP BY so ONLY_FULL_GROUP_BY holds.
    $limit  = $params->limit();
    $offset = $params->offset();

    $stmt = $conn->prepare(
        "SELECT tp.EmployeeID, tp.Date, u.Email, u.FirstName, u.LastName, u.Office, u.JobTitle
         FROM timepunches tp
         JOIN users u ON u.ID = tp.EmployeeID
         WHERE $where
         GROUP BY tp.EmployeeID, tp.Date, u.ID, u.Email, u.FirstName, u.LastName, u.Office, u.JobTitle
         ORDER BY u.FirstName, u.LastName, u.ID, tp.Date
         LIMIT ? OFFSET ?"
    );
    $pageTypes  = $baseTypes . 'ii';
    $pageValues = array_merge($baseValues, [$limit, $offset]);
    $stmt->bind_param($pageTypes, ...$pageValues);
    $stmt->execute();
    $keys = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ------------------------------------------------------------ punch detail
    // punchesByEmployeeDay[employeeId][date] = raw punch rows
    $punchesByEmployeeDay = [];
    if ($keys !== []) {
        // Row-constructor IN over the page's exact (employee, date) pairs. The
        // placeholder tuples are built from count() alone; every value is bound.
        $tuples = implode(',', array_fill(0, count($keys), '(?,?)'));
        $types  = 'ss' . str_repeat('is', count($keys));
        $values = [$start, $end];
        foreach ($keys as $k) {
            $values[] = (int)$k['EmployeeID'];
            $values[] = (string)$k['Date'];
        }

        // The redundant-looking Date BETWEEN keeps the date index usable; the row
        // constructor alone can degrade to a scan on older MariaDB optimizers.
        $stmt = $conn->prepare(
            "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd,
                    tp.TimeOut, tp.TotalHours
             FROM timepunches tp
             WHERE tp.Date BETWEEN ? AND ?
               AND (tp.EmployeeID, tp.Date) IN ($tuples)
             ORDER BY tp.EmployeeID, tp.Date, tp.TimeIN, tp.id"
        );
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($p = $res->fetch_assoc()) {
            $punchesByEmployeeDay[(int)$p['EmployeeID']][(string)$p['Date']][] = $p;
        }
        $res->free();
        $stmt->close();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Lunch seconds actually DEDUCTED from worked time, mirroring
     * HoursCalculator::punchWorkedSeconds() anchoring and clamping exactly.
     *
     * Derived here rather than as (span - worked) because the calculator clamps a
     * punch longer than 24h, and that clamp would otherwise be misreported as an
     * enormous lunch.
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

    /** Three-letter day name, matching the vocabulary in shifts.DaysOfWeek. */
    $dayOfWeek = static function (string $date): string {
        // UTC: a local midnight does not exist on every spring-forward date.
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $d === false ? '' : $d->format('D');
    };

    $nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

    // --------------------------------------------------------------- build rows
    $rows            = [];
    $totalWorked     = 0.0;
    $totalLunch      = 0.0;
    $totalOpen       = 0;
    $employeesOnPage = [];
    $rowsWithWarning = 0;

    // dailyHoursFromPunches applies the rounding policy to the summed DAY, so it
    // is called once per employee over that employee's page punches and the
    // per-date results are read back out.
    $dailyByEmployee = [];
    foreach ($punchesByEmployeeDay as $empId => $byDate) {
        $flat = [];
        foreach ($byDate as $punchRows) {
            foreach ($punchRows as $p) { $flat[] = $p; }
        }
        $dailyByEmployee[$empId] = HoursCalculator::dailyHoursFromPunches($flat, $params->rounding());
    }

    foreach ($keys as $k) {
        $empId  = (int)$k['EmployeeID'];
        $date   = (string)$k['Date'];
        $punchRows = $punchesByEmployeeDay[$empId][$date] ?? [];
        $day    = $dailyByEmployee[$empId]['days'][$date] ?? null;

        $firstInSec = null; $firstIn = null;
        $lastOutSec = null; $lastOut = null;
        $lunchSec   = 0;
        $storedSum  = null;

        foreach ($punchRows as $p) {
            $in  = HoursCalculator::timeToSeconds($p['TimeIN'] ?? null);
            $out = HoursCalculator::timeToSeconds($p['TimeOut'] ?? null);

            if ($in !== null && ($firstInSec === null || $in < $firstInSec)) {
                $firstInSec = $in;
                $firstIn    = (string)$p['TimeIN'];
            }
            if ($out !== null) {
                // Anchor each punch's clock-out after its OWN clock-in before
                // comparing, so an overnight punch sorts after a daytime one
                // instead of looking like the earliest event of the day.
                $anchored = ($in !== null && $out < $in) ? $out + 86400 : $out;
                if ($lastOutSec === null || $anchored > $lastOutSec) {
                    $lastOutSec = $anchored;
                    $lastOut    = (string)$p['TimeOut'];
                }
            }

            $lunchSec += $lunchSecondsFor($p);

            if ($p['TotalHours'] !== null) {
                $storedSum = ($storedSum ?? 0.0) + (float)$p['TotalHours'];
            }
        }

        $spanHours = ($firstInSec !== null && $lastOutSec !== null)
            ? round(max(0, $lastOutSec - $firstInSec) / 3600, 2)
            : 0.0;

        $workedHours = $day !== null ? (float)$day['hours'] : 0.0;
        $openCount   = $day !== null ? (int)$day['open_punch_count'] : 0;
        $warnings    = $day !== null ? array_values($day['warnings']) : [];

        $rows[] = [
            'employee_id'        => $empId,
            'employee_email'     => $nullIfBlank($k['Email']),
            'employee_name'      => trim(($k['FirstName'] ?? '') . ' ' . ($k['LastName'] ?? '')),
            'office'             => $nullIfBlank($k['Office']),
            'job_title'          => $nullIfBlank($k['JobTitle']),
            'date'               => $date,
            'day_of_week'        => $dayOfWeek($date),
            'first_in'           => $firstIn,
            'last_out'           => $lastOut,
            'punch_count'        => count($punchRows),
            'worked_hours'       => $workedHours,
            'lunch_hours'        => round($lunchSec / 3600, 2),
            'span_hours'         => $spanHours,
            'stored_total_hours' => $storedSum !== null ? round($storedSum, 2) : null,
            'has_open_punch'     => $openCount > 0,
            'warnings'           => $warnings,
        ];

        $totalWorked += $workedHours;
        $totalLunch  += $lunchSec / 3600;
        $totalOpen   += $openCount;
        $employeesOnPage[$empId] = true;
        if ($warnings !== []) { $rowsWithWarning++; }
    }

    // ------------------------------------------------------------------- output
    $rowCount = count($rows);
    $warnings = [];

    // Totals are scoped to the rows in THIS response. Saying so is not a
    // disclaimer for its own sake: a client that sums totals across pages would
    // otherwise be told nothing, and a client that reads page 1's totals as the
    // range total would be silently wrong.
    if ($offset > 0 || ($offset + $rowCount) < $totalCount) {
        $warnings[] = 'Totals cover only the ' . $rowCount . ' row(s) returned by this request, not all '
            . $totalCount . ' employee-days in the range. Page through the full result set to total it.';
    }
    if ($totalOpen > 0) {
        $warnings[] = $totalOpen . ' open punch(es) (clocked in, never clocked out) contributed 0 worked '
            . 'hours; the affected employee-days are flagged has_open_punch.';
    }
    if ($rowsWithWarning > 0) {
        $warnings[] = $rowsWithWarning . ' row(s) carry data-quality warnings — see each row\'s `warnings` '
            . 'field (codes: open_punch, incomplete_lunch, lunch_clamped, punch_exceeds_24h).';
    }

    $meta = $params->meta('hours', [
        'row_count'   => $rowCount,
        'total_count' => $totalCount,
        // This report never allocates overtime, so publishing the OT defaults
        // here would misrepresent what was applied.
        'rules'       => ['rounding_minutes' => $params->rounding()],
        'warnings'    => $warnings,
    ]);

    $data = [
        'rows'   => $rows,
        'meta'   => $meta,
        'totals' => [
            'worked_hours' => round($totalWorked, 2),
            'lunch_hours'  => round($totalLunch, 2),
            'days'         => $rowCount,
            'employees'    => count($employeesOnPage),
            'open_punches' => $totalOpen,
        ],
    ];

    // Column list is pinned so an empty result still emits a header row and the
    // column order never drifts with the first row's key order.
    Output::send($data, $params, 'hours', [
        'employee_id', 'employee_email', 'employee_name', 'office', 'job_title',
        'date', 'day_of_week', 'first_in', 'last_out', 'punch_count',
        'worked_hours', 'lunch_hours', 'span_hours', 'stored_total_hours',
        'has_open_punch', 'warnings',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/hours] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
