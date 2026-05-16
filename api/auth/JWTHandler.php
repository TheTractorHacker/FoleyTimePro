<?php
/**
 * JWT Handler Class
 * Handles JWT token generation, validation, and refresh
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../auth/db.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class JWTHandler
{
    private static $secretKey;
    private static $refreshSecretKey;
    private static $algorithm = 'HS256';
    private static $accessTokenExpiry = 900; // 15 minutes
    private static $refreshTokenExpiry = 2592000; // 30 days

    /**
     * Initialize secret keys from environment or generate them
     */
    private static function initKeys()
    {
        if (self::$secretKey === null) {
            // Try to load from .env file
            $envFile = __DIR__ . '/../../.env';
            if (file_exists($envFile)) {
                $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
                $dotenv->load();
                self::$secretKey = $_ENV['JWT_SECRET_KEY'] ?? self::generateSecretKey();
                self::$refreshSecretKey = $_ENV['JWT_REFRESH_SECRET_KEY'] ?? self::generateSecretKey();
            } else {
                // Generate new keys if .env doesn't exist
                self::$secretKey = self::generateSecretKey();
                self::$refreshSecretKey = self::generateSecretKey();

                // Save to .env file
                $envContent = "JWT_SECRET_KEY=" . self::$secretKey . "\n";
                $envContent .= "JWT_REFRESH_SECRET_KEY=" . self::$refreshSecretKey . "\n";
                file_put_contents($envFile, $envContent, FILE_APPEND);
            }
        }
    }

    /**
     * Generate a secure random secret key
     */
    private static function generateSecretKey()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate access token for a user
     */
    public static function generateAccessToken($userId, $email, $role = 'user')
    {
        self::initKeys();

        $issuedAt = time();
        $expire = $issuedAt + self::$accessTokenExpiry;

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'iss' => $_SERVER['HTTP_HOST'] ?? 'timeclock-api',
            'sub' => $userId,
            'email' => $email,
            'role' => $role,
            'type' => 'access'
        ];

        return JWT::encode($payload, self::$secretKey, self::$algorithm);
    }

    /**
     * Generate refresh token for a user
     */
    public static function generateRefreshToken($userId, $email, $role = 'user')
    {
        self::initKeys();

        $issuedAt = time();
        $expire = $issuedAt + self::$refreshTokenExpiry;

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'iss' => $_SERVER['HTTP_HOST'] ?? 'timeclock-api',
            'sub' => $userId,
            'email' => $email,
            'role' => $role,
            'type' => 'refresh'
        ];

        $token = JWT::encode($payload, self::$refreshSecretKey, self::$algorithm);

        // Store refresh token in database
        self::storeRefreshToken($userId, $token, $expire);

        return $token;
    }

    /**
     * Validate and decode access token
     */
    public static function validateAccessToken($token)
    {
        self::initKeys();

        try {
            $decoded = JWT::decode($token, new Key(self::$secretKey, self::$algorithm));

            // Verify token type
            if (!isset($decoded->type) || $decoded->type !== 'access') {
                return ['valid' => false, 'error' => 'Invalid token type'];
            }

            return [
                'valid' => true,
                'data' => $decoded
            ];
        } catch (ExpiredException $e) {
            return ['valid' => false, 'error' => 'Token expired', 'expired' => true];
        } catch (SignatureInvalidException $e) {
            return ['valid' => false, 'error' => 'Invalid signature'];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Invalid token: ' . $e->getMessage()];
        }
    }

    /**
     * Validate and decode refresh token
     */
    public static function validateRefreshToken($token)
    {
        self::initKeys();

        try {
            $decoded = JWT::decode($token, new Key(self::$refreshSecretKey, self::$algorithm));

            // Verify token type
            if (!isset($decoded->type) || $decoded->type !== 'refresh') {
                return ['valid' => false, 'error' => 'Invalid token type'];
            }

            // Check if token exists in database and is not revoked
            if (!self::isRefreshTokenValid($decoded->sub, $token)) {
                return ['valid' => false, 'error' => 'Token revoked or not found'];
            }

            return [
                'valid' => true,
                'data' => $decoded
            ];
        } catch (ExpiredException $e) {
            // Remove expired token from database
            self::removeRefreshToken($token);
            return ['valid' => false, 'error' => 'Token expired', 'expired' => true];
        } catch (SignatureInvalidException $e) {
            return ['valid' => false, 'error' => 'Invalid signature'];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Invalid token: ' . $e->getMessage()];
        }
    }

    /**
     * Store refresh token in database
     */
    private static function storeRefreshToken($userId, $token, $expiresAt)
    {
        global $conn;

        $stmt = $conn->prepare("INSERT INTO api_tokens (UserID, Token, Type, ExpiresAt, CreatedAt) VALUES (?, ?, 'refresh', FROM_UNIXTIME(?), NOW())");
        $stmt->bind_param("isi", $userId, $token, $expiresAt);
        $stmt->execute();
    }

    /**
     * Check if refresh token is valid in database
     */
    private static function isRefreshTokenValid($userId, $token)
    {
        global $conn;

        $stmt = $conn->prepare("SELECT ID FROM api_tokens WHERE UserID = ? AND Token = ? AND Type = 'refresh' AND IsRevoked = 0 AND ExpiresAt > NOW()");
        $stmt->bind_param("is", $userId, $token);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->num_rows > 0;
    }

    /**
     * Remove refresh token from database
     */
    public static function removeRefreshToken($token)
    {
        global $conn;

        $stmt = $conn->prepare("DELETE FROM api_tokens WHERE Token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
    }

    /**
     * Revoke refresh token (mark as revoked instead of deleting)
     */
    public static function revokeRefreshToken($token)
    {
        global $conn;

        $stmt = $conn->prepare("UPDATE api_tokens SET IsRevoked = 1 WHERE Token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
    }

    /**
     * Revoke all refresh tokens for a user (logout from all devices)
     */
    public static function revokeAllUserTokens($userId)
    {
        global $conn;

        $stmt = $conn->prepare("UPDATE api_tokens SET IsRevoked = 1 WHERE UserID = ? AND Type = 'refresh'");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
    }

    /**
     * Clean up expired tokens from database (should be run periodically)
     */
    public static function cleanupExpiredTokens()
    {
        global $conn;

        $stmt = $conn->prepare("DELETE FROM api_tokens WHERE ExpiresAt < NOW()");
        $stmt->execute();

        return $stmt->affected_rows;
    }
}
