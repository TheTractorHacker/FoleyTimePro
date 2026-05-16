<?php
/**
 * Clock API Endpoints
 * POST /api/v1/clock/in - Clock in
 * POST /api/v1/clock/out - Clock out
 * GET /api/v1/clock/status - Get current clock status
 * GET /api/v1/clock/history - Get punch history
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../auth/db.php';
require_once __DIR__ . '/../../functions/geofence.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/ApiResponse.php';

// Authenticate user
$user = AuthMiddleware::authenticate();
$userId = $user['userId'];

// Get action from query string or URL
$action = $_GET['action'] ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Route to appropriate handler
switch ($action) {
    case 'in':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleClockIn($userId);
        break;

    case 'out':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleClockOut($userId);
        break;

    case 'status':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleClockStatus($userId);
        break;

    case 'history':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleClockHistory($userId);
        break;

    default:
        ApiResponse::error('Invalid endpoint', 404);
}

/**
 * Handle clock in
 */
function handleClockIn($userId)
{
    global $conn;

    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);

    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    $notes = $input['notes'] ?? '';

    // Check if user is already clocked in
    $stmt = $conn->prepare("SELECT ID FROM punches WHERE UserID = ? AND ClockOut IS NULL ORDER BY ClockIn DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        ApiResponse::error('You are already clocked in. Please clock out first.', 400);
    }

    // Check GPS requirement
    $gpsRequired = false;
    $stmtSettings = $conn->query("SELECT SettingValue FROM settings WHERE SettingName = 'EnforceGPS'");
    if ($stmtSettings && $row = $stmtSettings->fetch_assoc()) {
        $gpsRequired = (bool)$row['SettingValue'];
    }

    if ($gpsRequired && ($latitude === null || $longitude === null)) {
        ApiResponse::error('GPS location is required for clocking in', 400);
    }
    // Geofence check
    if ($latitude !== null && $longitude !== null) {
        $userOfficeStmt = $conn->prepare("SELECT OfficeID FROM users WHERE ID = ?");
        $userOfficeStmt->bind_param("i", $userId);
        $userOfficeStmt->execute();
        $userOfficeRow = $userOfficeStmt->get_result()->fetch_assoc();
        $officeId = $userOfficeRow['OfficeID'] ?? null;

        $geoResult = checkGeofence($conn, (float)$latitude, (float)$longitude, $officeId);
        if (!$geoResult['pass']) {
            $near = $geoResult['nearest'];
            $msg = $near
                ? "You are {$near['distance']}m from {$near['name']} (required within {$near['radius']}m)"
                : 'You are outside all required work zones';
            ApiResponse::error($msg, 403, ['geofence_fail' => true]);
        }
    }


    // Insert clock in record
    $stmt = $conn->prepare("INSERT INTO punches (UserID, ClockIn, ClockInLatitude, ClockInLongitude, Notes) VALUES (?, NOW(), ?, ?, ?)");
    $stmt->bind_param("idds", $userId, $latitude, $longitude, $notes);

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to clock in');
    }

    $punchId = $conn->insert_id;

    // Get the clock in time
    $stmt = $conn->prepare("SELECT ClockIn FROM punches WHERE ID = ?");
    $stmt->bind_param("i", $punchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $punch = $result->fetch_assoc();

    ApiResponse::success([
        'punch_id' => $punchId,
        'clock_in' => $punch['ClockIn'],
        'location' => [
            'latitude' => $latitude,
            'longitude' => $longitude
        ]
    ], 'Clocked in successfully', 201);
}

/**
 * Handle clock out
 */
function handleClockOut($userId)
{
    global $conn;

    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);

    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    $notes = $input['notes'] ?? '';

    // Find active punch
    $stmt = $conn->prepare("SELECT ID, ClockIn FROM punches WHERE UserID = ? AND ClockOut IS NULL ORDER BY ClockIn DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        ApiResponse::error('You are not currently clocked in', 400);
    }

    $punch = $result->fetch_assoc();
    $punchId = $punch['ID'];
    $clockIn = $punch['ClockIn'];

    // Check GPS requirement
    $gpsRequired = false;
    $stmtSettings = $conn->query("SELECT SettingValue FROM settings WHERE SettingName = 'EnforceGPS'");
    if ($stmtSettings && $row = $stmtSettings->fetch_assoc()) {
        $gpsRequired = (bool)$row['SettingValue'];
    }

    if ($gpsRequired && ($latitude === null || $longitude === null)) {
        ApiResponse::error('GPS location is required for clocking out', 400);
    }

    // Update punch with clock out
    if ($notes) {
        $stmt = $conn->prepare("UPDATE punches SET ClockOut = NOW(), ClockOutLatitude = ?, ClockOutLongitude = ?, Notes = CONCAT(Notes, IF(Notes = '', '', '\n'), ?) WHERE ID = ?");
        $stmt->bind_param("ddsi", $latitude, $longitude, $notes, $punchId);
    } else {
        $stmt = $conn->prepare("UPDATE punches SET ClockOut = NOW(), ClockOutLatitude = ?, ClockOutLongitude = ? WHERE ID = ?");
        $stmt->bind_param("ddi", $latitude, $longitude, $punchId);
    }

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to clock out');
    }

    // Get the updated punch
    $stmt = $conn->prepare("SELECT ClockIn, ClockOut, TIMESTAMPDIFF(SECOND, ClockIn, ClockOut) / 3600 as TotalHours FROM punches WHERE ID = ?");
    $stmt->bind_param("i", $punchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $updatedPunch = $result->fetch_assoc();

    ApiResponse::success([
        'punch_id' => $punchId,
        'clock_in' => $updatedPunch['ClockIn'],
        'clock_out' => $updatedPunch['ClockOut'],
        'total_hours' => round((float)$updatedPunch['TotalHours'], 2)
    ], 'Clocked out successfully');
}

/**
 * Get current clock status
 */
function handleClockStatus($userId)
{
    global $conn;

    // Find active punch
    $stmt = $conn->prepare("SELECT ID, ClockIn, TIMESTAMPDIFF(SECOND, ClockIn, NOW()) / 3600 as ElapsedHours FROM punches WHERE UserID = ? AND ClockOut IS NULL ORDER BY ClockIn DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        ApiResponse::success([
            'clocked_in' => false,
            'current_punch' => null
        ]);
    }

    $punch = $result->fetch_assoc();

    ApiResponse::success([
        'clocked_in' => true,
        'current_punch' => [
            'id' => $punch['ID'],
            'clock_in' => $punch['ClockIn'],
            'elapsed_hours' => round((float)$punch['ElapsedHours'], 2)
        ]
    ]);
}

/**
 * Get punch history
 */
function handleClockHistory($userId)
{
    global $conn;

    // Get query parameters
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    // Build query
    $query = "SELECT ID, DATE(ClockIn) as Date, TIME(ClockIn) as ClockInTime, TIME(ClockOut) as ClockOutTime,
              TIMESTAMPDIFF(SECOND, ClockIn, ClockOut) / 3600 as TotalHours, Notes
              FROM punches
              WHERE UserID = ?";

    $params = [$userId];
    $types = "i";

    if ($startDate) {
        $query .= " AND DATE(ClockIn) >= ?";
        $params[] = $startDate;
        $types .= "s";
    }

    if ($endDate) {
        $query .= " AND DATE(ClockIn) <= ?";
        $params[] = $endDate;
        $types .= "s";
    }

    $query .= " ORDER BY ClockIn DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $punches = [];
    $totalHours = 0;

    while ($row = $result->fetch_assoc()) {
        $hours = $row['TotalHours'] ? round((float)$row['TotalHours'], 2) : null;

        $punches[] = [
            'id' => $row['ID'],
            'date' => $row['Date'],
            'clock_in' => $row['ClockInTime'],
            'clock_out' => $row['ClockOutTime'],
            'total_hours' => $hours,
            'notes' => $row['Notes'] ?? ''
        ];

        if ($hours !== null) {
            $totalHours += $hours;
        }
    }

    // Calculate regular vs overtime (assuming 40 hours per week)
    $regularHours = min($totalHours, 40);
    $overtimeHours = max($totalHours - 40, 0);

    ApiResponse::success([
        'punches' => $punches,
        'summary' => [
            'total_hours' => round($totalHours, 2),
            'regular_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2)
        ]
    ]);
}
