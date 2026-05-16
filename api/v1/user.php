<?php
/**
 * User Profile API Endpoints
 * GET /api/v1/user/profile - Get user profile
 * PUT /api/v1/user/update - Update user profile
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../auth/db.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/ApiResponse.php';

// Authenticate user
$user = AuthMiddleware::authenticate();
$userId = $user['userId'];

// Get action from query string or URL
$action = $_GET['action'] ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Route to appropriate handler
switch ($action) {
    case 'profile':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleGetProfile($userId);
        break;

    case 'update':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleUpdateProfile($userId);
        break;

    case 'change_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') ApiResponse::error('Method not allowed', 405);
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $newPw = trim($input['password'] ?? '');
        if (strlen($newPw) < 6) ApiResponse::error('Password must be at least 6 characters', 400);
        $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
        $s = $conn->prepare("UPDATE users SET Pass=? WHERE ID=?");
        $s->bind_param("si", $hash, $userId);
        $s->execute();
        ApiResponse::success([], 'Password updated successfully');
        break;

    default:
        ApiResponse::error('Invalid endpoint', 404);
}

/**
 * Get user profile
 */
function handleGetProfile($userId)
{
    global $conn;

    $stmt = $conn->prepare("SELECT ID, Email, FirstName, LastName, PhoneNumber, JobTitle, Office, ProfilePhoto, ThemePref, TwoFAEnabled, LockOut FROM users WHERE ID = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        ApiResponse::notFound('User not found');
    }

    $user = $result->fetch_assoc();

    // Get office name if office ID exists
    $officeName = null;
    if ($user['Office']) {
        $stmtOffice = $conn->prepare("SELECT OfficeName FROM offices WHERE OfficeID = ?");
        $stmtOffice->bind_param("i", $user['Office']);
        $stmtOffice->execute();
        $officeResult = $stmtOffice->get_result();
        if ($officeRow = $officeResult->fetch_assoc()) {
            $officeName = $officeRow['OfficeName'];
        }
    }

    // Get assigned shifts
    $shifts = [];
    $stmtShifts = $conn->prepare("
        SELECT s.ShiftID, s.ShiftName, s.StartTime, s.EndTime, s.DaysOfWeek
        FROM user_shifts us
        JOIN shifts s ON us.ShiftID = s.ShiftID
        WHERE us.UserID = ? AND s.IsActive = 1
    ");
    $stmtShifts->bind_param("i", $userId);
    $stmtShifts->execute();
    $shiftsResult = $stmtShifts->get_result();

    while ($shift = $shiftsResult->fetch_assoc()) {
        $shifts[] = [
            'id' => $shift['ShiftID'],
            'name' => $shift['ShiftName'],
            'start_time' => $shift['StartTime'],
            'end_time' => $shift['EndTime'],
            'days' => json_decode($shift['DaysOfWeek'], true)
        ];
    }

    ApiResponse::success([
        'id' => $user['ID'],
        'email' => $user['Email'],
        'first_name' => $user['FirstName'],
        'last_name' => $user['LastName'],
        'phone' => $user['PhoneNumber'],
        'job_title' => $user['JobTitle'],
        'office' => [
            'id' => $user['Office'],
            'name' => $officeName
        ],
        'profile_photo' => $user['ProfilePhoto'],
        'theme_preference' => $user['ThemePref'],
        'two_fa_enabled' => (bool)$user['TwoFAEnabled'],
        'is_locked' => (bool)$user['LockOut'],
        'shifts' => $shifts
    ]);
}

/**
 * Update user profile
 */
function handleUpdateProfile($userId)
{
    global $conn;

    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate input
    $allowedFields = ['first_name', 'last_name', 'phone', 'theme_preference'];
    $updates = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $dbField = match($field) {
                'first_name' => 'FirstName',
                'last_name' => 'LastName',
                'phone' => 'PhoneNumber',
                'theme_preference' => 'ThemePref',
            };

            $updates[] = "$dbField = ?";
            $params[] = $input[$field];
            $types .= 's';
        }
    }

    if (empty($updates)) {
        ApiResponse::validationError(['fields' => 'No valid fields to update']);
    }

    // Build and execute update query
    $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE ID = ?";
    $params[] = $userId;
    $types .= 'i';

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to update profile');
    }

    if ($stmt->affected_rows === 0) {
        ApiResponse::error('No changes made or user not found', 400);
    }

    ApiResponse::success(null, 'Profile updated successfully');
}
