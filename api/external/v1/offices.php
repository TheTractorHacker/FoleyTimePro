<?php
/**
 * GET /api/external/v1/offices
 * Authorization: Bearer <api-key>   Scope: offices:read
 *
 * The office/location DIMENSION for a BI star schema: the list every employee and
 * report row joins against, with headcounts attached.
 *
 * Two sources, one result. `Offices` is the lookup table an admin maintains, but
 * users.Office is a free-text varchar with NO foreign key — nothing stops a typo,
 * a renamed office or a bulk import from leaving employees pointing at a name that
 * has no Offices row. Those orphan names are returned as rows with office_id =
 * null and are named in meta.warnings. Dropping them would be worse than ugly: the
 * headcounts here would silently disagree with /employees, and the integrator
 * would have no way to see why.
 *
 * Name matching is done in SQL, never in PHP, so it follows the column collation
 * (utf8mb4_general_ci — case-insensitive) exactly like the `office` filter on every
 * other endpoint. Matching in PHP would split "Springfield" from "springfield" and
 * invent an orphan that no report filter agrees exists.
 *
 * Params: tz, format, limit, offset. No date range — this is a dimension, not a
 * report — and no include_inactive, because every row already carries both a total
 * and an active-only headcount.
 *
 * Row:
 *   { "office_id": 3|null, "office_name": "Springfield",
 *     "employee_count": 12, "active_employee_count": 11 }
 */

require_once __DIR__ . '/../../lib/ReportBootstrap.php';

try {
    ReportBootstrap::begin('/api/external/v1/offices', 'offices:read');

    $params = ReportParams::fromRequest($_GET, $conn, ['limit', 'offset']);

    // ------------------------------------------------------- offices + headcount
    // LEFT JOIN so an office with nobody assigned still appears — an empty location
    // is a real answer, not a missing row. COUNT(u.ID) rather than COUNT(*) so the
    // no-match side counts 0 instead of 1.
    $stmt = $conn->prepare(
        "SELECT o.ID AS OfficeID, o.OfficeName,
                COUNT(u.ID) AS EmployeeCount,
                COALESCE(SUM(CASE WHEN u.ID IS NOT NULL
                                   AND (u.LockOut IS NULL OR u.LockOut = 0)
                              THEN 1 ELSE 0 END), 0) AS ActiveCount
         FROM Offices o
         LEFT JOIN users u ON u.Office = o.OfficeName
         GROUP BY o.ID, o.OfficeName
         ORDER BY o.OfficeName ASC, o.ID ASC"
    );
    $stmt->execute();
    $officeRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ------------------------------------------------------------- orphan names
    // Distinct users.Office values with no Offices row. NOT EXISTS rather than a
    // PHP diff so the comparison uses the same collation as the join above.
    $stmt = $conn->prepare(
        "SELECT u.Office AS OfficeName,
                COUNT(*) AS EmployeeCount,
                COALESCE(SUM(CASE WHEN u.LockOut IS NULL OR u.LockOut = 0
                              THEN 1 ELSE 0 END), 0) AS ActiveCount
         FROM users u
         WHERE u.Office IS NOT NULL AND u.Office <> ''
           AND NOT EXISTS (SELECT 1 FROM Offices o WHERE o.OfficeName = u.Office)
         GROUP BY u.Office
         ORDER BY u.Office ASC"
    );
    $stmt->execute();
    $orphanRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Employees with no office at all get no row (there is no dimension member to
    // give them) but are counted in meta so rows + this figure reconcile to the
    // full roster.
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS EmployeeCount,
                COALESCE(SUM(CASE WHEN LockOut IS NULL OR LockOut = 0
                              THEN 1 ELSE 0 END), 0) AS ActiveCount
         FROM users
         WHERE Office IS NULL OR Office = ''"
    );
    $stmt->execute();
    $unassigned = $stmt->get_result()->fetch_assoc() ?: ['EmployeeCount' => 0, 'ActiveCount' => 0];
    $stmt->close();

    // ------------------------------------------------------------------- shaping
    $all = [];
    foreach ($officeRows as $r) {
        $all[] = [
            'office_id'             => (int)$r['OfficeID'],
            'office_name'           => (string)$r['OfficeName'],
            'employee_count'        => (int)$r['EmployeeCount'],
            'active_employee_count' => (int)$r['ActiveCount'],
        ];
    }
    $orphanNames = [];
    foreach ($orphanRows as $r) {
        $name          = (string)$r['OfficeName'];
        $orphanNames[] = $name;
        $all[] = [
            // null, not 0 or -1: "this office is not in the lookup table" is a fact
            // about the dimension, and a sentinel id would join to nothing anyway.
            'office_id'             => null,
            'office_name'           => $name,
            'employee_count'        => (int)$r['EmployeeCount'],
            'active_employee_count' => (int)$r['ActiveCount'],
        ];
    }

    // One stable ordering across both sources. Case-insensitive so the list reads
    // the way the join matches; the id tie-break (nulls last) keeps paging stable
    // when two rows share a name.
    usort($all, static function (array $a, array $b): int {
        $c = strcasecmp($a['office_name'], $b['office_name']);
        if ($c !== 0) {
            return $c;
        }
        return ($a['office_id'] ?? PHP_INT_MAX) <=> ($b['office_id'] ?? PHP_INT_MAX);
    });

    $totalCount = count($all);
    $rows       = array_slice($all, $params->offset(), $params->limit());
    $rowCount   = count($rows);

    // -------------------------------------------------------------- diagnostics
    $warnings = [];

    if ($orphanNames !== []) {
        $shown = array_slice($orphanNames, 0, 10);
        // Office names are user-entered text on their way into a JSON string and an
        // X-Report-Warnings header; strip control characters before echoing them.
        $shown = array_map(
            static fn($n) => preg_replace('/[\x00-\x1F\x7F]/u', '', $n) ?? '',
            $shown
        );
        $warnings[] = count($orphanNames) . ' users.Office value(s) have no matching row in the Offices '
            . 'lookup table and are returned with office_id = null: "'
            . implode('", "', $shown) . '"'
            . (count($orphanNames) > 10 ? ' (first 10 of ' . count($orphanNames) . ')' : '')
            . '. users.Office has no foreign key, so this is a data-quality signal, not an API error.';
    }

    // Offices has no unique key on OfficeName. Duplicates would each be joined to
    // the SAME employees, so summing the rows would double count them — say so
    // rather than letting a BI total quietly inflate.
    $byName = [];
    foreach ($officeRows as $r) {
        $key = strtolower((string)$r['OfficeName']);
        $byName[$key] = ($byName[$key] ?? 0) + 1;
    }
    $dupNames = array_keys(array_filter($byName, static fn($n) => $n > 1));
    if ($dupNames !== []) {
        $warnings[] = count($dupNames) . ' office name(s) appear on more than one Offices row. Each duplicate '
            . 'row reports the SAME employees, so summing employee_count across rows over-counts them. '
            . 'Deduplicate the Offices table, or aggregate by office_name rather than office_id.';
    }

    if ((int)$unassigned['EmployeeCount'] > 0) {
        $warnings[] = (int)$unassigned['EmployeeCount'] . ' employee(s) have no office set and appear in no '
            . 'row here; see meta.employees_without_office.';
    }

    if ($params->offset() > 0 || ($params->offset() + $rowCount) < $totalCount) {
        $warnings[] = 'Totals cover only the ' . $rowCount . ' row(s) returned by this request, not all '
            . $totalCount . ' office(s). Page through the full result set to total it.';
    }

    // ------------------------------------------------------------------- output
    $totalEmployees = 0;
    $totalActive    = 0;
    foreach ($rows as $r) {
        $totalEmployees += $r['employee_count'];
        $totalActive    += $r['active_employee_count'];
    }

    $meta = $params->meta('offices', [
        // No filter or rule knobs apply to a dimension listing, and publishing the
        // shared defaults (include_inactive:false, ot_weekly:40) would describe
        // behavior this endpoint does not have.
        'filters'     => (object)[],
        'rules'       => (object)[],
        'row_count'   => $rowCount,
        'total_count' => $totalCount,
        'warnings'    => $warnings,
        // Roster-wide, NOT page-scoped: rows + this = every employee, which is how
        // an integrator reconciles this dimension against /employees.
        'employees_without_office'        => (int)$unassigned['EmployeeCount'],
        'active_employees_without_office' => (int)$unassigned['ActiveCount'],
    ]);

    $data = [
        'rows'   => $rows,
        'meta'   => $meta,
        'totals' => [
            'offices'               => $rowCount,
            'employee_count'        => $totalEmployees,
            'active_employee_count' => $totalActive,
        ],
    ];

    // Pinned so an empty Offices table still emits a header row.
    Output::send($data, $params, 'offices', [
        'office_id', 'office_name', 'employee_count', 'active_employee_count',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/offices] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
