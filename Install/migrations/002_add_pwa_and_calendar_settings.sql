-- Migration: Add PWA/TWA and Calendar Integration Settings
-- Version: 002
-- Date: 2026-01-24
-- Description: Adds settings for Android TWA configuration and calendar integrations

-- ============================================
-- TWA (Trusted Web Activity) Settings
-- ============================================

-- TWA Package Name (e.g., com.company.timeclock)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('twa_package_name', '')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- TWA SHA256 Certificate Fingerprint (Release)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('twa_sha256_fingerprint', '')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- TWA SHA256 Certificate Fingerprint (Debug - optional)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('twa_sha256_fingerprint_debug', '')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- ============================================
-- Google Calendar Integration Settings
-- ============================================

-- Google Calendar OAuth Client ID
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('google_calendar_client_id', '')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- Google Calendar OAuth Client Secret (encrypted)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('google_calendar_client_secret', '')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- Google Calendar Enabled (1 = enabled, 0 = disabled)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('google_calendar_enabled', '0')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- ============================================
-- Microsoft Outlook Calendar Integration Settings
-- ============================================

-- Microsoft OAuth Client ID
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('microsoft_calendar_client_id', '')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- Microsoft OAuth Client Secret (encrypted)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('microsoft_calendar_client_secret', '')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- Microsoft OAuth Tenant ID (common, organizations, consumers, or specific tenant)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('microsoft_calendar_tenant_id', 'common')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- Microsoft Calendar Enabled (1 = enabled, 0 = disabled)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('microsoft_calendar_enabled', '0')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- ============================================
-- Calendar Sync Settings
-- ============================================

-- Calendar Encryption Key (for storing OAuth tokens securely)
-- This should be a 32-character key for AES-256 encryption
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('calendar_encryption_key', '')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- Sync time-off requests to calendar (1 = yes, 0 = no)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('sync_timeoff_to_calendar', '1')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- Sync shift schedules to calendar (1 = yes, 0 = no)
INSERT INTO settings (SettingKey, SettingValue)
VALUES ('sync_shifts_to_calendar', '1')
ON DUPLICATE KEY UPDATE SettingKey = SettingKey;

-- ============================================
-- Calendar Connections Table (if not exists)
-- ============================================

CREATE TABLE IF NOT EXISTS `calendar_connections` (
    `ID` int(11) NOT NULL AUTO_INCREMENT,
    `UserID` int(11) NOT NULL,
    `Provider` enum('google','outlook') NOT NULL,
    `AccessToken` text DEFAULT NULL,
    `RefreshToken` text DEFAULT NULL,
    `TokenExpiry` datetime DEFAULT NULL,
    `CalendarID` varchar(255) DEFAULT NULL,
    `SyncEnabled` tinyint(1) DEFAULT 1,
    `LastSyncAt` datetime DEFAULT NULL,
    `CreatedAt` datetime DEFAULT CURRENT_TIMESTAMP,
    `UpdatedAt` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`ID`),
    UNIQUE KEY `user_provider` (`UserID`, `Provider`),
    KEY `idx_user_id` (`UserID`),
    CONSTRAINT `fk_calendar_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Calendar Sync Log Table
-- ============================================

CREATE TABLE IF NOT EXISTS `calendar_sync_log` (
    `ID` int(11) NOT NULL AUTO_INCREMENT,
    `ConnectionID` int(11) NOT NULL,
    `EventType` enum('timeoff','shift','punch') NOT NULL,
    `LocalID` int(11) NOT NULL COMMENT 'ID of the local record (time_off_requests.ID, shifts.ShiftID, etc.)',
    `CalendarEventID` varchar(255) DEFAULT NULL COMMENT 'ID from calendar provider',
    `SyncAction` enum('created','updated','deleted') NOT NULL,
    `SyncStatus` enum('success','failed','pending') NOT NULL DEFAULT 'pending',
    `ErrorMessage` text DEFAULT NULL,
    `CreatedAt` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ID`),
    KEY `idx_connection` (`ConnectionID`),
    KEY `idx_local` (`EventType`, `LocalID`),
    CONSTRAINT `fk_sync_connection` FOREIGN KEY (`ConnectionID`) REFERENCES `calendar_connections` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
