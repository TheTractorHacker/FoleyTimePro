<?php
/**
 * GET /api/external/v1/audit/access
 * Authorization: Bearer <api-key>   Scope: audit:read
 *
 * Sign-in and API access records. UNIONs two sources with almost nothing in
 * common — one is tied to an employee, the other to an API key — so most
 * filters below are meaningful to only one arm; see FILTER SCOPING.
 *
 *   login_logs        -> source "employee_login"  successful employee sign-ins.
 *   api_key_audit_log -> source "api_request"      every /api/external/* call,
 *       success or failure (KeyID is NULL for a failed-auth attempt — see
 *       ApiKeyAuth::logRequest); api_keys is LEFT JOINed for Name/KeyPrefix
 *       ONLY, never KeyHash.
 *
 * "employee_login rows are always success" — VERIFIED, not assumed: the only
 * INSERT INTO login_logs in this codebase is in user/login.php, on the success
 * branch of PIN verification (after `$valid` is confirmed, before the 2FA
 * gate). Neither api/v1/auth.php nor admin/login.php write to this table, and
 * there is no failure-path INSERT anywhere for it. A failed employee sign-in
 * simply produces no row — it is invisible here, not a hidden `error` row —
 * which is exactly what this endpoint's `outcome` field for employee_login
 * says (a bare literal 'success', not derived from anything).
 *
 * FILTER SCOPING — a filter that only one arm's source table can satisfy
 * excludes the OTHER arm entirely rather than silently pretending it doesn't
 * apply there (meta.warnings says so whenever it fires):
 *   employee_id / employee_email / office  -> api_request has no employee at
 *       all (a key is not a person), so any of these three excludes it.
 *   status_code / endpoint                 -> employee_login has neither
 *       column, so either one excludes it.
 *   outcome=error                          -> employee_login is definitionally
 *       always 'success' (see above), so this excludes it; outcome=success
 *       does not exclude anything.
 *   ip                                     -> meaningful to both; narrows,
 *       never excludes an arm.
 * `include_inactive` alone (no employee_id/email/office alongside it) does
 * NOT exclude api_request — it only loosens the default active-employee
 * filter on the arm that HAS employees.
 *
 * users is LEFT JOINed to login_logs for the same reason punch-changes.php
 * LEFT JOINs it: admin/delete_user.php and archive_user.php cascade-delete
 * login_logs for a removed employee today, so this should not matter in
 * practice, but an audit trail degrading a name to null beats one silently
 * losing the row if that cascade is ever bypassed.
 *
 * Params: start, end (required, on occurred_at), employee_id, employee_email,
 *   office, include_inactive, tz, format, limit, offset, plus:
 *   source       comma list of employee_login,api_request (default: both)
 *   status_code  comma list of ints, api_request only
 *   outcome      "success" | "error"
 *   ip           exact match against the stored IP string
 *   endpoint     prefix match; the wildcard is appended to the bound value in
 *                PHP (never concatenated into the SQL string), and literal
 *                `%`/`_` in the supplied prefix are escaped so they match
 *                themselves rather than acting as LIKE wildcards.
 * Totals describe the full filtered set, not just the returned page.
 * Sort: occurred_at DESC, event_id DESC (stable tie-break).
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../../lib/ApiTimeFormat.php';

$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

/** Same shape as the equivalent helper in punch-changes.php; kept local on purpose (see that file). */
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

$parseEnumParam = static function (array $get, string $name, array $allowed): ?string {
    if (!array_key_exists($name, $get)) {
        return null;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = strtolower(trim((string)$raw));
    if ($v === '') return null;
    if (!in_array($v, $allowed, true)) {
        ApiResponse::error(
            'Invalid parameter `' . $name . '`: "' . htmlspecialchars(substr($v, 0, 64), ENT_QUOTES, 'UTF-8')
            . '". Accepted values are ' . implode(', ', $allowed) . '.',
            400
        );
    }
    return $v;
};

$parseIntCsv = static function (array $get, string $name, int $min, int $max): array {
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
        if (!preg_match('/^\d+$/', $piece) || (int)$piece < $min || (int)$piece > $max) {
            $echo = htmlspecialchars(substr($piece, 0, 64), ENT_QUOTES, 'UTF-8');
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: "' . $echo . '" is not an integer between '
                . $min . ' and ' . $max . '.',
                400
            );
        }
        $i = (int)$piece;
        if (!in_array($i, $out, true)) $out[] = $i;
    }
    return $out;
};

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

try {
    ReportBootstrap::begin('/api/external/v1/audit/access', 'audit:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'limit', 'offset', 'source', 'status_code', 'outcome', 'ip', 'endpoint',
    ]);

    $sourceFilter = $parseCsvEnum($_GET, 'source', [
        'employee_login' => 'employee_login', 'api_request' => 'api_request',
    ]);
    $statusCodeFilter = $parseIntCsv($_GET, 'status_code', 100, 599);
    $outcomeFilter    = $parseEnumParam($_GET, 'outcome', ['success', 'error']);
    $ip               = $parseExactString($_GET, 'ip', 45);
    $endpointPrefixRaw = $parseExactString($_GET, 'endpoint', 120);

    $tz = $params->timezone();
    $startEpoch        = (new DateTimeImmutable($params->startDate() . ' 00:00:00', $tz))->getTimestamp();
    $endEpochExclusive = (new DateTimeImmutable($params->endDate()   . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();

    $filter = $params->buildUserFilter('u');
    $hasEmployeeScope = $params->employeeIds() !== [] || $params->employeeEmails() !== [] || $params->offices() !== [];

    $wantLogin = ($sourceFilter === [] || in_array('employee_login', $sourceFilter, true))
        && $statusCodeFilter === []
        && $endpointPrefixRaw === null
        && $outcomeFilter !== 'error';
    $wantApi = ($sourceFilter === [] || in_array('api_request', $sourceFilter, true))
        && !$hasEmployeeScope;

    $arms = [];

    if ($wantLogin) {
        $where  = ['ll.Timestamp >= FROM_UNIXTIME(?)', 'll.Timestamp < FROM_UNIXTIME(?)'];
        $types  = 'ii';
        $values = [$startEpoch, $endEpochExclusive];
        if ($filter['sql'] !== '') {
            $where[] = $filter['sql'];
            $types  .= $filter['types'];
            $values  = array_merge($values, $filter['values']);
        }
        if ($ip !== null) { $where[] = 'll.IP = ?'; $types .= 's'; $values[] = $ip; }

        $arms[] = [
            'sql' => "SELECT
                    CONCAT('ll-', ll.ID)                 AS event_id,
                    'employee_login'                      AS source,
                    UNIX_TIMESTAMP(ll.Timestamp)          AS occurred_at_epoch,
                    ll.IP                                 AS ip_address,
                    ll.EmployeeID                         AS employee_id,
                    u.Email                                AS employee_email,
                    CONCAT(u.FirstName, ' ', u.LastName)  AS employee_name,
                    NULL                                   AS api_key_name,
                    NULL                                   AS api_key_prefix,
                    NULL                                   AS endpoint,
                    NULL                                   AS method,
                    NULL                                   AS status_code,
                    NULL                                   AS duration_ms,
                    'success'                              AS outcome
                FROM login_logs ll
                LEFT JOIN users u ON u.ID = ll.EmployeeID
                WHERE " . implode(' AND ', $where),
            'types'  => $types,
            'values' => $values,
        ];
    }

    if ($wantApi) {
        $where  = ['ak.CreatedAt >= FROM_UNIXTIME(?)', 'ak.CreatedAt < FROM_UNIXTIME(?)'];
        $types  = 'ii';
        $values = [$startEpoch, $endEpochExclusive];
        if ($ip !== null) { $where[] = 'ak.IP = ?'; $types .= 's'; $values[] = $ip; }
        if ($endpointPrefixRaw !== null) {
            // Wildcard appended to the BOUND value, never the SQL string. Literal
            // %/_ in the caller's prefix are escaped so "reports/hours" can't
            // accidentally match "reportsXhours" via an unintended _ wildcard.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $endpointPrefixRaw);
            $where[]  = "ak.Endpoint LIKE ?";
            $types   .= 's';
            $values[] = $escaped . '%';
        }
        if ($statusCodeFilter !== []) {
            $ph      = implode(',', array_fill(0, count($statusCodeFilter), '?'));
            $where[] = "ak.StatusCode IN ($ph)";
            $types  .= str_repeat('i', count($statusCodeFilter));
            $values  = array_merge($values, $statusCodeFilter);
        }
        if ($outcomeFilter !== null) {
            $where[]  = "(CASE WHEN ak.StatusCode >= 400 THEN 'error' ELSE 'success' END) = ?";
            $types   .= 's';
            $values[] = $outcomeFilter;
        }

        $arms[] = [
            'sql' => "SELECT
                    CONCAT('ak-', ak.ID)                 AS event_id,
                    'api_request'                         AS source,
                    UNIX_TIMESTAMP(ak.CreatedAt)          AS occurred_at_epoch,
                    ak.IP                                 AS ip_address,
                    NULL                                   AS employee_id,
                    NULL                                   AS employee_email,
                    NULL                                   AS employee_name,
                    k.Name                                 AS api_key_name,
                    k.KeyPrefix                            AS api_key_prefix,
                    ak.Endpoint                            AS endpoint,
                    ak.Method                              AS method,
                    ak.StatusCode                          AS status_code,
                    ak.DurationMs                          AS duration_ms,
                    CASE WHEN ak.StatusCode >= 400 THEN 'error' ELSE 'success' END AS outcome
                FROM api_key_audit_log ak
                LEFT JOIN api_keys k ON k.ID = ak.KeyID
                WHERE " . implode(' AND ', $where),
            'types'  => $types,
            'values' => $values,
        ];
    }

    $warnings = [];
    if ($hasEmployeeScope && ($sourceFilter === [] || in_array('api_request', $sourceFilter, true)) && !$wantApi) {
        $warnings[] = 'employee_id/employee_email/office filter(s) applied: api_request rows have no '
            . 'employee association and are excluded from this response entirely (including totals).';
    }
    if (!$wantLogin && ($sourceFilter === [] || in_array('employee_login', $sourceFilter, true))) {
        $reasons = [];
        if ($statusCodeFilter !== [])   $reasons[] = '`status_code`';
        if ($endpointPrefixRaw !== null) $reasons[] = '`endpoint`';
        if ($outcomeFilter === 'error')  $reasons[] = '`outcome=error`';
        if ($reasons !== []) {
            $warnings[] = implode(' and ', $reasons) . ' filter applied: employee_login rows have no such '
                . 'column (or are never `error`) and are excluded from this response entirely (including totals).';
        }
    }

    $rows       = [];
    $totalCount = 0;
    $totals     = ['events' => 0, 'employee_logins' => 0, 'api_requests' => 0, 'errors' => 0, 'distinct_ips' => 0];

    if ($arms === []) {
        $warnings[] = 'No source matched the requested filters, so this response is empty.';
    } else {
        $unionSql    = implode(' UNION ALL ', array_column($arms, 'sql'));
        $unionTypes  = implode('', array_column($arms, 'types'));
        $unionValues = array_merge(...array_column($arms, 'values'));

        $countSql = "SELECT COUNT(*) AS total,
                            SUM(source = 'employee_login') AS employee_logins,
                            SUM(source = 'api_request') AS api_requests,
                            SUM(outcome = 'error') AS errors,
                            COUNT(DISTINCT ip_address) AS distinct_ips
                     FROM ($unionSql) t";
        $stmt = $conn->prepare($countSql);
        $stmt->bind_param($unionTypes, ...$unionValues);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalCount = (int)($t['total'] ?? 0);
        $totals = [
            'events'          => $totalCount,
            'employee_logins' => (int)($t['employee_logins'] ?? 0),
            'api_requests'    => (int)($t['api_requests'] ?? 0),
            'errors'          => (int)($t['errors'] ?? 0),
            'distinct_ips'    => (int)($t['distinct_ips'] ?? 0),
        ];

        $limit  = $params->limit();
        $offset = $params->offset();
        $pageSql = "$unionSql ORDER BY occurred_at_epoch DESC, event_id DESC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($pageSql);
        $stmt->bind_param($unionTypes . 'ii', ...array_merge($unionValues, [$limit, $offset]));
        $stmt->execute();
        $page = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($page as $r) {
            $rows[] = [
                'event_id'       => (string)$r['event_id'],
                'source'         => (string)$r['source'],
                'occurred_at'    => ApiTimeFormat::fromEpoch(ApiTimeFormat::epochOrNull($r['occurred_at_epoch']), $tz),
                'ip_address'     => $nullIfBlank($r['ip_address']),
                'employee_id'    => $r['employee_id'] !== null ? (int)$r['employee_id'] : null,
                'employee_email' => $nullIfBlank($r['employee_email']),
                'employee_name'  => $r['employee_name'] !== null ? $nullIfBlank(trim((string)$r['employee_name'])) : null,
                'api_key_name'   => $nullIfBlank($r['api_key_name']),
                'api_key_prefix' => $nullIfBlank($r['api_key_prefix']),
                'endpoint'       => $nullIfBlank($r['endpoint']),
                'method'         => $nullIfBlank($r['method']),
                'status_code'    => $r['status_code'] !== null ? (int)$r['status_code'] : null,
                'duration_ms'    => $r['duration_ms'] !== null ? (int)$r['duration_ms'] : null,
                'outcome'        => (string)$r['outcome'],
            ];
        }
    }

    $meta = $params->meta('access', [
        'row_count'   => count($rows),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);
    $meta['filters']['source']      = $sourceFilter;
    $meta['filters']['status_code'] = $statusCodeFilter;
    $meta['filters']['outcome']     = $outcomeFilter;
    $meta['filters']['ip']          = $ip;
    $meta['filters']['endpoint']    = $endpointPrefixRaw;

    $csvColumns = [
        'event_id', 'source', 'occurred_at', 'ip_address', 'employee_id', 'employee_email',
        'employee_name', 'api_key_name', 'api_key_prefix', 'endpoint', 'method', 'status_code',
        'duration_ms', 'outcome',
    ];

    Output::send(['rows' => $rows, 'meta' => $meta, 'totals' => $totals], $params, 'access', $csvColumns);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/audit/access] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
