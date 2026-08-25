<?php
require_once __DIR__ . '/../auth/db.php';

echo "Creating notification tables...\n\n";

$tables = [
    'notification_preferences' => "
        CREATE TABLE IF NOT EXISTS notification_preferences (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            UserID INT NOT NULL,
            ClockReminders TINYINT(1) DEFAULT 1,
            TimeOffUpdates TINYINT(1) DEFAULT 1,
            ShiftChanges TINYINT(1) DEFAULT 1,
            GeneralAnnouncements TINYINT(1) DEFAULT 1,
            CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE CASCADE,
            UNIQUE KEY unique_user_prefs (UserID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'notification_logs' => "
        CREATE TABLE IF NOT EXISTS notification_logs (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            DeviceCount INT NOT NULL,
            Title VARCHAR(255) NOT NULL,
            Body TEXT NOT NULL,
            Data JSON,
            Status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
            SentAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sent_at (SentAt),
            INDEX idx_status (Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "
];

foreach ($tables as $name => $sql) {
    echo "Creating table: $name\n";
    if ($conn->query($sql)) {
        echo "✓ Table '$name' created successfully\n\n";
    } else {
        echo "✗ Error creating '$name': " . $conn->error . "\n\n";
    }
}

// Add settings
echo "Adding notification settings...\n";

$settings = [
    ['fcm_server_key', '', 'Firebase Cloud Messaging Server Key for push notifications'],
    ['push_notifications_enabled', '0', 'Enable/disable push notifications (0=disabled, 1=enabled)']
];

foreach ($settings as list($key, $value, $description)) {
    $stmt = $conn->prepare("
        INSERT INTO settings (SettingKey, SettingValue, Description)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE Description = VALUES(Description)
    ");
    $stmt->bind_param("sss", $key, $value, $description);

    if ($stmt->execute()) {
        echo "✓ Setting '$key' added\n";
    } else {
        echo "✗ Error adding '$key': " . $conn->error . "\n";
    }
}

echo "\n========================================\n";
echo "Verification:\n";
echo "========================================\n\n";

// Verify tables
echo "Tables:\n";
foreach (array_keys($tables) as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "✓ $table exists\n";
        $cols = $conn->query("DESCRIBE $table");
        echo "  └─ Columns: " . $cols->num_rows . "\n";
    } else {
        echo "✗ $table NOT found\n";
    }
}

echo "\nSettings:\n";
$result = $conn->query("
    SELECT SettingKey, SettingValue, Description FROM settings
    WHERE SettingKey IN ('fcm_server_key', 'push_notifications_enabled')
");
while ($row = $result->fetch_assoc()) {
    echo "✓ {$row['SettingKey']}: '{$row['SettingValue']}'\n";
    echo "  └─ {$row['Description']}\n";
}

echo "\n🎉 Notification infrastructure setup complete!\n";
$conn->close();
