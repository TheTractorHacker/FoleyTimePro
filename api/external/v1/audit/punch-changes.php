<?php
/**
 * GET /api/external/v1/audit/punch-changes
 * Authorization: Bearer <api-key>   Scope: audit:read
 *
 * Timesheet edit history: who altered a punch, from what to what, and why.
 * UNIONs two independent sources that never overlap (admin/process_edits.php
 * applies an approved employee request straight to `timepunches` — it never
 * writes punch_changelog — so the same real-world edit can never appear twice
 * under two different `source` values):
 *
 *   punch_changelog -> source "admin_edit"       one row per (punch, field) an
 *       admin changed via admin/save_punches.php or the auto-clockout cron.
 *   pending_edits    -> source "employee_request" one row per employee-submitted
 *       correction, whatever its review outcome.
 *
 * TWO DIFFERENT "DATE" CONCEPTS — do not confuse them:
 *   `start`/`end` (required) filter on the CHANGE TIMESTAMP: punch_changelog.
 *   ChangeTime for admin_edit, pending_edits.SubmittedAt for employee_request
 *   (the moment the request was created — its later approval/rejection is
 *   reported separately via `reviewed_at` and never moves a row in or out of
 *   this range). An edit made today to a punch from last month appears in
 *   TODAY's range, not last month's.
 *   `punch_start`/`punch_end` (optional, independent, no range cap) instead
 *   filter on the punch's OWN Date — the day the timesheet entry itself
 *   covers. Pass both to answer "what changed, in the last 7 days, to punches
 *   from Q1" — a question start/end alone cannot express.
 *
 * FIELD_CHANGED / OLD_VALUE / NEW_VALUE — the two sources do not share a shape.
 * punch_changelog already logs one field per row, so those three columns pass
 * through verbatim. pending_edits stores a REQUEST as a full set of proposed
 * column values (TimeIN/LunchStart/LunchEnd/TimeOut), any subset of which may
 * be non-null, so a single request can touch several fields at once:
 *   field_changed = comma list of the fields the request set (e.g. "TimeIN,TimeOut")
 *   new_value     = the corresponding proposed values, same order, comma list
 *   old_value     = always null for employee_request. pending_edits never
 *       recorded the punch's prior value, and looking one up from the CURRENT
 *       timepunches row would report whatever is there today, not what was
 *       there when the request was submitted (it may since have changed
 *       again) — a wrong old_value is worse than an honest null.
 * CONCAT_WS() skips NULL arguments, so both lists are built from the same four
 * columns in the same order and always line up positionally.
 *
 * `applied` (bool, additive — not narrated as its own bullet in the row-field
 * list this endpoint was speced against, included because the spec explicitly
 * calls out that a Pending row must say, per row, that it has not taken
 * effect): true for every admin_edit row (punch_changelog is only ever
 * written alongside the UPDATE that made it real — see save_punches.php /
 * auto_clockout_cron.php) and for an employee_request row exactly when
 * Status = 'Approved' (the only status process_edits.php ever turns into a
 * `timepunches` UPDATE). Pending and Rejected requests are both `applied:false`.
 *
 * `changed_by` for employee_request has no dedicated column to read verbatim
 * (unlike punch_changelog.ChangedBy) — it is the requesting employee's own
 * name, since they are who proposed the change.
 *
 * users is LEFT JOINed, not INNER: admin/delete_user.php and archive_user.php
 * both cascade-delete punch_changelog/pending_edits rows for a removed
 * employee at the application level today, so an orphan should not occur —
 * but an audit trail must not silently drop history if that ever changes
 * (e.g. a direct DB deletion), so employee_id/office/name degrade to null
 * rather than the row vanishing. employee_id itself always comes from the
 * source table's own EmployeeID column, never from the (possibly missing) u.ID,
 * so it survives even when the join misses.
 *
 * Params: start, end (required; see above), employee_id, employee_email,
 *   office, include_inactive, tz, format, limit, offset, plus:
 *   source      comma list of admin_edit,employee_request (default: both)
 *   status      comma list of Pending,Approved,Rejected — matches
 *               employee_request only; punch_changelog rows have no status,
 *               so this filter drops admin_edit rows from the response
 *               entirely (stated in meta.warnings when it does).
 *   changed_by  exact match against ChangedBy (admin_edit) or the employee's
 *               full name (employee_request).
 *   punch_start, punch_end  optional Y-m-d pair, see above.
 * Totals describe the full filtered set, not just the returned page.
 * Sort: changed_at DESC, change_id DESC (stable tie-break).
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../../lib/ApiTimeFormat.php';

/** Local, not global — avoids any risk of colliding with another endpoint's helpers. */
$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

/**
 * Comma-list validated against a fixed vocabulary, case-insensitive on input,
 * returned in the vocabulary's own canonical casing. Same shape as
 * ReportParams::csvList()/parseEnumParam in spirit, kept local because the
 * vocabulary (and whether the value even reaches ReportParams) is specific to
 * this endpoint.
 *
 * @param array<string,string> $canonical lowercase input => canonical output
 * @return string[]
 */
$parseCsvEnum = static function (array $get, string $name, array $canonical): array {
    if (!array_key_exists($name, $get)) {
        return [];
    }
    $raw = $get[$name];
    if (is_array($raw)) {
        $flat = [];
        foreach ($raw as $v) { if (is_scalar($v)) $flat[] = (string)$v; }
        $raw = implode(',', $flat);
    } elseif (!is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a value or comma list.', 400);
    }
    $out = [];
    foreach (explode(',', (string)$raw) as $piece) {
        $piece = trim($piece);
        if ($piece === '') continue;
        $key = strtolower($piece);
        if (!isset($canonical[$key])) {
            $echo = htmlspecialchars(substr($piece, 0, 64), ENT_QUOTES, 'UTF-8');
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are '
                . implode(', ', array_unique(array_values($canonical))) . '.',
                400
            );
        }
        $c = $canonical[$key];
        if (!in_array($c, $out, true)) $out[] = $c;
    }
    return $out;
};

/** A single trimmed scalar, length-capped to match the DB column it will be bound against. */
$parseExactString = static function (array $get, string $name, int $maxLen): ?string {
    if (!array_key_exists($name, $get)) {
        return null;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = trim((string)$raw);
    if ($v === '') return null;
    if (strlen($v) > $maxLen) {
        ApiResponse::error('Invalid parameter `' . $name . '`: exceeds ' . $maxLen . ' characters.', 400);
    }
    return $v;
};

/** Y-m-d or null; unlike ReportParams::parseDateRange this parameter is optional. */
$parseOptionalDate = static function (array $get, string $name): ?string {
    if (!array_key_exists($name, $get)) {
        return null;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = trim((string)$raw);
    if ($v === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));
    if ($d === false || $d->format('Y-m-d') !== $v) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a calendar date as YYYY-MM-DD.', 400);
    }
    return $v;
};

try {
    ReportBootstrap::begin('/api/external/v1/audit/punch-changes', 'audit:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'limit', 'offset', 'source', 'status', 'changed_by', 'punch_start', 'punch_end',
    ]);

    $sourceFilter = $parseCsvEnum($_GET, 'source', [
        'admin_edit' => 'admin_edit', 'employee_request' => 'employee_request',
    ]);
    $statusFilter = $parseCsvEnum($_GET, 'status', [
        'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected',
    ]);
    $changedBy = $parseExactString($_GET, 'changed_by', 100);
    $punchStart = $parseOptionalDate($_GET, 'punch_start');
    $punchEnd   = $parseOptionalDate($_GET, 'punch_end');
    if ($punchStart !== null && $punchEnd !== null && $punchEnd < $punchStart) {
        ApiResponse::error(
            'Invalid date range: `punch_end` (' . $punchEnd . ') is before `punch_start` (' . $punchStart . ').',
            400
        );
    }

    $tz = $params->timezone();
    // Change-timestamp columns are naive DATETIMEs written under the DB
    // session timezone (see auth/db.php / ApiTimeFormat's file header) — the
    // same convention punches.php relies on for UpdatedAt. Converting the
    // requested calendar range through the REPORT timezone (which may differ
    // from the DB's) and back via FROM_UNIXTIME() is what makes `tz=UTC`
    // and the default timezone agree on which rows are "in range".
    $startEpoch        = (new DateTimeImmutable($params->startDate() . ' 00:00:00', $tz))->getTimestamp();
    $endEpochExclusive = (new DateTimeImmutable($params->endDate()   . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();

    $filter = $params->buildUserFilter('u');

    // status only ever matches an employee_request row (punch_changelog has no
    // status column at all), so an active status filter drops admin_edit
    // entirely rather than let it be silently filtered by a predicate it can
    // never satisfy.
    $wantAdminEdit       = ($sourceFilter === [] || in_array('admin_edit', $sourceFilter, true)) && $statusFilter === [];
    $wantEmployeeRequest = ($sourceFilter === [] || in_array('employee_request', $sourceFilter, true));

    $arms = [];

    if ($wantAdminEdit) {
        $where  = ['cl.ChangeTime >= FROM_UNIXTIME(?)', 'cl.ChangeTime < FROM_UNIXTIME(?)'];
        $types  = 'ii';
        $values = [$startEpoch, $endEpochExclusive];
        if ($filter['sql'] !== '') {
            $where[] = $filter['sql'];
            $types  .= $filter['types'];
            $values  = array_merge($values, $filter['values']);
        }
        if ($changedBy !== null) {
            $where[]  = 'cl.ChangedBy = ?';
            $types   .= 's';
            $values[] = $changedBy;
        }
        if ($punchStart !== null) { $where[] = 'cl.Date >= ?'; $types .= 's'; $values[] = $punchStart; }
        if ($punchEnd   !== null) { $where[] = 'cl.Date <= ?'; $types .= 's'; $values[] = $punchEnd; }

        $arms[] = [
            'sql' => "SELECT
                    CONCAT('cl-', cl.ID)                AS change_id,
                    'admin_edit'                         AS source,
                    cl.EmployeeID                        AS employee_id,
                    u.Email                              AS employee_email,
                    CONCAT(u.FirstName, ' ', u.LastName) AS employee_name,
                    u.Office                             AS office,
                    cl.Date                              AS punch_date,
                    cl.FieldChanged                      AS field_changed,
                    cl.OldValue                          AS old_value,
                    cl.NewValue                          AS new_value,
                    cl.Reason                            AS reason,
                    cl.ChangedBy                         AS changed_by,
                    UNIX_TIMESTAMP(cl.ChangeTime)        AS changed_at_epoch,
                    NULL                                 AS status,
                    NULL                                 AS reviewed_by,
                    NULL                                 AS reviewed_at_epoch,
                    1                                    AS applied
                FROM punch_changelog cl
                LEFT JOIN users u ON u.ID = cl.EmployeeID
                WHERE " . implode(' AND ', $where),
            'types'  => $types,
            'values' => $values,
        ];
    }

    if ($wantEmployeeRequest) {
        $where  = ['pe.SubmittedAt >= FROM_UNIXTIME(?)', 'pe.SubmittedAt < FROM_UNIXTIME(?)'];
        $types  = 'ii';
        $values = [$startEpoch, $endEpochExclusive];
        if ($filter['sql'] !== '') {
            $where[] = $filter['sql'];
            $types  .= $filter['types'];
            $values  = array_merge($values, $filter['values']);
        }
        if ($changedBy !== null) {
            $where[]  = "CONCAT(u.FirstName, ' ', u.LastName) = ?";
            $types   .= 's';
            $values[] = $changedBy;
        }
        if ($statusFilter !== []) {
            $ph      = implode(',', array_fill(0, count($statusFilter), '?'));
            $where[] = "pe.Status IN ($ph)";
            $types  .= str_repeat('s', count($statusFilter));
            $values  = array_merge($values, $statusFilter);
        }
        if ($punchStart !== null) { $where[] = 'pe.Date >= ?'; $types .= 's'; $values[] = $punchStart; }
        if ($punchEnd   !== null) { $where[] = 'pe.Date <= ?'; $types .= 's'; $values[] = $punchEnd; }

        $arms[] = [
            'sql' => "SELECT
                    CONCAT('pe-', pe.ID)                 AS change_id,
                    'employee_request'                    AS source,
                    pe.EmployeeID                         AS employee_id,
                    u.Email                               AS employee_email,
                    CONCAT(u.FirstName, ' ', u.LastName)  AS employee_name,
                    u.Office                              AS office,
                    pe.Date                               AS punch_date,
                    CONCAT_WS(',',
                        IF(pe.TimeIN     IS NOT NULL, 'TimeIN',     NULL),
                        IF(pe.LunchStart IS NOT NULL, 'LunchStart', NULL),
                        IF(pe.LunchEnd   IS NOT NULL, 'LunchEnd',   NULL),
                        IF(pe.TimeOut    IS NOT NULL, 'TimeOut',    NULL)
                    )                                      AS field_changed,
                    NULL                                   AS old_value,
                    CONCAT_WS(',', pe.TimeIN, pe.LunchStart, pe.LunchEnd, pe.TimeOut) AS new_value,
                    pe.Reason                             AS reason,
                    CONCAT(u.FirstName, ' ', u.LastName)  AS changed_by,
                    UNIX_TIMESTAMP(pe.SubmittedAt)         AS changed_at_epoch,
                    pe.Status                             AS status,
                    pe.ReviewedBy                         AS reviewed_by,
                    UNIX_TIMESTAMP(pe.ReviewedAt)          AS reviewed_at_epoch,
                    (pe.Status = 'Approved')              AS applied
                FROM pending_edits pe
                LEFT JOIN users u ON u.ID = pe.EmployeeID
                WHERE " . implode(' AND ', $where),
            'types'  => $types,
            'values' => $values,
        ];
    }

    $warnings = [];
    if ($statusFilter !== [] && !$wantAdminEdit && ($sourceFilter === [] || in_array('admin_edit', $sourceFilter, true))) {
        $warnings[] = '`status` was supplied: admin_edit rows have no status and are excluded from this '
            . 'response entirely (including totals.admin_edits), regardless of `source`.';
    }

    $rows       = [];
    $totalCount = 0;
    $totals     = [
        'changes' => 0, 'employees_affected' => 0, 'admin_edits' => 0,
        'employee_requests' => 0, 'pending_requests' => 0,
    ];

    if ($arms === []) {
        $warnings[] = 'No source matched the requested `source`/`status` filters, so this response is empty.';
    } else {
        $unionSql    = implode(' UNION ALL ', array_column($arms, 'sql'));
        $unionTypes  = implode('', array_column($arms, 'types'));
        $unionValues = array_merge(...array_column($arms, 'values'));

        $countSql = "SELECT COUNT(*) AS total,
                            COUNT(DISTINCT employee_id) AS employees_affected,
                            SUM(source = 'admin_edit') AS admin_edits,
                            SUM(source = 'employee_request') AS employee_requests,
                            SUM(status = 'Pending') AS pending_requests
                     FROM ($unionSql) t";
        $stmt = $conn->prepare($countSql);
        $stmt->bind_param($unionTypes, ...$unionValues);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalCount = (int)($t['total'] ?? 0);
        $totals = [
            'changes'            => $totalCount,
            'employees_affected' => (int)($t['employees_affected'] ?? 0),
            'admin_edits'        => (int)($t['admin_edits'] ?? 0),
            'employee_requests'  => (int)($t['employee_requests'] ?? 0),
            'pending_requests'   => (int)($t['pending_requests'] ?? 0),
        ];

        if ($totals['employee_requests'] > 0) {
            $warnings[] = 'employee_request rows never carry old_value: pending_edits records only the '
                . 'proposed new value(s), not what the punch held before. field_changed/new_value list every '
                . 'field the request touched (a single request can set more than one).';
        }

        $limit  = $params->limit();
        $offset = $params->offset();
        $pageSql = "$unionSql ORDER BY changed_at_epoch DESC, change_id DESC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($pageSql);
        $stmt->bind_param($unionTypes . 'ii', ...array_merge($unionValues, [$limit, $offset]));
        $stmt->execute();
        $page = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($page as $r) {
            $rows[] = [
                'change_id'      => (string)$r['change_id'],
                'source'         => (string)$r['source'],
                'employee_id'    => (int)$r['employee_id'],
                'employee_email' => $nullIfBlank($r['employee_email']),
                'employee_name'  => $nullIfBlank(trim((string)$r['employee_name'])),
                'office'         => $nullIfBlank($r['office']),
                'punch_date'     => ApiTimeFormat::dateOnly($r['punch_date']),
                'field_changed'  => $nullIfBlank($r['field_changed']),
                'old_value'      => $nullIfBlank($r['old_value']),
                'new_value'      => $nullIfBlank($r['new_value']),
                'reason'         => $nullIfBlank($r['reason']),
                'changed_by'     => $nullIfBlank($r['changed_by']),
                'changed_at'     => ApiTimeFormat::fromEpoch(ApiTimeFormat::epochOrNull($r['changed_at_epoch']), $tz),
                'status'         => $r['status'] !== null ? (string)$r['status'] : null,
                'reviewed_by'    => $nullIfBlank($r['reviewed_by']),
                'reviewed_at'    => ApiTimeFormat::fromEpoch(ApiTimeFormat::epochOrNull($r['reviewed_at_epoch']), $tz),
                'applied'        => (bool)$r['applied'],
            ];
        }
    }

    $meta = $params->meta('punch-changes', [
        'row_count'   => count($rows),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);
    $meta['filters']['source']      = $sourceFilter;
    $meta['filters']['status']      = $statusFilter;
    $meta['filters']['changed_by']  = $changedBy;
    $meta['filters']['punch_start'] = $punchStart;
    $meta['filters']['punch_end']   = $punchEnd;

    $csvColumns = [
        'change_id', 'source', 'employee_id', 'employee_email', 'employee_name', 'office',
        'punch_date', 'field_changed', 'old_value', 'new_value', 'reason', 'changed_by',
        'changed_at', 'status', 'reviewed_by', 'reviewed_at', 'applied',
    ];

    Output::send(['rows' => $rows, 'meta' => $meta, 'totals' => $totals], $params, 'punch-changes', $csvColumns);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/audit/punch-changes] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
