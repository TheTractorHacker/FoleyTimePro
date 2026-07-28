<?php
date_default_timezone_set('America/Chicago');
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/pin_helper.php';

// Bind session cookie flags before start so the punch-authorization ticket
// we set below is protected against XSS reads and non-HTTPS leakage.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json');

$empID = $_POST['EmployeeID'] ?? '';
$pin = $_POST['PIN'] ?? '';

if (!$empID || !$pin) {
    echo json_encode(['success' => false, 'message' => 'Missing credentials.']);
    exit;
}

// Prepare user lookup
$stmt = $conn->prepare("SELECT ID, UserPIN, ClockStatus FROM users WHERE ID = ?");
$stmt->bind_param("s", $empID);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    if (!empty($user['UserPIN']) && pin_verify_and_upgrade($conn, (int)$user['ID'], $pin, $user['UserPIN'])) {

        // Grant a short-lived server-side punch capability. clock_action.php
        // requires this before honoring any EmployeeID from a JSON body —
        // without it, the endpoint used to accept arbitrary EmployeeIDs.
        $_SESSION['punch_authorized_empID'] = (int)$user['ID'];
        $_SESSION['punch_authorized_until'] = time() + 60;


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