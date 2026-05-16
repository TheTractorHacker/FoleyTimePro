<?php
/**
 * Push Notification API Endpoints
 * POST /api/v1/notifications/register - Register device for push notifications
 * DELETE /api/v1/notifications/unregister - Unregister device
 * GET /api/v1/notifications/preferences - Get notification preferences
 * PUT /api/v1/notifications/preferences - Update notification preferences
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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

// Get action from query string or URL path
$action = $_GET['action'] ?? '';

// Route to appropriate handler
switch ($action) {
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleRegisterDevice($userId);
        break;

    case 'unregister':
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleUnregisterDevice($userId);
        break;

    case 'preferences':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            handleGetPreferences($userId);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            handleUpdatePreferences($userId);
        } else {
            ApiResponse::error('Method not allowed', 405);
        }
        break;

    default:
        ApiResponse::error('Invalid endpoint', 404);
}

/**
 * Register device for push notifications
 */
function handleRegisterDevice($userId)
{
    global $conn;

    $input = json_decode(file_get_contents('php://input'), true);

    // Validate input
    $errors = [];

    if (!isset($input['device_token']) || empty($input['device_token'])) {
        $errors['device_token'] = 'Device token is required';
    }

    if (!isset($input['platform']) || !in_array($input['platform'], ['ios', 'android', 'web'])) {
        $errors['platform'] = 'Valid platform is required (ios, android, web)';
    }

    if (!empty($errors)) {
        ApiResponse::validationError($errors);
    }

    $deviceToken = $input['device_token'];
    $platform = $input['platform'];
    $deviceName = $input['device_name'] ?? null;

    // Check if token already exists for this user
    $stmt = $conn->prepare("
        SELECT ID FROM device_tokens
        WHERE UserID = ? AND DeviceToken = ?
    ");
    $stmt->bind_param("is", $userId, $deviceToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Update existing token
        $stmt = $conn->prepare("
            UPDATE device_tokens
            SET Platform = ?, DeviceName = ?, LastUsed = NOW(), IsActive = 1
            WHERE UserID = ? AND DeviceToken = ?
        ");
        $stmt->bind_param("ssis", $platform, $deviceName, $userId, $deviceToken);
        $stmt->execute();

        ApiResponse::success(null, 'Device token updated successfully');
    } else {
        // Insert new token
        $stmt = $conn->prepare("
            INSERT INTO device_tokens (UserID, DeviceToken, Platform, DeviceName, CreatedAt, LastUsed, IsActive)
            VALUES (?, ?, ?, ?, NOW(), NOW(), 1)
        ");
        $stmt->bind_param("isss", $userId, $deviceToken, $platform, $deviceName);

        if (!$stmt->execute()) {
            ApiResponse::serverError('Failed to register device token');
        }

        ApiResponse::success(null, 'Device registered successfully', 201);
    }
}

/**
 * Unregister device from push notifications
 */
function handleUnregisterDevice($userId)
{
    global $conn;

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['device_token']) || empty($input['device_token'])) {
        ApiResponse::validationError(['device_token' => 'Device token is required']);
    }

    $deviceToken = $input['device_token'];

    // Mark device as inactive
    $stmt = $conn->prepare("
        UPDATE device_tokens
        SET IsActive = 0
        WHERE UserID = ? AND DeviceToken = ?
    ");
    $stmt->bind_param("is", $userId, $deviceToken);

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to unregister device');
    }

    if ($stmt->affected_rows === 0) {
        ApiResponse::notFound('Device token not found');
    }

    ApiResponse::success(null, 'Device unregistered successfully');
}

/**
 * Get notification preferences
 */
function handleGetPreferences($userId)
{
    global $conn;

    // Check if user has notification preferences
    $stmt = $conn->prepare("
        SELECT ClockReminders, TimeOffUpdates, ShiftChanges, GeneralAnnouncements
        FROM notification_preferences
        WHERE UserID = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Return default preferences
        $preferences = [
            'clock_reminders' => true,
            'timeoff_updates' => true,
            'shift_changes' => true,
            'general_announcements' => true
        ];
    } else {
        $row = $result->fetch_assoc();
        $preferences = [
            'clock_reminders' => (bool)$row['ClockReminders'],
            'timeoff_updates' => (bool)$row['TimeOffUpdates'],
            'shift_changes' => (bool)$row['ShiftChanges'],
            'general_announcements' => (bool)$row['GeneralAnnouncements']
        ];
    }

    ApiResponse::success(['preferences' => $preferences]);
}

/**
 * Update notification preferences
 */
function handleUpdatePreferences($userId)
{
    global $conn;

    $input = json_decode(file_get_contents('php://input'), true);

    // Default values if not provided
    $clockReminders = isset($input['clock_reminders']) ? (int)$input['clock_reminders'] : 1;
    $timeoffUpdates = isset($input['timeoff_updates']) ? (int)$input['timeoff_updates'] : 1;
    $shiftChanges = isset($input['shift_changes']) ? (int)$input['shift_changes'] : 1;
    $generalAnnouncements = isset($input['general_announcements']) ? (int)$input['general_announcements'] : 1;

    // Check if preferences exist
    $stmt = $conn->prepare("SELECT ID FROM notification_preferences WHERE UserID = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Update existing preferences
        $stmt = $conn->prepare("
            UPDATE notification_preferences
            SET ClockReminders = ?, TimeOffUpdates = ?, ShiftChanges = ?, GeneralAnnouncements = ?
            WHERE UserID = ?
        ");
        $stmt->bind_param("iiiii", $clockReminders, $timeoffUpdates, $shiftChanges, $generalAnnouncements, $userId);
    } else {
        // Insert new preferences
        $stmt = $conn->prepare("
            INSERT INTO notification_preferences (UserID, ClockReminders, TimeOffUpdates, ShiftChanges, GeneralAnnouncements)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiiii", $userId, $clockReminders, $timeoffUpdates, $shiftChanges, $generalAnnouncements);
    }

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to update notification preferences');
    }

    ApiResponse::success(null, 'Notification preferences updated successfully');
}
