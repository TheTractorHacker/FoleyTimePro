<?php
/**
 * API Authentication Middleware
 * Validates JWT tokens and protects API endpoints
 */

require_once __DIR__ . '/../auth/JWTHandler.php';

class AuthMiddleware
{
    /**
     * Authenticate request and extract user data from JWT
     */
    public static function authenticate()
    {
        // Get authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!$authHeader) {
            self::respondUnauthorized('No authorization token provided');
        }

        // Extract token from "Bearer <token>"
        $parts = explode(' ', $authHeader);
        if (count($parts) !== 2 || strtolower($parts[0]) !== 'bearer') {
            self::respondUnauthorized('Invalid authorization header format');
        }

        $token = $parts[1];

        // Validate token
        $result = JWTHandler::validateAccessToken($token);

        if (!$result['valid']) {
            $error = $result['error'] ?? 'Invalid token';
            $statusCode = isset($result['expired']) ? 401 : 403;
            self::respondError($error, $statusCode);
        }

        // Return user data from token
        return [
            'userId' => $result['data']->sub,
            'email' => $result['data']->email,
            'role' => $result['data']->role ?? 'user'
        ];
    }

    /**
     * Require specific role(s)
     */
    public static function requireRole($user, $allowedRoles)
    {
        if (!is_array($allowedRoles)) {
            $allowedRoles = [$allowedRoles];
        }

        if (!in_array($user['role'], $allowedRoles)) {
            self::respondForbidden('Insufficient permissions');
        }
    }

    /**
     * Respond with 401 Unauthorized
     */
    private static function respondUnauthorized($message)
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }

    /**
     * Respond with 403 Forbidden
     */
    private static function respondForbidden($message)
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }

    /**
     * Respond with custom error
     */
    private static function respondError($message, $statusCode = 400)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }
}
