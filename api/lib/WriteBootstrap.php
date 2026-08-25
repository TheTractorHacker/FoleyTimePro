<?php
/**
 * WriteBootstrap — the WRITE-endpoint counterpart to ReportBootstrap.php.
 *
 * A read endpoint has exactly one required scope for the whole file. A write
 * endpoint does not: punches.php serves GET (read the feed), POST (create a
 * punch) and PATCH (correct a punch) from the SAME file, and each verb is
 * gated by a DIFFERENT scope. A key holding only `punches:read` must 403 on
 * POST/PATCH; a key holding only `punches:write` must NOT also be required to
 * hold `punches:read` just to reach the write branch — read and write are
 * independent grants, not a hierarchy. begin() resolves the required scope
 * from the ACTUAL incoming method, so each endpoint file states its full
 * method -> scope map once and gets the right per-verb check for free.
 *
 * begin() does, in this order (same order and same reasoning as
 * ReportBootstrap::begin() — see that file):
 *   1. security headers
 *   2. the shutdown audit hook (by-reference $keyId, duration, status code, IP,
 *      truncated query string) — IDENTICAL mechanic to ReportBootstrap, copied
 *      verbatim rather than shared, so a future change to one cannot silently
 *      change the other's audit-log contract.
 *   3. method allowlist -> 405 with an Allow header, BEFORE auth. The allowlist
 *      IS the key set of $methodScopes — there is no separate list to fall out
 *      of sync, so a verb with no configured scope can never be silently
 *      authorized.
 *   4. ApiKeyAuth::authenticate($scopeForThisMethod)
 *
 * Steps 2 and 3 are deliberately in that order: a 405 from a client hammering
 * the wrong verb still belongs in the audit log, and rejecting it before auth
 * means a bad verb never costs a DB round trip or counts against the key's
 * rate limit.
 */

require_once __DIR__ . '/../../auth/db.php';
require_once __DIR__ . '/../middleware/ApiResponse.php';
require_once __DIR__ . '/../middleware/ApiKeyAuth.php';
require_once __DIR__ . '/ApiScopes.php';

class WriteBootstrap
{
    /**
     * @param string $endpointPath e.g. '/api/external/v1/punches'
     * @param array<string,string> $methodScopes Map of HTTP method -> required
     *        scope, e.g.
     *          ['GET' => 'employees:read', 'POST' => 'employees:write', 'PATCH' => 'employees:write']
     *        Keys are matched against the incoming method case-insensitively
     *        (normalized to upper-case internally) — pass them however is
     *        convenient, but upper-case is the convention every call site
     *        uses. The key SET of this map is also the endpoint's method
     *        allowlist: a verb not present here 405s before auth runs, even
     *        if it would otherwise be a sensible HTTP method.
     * @return array{id: int, scopes: string[], method: string} the
     *         authenticated key's id and full scope list, plus the
     *         normalized (upper-case) method that was actually authorized —
     *         so the caller never re-derives $_SERVER['REQUEST_METHOD'] and
     *         risks skewing from what was actually checked.
     */
    public static function begin(string $endpointPath, array $methodScopes): array
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');

        $startTime   = microtime(true);
        $ip          = ApiKeyAuth::clientIp();
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $method      = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN');

        $scopesByMethod = [];
        foreach ($methodScopes as $m => $scope) {
            $scopesByMethod[strtoupper((string)$m)] = $scope;
        }

        // $keyId is captured BY REFERENCE: the hook is registered before auth
        // has resolved, but must log the key that eventually authenticated
        // (and NULL when auth never got that far, which is how failed
        // attempts and 405s show up in api_key_audit_log). Same contract as
        // ReportBootstrap::begin() / punches.php.
        $keyId = null;
        register_shutdown_function(function () use (&$keyId, $startTime, $ip, $queryString, $endpointPath, $method) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $code = http_response_code();
            // http_response_code() returns false in non-web SAPIs and when no
            // code has been set; a request that died before setting one WAS a 500.
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

        if (!array_key_exists($method, $scopesByMethod)) {
            $allowed = array_keys($scopesByMethod);
            header('Allow: ' . implode(', ', $allowed));
            ApiResponse::error(
                'Method not allowed. This endpoint accepts: ' . implode(', ', $allowed) . '.',
                405
            );
        }

        $requiredScope = $scopesByMethod[$method];

        // A scope that ApiScopes::CATALOG marks reserved (available=>false) is
        // NOT authorized here, even if a key happens to already hold that
        // scope string in api_keys.Scopes. This is the actual enforcement of
        // the promise admin/api_keys.php makes when it shows a reserved scope
        // as "not yet available" — before this check existed, that promise
        // was UI copy only: ApiKeyAuth::authenticate() does nothing but
        // `in_array($requiredScope, $scopes)`, so ANY key already holding a
        // reserved scope string would have silently gained real access the
        // moment an endpoint started requiring that scope, with no new grant,
        // no admin action, and no warning. Same 403 message as an outright
        // missing scope — a caller must never be able to distinguish
        // "you don't have this" from "this isn't turned on yet".
        if (!ApiScopes::isAvailable($requiredScope)) {
            ApiResponse::error('Missing required scope: ' . $requiredScope, 403);
        }

        $key   = ApiKeyAuth::authenticate($requiredScope);
        $keyId = (int)$key['id'];

        return [
            'id'     => $keyId,
            'scopes' => $key['scopes'],
            'method' => $method,
        ];
    }
}
