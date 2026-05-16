<?php
/**
 * Test script for push notification infrastructure
 * Run: php api/test_notifications.php
 */

require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/services/NotificationService.php';

echo "===========================================\n";
echo "Push Notification Infrastructure Test\n";
echo "===========================================\n\n";

$errors = [];
$success = 0;

// Test 1: Check database tables
echo "1. Checking database tables...\n";
$tables = ['notification_preferences', 'notification_logs', 'device_tokens'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "   ✓ Table '$table' exists\n";
        $success++;
    } else {
        echo "   ✗ Table '$table' NOT found\n";
        $errors[] = "Missing table: $table";
    }
}

// Test 2: Check settings
echo "\n2. Checking notification settings...\n";
$result = $conn->query("
    SELECT SettingKey, SettingValue FROM settings
    WHERE SettingKey IN ('fcm_server_key', 'push_notifications_enabled')
");

if ($result->num_rows === 2) {
    while ($row = $result->fetch_assoc()) {
        $value = !empty($row['SettingValue']) ? $row['SettingValue'] : '(empty)';
        echo "   ✓ {$row['SettingKey']}: $value\n";
        $success++;
    }
} else {
    echo "   ✗ Settings not found\n";
    $errors[] = "Missing notification settings";
}

// Test 3: Check NotificationService class
echo "\n3. Checking NotificationService class...\n";
if (class_exists('NotificationService')) {
    echo "   ✓ NotificationService class loaded\n";
    $success++;

    // Test instantiation
    try {
        $service = new NotificationService($conn);
        echo "   ✓ NotificationService instantiated successfully\n";
        $success++;

        // Check methods exist
        $methods = [
            'sendToUser',
            'sendToMultipleUsers',
            'sendTimeOffApproval',
            'sendTimeOffRejection',
            'sendShiftChange',
            'sendClockReminder',
            'sendAnnouncement',
            'shouldSendNotification'
        ];

        foreach ($methods as $method) {
            if (method_exists($service, $method)) {
                echo "   ✓ Method $method() exists\n";
                $success++;
            } else {
                echo "   ✗ Method $method() NOT found\n";
                $errors[] = "Missing method: $method";
            }
        }
    } catch (Exception $e) {
        echo "   ✗ Error instantiating NotificationService: " . $e->getMessage() . "\n";
        $errors[] = "NotificationService instantiation failed";
    }
} else {
    echo "   ✗ NotificationService class NOT found\n";
    $errors[] = "Missing NotificationService class";
}

// Test 4: Check API endpoints
echo "\n4. Checking notification API files...\n";
$apiFile = __DIR__ . '/v1/notifications.php';
if (file_exists($apiFile)) {
    echo "   ✓ API endpoint file exists\n";
    $success++;

    // Check for required functions
    $content = file_get_contents($apiFile);
    $functions = [
        'handleRegisterDevice',
        'handleUnregisterDevice',
        'handleGetPreferences',
        'handleUpdatePreferences'
    ];

    foreach ($functions as $func) {
        if (strpos($content, "function $func") !== false) {
            echo "   ✓ Function $func found\n";
            $success++;
        } else {
            echo "   ✗ Function $func NOT found\n";
            $errors[] = "Missing API function: $func";
        }
    }
} else {
    echo "   ✗ API endpoint file NOT found\n";
    $errors[] = "Missing notifications.php API file";
}

// Test 5: Check .htaccess routes
echo "\n5. Checking .htaccess routes...\n";
$htaccess = file_get_contents(__DIR__ . '/.htaccess');
$routes = [
    'notifications/register',
    'notifications/unregister',
    'notifications/preferences'
];

foreach ($routes as $route) {
    if (strpos($htaccess, $route) !== false) {
        echo "   ✓ Route /$route configured\n";
        $success++;
    } else {
        echo "   ✗ Route /$route NOT found\n";
        $errors[] = "Missing .htaccess route: $route";
    }
}

// Test 6: Test database operations
echo "\n6. Testing database operations...\n";

// Test user for demonstration
$result = $conn->query("SELECT ID FROM users LIMIT 1");
if ($result->num_rows > 0) {
    $userId = $result->fetch_assoc()['ID'];

    // Test notification preferences insert
    $stmt = $conn->prepare("
        INSERT INTO notification_preferences (UserID, ClockReminders, TimeOffUpdates, ShiftChanges, GeneralAnnouncements)
        VALUES (?, 1, 1, 1, 1)
        ON DUPLICATE KEY UPDATE ClockReminders = 1
    ");
    $stmt->bind_param("i", $userId);

    if ($stmt->execute()) {
        echo "   ✓ Can insert/update notification preferences\n";
        $success++;

        // Test retrieval
        $stmt = $conn->query("SELECT * FROM notification_preferences WHERE UserID = $userId");
        if ($stmt->num_rows > 0) {
            echo "   ✓ Can query notification preferences\n";
            $success++;
        }
    } else {
        echo "   ✗ Failed to insert notification preferences\n";
        $errors[] = "Database insert failed";
    }

    // Test notification log
    $stmt = $conn->prepare("
        INSERT INTO notification_logs (DeviceCount, Title, Body, Data, Status)
        VALUES (1, 'Test', 'Test notification', '{}', 'sent')
    ");

    if ($stmt->execute()) {
        echo "   ✓ Can insert notification logs\n";
        $success++;

        // Clean up test data
        $conn->query("DELETE FROM notification_logs WHERE Title = 'Test'");
    } else {
        echo "   ✗ Failed to insert notification log\n";
        $errors[] = "Notification log insert failed";
    }
} else {
    echo "   ⚠ No users found for testing\n";
}

// Summary
echo "\n===========================================\n";
echo "Test Summary\n";
echo "===========================================\n";
echo "✓ Successful tests: $success\n";
echo "✗ Errors: " . count($errors) . "\n";

if (count($errors) > 0) {
    echo "\nErrors found:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    echo "\n❌ Some tests failed. Please review above.\n";
    exit(1);
} else {
    echo "\n🎉 All tests passed! Push notification infrastructure is ready.\n\n";
    echo "Next steps:\n";
    echo "1. Configure FCM server key in settings:\n";
    echo "   UPDATE settings SET SettingValue = 'YOUR_KEY' WHERE SettingKey = 'fcm_server_key';\n";
    echo "2. Enable push notifications:\n";
    echo "   UPDATE settings SET SettingValue = '1' WHERE SettingKey = 'push_notifications_enabled';\n";
    echo "3. Integrate with mobile app\n";
    echo "4. Test end-to-end notification flow\n\n";
    echo "See PUSH_NOTIFICATIONS.md for complete documentation.\n";
    exit(0);
}
