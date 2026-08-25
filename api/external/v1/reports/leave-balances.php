<?php
/**
 * GET /api/external/v1/reports/leave-balances
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * Current leave balances: one row per employee per leave type for a single year.
 *
 * Params: employee_id, employee_email, office, include_inactive, tz, format,
 *         limit, offset, plus:
 *
 *   year          4-digit year (default: the current year in the report timezone)
 *   type          comma list over Vacation, Sick, Personal, Other (default: all)
 *   include_zero  bool (default false) — keep ledger rows whose accrued, carryover
 *                 and used hours are all 0
 *
 * This report is POINT-IN-TIME, so it deliberately does NOT take start/end: a
 * balance is a running ledger figure, not something that accrues inside a window,
 * and accepting a range would invite an integrator to believe it was applied.
 *
 * ROW SOURCE: leave_balances is a ledger, and this report reflects it as it stands.
 * An employee with no leave_balances row for `year` produces NO row — a fabricated
 * all-zero row would be indistinguishable from a real zeroed balance, and the two
 * mean very different things (never enrolled vs. fully consumed). `include_zero`
 * governs only rows that genuinely exist and are all zero.
 *
 * available_hours = accrued + carryover - used, and is NOT clamped: an employee who
 * took leave they had not accrued really is negative, and clamping would hide the
 * exception a BI consumer is looking for. is_negative flags those rows explicitly
 * so a spreadsheet can filter on a boolean instead of on a sign test.
 *
 * Policy columns come from leave_accrual_policy, LEFT JOINed and deliberately NOT
 * filtered by IsActive: the report states what the policy row says and lets the
 * consumer decide what a deactivated policy means. They are null when no policy row
 * exists for that leave type.
 *
 * `totals` describe the RETURNED PAGE, and meta.warnings says so whenever the
 * result is paged (same contract as /reports/summary).
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';
// Not pulled in by ReportBootstrap: the shared ISO 8601 helper used for the
// ledger's UpdatedAt timestamp.
require_once __DIR__ . '/../../../lib/ApiTimeFormat.php';

/**
 * Boolean parser for a report-specific parameter ReportParams does not own.
 * A closure rather than a named function so two endpoints loaded in the same
 * process can never collide in the global namespace.
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
 * Comma list over a closed vocabulary, matched case-insensitively and returned in
 * the CANONICAL spelling of the underlying ENUM — that spelling is what gets bound
 * and what is echoed in meta.filters, so the filter a caller sees is the filter the
 * database applied. An unrecognized value is a 400 rather than a silent empty
 * result: "type=pto" returning zero rows looks like an outage, not like a typo.
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

/**
 * A DATETIME read back as a UTC epoch (UNIX_TIMESTAMP in the query, so the instant
 * is unambiguous across a DST fall-back) and rendered in the report timezone.
 *
 * The extra epoch <= 0 guard is this report's own: MariaDB hands back 0 for the
 * '0000-00-00 00:00:00' sentinel a legacy row can carry, and publishing that as
 * 1970-01-01 would put a fictional ledger date into a BI date dimension.
 */
$formatEpoch = static function ($epoch, DateTimeZone $tz): ?string {
    $e = ApiTimeFormat::epochOrNull($epoch);
    return ($e === null || $e <= 0) ? null : ApiTimeFormat::fromEpoch($e, $tz);
};

$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

try {
    ReportBootstrap::begin('/api/external/v1/reports/leave-balances', 'reports:read');

    // No start/end (point-in-time, see the header) and no rounding/OT parameters:
    // nothing here is worked time, and an accepted-but-unused parameter would make
    // an integrator believe their payroll rules were applied.
    $params = ReportParams::fromRequest($_GET, $conn, [
        'employee_id', 'employee_email', 'office', 'include_inactive',
        'limit', 'offset', 'year', 'type', 'include_zero',
    ]);

    $tz = $params->timezone();

    // Declared in $accepted above so they are not reported as ignored params, but
    // parsed here because they belong to this report alone.
    // The default year is resolved in the REPORT timezone: on New Year's Eve the
    // answer legitimately differs between America/Chicago and UTC, and the caller's
    // tz is the one they asked us to report in.
    $yearRaw = $_GET['year'] ?? null;
    if (is_array($yearRaw)) {
        ApiResponse::error('Invalid parameter `year`: expected a single value, got a list.', 400);
    }
    $yearRaw = $yearRaw === null ? '' : trim((string)$yearRaw);
    if ($yearRaw === '') {
        $year = (int)(new DateTimeImmutable('now', $tz))->format('Y');
    } else {
        // 1901-2155 is the storable range of MySQL's YEAR(4); anything outside it
        // could never match a leave_balances row, so it is a 400 rather than an
        // empty result an integrator would read as "no balances".
        if (!preg_match('/^\d{4}$/', $yearRaw) || (int)$yearRaw < 1901 || (int)$yearRaw > 2155) {
            $echo = htmlspecialchars(
                substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $yearRaw) ?? '', 0, 64),
                ENT_QUOTES,
                'UTF-8'
            );
            ApiResponse::error(
                'Invalid parameter `year`: "' . $echo . '". Expected a 4-digit year between 1901 and 2155.',
                400
            );
        }
        $year = (int)$yearRaw;
    }

    $typeFilter  = $parseEnumListParam($_GET, 'type', ['Vacation', 'Sick', 'Personal', 'Other']);
    $includeZero = $parseBoolParam($_GET, 'include_zero', false);

    $limit  = $params->limit();
    $offset = $params->offset();

    // ----------------------------------------------------------------- filter
    // Every value below travels through bind_param; the only thing interpolated
    // into SQL is a placeholder string built from the COUNT of validated values.
    $filter = $params->buildUserFilter('u');

    $conds  = ['lb.YearNumber = ?'];
    $types  = 'i';
    $values = [$year];

    if ($filter['sql'] !== '') {
        $conds[] = $filter['sql'];
        $types  .= $filter['types'];
        $values  = array_merge($values, $filter['values']);
    }
    if ($typeFilter !== []) {
        $conds[] = 'lb.LeaveType IN (' . implode(',', array_fill(0, count($typeFilter), '?')) . ')';
        $types  .= str_repeat('s', count($typeFilter));
        $values  = array_merge($values, $typeFilter);
    }
    if (!$includeZero) {
        // A row that has never accrued, carried over or been used carries no
        // information; excluding it by default keeps a 4-types-per-employee ledger
        // from padding the export with rows nobody asked about.
        $conds[] = '(lb.AccruedHours <> 0 OR lb.CarryoverHours <> 0 OR lb.UsedHours <> 0)';
    }
    $where = implode(' AND ', $conds);

    // --------------------------------------------------------------- counting
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM leave_balances lb
         JOIN users u ON u.ID = lb.EmployeeID
         WHERE $where"
    );
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $totalCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // -------------------------------------------------------------- page rows
    // ORDER BY carries u.ID as a tie-break: two employees can share a display name,
    // and without a unique tail the same row can surface on two different pages.
    // LeaveType sorts by its ENUM definition order (Vacation, Sick, Personal,
    // Other), which is stable and is the order the admin UI uses.
    //
    // leave_accrual_policy is LEFT JOINed on LeaveType and NOT filtered by
    // IsActive — see the header note; a deactivated policy still describes the
    // numbers already sitting in the ledger.
    $stmt = $conn->prepare(
        "SELECT lb.EmployeeID, lb.LeaveType, lb.YearNumber,
                lb.AccruedHours, lb.CarryoverHours, lb.UsedHours,
                UNIX_TIMESTAMP(lb.UpdatedAt) AS UpdatedAtEpoch,
                u.Email, u.FirstName, u.LastName, u.Office,
                p.HoursPerYear, p.MaxCarryover, p.AccrualFrequency
         FROM leave_balances lb
         JOIN users u ON u.ID = lb.EmployeeID
         LEFT JOIN leave_accrual_policy p ON p.LeaveType = lb.LeaveType
         WHERE $where
         ORDER BY u.FirstName, u.LastName, u.ID, lb.LeaveType
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param($types . 'ii', ...array_merge($values, [$limit, $offset]));
    $stmt->execute();
    $balances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ------------------------------------------------------------- build rows
    $rows          = [];
    $employeesSeen = [];
    $totAccrued    = 0.0;
    $totCarryover  = 0.0;
    $totUsed       = 0.0;
    $totAvailable  = 0.0;
    $negativeRows  = 0;
    $noPolicyRows  = 0;

    foreach ($balances as $b) {
        $empId     = (int)$b['EmployeeID'];
        $accrued   = (float)$b['AccruedHours'];
        $carryover = (float)$b['CarryoverHours'];
        $used      = (float)$b['UsedHours'];
        $available = round($accrued + $carryover - $used, 2);
        $hasPolicy = $b['AccrualFrequency'] !== null;

        $rows[] = [
            'employee_id'              => $empId,
            'employee_email'           => $nullIfBlank($b['Email']),
            'employee_name'            => trim((string)($b['FirstName'] ?? '') . ' ' . (string)($b['LastName'] ?? '')),
            'office'                   => $nullIfBlank($b['Office']),
            'year'                     => (int)$b['YearNumber'],
            'leave_type'               => (string)$b['LeaveType'],
            'accrued_hours'            => round($accrued, 2),
            'carryover_hours'          => round($carryover, 2),
            'used_hours'               => round($used, 2),
            'available_hours'          => $available,
            // Sits next to the value it qualifies rather than at the end of the row:
            // a consumer reading available_hours needs the flag in the same glance.
            'is_negative'              => $available < 0,
            'policy_hours_per_year'    => $b['HoursPerYear'] !== null ? (float)$b['HoursPerYear'] : null,
            'policy_max_carryover'     => $b['MaxCarryover'] !== null ? (float)$b['MaxCarryover'] : null,
            'policy_accrual_frequency' => $hasPolicy ? (string)$b['AccrualFrequency'] : null,
            'updated_at'               => $formatEpoch($b['UpdatedAtEpoch'], $tz),
        ];

        $employeesSeen[$empId] = true;
        $totAccrued   += $accrued;
        $totCarryover += $carryover;
        $totUsed      += $used;
        $totAvailable += $available;
        if ($available < 0) { $negativeRows++; }
        if (!$hasPolicy)    { $noPolicyRows++; }
    }

    // ------------------------------------------------------------------ output
    $rowCount = count($rows);
    $warnings = [];

    if ($offset > 0 || ($offset + $rowCount) < $totalCount) {
        $warnings[] = 'Totals cover only the ' . $rowCount . ' row(s) returned by this request, not all '
            . $totalCount . ' matching the filters. Page through the full result set to total it.';
    }
    if ($negativeRows > 0) {
        $warnings[] = $negativeRows . ' row(s) have negative available_hours (leave taken beyond what was '
            . 'accrued and carried over); the value is reported as-is, not clamped. See is_negative.';
    }
    if ($noPolicyRows > 0) {
        $warnings[] = $noPolicyRows . ' row(s) have no leave_accrual_policy entry for their leave type, so '
            . 'their policy_* fields are null. The balances themselves are unaffected.';
    }

    $meta = $params->meta('leave-balances', [
        'row_count'   => $rowCount,
        'total_count' => $totalCount,
        // Nothing here is worked time and no overtime is allocated, so publishing
        // the shared OT/rounding defaults would misrepresent what was applied.
        'rules'       => [
            'available_hours' => 'accrued + carryover - used, never clamped',
            'policy_source'   => 'leave_accrual_policy joined on LeaveType, not filtered by IsActive',
            'row_source'      => 'existing leave_balances rows only; an employee with no ledger row for '
                                 . 'the year is not represented',
        ],
        'warnings'    => $warnings,
    ]);
    // Report-specific filters, appended to the shared filters block rather than
    // replacing it, so the published key order of meta itself never shifts.
    $meta['filters']['year']         = $year;
    $meta['filters']['type']         = $typeFilter;
    $meta['filters']['include_zero'] = $includeZero;

    $data = [
        'rows'   => $rows,
        'meta'   => $meta,
        'totals' => [
            'employees'       => count($employeesSeen),
            'accrued_hours'   => round($totAccrued, 2),
            'carryover_hours' => round($totCarryover, 2),
            'used_hours'      => round($totUsed, 2),
            'available_hours' => round($totAvailable, 2),
        ],
    ];

    // Pinned so a zero-row CSV still carries a header row for the importer, and so
    // column order can never drift with the first row's key order.
    Output::send($data, $params, 'leave-balances', [
        'employee_id', 'employee_email', 'employee_name', 'office', 'year', 'leave_type',
        'accrued_hours', 'carryover_hours', 'used_hours', 'available_hours', 'is_negative',
        'policy_hours_per_year', 'policy_max_carryover', 'policy_accrual_frequency', 'updated_at',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/leave-balances] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
