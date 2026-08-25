<?php
/**
 * ReportBootstrap — the ~30 lines of boilerplate that every /api/external/v1
 * endpoint used to copy-paste (see punches.php / employees.php), in one place.
 *
 * begin() does, in this order:
 *   1. security headers
 *   2. the shutdown audit hook (so even a fatal is logged with a real status code)
 *   3. method allowlist -> 405 with an Allow header, BEFORE authenticating
 *   4. ApiKeyAuth::authenticate($requiredScope)
 *
 * Steps 2 and 3 are deliberately in that order: a 405 from a client hammering the
 * wrong verb is exactly the kind of thing an incident responder needs in the audit
 * log, and rejecting it before auth means a bad verb never costs a DB round trip
 * or counts against the key's rate limit.
 */

require_once __DIR__ . '/../../auth/db.php';
require_once __DIR__ . '/../middleware/ApiResponse.php';
require_once __DIR__ . '/../middleware/ApiKeyAuth.php';
require_once __DIR__ . '/ReportParams.php';
require_once __DIR__ . '/HoursCalculator.php';
require_once __DIR__ . '/ScheduleCalculator.php';
require_once __DIR__ . '/Output.php';

class ReportBootstrap
{
    /**
     * @param string   $endpointPath   e.g. '/api/external/v1/reports/utilization'
     * @param string   $requiredScope  e.g. 'reports:read'
     * @param string[] $allowedMethods verbs this endpoint serves
     * @return array{id: int, scopes: string[]} the authenticated API key
     */
    public static function begin(
        string $endpointPath,
        string $requiredScope,
        array $allowedMethods = ['GET']
    ): array {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');

        $startTime   = microtime(true);
        $ip          = ApiKeyAuth::clientIp();
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $method      = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

        // $keyId is captured BY REFERENCE: the hook is registered before auth has
        // resolved, but must log the key that eventually authenticated (and NULL
        // when auth never got that far, which is how failed attempts show up in
        // api_key_audit_log). Same contract as punches.php.
        $keyId = null;
        register_shutdown_function(function () use (&$keyId, $startTime, $ip, $queryString, $endpointPath, $method) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $code = http_response_code();
            // http_response_code() returns false in non-web SAPIs and when no code
            // has been set; a request that died before setting one WAS a 500.
            if (!is_int($code)) $code = 500;
            ApiKeyAuth::logRequest(
                $keyId,
                $endpointPath,
                $method,
                $code,
                $durationMs,
                $ip,
                $queryString
            );
        });

        $allowed = array_values(array_unique(array_map('strtoupper', $allowedMethods)));
        if (!in_array(strtoupper($method), $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            ApiResponse::error(
                'Method not allowed. This endpoint accepts: ' . implode(', ', $allowed) . '.',
                405
            );
        }

        $key   = ApiKeyAuth::authenticate($requiredScope);
        $keyId = (int)$key['id'];

        return $key;
    }
}
