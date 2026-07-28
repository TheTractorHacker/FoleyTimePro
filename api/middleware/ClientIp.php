<?php
/**
 * Shared client-IP resolver.
 *
 * X-Forwarded-For / X-Real-IP are attacker-controlled headers unless the
 * REMOTE_ADDR of the current request is one of our configured trusted
 * reverse proxies. When we're facing the internet directly (no proxy in
 * front) or when the request is coming from an untrusted hop, honoring
 * those headers lets attackers:
 *   - bypass per-IP rate limits by rotating the header, and
 *   - poison per-IP counters against a victim's real address to lock
 *     that address out for the throttle window (targeted DoS).
 *
 * TRUSTED_PROXIES is a comma-separated env var. If unset it defaults to
 * loopback so a bare "just installed on one box" deploy is safe by default.
 *
 * Callers:
 *   - api/v1/auth.php::handleLogin           (per-IP login lockout key)
 *   - api/v1/punch.php::getClientIp          (audit-column IPAddress*)
 *   - api/middleware/ApiKeyAuth::clientIp    (existing — kept for compat)
 *   - kiosk / clock_action.php               (audit-column IPAddress*)
 */

if (!function_exists('trusted_client_ip')) {
    /**
     * Return the caller's real IP, honoring reverse-proxy headers only when
     * the direct peer (REMOTE_ADDR) is in the TRUSTED_PROXIES allowlist.
     *
     * Never returns null — falls back to REMOTE_ADDR, then 'unknown'.
     */
    function trusted_client_ip(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $trusted = $_ENV['TRUSTED_PROXIES'] ?? '127.0.0.1,::1';
        $trustedList = array_filter(array_map('trim', explode(',', $trusted)));

        if (!in_array($remoteAddr, $trustedList, true)) {
            return $remoteAddr;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // XFF is a comma-list; the left-most entry is the original client
            // as reported by the first proxy — trust it only because we've
            // already gated on REMOTE_ADDR being a trusted proxy.
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }

        return $_SERVER['HTTP_X_REAL_IP'] ?? $remoteAddr;
    }
}
