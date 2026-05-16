<?php
/**
 * Authentication API Endpoints
 * POST /api/v1/auth/login - User login
 * POST /api/v1/auth/refresh - Refresh access token
 * POST /api/v1/auth/logout - Logout (revoke refresh token)
 * POST /api/v1/auth/logout-all - Logout from all devices
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../auth/db.php';
require_once __DIR__ . '/../auth/JWTHandler.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/ApiResponse.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed', 405);
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

// Determine action from URL path
$requestUri = $_SERVER['REQUEST_URI'];
$action = basename(parse_url($requestUri, PHP_URL_PATH));

switch ($action) {
    case 'login':
        handleLogin($input);
        break;
    case 'refresh':
        handleRefresh($input);
        break;
    case 'logout':
        handleLogout($input);
        break;
    case 'webview-session':
        handleWebViewSession();
        break;
    case 'logout-all':
        handleLogoutAll();
        break;
    default:
        ApiResponse::error('Invalid endpoint', 404);
}

/**
 * Handle user login
 */
function handleLogin($input)
{
    global $conn;

    // Validate input
    if (!isset($input['email']) || !isset($input['password'])) {
        ApiResponse::validationError(['email' => 'Email and password are required']);
    }

    $email = $input['email'];
    $password = $input['password'];

    // Find user
    $stmt = $conn->prepare("SELECT ID, Email, Pass, FirstName, LastName, LockOut FROM users WHERE Email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        ApiResponse::error('Invalid credentials', 401);
    }

    // Check if user is locked out
    if ($user['LockOut']) {
        ApiResponse::error('Account is locked. Please contact your administrator.', 403);
    }

    // Verify password
    if (!password_verify($password, $user['Pass'])) {
        ApiResponse::error('Invalid credentials', 401);
    }

    // Check 2FA if enabled
    $twoFAEnabled = false;
    $stmt2FA = $conn->prepare("SELECT TwoFactorEnabled FROM two_factor_auth WHERE UserID = ?");
    $stmt2FA->bind_param("i", $user['ID']);
    $stmt2FA->execute();
    $result2FA = $stmt2FA->get_result();
    $twoFAData = $result2FA->fetch_assoc();

    if ($twoFAData && $twoFAData['TwoFactorEnabled']) {
        $twoFAEnabled = true;

        // If 2FA code not provided, require it
        if (!isset($input['twofa_code'])) {
            ApiResponse::error('2FA code required', 403, ['requires_2fa' => true]);
        }

        // Verify 2FA code
        require_once __DIR__ . '/../../vendor/autoload.php';

        $stmt2FASecret = $conn->prepare("SELECT TwoFactorSecret FROM two_factor_auth WHERE UserID = ?");
        $stmt2FASecret->bind_param("i", $user['ID']);
        $stmt2FASecret->execute();
        $result2FASecret = $stmt2FASecret->get_result();
        $secret = $result2FASecret->fetch_assoc()['TwoFactorSecret'];

        $totp = OTPHP\TOTP::createFromSecret($secret);
        $code = $input['twofa_code'];

        if (!$totp->verify($code, null, 2)) {
            // Try backup codes
            $stmtBackup = $conn->prepare("SELECT ID FROM backup_codes WHERE UserID = ? AND Code = ? AND IsUsed = 0");
            $hashedCode = hash('sha256', $code);
            $stmtBackup->bind_param("is", $user['ID'], $hashedCode);
            $stmtBackup->execute();
            $backupResult = $stmtBackup->get_result();

            if ($backupResult->num_rows === 0) {
                ApiResponse::error('Invalid 2FA code', 401);
            }

            // Mark backup code as used
            $backupCodeId = $backupResult->fetch_assoc()['ID'];
            $stmtMarkUsed = $conn->prepare("UPDATE backup_codes SET IsUsed = 1, UsedAt = NOW() WHERE ID = ?");
            $stmtMarkUsed->bind_param("i", $backupCodeId);
            $stmtMarkUsed->execute();
        }
    }

    // Generate tokens (no role in current schema, default to 'user')
    $role = 'user';
    $accessToken = JWTHandler::generateAccessToken($user['ID'], $user['Email'], $role);
    $refreshToken = JWTHandler::generateRefreshToken($user['ID'], $user['Email'], $role);

    // Return tokens and user info
    ApiResponse::success([
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type' => 'Bearer',
        'expires_in' => 900, // 15 minutes
        'user' => [
            'id' => $user['ID'],
            'email' => $user['Email'],
            'first_name' => $user['FirstName'],
            'last_name' => $user['LastName'],
            'role' => $role,
            'two_fa_enabled' => $twoFAEnabled
        ]
    ], 'Login successful');
}

/**
 * Handle token refresh
 */
function handleRefresh($input)
{
    global $conn;

    // Validate input
    if (!isset($input['refresh_token'])) {
        ApiResponse::validationError(['refresh_token' => 'Refresh token is required']);
    }

    $refreshToken = $input['refresh_token'];

    // Validate refresh token
    $result = JWTHandler::validateRefreshToken($refreshToken);

    if (!$result['valid']) {
        $error = $result['error'] ?? 'Invalid refresh token';
        ApiResponse::error($error, 401);
    }

    $userId = $result['data']->sub;
    $email = $result['data']->email;
    $role = $result['data']->role ?? 'user';

    // Verify user still exists and is not locked out
    $stmt = $conn->prepare("SELECT ID, LockOut FROM users WHERE ID = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userResult = $stmt->get_result();
    $user = $userResult->fetch_assoc();

    if (!$user || $user['LockOut']) {
        // Revoke the refresh token
        JWTHandler::revokeRefreshToken($refreshToken);
        ApiResponse::error('User account is locked or no longer exists', 403);
    }

    // Generate new access token
    $accessToken = JWTHandler::generateAccessToken($userId, $email, $role);

    // Return new access token
    ApiResponse::success([
        'access_token' => $accessToken,
        'token_type' => 'Bearer',
        'expires_in' => 900 // 15 minutes
    ], 'Token refreshed successfully');
}

/**
 * Handle logout (revoke single refresh token)
 */
function handleLogout($input)
{
    // Validate input
    if (!isset($input['refresh_token'])) {
        ApiResponse::validationError(['refresh_token' => 'Refresh token is required']);
    }

    $refreshToken = $input['refresh_token'];

    // Revoke the refresh token
    JWTHandler::revokeRefreshToken($refreshToken);

    ApiResponse::success(null, 'Logged out successfully');
}

/**
 * Handle logout from all devices (revoke all user's refresh tokens)
 */
function handleLogoutAll()
{
    // Authenticate the request
    $user = AuthMiddleware::authenticate();

    // Revoke all user's refresh tokens
    JWTHandler::revokeAllUserTokens($user['userId']);

    ApiResponse::success(null, 'Logged out from all devices successfully');
}

/**
 * Handle webview-session — creates a PHP session from a valid JWT so the
 * native Android app can authenticate the embedded WebView without a second login.
 * POST /api/v1/auth/webview-session   Authorization: Bearer <access_token>
 */
function handleWebViewSession()
{
    global $conn;

    $user = AuthMiddleware::authenticate(); // 401 on bad/missing token
    $userId = $user['userId'] ?? $user['sub'] ?? null;
    if (!$userId) { ApiResponse::error('Invalid token payload', 401); }

    // Look up user
    $stmt = $conn->prepare("SELECT ID, FirstName, LastName, Email FROM users WHERE ID = ? AND LockOut = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { ApiResponse::error('User not found or locked', 403); }

    // Session cookie params matching the web app
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',   // None so Android WebView can use it cross-context
    ]);
    session_start();
    session_regenerate_id(true);

    $_SESSION['EmployeeID'] = $row['ID'];
    $_SESSION['FirstName']  = $row['FirstName'];
    $_SESSION['Name']       = $row['FirstName'] . ' ' . $row['LastName'];

    // Expose Set-Cookie to the native client
    header('Access-Control-Expose-Headers: Set-Cookie');

    ApiResponse::success([
        'session_id' => session_id(),
        'redirect'   => '/user/dashboard.php',
    ], 'WebView session created');
}
