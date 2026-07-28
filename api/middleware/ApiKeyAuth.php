<?php
/**
 * API Key Authentication Middleware
 *
 * Server-to-server auth for /api/external/*. Validates Bearer <key>,
 * enforces per-IP throttle on FAILED auth and per-key rate limit on
 * SUCCEEDED auth. Provides logRequest() for audit trail.
 */

require_once __DIR__ . '/ApiResponse.php';

class ApiKeyAuth
{
    private const RATE_LIMIT_PER_MINUTE = 60;
    private const IP_FAIL_LIMIT_PER_15_MIN = 20;

    /**
     * Validate the request and (optionally) require a scope.
     * Returns ['id' => int, 'scopes' => string[]] on success.
     * Exits with 401/403/429/500 on failure.
     */
    public static function authenticate(?string $requiredScope = null): array
    {
        global $conn;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
        header('Content-Type: application/json');

        $ip = self::clientIp();

        // Per-IP throttle on failed auth attempts (uses api_login_attempts table
        // that already exists for /api/v1/auth.php login attempts).
        self::checkIpFailureRate($ip);

        $authHeader = self::readAuthHeader();
        if ($authHeader === null) {
            self::rejectAuthFailure($ip, 'missing-header', 'Invalid API key');
        }

        $parts = explode(' ', trim($authHeader), 2);
        if (count($parts) !== 2 || strtolower($parts[0]) !== 'bearer') {
            self::rejectAuthFailure($ip, 'bad-format', 'Invalid API key');
        }
        $plaintext = trim($parts[1]);

        if (!preg_match('/^[a-f0-9]{64}$/', $plaintext)) {
            self::rejectAuthFailure($ip, 'bad-format', 'Invalid API key');
        }

        $prefix = substr($plaintext, 0, 8);

        $stmt = $conn->prepare(
            "SELECT ID, KeyHash, Scopes, RevokedAt, ExpiresAt
             FROM api_keys WHERE KeyPrefix = ? LIMIT 1"
        );
        $stmt->bind_param('s', $prefix);
        $stmt->execute();
        $key = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $pepper = $_ENV['API_KEY_PEPPER'] ?? '';
        if ($pepper === '') {
            error_log('[ApiKeyAuth] API_KEY_PEPPER is not set in .env');
            ApiResponse::error('Server misconfigured', 500);
        }

        $candidate = hash('sha256', $pepper . $plaintext);
        $known     = $key['KeyHash'] ?? str_repeat('0', 64);
        $match     = hash_equals($known, $candidate);

        if (!$match || !$key) {
            self::rejectAuthFailure($ip, 'hash-miss prefix=' . $prefix, 'Invalid API key');
        }

        // Revoked/expired: still a "valid" plaintext key (hash matched), so we
        // do NOT count this against the IP fail counter. But we uniformly return
        // "Invalid API key" so the response body doesn't leak lifecycle state.
        if ($key['RevokedAt'] !== null) {
            error_log('[ApiKeyAuth] Rejected revoked key: prefix=' . $prefix . ' ip=' . $ip);
            ApiResponse::error('Invalid API key', 401);
        }
        if ($key['ExpiresAt'] !== null && strtotime($key['ExpiresAt']) < time()) {
            error_log('[ApiKeyAuth] Rejected expired key: prefix=' . $prefix . ' ip=' . $ip);
            ApiResponse::error('Invalid API key', 401);
        }

        $scopes = array_values(array_filter(array_map('trim', explode(',', $key['Scopes'] ?? ''))));
        if ($requiredScope !== null && !in_array($requiredScope, $scopes, true)) {
            ApiResponse::error('Missing required scope: ' . $requiredScope, 403);
        }

        $keyId = (int)$key['ID'];

        // Per-key sliding-window rate limit
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) FROM api_key_requests
             WHERE KeyID = ? AND RequestedAt > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $countStmt->bind_param('i', $keyId);
        $countStmt->execute();
        $count = (int)$countStmt->get_result()->fetch_row()[0];
        $countStmt->close();

        if ($count >= self::RATE_LIMIT_PER_MINUTE) {
            header('Retry-After: 60');
            ApiResponse::error('Rate limit exceeded (60 requests/min per key)', 429);
        }

        $insStmt = $conn->prepare("INSERT INTO api_key_requests (KeyID) VALUES (?)");
        $insStmt->bind_param('i', $keyId);
        $insStmt->execute();
        $insStmt->close();

        if (mt_rand(1, 100) === 1) {
            $conn->query(
                "DELETE FROM api_key_requests
                 WHERE RequestedAt < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
        }

        $upStmt = $conn->prepare(
            "UPDATE api_keys SET LastUsedAt = NOW(), LastUsedIP = ? WHERE ID = ?"
        );
        $upStmt->bind_param('si', $ip, $keyId);
        $upStmt->execute();
        $upStmt->close();

        return ['id' => $keyId, 'scopes' => $scopes];
    }

    /**
     * Write an entry to api_key_audit_log. Safe to call from a shutdown
     * function — swallows all errors. Records both successful requests
     * (with a KeyID) AND failed authentications (KeyID NULL) so incident
     * responders can see enumeration / brute-force attempts against the
     * external API. The per-IP throttle in api_login_attempts limits how
     * fast an attacker can pile these up.
     */
    public static function logRequest(
        ?int $keyId,
        string $endpoint,
        string $method,
        int $statusCode,
        ?int $durationMs,
        ?string $ip,
        ?string $queryString
    ): void {
        global $conn;
        if (!$conn) return;

        try {
            if ($queryString !== null) {
                $queryString = substr($queryString, 0, 500);
            }
            // mysqli bind_param 'i' converts PHP null to 0, so build the
            // KeyID column expression conditionally to preserve SQL NULL
            // for failed-auth rows (FK is ON DELETE SET NULL).
            if ($keyId === null) {
                $stmt = $conn->prepare(
                    "INSERT INTO api_key_audit_log
                     (KeyID, Endpoint, Method, QueryString, IP, StatusCode, DurationMs)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?)"
                );
                if (!$stmt) return;
                $stmt->bind_param(
                    'ssssii',
                    $endpoint, $method, $queryString, $ip, $statusCode, $durationMs
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO api_key_audit_log
                     (KeyID, Endpoint, Method, QueryString, IP, StatusCode, DurationMs)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                if (!$stmt) return;
                $stmt->bind_param(
                    'issssii',
                    $keyId, $endpoint, $method, $queryString, $ip, $statusCode, $durationMs
                );
            }
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // audit-log failure must never bubble up
        }
    }

    /**
     * Real client IP. Only honors proxy headers when REMOTE_ADDR is in the
     * configured TRUSTED_PROXIES allowlist (default: 127.0.0.1, ::1) —
     * otherwise attackers could spoof LastUsedIP / audit-log IP.
     */
    public static function clientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!self::isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['HTTP_X_REAL_IP'] ?? $remoteAddr;
    }

    private static function isTrustedProxy(string $ip): bool
    {
        $trusted = $_ENV['TRUSTED_PROXIES'] ?? '127.0.0.1,::1';
        $list = array_map('trim', explode(',', $trusted));
        return in_array($ip, $list, true);
    }

    private static function readAuthHeader(): ?string
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (isset($h['Authorization'])) return $h['Authorization'];
            if (isset($h['authorization'])) return $h['authorization'];
        }
        return $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;
    }

    /** Reject with 429 if this IP has too many recent auth failures. */
    private static function checkIpFailureRate(string $ip): void
    {
        global $conn;
        $identifier = 'apikey:' . $ip;
        $since = date('Y-m-d H:i:s', strtotime('-15 minutes'));

        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM api_login_attempts
             WHERE identifier = ? AND attempted_at > ?"
        );
        $stmt->bind_param('ss', $identifier, $since);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();

        if ($count >= self::IP_FAIL_LIMIT_PER_15_MIN) {
            header('Retry-After: 900');
            ApiResponse::error('Too many failed authentication attempts. Try again later.', 429);
        }
    }

    private static function recordIpFailure(string $ip): void
    {
        global $conn;
        $identifier = 'apikey:' . $ip;
        $stmt = $conn->prepare("INSERT INTO api_login_attempts (identifier) VALUES (?)");
        $stmt->bind_param('s', $identifier);
        $stmt->execute();
        $stmt->close();
        // Piggy-back GC on failure path
        $conn->query("DELETE FROM api_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }

    /**
     * Record + throttle + reject in one call. Reason goes to error_log only;
     * $publicMessage is what the client sees (always uniform for the 401 case).
     */
    private static function rejectAuthFailure(string $ip, string $reason, string $publicMessage): void
    {
        error_log('[ApiKeyAuth] auth failure: ' . $reason . ' ip=' . $ip);
        self::recordIpFailure($ip);
        self::throttleFailedAuth();
        ApiResponse::error($publicMessage, 401);
    }

    /** Jittered delay on failed auth to raise the noise floor for timing attacks. */
    private static function throttleFailedAuth(): void
    {
        usleep(mt_rand(25000, 50000));
    }
}
