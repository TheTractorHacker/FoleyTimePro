<?php
/**
 * Shifts API Endpoints
 * GET /api/v1/shifts - Get all shifts (admin only)
 * GET /api/v1/shifts/my - Get user's assigned shifts
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method not allowed', 405);
}

// Get action from query string or URL
$action = $_GET['action'] ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Route to appropriate handler
switch ($action) {
    case 'list':
    case 'shifts':
        handleGetAllShifts($user);
        break;

    case 'my':
        handleGetMyShifts($userId);
        break;

    default:
        ApiResponse::error('Invalid endpoint', 404);
}

/**
 * Get all shifts (admin only)
 */
function handleGetAllShifts($user)
{
    global $conn;

    // Check if user is admin
    $stmtAdmin = $GLOBALS['conn']->prepare("SELECT ID FROM admins WHERE username = ?");
    $stmtAdmin->bind_param("s", $user['email']);
    $stmtAdmin->execute();
    $isAdmin = $stmtAdmin->get_result()->num_rows > 0;

    if (!$isAdmin) {
        ApiResponse::forbidden('Admin access required');
    }

    // Get all shifts
    $stmt = $conn->prepare("SELECT ShiftID, ShiftName, StartTime, EndTime, DaysOfWeek, LunchDuration, LunchStartTime, IsActive FROM shifts ORDER BY ShiftName");
    $stmt->execute();
    $result = $stmt->get_result();

    $shifts = [];
    while ($row = $result->fetch_assoc()) {
        $shifts[] = [
            'id' => $row['ShiftID'],
            'name' => $row['ShiftName'],
            'start_time' => $row['StartTime'],
            'end_time' => $row['EndTime'],
            'days' => json_decode($row['DaysOfWeek'], true),
            'lunch_duration' => $row['LunchDuration'],
            'lunch_start_time' => $row['LunchStartTime'],
            'is_active' => (bool)$row['IsActive']
        ];
    }

    ApiResponse::success(['shifts' => $shifts]);
}

/**
 * Get user's assigned shifts
 */
function handleGetMyShifts($userId)
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT s.ShiftID, s.ShiftName, s.StartTime, s.EndTime, s.DaysOfWeek, s.LunchDuration, s.LunchStartTime
        FROM user_shifts us
        JOIN shifts s ON us.ShiftID = s.ShiftID
        WHERE us.UserID = ? AND s.IsActive = 1
        ORDER BY s.ShiftName
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $shifts = [];
    while ($row = $result->fetch_assoc()) {
        $shifts[] = [
            'id' => $row['ShiftID'],
            'name' => $row['ShiftName'],
            'start_time' => $row['StartTime'],
            'end_time' => $row['EndTime'],
            'days' => json_decode($row['DaysOfWeek'], true),
            'lunch_duration' => $row['LunchDuration'],
            'lunch_start_time' => $row['LunchStartTime']
        ];
    }

    ApiResponse::success(['shifts' => $shifts]);
}
