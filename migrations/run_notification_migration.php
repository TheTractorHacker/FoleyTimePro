<?php
require_once __DIR__ . '/../auth/db.php';

echo "Running notification tables migration...\n\n";

// Read the migration file
$sql = file_get_contents(__DIR__ . '/008_add_notification_tables.sql');

// Remove comments and split into individual statements
$statements = array_filter(
    array_map('trim',
        preg_split('/;(?=(?:[^\'"]|[\'"][^\'"]*[\'"])*$)/', $sql)
    ),
    function($stmt) {
        return !empty($stmt) && !preg_match('/^--/', $stmt);
    }
);

$success = 0;
$errors = 0;

foreach ($statements as $statement) {
    // Skip empty statements and comments
    if (empty(trim($statement)) || preg_match('/^--/', trim($statement))) {
        continue;
    }

    echo "Executing: " . substr(trim($statement), 0, 80) . "...\n";

    if ($conn->query($statement) === TRUE) {
        echo "✓ Success\n\n";
        $success++;
    } else {
        // Check if error is about duplicate key (which is OK for INSERT ... ON DUPLICATE KEY)
        if (strpos($conn->error, 'Duplicate') === false && strpos($statement, 'ON DUPLICATE KEY') === false) {
            echo "✗ Error: " . $conn->error . "\n\n";
            $errors++;
        } else {
            echo "✓ Already exists (OK)\n\n";
            $success++;
        }
    }
}

echo "\n========================================\n";
echo "Migration Summary:\n";
echo "✓ Successful: $success\n";
echo "✗ Errors: $errors\n";
echo "========================================\n";

if ($errors === 0) {
    echo "\n🎉 Migration completed successfully!\n\n";

    // Verify tables were created
    echo "Verifying created tables:\n";
    $tables = ['notification_preferences', 'notification_logs'];
    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows > 0) {
            echo "✓ Table '$table' exists\n";

            // Show column count
            $cols = $conn->query("DESCRIBE $table");
            echo "  └─ $cols->num_rows columns\n";
        } else {
            echo "✗ Table '$table' NOT found\n";
        }
    }

    // Check settings were inserted
    echo "\nVerifying settings:\n";
    $settingsCheck = $conn->query("
        SELECT SettingKey, SettingValue FROM settings
        WHERE SettingKey IN ('fcm_server_key', 'push_notifications_enabled')
    ");
    while ($row = $settingsCheck->fetch_assoc()) {
        echo "✓ Setting '{$row['SettingKey']}' = '{$row['SettingValue']}'\n";
    }
} else {
    echo "\n⚠ Migration completed with errors. Please review above.\n";
}

$conn->close();
