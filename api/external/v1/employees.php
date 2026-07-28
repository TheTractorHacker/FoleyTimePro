<?php
/**
 * GET /api/external/v1/employees
 * Authorization: Bearer <api-key>
 *
 * Returns the employee roster so external systems (e.g. ITFlow) can map
 * their own employees to timeclock IDs / emails.
 */

require_once __DIR__ . '/../../../auth/db.php';
require_once __DIR__ . '/../../middleware/ApiResponse.php';
require_once __DIR__ . '/../../middleware/ApiKeyAuth.php';

$startTime   = microtime(true);
$keyId       = null;
$ip          = ApiKeyAuth::clientIp();
$queryString = $_SERVER['QUERY_STRING'] ?? '';

register_shutdown_function(function () use (&$keyId, $startTime, $ip, $queryString) {
    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    $code = http_response_code();
    if (!is_int($code)) $code = 500;
    ApiKeyAuth::logRequest(
        $keyId,
        '/api/external/v1/employees',
        $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        $code,
        $durationMs,
        $ip,
        $queryString
    );
});

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        ApiResponse::error('Method not allowed', 405);
    }

    $key   = ApiKeyAuth::authenticate('employees:read');
    $keyId = $key['id'];

    // TagID is deliberately omitted: it is the sole kiosk auth credential,
    // and exposing it here would let any external-API integrator clock any
    // employee in/out at the kiosk. Integrations that need to reconcile a
    // badge should ask for a fingerprint API (last-4 or SHA-256(TagID))
    // rather than the raw value.
    $rows = $conn->query(
        "SELECT ID, Email, FirstName, LastName, Office, LockOut
         FROM users
         ORDER BY LastName, FirstName"
    )->fetch_all(MYSQLI_ASSOC);

    $employees = [];
    foreach ($rows as $r) {
        $employees[] = [
            'id'         => (string)$r['ID'],
            'email'      => $r['Email'],
            'first_name' => $r['FirstName'],
            'last_name'  => $r['LastName'],
            'office'     => $r['Office'] !== null && $r['Office'] !== '' ? $r['Office'] : null,
            'active'     => empty($r['LockOut']),
        ];
    }

    ApiResponse::success(['employees' => $employees]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/employees] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
