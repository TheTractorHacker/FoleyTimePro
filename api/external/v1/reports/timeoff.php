<?php
/**
 * GET /api/external/v1/reports/timeoff
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * Time-off request analytics: the requests themselves, or those requests folded up
 * per employee / per employee-and-type.
 *
 * Params: start, end (required), employee_id, employee_email, office,
 *         include_inactive, tz, format, limit, offset, plus:
 *
 *   status    comma list over Pending, Approved, Rejected   (default: all)
 *   type      comma list over Vacation, Sick, Personal, Other, Unpaid (default: all)
 *   group_by  "request" (default) | "employee" | "employee_type"
 *
 * RANGE SEMANTICS — the part that decides whether two adjacent pulls double count:
 *   - A request is IN the range when it OVERLAPS it (StartDate <= end AND
 *     EndDate >= start), not when it is contained by it. A vacation that starts in
 *     March and ends in April belongs to both months' reports.
 *   - Because of that, every request also carries days_in_range / hours_in_range:
 *     the request CLIPPED to [start, end]. Summing hours_requested across two
 *     adjacent ranges double counts the straddling request; summing hours_in_range
 *     does not. The aggregate groupings therefore total the CLIPPED figures — see
 *     the note on the employee rows below.
 *
 * HOURS SOURCE (why two different rules):
 *   - Approved requests use the same rule ScheduleCalculator::loadApprovedTimeOff()
 *     applies to the utilization denominator, so /reports/timeoff and
 *     /reports/utilization can never disagree about how much leave a day consumed:
 *     HoursRequested is trusted only on a SINGLE-DAY request (on a multi-day
 *     request it is the span total, and dividing it across days would invent
 *     per-day numbers), and every other date takes that employee's scheduled hours
 *     — 0 on a non-working day, and 0 for an employee with no active shift.
 *   - Pending and Rejected requests never reached the schedule at all. Consulting
 *     it for them would report capacity that was never committed, so they simply
 *     apportion HoursRequested by calendar days inside the range.
 *   Both cases are counted and reported in meta.warnings when they produce a zero,
 *   because a silent 0 in an hours column reads as "no leave", not as "unknown".
 *
 * PAGINATION: the page is taken over whatever the grouping's row identity is —
 * requests, employees, or (employee, type) pairs — and only that page's requests
 * are read back for the math, so a deep offset never pulls the whole company's
 * time-off history. `totals` therefore describe the RETURNED PAGE, and say so in
 * meta.warnings whenever the result is paged (same contract as /reports/summary).
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';
// Not pulled in by ReportBootstrap: the shared ISO 8601 helpers used for the
// review/request timestamps and for guarding zero DATE values.
require_once __DIR__ . '/../../../lib/ApiTimeFormat.php';

/**
 * Fixed-vocabulary parameter parser for a value ReportParams does not own.
 * A closure rather than a named function so two endpoints loaded in the same
 * process can never collide in the global namespace.
 */
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
 * Comma list over a closed vocabulary, matched case-insensitively and returned in
 * the CANONICAL spelling of the underlying ENUM — that spelling is what gets bound
 * and what is echoed in meta.filters, so the filter a caller sees is the filter the
 * database applied.
 *
 * An unrecognized value is a 400 rather than a silent empty result: "status=denied"
 * returning zero rows looks like an outage, not like a typo.
 *
 * @param string[] $canonical
 * @return string[] deduped, canonically cased; empty means "no filter"
 */
$parseEnumListParam = static function (array $get, string $name, array $canonical): array {
    if (!array_key_exists($name, $get)) {
        return [];
    }
    $raw = $get[$name];
    if (is_array($raw)) {
        $flat = [];
        foreach ($raw as $v) {
            if (is_scalar($v)) { $flat[] = (string)$v; }
        }
        $raw = implode(',', $flat);
    } elseif (!is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a value or comma list.', 400);
    }

    $lookup = [];
    foreach ($canonical as $c) {
        $lookup[strtolower($c)] = $c;
    }

    $out = [];
    foreach (explode(',', (string)$raw) as $piece) {
        $piece = trim($piece);
        if ($piece === '') {
            continue;
        }
        $key = strtolower($piece);
        if (!isset($lookup[$key])) {
            $echo = htmlspecialchars(
                substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $piece) ?? '', 0, 64),
                ENT_QUOTES,
                'UTF-8'
            );
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are '
                . implode(', ', $canonical) . ' (one value or a comma list).',
                400
            );
        }
        if (!in_array($lookup[$key], $out, true)) {
            $out[] = $lookup[$key];
        }
    }
    return $out;
};

/** Inclusive day count between two Y-m-d dates; 0 when either is unparseable or reversed. */
$inclusiveDays = static function (string $from, string $to): int {
    $utc = new DateTimeZone('UTC');
    $a   = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
    $b   = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $utc);
    // UTC, not the report tz: a local midnight does not exist on every
    // spring-forward date, and a day count must not depend on that.
    if ($a === false || $b === false || $b < $a) {
        return 0;
    }
    return (int)$a->diff($b)->days + 1;
};

/**
 * A DATETIME read back as a UTC epoch (UNIX_TIMESTAMP in the query, so the instant
 * is unambiguous across a DST fall-back) and rendered in the report timezone.
 *
 * The extra epoch <= 0 guard is this report's own: MariaDB hands back 0 for the
 * '0000-00-00 00:00:00' sentinel a legacy row can carry, and publishing that as
 * 1970-01-01 would put a fictional review date into a BI date dimension.
 */
$formatEpoch = static function ($epoch, DateTimeZone $tz): ?string {
    $e = ApiTimeFormat::epochOrNull($epoch);
    return ($e === null || $e <= 0) ? null : ApiTimeFormat::fromEpoch($e, $tz);
};

$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

try {
    ReportBootstrap::begin('/api/external/v1/reports/timeoff', 'reports:read');

    // rounding / week_start / ot_* are deliberately NOT accepted: nothing here is
    // worked time and no overtime is allocated, and an accepted-but-unused
    // parameter would make an integrator believe their payroll rules were applied.
    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office',
        'include_inactive', 'limit', 'offset',
        'status', 'type', 'group_by',
    ]);

    // Declared in $accepted above so they are not reported as ignored params, but
    // parsed here because they belong to this report alone.
    $statusFilter = $parseEnumListParam($_GET, 'status', ['Pending', 'Approved', 'Rejected']);
    $typeFilter   = $parseEnumListParam($_GET, 'type', ['Vacation', 'Sick', 'Personal', 'Other', 'Unpaid']);
    $groupBy      = $parseEnumParam($_GET, 'group_by', ['request', 'employee', 'employee_type'], 'request');

    $start  = (string)$params->startDate();
    $end    = (string)$params->endDate();
    $tz     = $params->timezone();
    $limit  = $params->limit();
    $offset = $params->offset();

    // ----------------------------------------------------------------- filter
    // Overlap, not containment (see the range note in the header). Every value
    // below travels through bind_param; the only thing interpolated into SQL is a
    // placeholder string built from the COUNT of already-validated values.
    $filter = $params->buildUserFilter('u');

    $conds  = ['tor.StartDate <= ?', 'tor.EndDate >= ?'];
    $types  = 'ss';
    $values = [$end, $start];

    if ($filter['sql'] !== '') {
        $conds[] = $filter['sql'];
        $types  .= $filter['types'];
        $values  = array_merge($values, $filter['values']);
    }
    if ($statusFilter !== []) {
        $conds[] = 'tor.Status IN (' . implode(',', array_fill(0, count($statusFilter), '?')) . ')';
        $types  .= str_repeat('s', count($statusFilter));
        $values  = array_merge($values, $statusFilter);
    }
    if ($typeFilter !== []) {
        $conds[] = 'tor.RequestType IN (' . implode(',', array_fill(0, count($typeFilter), '?')) . ')';
        $types  .= str_repeat('s', count($typeFilter));
        $values  = array_merge($values, $typeFilter);
    }
    $where = implode(' AND ', $conds);

    // --------------------------------------------------------------- counting
    // total_count is the size of THIS grouping's result set, so has_more means
    // "more rows of the shape you asked for", not "more requests".
    if ($groupBy === 'employee') {
        $countSql = "SELECT COUNT(DISTINCT tor.EmployeeID) AS c
                     FROM time_off_requests tor
                     JOIN users u ON u.ID = tor.EmployeeID
                     WHERE $where";
    } elseif ($groupBy === 'employee_type') {
        $countSql = "SELECT COUNT(*) AS c FROM (
                         SELECT tor.EmployeeID, tor.RequestType
                         FROM time_off_requests tor
                         JOIN users u ON u.ID = tor.EmployeeID
                         WHERE $where
                         GROUP BY tor.EmployeeID, tor.RequestType
                     ) g";
    } else {
        $countSql = "SELECT COUNT(*) AS c
                     FROM time_off_requests tor
                     JOIN users u ON u.ID = tor.EmployeeID
                     WHERE $where";
    }
    $stmt = $conn->prepare($countSql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $totalCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // ----------------------------------------------------------------- helpers

    /**
     * Clip one request to [start, end] and price the clipped part.
     *
     * @param array      $r     ID/EmployeeID/StartDate/EndDate/Status/HoursRequested
     * @param array|null $shift that employee's ACTIVE shift row, or null when unscheduled
     * @return array{days_requested:int, days_in_range:int, hours_requested:?float,
     *               hours_in_range:float, unscheduled:bool, no_hours:bool}
     */
    $apportion = static function (array $r, ?array $shift) use ($start, $end, $inclusiveDays): array {
        $reqStart = (string)$r['StartDate'];
        $reqEnd   = (string)$r['EndDate'];
        $status   = (string)$r['Status'];
        $hoursReq = $r['HoursRequested'] !== null ? (float)$r['HoursRequested'] : null;

        $daysRequested = $inclusiveDays($reqStart, $reqEnd);

        $from        = $reqStart > $start ? $reqStart : $start;
        $to          = $reqEnd   < $end   ? $reqEnd   : $end;
        $daysInRange = $inclusiveDays($from, $to);

        $singleDay    = $reqStart === $reqEnd;
        $hoursInRange = 0.0;
        $unscheduled  = false;
        $noHours      = false;

        if ($daysInRange > 0) {
            if ($status === 'Approved') {
                if ($singleDay && $hoursReq !== null) {
                    $hoursInRange = $hoursReq;
                } else {
                    foreach (ScheduleCalculator::datesBetween($from, $to) as $date) {
                        $hoursInRange += ScheduleCalculator::scheduledHoursForDate($shift, $date);
                    }
                    // Not the same as "0 hours off": there is simply no schedule to
                    // price this leave against. Counted for meta.warnings.
                    if ($shift === null) {
                        $unscheduled = true;
                    }
                }
            } elseif ($hoursReq !== null && $daysRequested > 0) {
                $hoursInRange = $hoursReq * ($daysInRange / $daysRequested);
            } else {
                $noHours = true;
            }
        }

        return [
            'days_requested'  => $daysRequested,
            'days_in_range'   => $daysInRange,
            'hours_requested' => $hoursReq,
            'hours_in_range'  => round($hoursInRange, 2),
            'unscheduled'     => $unscheduled,
            'no_hours'        => $noHours,
        ];
    };

    // Page-scoped totals, accumulated from exactly the requests that fed the rows.
    $totRequests  = 0;
    $totDays      = 0;
    $totHours     = 0.0;
    $totApproved  = 0.0;
    $totPending   = 0.0;
    $totRejected  = 0.0;
    $employeesSeen   = [];
    $unscheduledReqs = 0;
    $noHoursReqs     = 0;

    $accumulate = static function (int $empId, string $status, array $a) use (
        &$totRequests, &$totDays, &$totHours, &$totApproved, &$totPending, &$totRejected,
        &$employeesSeen, &$unscheduledReqs, &$noHoursReqs
    ): void {
        $totRequests++;
        $totDays  += $a['days_in_range'];
        $totHours += $a['hours_in_range'];
        if ($status === 'Approved')      { $totApproved += $a['hours_in_range']; }
        elseif ($status === 'Rejected')  { $totRejected += $a['hours_in_range']; }
        else                             { $totPending  += $a['hours_in_range']; }
        $employeesSeen[$empId] = true;
        if ($a['unscheduled']) { $unscheduledReqs++; }
        if ($a['no_hours'])    { $noHoursReqs++; }
    };

    $rows = [];

    if ($groupBy === 'request') {
        // ------------------------------------------------------ request rows
        // ORDER BY carries u.ID and tor.ID as tie-breaks: two employees can share a
        // display name and one employee can file two requests on the same date, and
        // without a unique tail the same row can surface on two different pages.
        //
        // admins is joined for the reviewer's USERNAME only — never password or
        // TwoFA* columns, which have no business leaving the admin tables.
        $stmt = $conn->prepare(
            "SELECT tor.ID, tor.EmployeeID, tor.StartDate, tor.EndDate, tor.RequestType,
                    tor.Status, tor.Reason, tor.ReviewNotes, tor.HoursRequested,
                    UNIX_TIMESTAMP(tor.RequestedAt) AS RequestedAtEpoch,
                    UNIX_TIMESTAMP(tor.ReviewedAt)  AS ReviewedAtEpoch,
                    u.Email, u.FirstName, u.LastName, u.Office,
                    a.username AS ReviewedByUsername
             FROM time_off_requests tor
             JOIN users u ON u.ID = tor.EmployeeID
             LEFT JOIN admins a ON a.id = tor.ReviewedBy
             WHERE $where
             ORDER BY u.FirstName, u.LastName, u.ID, tor.StartDate, tor.ID
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($types . 'ii', ...array_merge($values, [$limit, $offset]));
        $stmt->execute();
        $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Shifts only for the employees on this page — approved leave is priced
        // against the schedule, and nothing else needs them.
        $shifts = ScheduleCalculator::loadShiftsForUsers(
            $conn,
            array_map(static fn($r) => (int)$r['EmployeeID'], $requests)
        );

        foreach ($requests as $r) {
            $empId = (int)$r['EmployeeID'];
            $a     = $apportion($r, $shifts[$empId] ?? null);
            $accumulate($empId, (string)$r['Status'], $a);

            $rows[] = [
                'request_id'      => (int)$r['ID'],
                'employee_id'     => $empId,
                'employee_email'  => $nullIfBlank($r['Email']),
                'employee_name'   => trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['LastName'] ?? '')),
                'office'          => $nullIfBlank($r['Office']),
                'request_type'    => (string)$r['RequestType'],
                'status'          => (string)$r['Status'],
                'start_date'      => (string)$r['StartDate'],
                'end_date'        => (string)$r['EndDate'],
                'days_requested'  => $a['days_requested'],
                'hours_requested' => $a['hours_requested'],
                'days_in_range'   => $a['days_in_range'],
                'hours_in_range'  => $a['hours_in_range'],
                'reason'          => $nullIfBlank($r['Reason']),
                'requested_at'    => $formatEpoch($r['RequestedAtEpoch'], $tz),
                'reviewed_at'     => $formatEpoch($r['ReviewedAtEpoch'], $tz),
                // null when the request was never reviewed, or when the reviewing
                // admin account has since been deleted (FK is ON DELETE SET NULL).
                'reviewed_by'     => $nullIfBlank($r['ReviewedByUsername']),
                'review_notes'    => $nullIfBlank($r['ReviewNotes']),
            ];
        }
    } else {
        // --------------------------------------------------- aggregate rows
        // Two passes: page the GROUP identity first, then read back only that
        // page's requests. Aggregating first and slicing after would mean loading
        // every matching request just to throw most of them away.
        $byType   = $groupBy === 'employee_type';
        $keySql   = $byType ? ', tor.RequestType' : '';
        $orderSql = $byType
            ? 'ORDER BY u.FirstName, u.LastName, u.ID, tor.RequestType'
            : 'ORDER BY u.FirstName, u.LastName, u.ID';

        $stmt = $conn->prepare(
            "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office$keySql
             FROM time_off_requests tor
             JOIN users u ON u.ID = tor.EmployeeID
             WHERE $where
             GROUP BY u.ID, u.Email, u.FirstName, u.LastName, u.Office$keySql
             $orderSql
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($types . 'ii', ...array_merge($values, [$limit, $offset]));
        $stmt->execute();
        $keyRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Row order is fixed here, before any request is read, so the emitted order
        // is exactly the paged order even for a group with no matching detail rows.
        $agg = [];
        foreach ($keyRows as $k) {
            $key = $byType ? ((int)$k['ID'] . '|' . (string)$k['RequestType']) : (string)(int)$k['ID'];
            $agg[$key] = [
                'user'     => $k,
                'requests' => 0,
                'days'     => 0,
                'hours'    => 0.0,
                'approved' => 0.0,
                'pending'  => 0.0,
                'rejected' => 0.0,
                'Vacation' => 0.0,
                'Sick'     => 0.0,
                'Personal' => 0.0,
                'Other'    => 0.0,
            ];
        }

        if ($agg !== []) {
            $empIds = [];
            foreach ($keyRows as $k) {
                $id = (int)$k['ID'];
                if (!in_array($id, $empIds, true)) {
                    $empIds[] = $id;
                }
            }

            $detailTypes  = $types . str_repeat('i', count($empIds));
            $detailValues = array_merge($values, $empIds);
            $stmt = $conn->prepare(
                "SELECT tor.ID, tor.EmployeeID, tor.StartDate, tor.EndDate,
                        tor.RequestType, tor.Status, tor.HoursRequested
                 FROM time_off_requests tor
                 JOIN users u ON u.ID = tor.EmployeeID
                 WHERE $where
                   AND tor.EmployeeID IN (" . implode(',', array_fill(0, count($empIds), '?')) . ")
                 ORDER BY tor.EmployeeID, tor.StartDate, tor.ID"
            );
            $stmt->bind_param($detailTypes, ...$detailValues);
            $stmt->execute();
            $detail = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $shifts = ScheduleCalculator::loadShiftsForUsers($conn, $empIds);

            foreach ($detail as $r) {
                $empId = (int)$r['EmployeeID'];
                $type  = (string)$r['RequestType'];
                $key   = $byType ? ($empId . '|' . $type) : (string)$empId;
                // group_by=employee_type pages over PAIRS, so an employee on the
                // page can still own types that belong to a different page.
                if (!isset($agg[$key])) {
                    continue;
                }

                $status = (string)$r['Status'];
                $a      = $apportion($r, $shifts[$empId] ?? null);
                $accumulate($empId, $status, $a);

                $agg[$key]['requests'] += 1;
                $agg[$key]['days']     += $a['days_in_range'];
                $agg[$key]['hours']    += $a['hours_in_range'];
                if ($status === 'Approved')     { $agg[$key]['approved'] += $a['hours_in_range']; }
                elseif ($status === 'Rejected') { $agg[$key]['rejected'] += $a['hours_in_range']; }
                else                            { $agg[$key]['pending']  += $a['hours_in_range']; }
                if (isset($agg[$key][$type])) {
                    $agg[$key][$type] += $a['hours_in_range'];
                }
            }
        }

        foreach ($agg as $g) {
            $u    = $g['user'];
            $base = [
                'employee_id'    => (int)$u['ID'],
                'employee_email' => $nullIfBlank($u['Email']),
                'employee_name'  => trim((string)($u['FirstName'] ?? '') . ' ' . (string)($u['LastName'] ?? '')),
                'office'         => $nullIfBlank($u['Office']),
            ];

            if ($byType) {
                $rows[] = $base + [
                    'request_type'   => (string)$u['RequestType'],
                    'requests'       => $g['requests'],
                    'days'           => $g['days'],
                    'hours'          => round($g['hours'], 2),
                    'approved_hours' => round($g['approved'], 2),
                ];
            } else {
                // days_requested / hours_requested are the CLIPPED figures here, not
                // the requests' full spans: an aggregate that summed whole requests
                // would not tie out against its own status and type columns, and
                // would double count a straddling request across adjacent ranges.
                $rows[] = $base + [
                    'requests'        => $g['requests'],
                    'days_requested'  => $g['days'],
                    'hours_requested' => round($g['hours'], 2),
                    'approved_hours'  => round($g['approved'], 2),
                    'pending_hours'   => round($g['pending'], 2),
                    'rejected_hours'  => round($g['rejected'], 2),
                    'vacation_hours'  => round($g['Vacation'], 2),
                    'sick_hours'      => round($g['Sick'], 2),
                    'personal_hours'  => round($g['Personal'], 2),
                    'other_hours'     => round($g['Other'], 2),
                ];
            }
        }
    }

    // ------------------------------------------------------------------ output
    $rowCount = count($rows);
    $warnings = [];

    if ($offset > 0 || ($offset + $rowCount) < $totalCount) {
        $warnings[] = 'Totals cover only the ' . $rowCount . ' row(s) returned by this request, not all '
            . $totalCount . ' matching the filters. Page through the full result set to total it.';
    }
    if ($unscheduledReqs > 0) {
        $warnings[] = $unscheduledReqs . ' approved request(s) belong to an employee with no active assigned '
            . 'shift, so their hours_in_range is 0: scheduled hours are the source for multi-day approved '
            . 'leave and there is no schedule to price it against.';
    }
    if ($noHoursReqs > 0) {
        $warnings[] = $noHoursReqs . ' pending/rejected request(s) have no HoursRequested recorded, so their '
            . 'hours_in_range is 0; their days_in_range is still exact.';
    }

    $meta = $params->meta('timeoff', [
        'row_count'   => $rowCount,
        'total_count' => $totalCount,
        // Nothing here is worked time and no overtime is allocated, so publishing
        // the shared OT/rounding defaults would misrepresent what was applied.
        // These are the rules that DID shape the numbers.
        'rules'       => [
            'match'                  => 'requests overlapping [start, end]',
            'approved_hours'         => 'HoursRequested on a single-day request, otherwise the employee\'s '
                                        . 'scheduled hours for each date in range',
            'pending_rejected_hours' => 'HoursRequested apportioned by calendar days in range',
        ],
        'warnings'    => $warnings,
    ]);
    // Report-specific filters, appended to the shared filters block rather than
    // replacing it, so the published key order of meta itself never shifts.
    $meta['filters']['status']   = $statusFilter;
    $meta['filters']['type']     = $typeFilter;
    $meta['filters']['group_by'] = $groupBy;

    $data = [
        'rows'   => $rows,
        'meta'   => $meta,
        'totals' => [
            'requests'        => $totRequests,
            'employees'       => count($employeesSeen),
            'days_requested'  => $totDays,
            'hours_requested' => round($totHours, 2),
            'approved_hours'  => round($totApproved, 2),
            'pending_hours'   => round($totPending, 2),
            'rejected_hours'  => round($totRejected, 2),
        ],
    ];

    // Pinned so a zero-row CSV still carries a header row for the importer, and so
    // column order can never drift with the first row's key order.
    if ($groupBy === 'employee') {
        $csvColumns = [
            'employee_id', 'employee_email', 'employee_name', 'office', 'requests',
            'days_requested', 'hours_requested', 'approved_hours', 'pending_hours',
            'rejected_hours', 'vacation_hours', 'sick_hours', 'personal_hours', 'other_hours',
        ];
    } elseif ($groupBy === 'employee_type') {
        $csvColumns = [
            'employee_id', 'employee_email', 'employee_name', 'office', 'request_type',
            'requests', 'days', 'hours', 'approved_hours',
        ];
    } else {
        $csvColumns = [
            'request_id', 'employee_id', 'employee_email', 'employee_name', 'office',
            'request_type', 'status', 'start_date', 'end_date', 'days_requested',
            'hours_requested', 'days_in_range', 'hours_in_range', 'reason',
            'requested_at', 'reviewed_at', 'reviewed_by', 'review_notes',
        ];
    }

    Output::send($data, $params, 'timeoff', $csvColumns);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/timeoff] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
