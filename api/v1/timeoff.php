<?php
/**
 * Time-Off Request API Endpoints
 * GET /api/v1/timeoff - Get user's time-off requests
 * POST /api/v1/timeoff/request - Submit new time-off request
 * PUT /api/v1/timeoff/{id}/cancel - Cancel pending request
 * GET /api/v1/timeoff/all - Get all requests (admin only)
 * PUT /api/v1/timeoff/{id}/approve - Approve request (admin only)
 * PUT /api/v1/timeoff/{id}/reject - Reject request (admin only)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../auth/db.php';
require_once __DIR__ . '/../../functions/leave_balance.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/ApiResponse.php';
require_once __DIR__ . '/../services/NotificationService.php';

// Authenticate user
$user = AuthMiddleware::authenticate();
$userId = $user['userId'];

// Check if user is admin
function isAdmin($email) {
    global $conn;
    $stmt = $conn->prepare("SELECT ID FROM admins WHERE username = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// Get action from query string or URL path
$requestUri = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', parse_url($requestUri, PHP_URL_PATH));
$action = $_GET['action'] ?? end($pathParts);

// Extract ID from URL if present (e.g., /timeoff/123/approve)
$requestId = null;
if (is_numeric($action)) {
    $requestId = (int)$action;
    $action = $pathParts[count($pathParts) - 1] ?? 'view';
}

// Route to appropriate handler
switch ($action) {
    case 'timeoff':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleGetMyRequests($userId);
        break;

    case 'balances':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleGetMyBalances($userId);
        break;

    case 'request':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleSubmitRequest($userId);
        break;

    case 'cancel':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            ApiResponse::error('Method not allowed', 405);
        }
        handleCancelRequest($userId, $requestId);
        break;

    case 'all':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            ApiResponse::error('Method not allowed', 405);
        }
        if (!isAdmin($user['email'])) {
            ApiResponse::forbidden('Admin access required');
        }
        handleGetAllRequests();
        break;

    case 'approve':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            ApiResponse::error('Method not allowed', 405);
        }
        if (!isAdmin($user['email'])) {
            ApiResponse::forbidden('Admin access required');
        }
        handleApproveRequest($user['email'], $requestId);
        break;

    case 'reject':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            ApiResponse::error('Method not allowed', 405);
        }
        if (!isAdmin($user['email'])) {
            ApiResponse::forbidden('Admin access required');
        }
        handleRejectRequest($user['email'], $requestId);
        break;

    default:
        ApiResponse::error('Invalid endpoint', 404);
}

/**
 * Get user's time-off requests
 */
function handleGetMyRequests($userId)
{
    global $conn;

    // Get filter parameters
    $status = $_GET['status'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    $query = "SELECT ID, StartDate, EndDate, RequestType, Reason, Status, RequestedAt, ReviewedAt, ReviewNotes
              FROM time_off_requests
              WHERE EmployeeID = ?";

    $params = [$userId];
    $types = "i";

    if ($status && in_array($status, ['Pending', 'Approved', 'Rejected'])) {
        $query .= " AND Status = ?";
        $params[] = $status;
        $types .= "s";
    }

    $query .= " ORDER BY RequestedAt DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = [
            'id' => $row['ID'],
            'start_date' => $row['StartDate'],
            'end_date' => $row['EndDate'],
            'type' => $row['RequestType'],
            'reason' => $row['Reason'],
            'status' => $row['Status'],
            'requested_at' => $row['RequestedAt'],
            'reviewed_at' => $row['ReviewedAt'],
            'review_notes' => $row['ReviewNotes']
        ];
    }

    ApiResponse::success(['requests' => $requests]);
}

/**
 * Get the current employee's leave balances for the current calendar year.
 *
 * Reuses functions/leave_balance.php's getAllLeaveBalances() — the same ledger
 * read admin/time_off_requests.php trusts when it deducts on approval via
 * deductLeaveBalance() — rather than re-deriving the accrued/used/available
 * math here. `available` is already floored at 0 by that helper.
 *
 * Note for whoever wires this up further: AccruedHours is not currently
 * populated by anything (no accrual job exists yet, only manual/seeded rows),
 * so `available` will read 0 for most employees today even though
 * leave_accrual_policy defines a real HoursPerYear per type. That's a
 * pre-existing gap in the accrual side of this feature, not something this
 * endpoint papers over or fixes.
 */
function handleGetMyBalances($userId)
{
    global $conn;

    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $balances = getAllLeaveBalances($conn, $userId, $year);

    $out = [];
    foreach ($balances as $type => $b) {
        $out[] = [
            'type'      => $type,
            'accrued'   => $b['accrued'],
            'used'      => $b['used'],
            'carryover' => $b['carryover'],
            'available' => $b['available'],
        ];
    }

    ApiResponse::success(['year' => $year, 'balances' => $out]);
}

/**
 * Submit new time-off request
 */
function handleSubmitRequest($userId)
{
    global $conn;

    $input = json_decode(file_get_contents('php://input'), true);

    // Validate input
    $errors = [];

    if (!isset($input['start_date']) || empty($input['start_date'])) {
        $errors['start_date'] = 'Start date is required';
    }

    if (!isset($input['end_date']) || empty($input['end_date'])) {
        $errors['end_date'] = 'End date is required';
    }

    if (!isset($input['type']) || !in_array($input['type'], ['Vacation', 'Sick', 'Personal', 'Other', 'Unpaid'])) {
        $errors['type'] = 'Valid request type is required (Vacation, Sick, Personal, Other, Unpaid)';
    }

    if (!empty($errors)) {
        ApiResponse::validationError($errors);
    }

    $startDate = $input['start_date'];
    $endDate = $input['end_date'];
    $type = $input['type'];
    $reason = $input['reason'] ?? '';

    // Validate dates
    $start = strtotime($startDate);
    $end = strtotime($endDate);

    if (!$start || !$end) {
        ApiResponse::validationError(['dates' => 'Invalid date format. Use YYYY-MM-DD']);
    }

    if ($end < $start) {
        ApiResponse::validationError(['dates' => 'End date must be after start date']);
    }

    // Check for overlapping requests — ranges [A,B] and [C,D] overlap when A<=D AND B>=C
    $stmt = $conn->prepare("
        SELECT ID FROM time_off_requests
        WHERE EmployeeID = ?
        AND Status IN ('Pending', 'Approved')
        AND StartDate <= ? AND EndDate >= ?
    ");
    $stmt->bind_param("iss", $userId, $endDate, $startDate);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        ApiResponse::error('You already have a time-off request for this date range', 409);
    }

    // Insert request
    $stmt = $conn->prepare("
        INSERT INTO time_off_requests (EmployeeID, StartDate, EndDate, RequestType, Reason, Status, RequestedAt)
        VALUES (?, ?, ?, ?, ?, 'Pending', NOW())
    ");
    $stmt->bind_param("issss", $userId, $startDate, $endDate, $type, $reason);

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to submit time-off request');
    }

    $requestId = $conn->insert_id;

    ApiResponse::success([
        'request_id' => $requestId,
        'status' => 'Pending'
    ], 'Time-off request submitted successfully', 201);
}

/**
 * Cancel pending request
 */
function handleCancelRequest($userId, $requestId)
{
    global $conn;

    if (!$requestId) {
        ApiResponse::validationError(['id' => 'Request ID is required']);
    }

    // Check if request exists and belongs to user
    $stmt = $conn->prepare("SELECT ID, Status FROM time_off_requests WHERE ID = ? AND EmployeeID = ?");
    $stmt->bind_param("ii", $requestId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        ApiResponse::notFound('Request not found');
    }

    $request = $result->fetch_assoc();

    if ($request['Status'] !== 'Pending') {
        ApiResponse::error('Only pending requests can be cancelled', 400);
    }

    // Delete the request
    $stmt = $conn->prepare("DELETE FROM time_off_requests WHERE ID = ?");
    $stmt->bind_param("i", $requestId);

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to cancel request');
    }

    ApiResponse::success(null, 'Request cancelled successfully');
}

/**
 * Get all time-off requests (admin only)
 */
function handleGetAllRequests()
{
    global $conn;

    // Get filter parameters
    $status = $_GET['status'] ?? null;
    $userId = $_GET['user_id'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $query = "SELECT tor.ID, tor.EmployeeID, u.FirstName, u.LastName, u.Email,
              tor.StartDate, tor.EndDate, tor.RequestType, tor.Reason,
              tor.Status, tor.RequestedAt, tor.ReviewedAt, tor.ReviewNotes,
              CONCAT(admin.username) as ReviewedByEmail
              FROM time_off_requests tor
              JOIN users u ON tor.EmployeeID = u.ID
              LEFT JOIN admins admin ON tor.ReviewedBy = admin.ID
              WHERE 1=1";

    $params = [];
    $types = "";

    if ($status && in_array($status, ['Pending', 'Approved', 'Rejected'])) {
        $query .= " AND tor.Status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if ($userId) {
        $query .= " AND tor.EmployeeID = ?";
        $params[] = (int)$userId;
        $types .= "i";
    }

    $query .= " ORDER BY tor.RequestedAt DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = [
            'id' => $row['ID'],
            'employee' => [
                'id' => $row['EmployeeID'],
                'name' => $row['FirstName'] . ' ' . $row['LastName'],
                'email' => $row['Email']
            ],
            'start_date' => $row['StartDate'],
            'end_date' => $row['EndDate'],
            'type' => $row['RequestType'],
            'reason' => $row['Reason'],
            'status' => $row['Status'],
            'requested_at' => $row['RequestedAt'],
            'reviewed_at' => $row['ReviewedAt'],
            'reviewed_by' => $row['ReviewedByEmail'],
            'review_notes' => $row['ReviewNotes']
        ];
    }

    ApiResponse::success(['requests' => $requests]);
}

/**
 * Approve time-off request (admin only)
 */
function handleApproveRequest($adminEmail, $requestId)
{
    global $conn;

    if (!$requestId) {
        ApiResponse::validationError(['id' => 'Request ID is required']);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $notes = $input['notes'] ?? '';

    // Resolve the ACTING admin (was: always the first admin in the table,
    // making ReviewedBy useless for audit).
    $stmtAdmin = $conn->prepare("SELECT ID FROM admins WHERE username = ?");
    $stmtAdmin->bind_param('s', $adminEmail);
    $stmtAdmin->execute();
    $adminId = $stmtAdmin->get_result()->fetch_assoc()['ID'] ?? null;
    $stmtAdmin->close();
    if ($adminId === null) ApiResponse::forbidden('Admin account not found');

    // Check if request exists and get employee ID and dates
    $stmt = $conn->prepare("
        SELECT ID, EmployeeID, StartDate, EndDate, Status
        FROM time_off_requests WHERE ID = ?
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        ApiResponse::notFound('Request not found');
    }

    $request = $result->fetch_assoc();

    if ($request['Status'] !== 'Pending') {
        ApiResponse::error('Only pending requests can be approved', 400);
    }

    // Update request
    $stmt = $conn->prepare("
        UPDATE time_off_requests
        SET Status = 'Approved', ReviewedAt = NOW(), ReviewedBy = ?, ReviewNotes = ?
        WHERE ID = ?
    ");
    $stmt->bind_param("isi", $adminId, $notes, $requestId);

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to approve request');
    }

    // Send push notification to employee
    $notificationService = new NotificationService($conn);
    $notificationService->sendTimeOffApproval(
        $request['EmployeeID'],
        date('M d', strtotime($request['StartDate'])),
        date('M d, Y', strtotime($request['EndDate']))
    );

    ApiResponse::success(null, 'Request approved successfully');
}

/**
 * Reject time-off request (admin only)
 */
function handleRejectRequest($adminEmail, $requestId)
{
    global $conn;

    if (!$requestId) {
        ApiResponse::validationError(['id' => 'Request ID is required']);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $notes = $input['notes'] ?? '';

    if (empty($notes)) {
        ApiResponse::validationError(['notes' => 'Rejection reason is required']);
    }

    // Resolve the ACTING admin (was: always the first admin in the table).
    $stmtAdmin = $conn->prepare("SELECT ID FROM admins WHERE username = ?");
    $stmtAdmin->bind_param('s', $adminEmail);
    $stmtAdmin->execute();
    $adminId = $stmtAdmin->get_result()->fetch_assoc()['ID'] ?? null;
    $stmtAdmin->close();
    if ($adminId === null) ApiResponse::forbidden('Admin account not found');

    // Check if request exists and get employee ID and dates
    $stmt = $conn->prepare("
        SELECT ID, EmployeeID, StartDate, EndDate, Status
        FROM time_off_requests WHERE ID = ?
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        ApiResponse::notFound('Request not found');
    }

    $request = $result->fetch_assoc();

    if ($request['Status'] !== 'Pending') {
        ApiResponse::error('Only pending requests can be rejected', 400);
    }

    // Update request
    $stmt = $conn->prepare("
        UPDATE time_off_requests
        SET Status = 'Rejected', ReviewedAt = NOW(), ReviewedBy = ?, ReviewNotes = ?
        WHERE ID = ?
    ");
    $stmt->bind_param("isi", $adminId, $notes, $requestId);

    if (!$stmt->execute()) {
        ApiResponse::serverError('Failed to reject request');
    }

    // Send push notification to employee
    $notificationService = new NotificationService($conn);
    $notificationService->sendTimeOffRejection(
        $request['EmployeeID'],
        date('M d', strtotime($request['StartDate'])),
        date('M d, Y', strtotime($request['EndDate'])),
        $notes
    );

    ApiResponse::success(null, 'Request rejected successfully');
}
