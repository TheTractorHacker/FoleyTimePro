<?php
/**
 * GET /api/external/v1/meta
 * Authorization: Bearer <api-key>   Scope: NONE — any valid, unrevoked, unexpired
 *                                    key may call this regardless of which scopes
 *                                    it holds.
 *
 * Discovery endpoint: lets an integrator confirm a key works and see exactly what
 * it can reach — its own scopes, the full endpoint catalog, the server's current
 * limits, and how much data actually exists — before they spend a request against
 * an endpoint they may not be scoped for.
 *
 * WHY THIS FILE DOES NOT CALL ReportBootstrap::begin():
 * begin()'s $requiredScope parameter is typed `string`, not `?string` — it always
 * enforces a scope. ApiKeyAuth::authenticate(?string $requiredScope = null),
 * confirmed by reading ApiKeyAuth.php, skips the scope check entirely when given
 * null (`if ($requiredScope !== null && !in_array(...))`), which is exactly what
 * this endpoint needs. Since begin() cannot be asked to pass null through, this
 * file reproduces its header / shutdown-audit-hook / method-allowlist sequence by
 * hand and calls ApiKeyAuth::authenticate(null) directly, rather than changing
 * the shared library's signature for one endpoint. ReportBootstrap.php is still
 * require_once'd — for db.php, the middleware, and the other lib classes — its
 * begin() method is simply never invoked.
 *
 * The key's Name is not part of ApiKeyAuth::authenticate()'s return value, so it
 * is read back here with its own prepared query on the id authenticate() did
 * return, rather than widening the middleware's contract for one field one
 * endpoint needs.
 *
 * limits below mirror private constants an integrator has no other way to see:
 * ApiKeyAuth::RATE_LIMIT_PER_MINUTE and ReportParams::MAX_RANGE_DAYS /
 * ::MAX_LIMIT. They are duplicated here deliberately (those constants are
 * private, and adding public accessors for three numbers is not worth widening
 * either class's surface) — if those constants ever change, this literal block
 * must change with them.
 */

require_once __DIR__ . '/../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../lib/ApiTimeFormat.php';

$endpointPath = '/api/external/v1/meta';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$startTime   = microtime(true);
$ip          = ApiKeyAuth::clientIp();
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$method      = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

// Captured by reference, same contract as ReportBootstrap::begin(): the hook is
// registered before auth resolves, but must log whichever key eventually
// authenticated (or NULL for a request that never got that far).
$keyId = null;
register_shutdown_function(function () use (&$keyId, $startTime, $ip, $queryString, $endpointPath, $method) {
    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    $code = http_response_code();
    if (!is_int($code)) $code = 500;
    ApiKeyAuth::logRequest($keyId, $endpointPath, $method, $code, $durationMs, $ip, $queryString);
});

if (strtoupper($method) !== 'GET') {
    header('Allow: GET');
    ApiResponse::error('Method not allowed. This endpoint accepts: GET.', 405);
}

try {
    $key   = ApiKeyAuth::authenticate(null);
    $keyId = (int)$key['id'];

    // tz/format are the only query params this endpoint honors — there is no
    // tabular data here for CSV, so `format` is accepted (never flagged as
    // ignored) but not acted on beyond that.
    $params = ReportParams::fromRequest($_GET, $conn, []);
    $tz     = $params->timezone();

    // ---------------------------------------------------------------- key info
    $stmt = $conn->prepare(
        "SELECT Name, UNIX_TIMESTAMP(ExpiresAt) AS ExpiresAtEpoch FROM api_keys WHERE ID = ?"
    );
    $stmt->bind_param('i', $keyId);
    $stmt->execute();
    $keyRow = $stmt->get_result()->fetch_assoc() ?: ['Name' => null, 'ExpiresAtEpoch' => null];
    $stmt->close();

    // ------------------------------------------------------------- branding
    $companyName = 'FoleyTimePro'; // schema default; used if the branding row is missing
    try {
        $res = $conn->query("SELECT CompanyName FROM branding ORDER BY ID LIMIT 1");
        if ($res) {
            $row = $res->fetch_assoc();
            $res->free();
            if ($row && is_string($row['CompanyName']) && $row['CompanyName'] !== '') {
                $companyName = $row['CompanyName'];
            }
        }
    } catch (Throwable $e) {
        // A missing/unreadable branding row must not fail discovery.
        error_log('[external-api/meta] branding lookup failed: ' . $e->getMessage());
    }

    // ------------------------------------------------------------- data range
    $dataRange = ['earliest_punch_date' => null, 'latest_punch_date' => null, 'employee_count' => 0];
    $res = $conn->query("SELECT MIN(Date) AS EarliestDate, MAX(Date) AS LatestDate FROM timepunches");
    if ($res) {
        $row = $res->fetch_assoc();
        $res->free();
        if ($row) {
            $dataRange['earliest_punch_date'] = ApiTimeFormat::dateOnly($row['EarliestDate']);
            $dataRange['latest_punch_date']   = ApiTimeFormat::dateOnly($row['LatestDate']);
        }
    }
    $res = $conn->query("SELECT COUNT(*) AS c FROM users");
    if ($res) {
        $row = $res->fetch_assoc();
        $res->free();
        $dataRange['employee_count'] = (int)($row['c'] ?? 0);
    }

    // -------------------------------------------------------------- endpoints
    // Hardcoded documentation of every endpoint that actually exists under
    // /api/external/v1 today. Kept here rather than generated so a route that is
    // scaffolded but not yet wired (see ApiScopes.php's reserved write scopes)
    // never appears as though it were callable.
    $endpoints = [
        ['path' => '/api/external/v1/meta', 'method' => 'GET', 'scope' => null,
         'description' => 'API discovery: this endpoint. Confirms a key is valid and lists what it can reach.'],
        ['path' => '/api/external/v1/punches', 'method' => 'GET', 'scope' => 'punches:read',
         'description' => 'Raw clock in/out and lunch punches, with a delta-sync cursor.'],
        ['path' => '/api/external/v1/employees', 'method' => 'GET', 'scope' => 'employees:read',
         'description' => 'Employee roster for mapping external IDs, office, shift and clock status.'],
        ['path' => '/api/external/v1/offices', 'method' => 'GET', 'scope' => 'offices:read',
         'description' => 'Office/location dimension with employee headcounts.'],
        ['path' => '/api/external/v1/shifts', 'method' => 'GET', 'scope' => 'shifts:read',
         'description' => 'Shift definitions: days, span, lunch and scheduled hours per day.'],
        ['path' => '/api/external/v1/schedule', 'method' => 'GET', 'scope' => 'shifts:read',
         'description' => 'Expanded per-employee, per-date schedule for scheduled-vs-actual joins.'],
        ['path' => '/api/external/v1/timeoff', 'method' => 'GET', 'scope' => 'timeoff:read',
         'description' => 'Raw time-off request feed, delta-sync friendly via updated_since.'],
        ['path' => '/api/external/v1/reports/hours', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'Fact table: one row per employee per day with punch activity.'],
        ['path' => '/api/external/v1/reports/summary', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'One row per employee for the whole range: regular/overtime/double-time totals.'],
        ['path' => '/api/external/v1/reports/overtime', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'One row per employee per week — the overtime trend series.'],
        ['path' => '/api/external/v1/reports/utilization', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'Scheduled-vs-worked capacity analysis, by employee, employee-week or office.'],
        ['path' => '/api/external/v1/reports/attendance', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'Attendance calendar: one row per employee per date for the requested range.'],
        ['path' => '/api/external/v1/reports/timeoff', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'Time-off request analytics, apportioned to the range and optionally folded up.'],
        ['path' => '/api/external/v1/reports/leave-balances', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'Current leave balances, one row per employee per leave type per year.'],
        ['path' => '/api/external/v1/reports/payroll', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'One row per employee for a pay period, shaped for payroll import.'],
        ['path' => '/api/external/v1/reports/exceptions', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'Payroll-prep data-quality report: punches likely to make payroll wrong.'],
        ['path' => '/api/external/v1/reports/punctuality', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'Arrival/departure behaviour per employee: late rate, average minutes late.'],
        ['path' => '/api/external/v1/reports/trends', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'Time series of hours/overtime by day, week or month, for charting.'],
        ['path' => '/api/external/v1/reports/geofence', 'method' => 'GET', 'scope' => 'reports:read',
         'description' => 'GPS compliance: punches recorded outside their assigned geofence.'],
    ];

    // ------------------------------------------------------------------ output
    $data = [
        'api_version'  => 'v1',
        'company_name' => $companyName,
        'timezone'     => $tz->getName(),
        'server_time'  => ApiTimeFormat::now($tz),
        'key'          => [
            'name'       => $keyRow['Name'] ?? null,
            'scopes'     => $key['scopes'],
            'expires_at' => ApiTimeFormat::fromEpoch(
                ApiTimeFormat::epochOrNull($keyRow['ExpiresAtEpoch'] ?? null), $tz
            ),
        ],
        'endpoints' => $endpoints,
        'limits'    => [
            'rate_limit_per_minute' => 60,
            'max_range_days'        => 400,
            'max_limit'             => 5000,
        ],
        'data_range' => $dataRange,
    ];

    ApiResponse::success($data);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/meta] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
