<?php 
date_default_timezone_set('America/Chicago');
require_once __DIR__ . '/../auth/db.php';

header('Content-Type: application/json');

$empID = $_POST['EmployeeID'] ?? '';
$pin = $_POST['PIN'] ?? '';

if (!$empID || !$pin) {
    echo json_encode(['success' => false, 'message' => 'Missing credentials.']);
    exit;
}

// Prepare user lookup
$stmt = $conn->prepare("SELECT ID, Pass, ClockStatus FROM users WHERE ID = ?");
$stmt->bind_param("s", $empID);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    if (password_verify($pin, $user['Pass'])) {

        // Use a single optimized query for latest punch
        $punchStmt = $conn->prepare("
            SELECT TimeOUT, LunchEnd, LunchStart, TimeIN 
            FROM timepunches 
            WHERE EmployeeID = ? 
            ORDER BY Date DESC, TimeIN DESC 
            LIMIT 1
        ");
        $punchStmt->bind_param("s", $empID);
        $punchStmt->execute();
        $punchResult = $punchStmt->get_result();

        $time = 'N/A';
        if ($punch = $punchResult->fetch_assoc()) {
            foreach (['TimeOUT', 'LunchEnd', 'LunchStart', 'TimeIN'] as $field) {
                if (!empty($punch[$field])) {
                    $time = date("g:i A", strtotime($punch[$field]));
                    break;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'status' => $user['ClockStatus'] ?? 'Out',
            'time' => $time
        ]);
        exit;
    }
}

echo json_encode([
    'success' => false,
    'message' => 'Invalid PIN.'
]);