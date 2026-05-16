<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "Starting test...<br>";

try {
    echo "1. Starting session...<br>";
    session_start();
    $_SESSION['admin'] = true;
    $_SESSION['admin_id'] = 1;

    echo "2. Loading database...<br>";
    require_once '../auth/db.php';

    echo "3. Loading NotificationService...<br>";
    require_once '../api/services/NotificationService.php';

    echo "4. Testing NotificationService instantiation...<br>";
    $notificationService = new NotificationService($conn);

    echo "5. All includes successful!<br>";

    echo "6. Testing database query...<br>";
    $result = $conn->query("SELECT COUNT(*) as count FROM time_off_requests");
    $count = $result->fetch_assoc()['count'];
    echo "Found $count time-off requests<br>";

    echo "<br><strong>✓ All tests passed! The issue is elsewhere.</strong><br>";

} catch (Throwable $e) {
    echo "<br><strong>✗ Error found:</strong><br>";
    echo "Message: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
