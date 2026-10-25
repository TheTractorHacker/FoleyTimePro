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
 * The number of proxies in front is an admin setting (settings.proxy_count).
 * Unset means 0, so a bare "just installed on one box" deploy is safe by
 * default.
 *
 * Callers:
 *   - api/v1/auth.php::handleLogin           (per-IP login lockout key)
 *   - api/v1/punch.php::getClientIp          (audit-column IPAddress*)
 *   - api/middleware/ApiKeyAuth::clientIp    (existing — kept for compat)
 *   - kiosk / clock_action.php               (audit-column IPAddress*)
 */

if (!function_exists('proxy_count')) {
    /**
     * Number of reverse proxies / load balancers between the internet and
     * this web server (admin > Settings > Network). 0 = clients connect
     * directly, so forwarding headers are never trusted.
     *
     * If the admin has not saved the setting yet, fall back to the legacy
     * TRUSTED_PROXIES env var: when set, assume one proxy; otherwise 0.
     */
    function proxy_count(): int
    {
        static $count = null;
        if ($count !== null) {
            return $count;
        }

        $count = isset($_ENV['TRUSTED_PROXIES']) && trim($_ENV['TRUSTED_PROXIES']) !== '' ? 1 : 0;

        global $conn;
        if ($conn instanceof mysqli) {
            try {
                $res = $conn->query("SELECT SettingValue FROM settings WHERE SettingKey = 'proxy_count' LIMIT 1");
                $row = $res ? $res->fetch_assoc() : null;
                if ($row !== null && $row['SettingValue'] !== null && ctype_digit((string)$row['SettingValue'])) {
                    $count = min(10, (int)$row['SettingValue']);
                }
            } catch (Throwable $e) {
                // settings table unreadable — keep the fallback
            }
        }
        return $count;
    }
}

if (!function_exists('trusted_client_ip')) {
    /**
     * Return the caller's real IP.
     *
     * Every proxy appends the address it received the request from, so the
     * trustworthy part of X-Forwarded-For is the right-hand end. With N
     * proxies in front, the chain [...XFF entries, REMOTE_ADDR] has the real
     * client N positions from the end; anything further left was supplied by
     * the client and is ignored, so rotating the header cannot spoof the IP.
     *
     * Never returns null — falls back to REMOTE_ADDR, then 'unknown'.
     */
    function trusted_client_ip(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $n = proxy_count();

        if ($n < 1 || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remoteAddr;
        }

        $chain = [];
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $part = trim($part);
            if (filter_var($part, FILTER_VALIDATE_IP)) {
                $chain[] = $part;
            }
        }
        $chain[] = $remoteAddr;

        $idx = count($chain) - 1 - $n;
        return $idx >= 0 ? $chain[$idx] : $remoteAddr;
    }
}
