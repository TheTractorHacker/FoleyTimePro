-- Migration: Add notification-related tables
-- Created: 2025-11-24

-- Notification preferences table
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification logs table (for tracking sent notifications)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add FCM server key to settings table
INSERT INTO settings (SettingKey, SettingValue, Description)
VALUES ('fcm_server_key', '', 'Firebase Cloud Messaging Server Key for push notifications')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- Add push notification enabled setting
INSERT INTO settings (SettingKey, SettingValue, Description)
VALUES ('push_notifications_enabled', '0', 'Enable/disable push notifications (0=disabled, 1=enabled)')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;
