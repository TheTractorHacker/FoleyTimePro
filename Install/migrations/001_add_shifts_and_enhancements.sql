-- Migration: Add shifts, branding, time-off, and API support
-- Version: 1.0
-- Date: 2025-11-24

-- ============================================================================
-- SHIFTS MANAGEMENT
-- ============================================================================

-- Create shifts table
CREATE TABLE IF NOT EXISTS `shifts` (
    `ShiftID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ShiftName` VARCHAR(100) NOT NULL,
    `StartTime` TIME NOT NULL,
    `EndTime` TIME NOT NULL,
    `DaysOfWeek` VARCHAR(50) NOT NULL COMMENT 'JSON array of days: ["Mon","Tue","Wed","Thu","Fri"]',
    `LunchDuration` INT DEFAULT 0 COMMENT 'Lunch duration in minutes',
    `LunchStartTime` TIME DEFAULT NULL COMMENT 'Expected lunch start time',
    `IsActive` TINYINT(1) DEFAULT 1,
    `CreatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `UpdatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active` (`IsActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create user_shifts junction table
CREATE TABLE IF NOT EXISTS `user_shifts` (
    `ID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `UserID` INT NOT NULL,
    `ShiftID` INT NOT NULL,
    `AssignedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `AssignedBy` INT DEFAULT NULL COMMENT 'Admin ID who assigned the shift',
    FOREIGN KEY (`UserID`) REFERENCES `users`(`ID`) ON DELETE CASCADE,
    FOREIGN KEY (`ShiftID`) REFERENCES `shifts`(`ShiftID`) ON DELETE CASCADE,
    FOREIGN KEY (`AssignedBy`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `unique_user_shift` (`UserID`),
    INDEX `idx_shift` (`ShiftID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TIME OFF REQUESTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `time_off_requests` (
    `ID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `EmployeeID` INT NOT NULL,
    `StartDate` DATE NOT NULL,
    `EndDate` DATE NOT NULL,
    `RequestType` ENUM('Vacation', 'Sick', 'Personal', 'Other') NOT NULL DEFAULT 'Vacation',
    `Reason` TEXT DEFAULT NULL,
    `Status` ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    `RequestedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ReviewedAt` DATETIME DEFAULT NULL,
    `ReviewedBy` INT DEFAULT NULL COMMENT 'Admin ID who reviewed',
    `ReviewNotes` TEXT DEFAULT NULL,
    FOREIGN KEY (`EmployeeID`) REFERENCES `users`(`ID`) ON DELETE CASCADE,
    FOREIGN KEY (`ReviewedBy`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
    INDEX `idx_employee` (`EmployeeID`),
    INDEX `idx_status` (`Status`),
    INDEX `idx_dates` (`StartDate`, `EndDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- AUTO CLOCK-OUT LOGGING
-- ============================================================================

CREATE TABLE IF NOT EXISTS `auto_clockout_log` (
    `ID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `EmployeeID` INT NOT NULL,
    `PunchID` INT NOT NULL,
    `OriginalShiftEndTime` TIME NOT NULL,
    `AutoClockOutTime` TIME NOT NULL,
    `ProcessedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `NotificationSent` TINYINT(1) DEFAULT 0,
    FOREIGN KEY (`EmployeeID`) REFERENCES `users`(`ID`) ON DELETE CASCADE,
    FOREIGN KEY (`PunchID`) REFERENCES `timepunches`(`id`) ON DELETE CASCADE,
    INDEX `idx_employee` (`EmployeeID`),
    INDEX `idx_processed` (`ProcessedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- BRANDING / CUSTOMIZATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS `branding` (
    `ID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `CompanyName` VARCHAR(255) NOT NULL DEFAULT 'D-BEST TimeSmart',
    `LogoPath` VARCHAR(255) DEFAULT NULL,
    `FaviconPath` VARCHAR(255) DEFAULT NULL,
    `PrimaryColor` VARCHAR(7) DEFAULT '#3498db' COMMENT 'Hex color code',
    `SecondaryColor` VARCHAR(7) DEFAULT '#2ecc71' COMMENT 'Hex color code',
    `AccentColor` VARCHAR(7) DEFAULT '#e74c3c' COMMENT 'Hex color code',
    `LoginBackground` VARCHAR(255) DEFAULT NULL,
    `EmailSignature` TEXT DEFAULT NULL COMMENT 'HTML email signature',
    `ContactEmail` VARCHAR(255) DEFAULT NULL,
    `ContactPhone` VARCHAR(20) DEFAULT NULL,
    `Address` TEXT DEFAULT NULL,
    `Timezone` VARCHAR(50) DEFAULT 'America/Chicago',
    `DateFormat` VARCHAR(20) DEFAULT 'MM/DD/YYYY',
    `TimeFormat` ENUM('12h', '24h') DEFAULT '12h',
    `CreatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `UpdatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default branding
INSERT INTO `branding` (
    `CompanyName`,
    `LogoPath`,
    `FaviconPath`,
    `PrimaryColor`,
    `SecondaryColor`,
    `AccentColor`,
    `Timezone`,
    `DateFormat`,
    `TimeFormat`
) VALUES (
    'D-BEST TimeSmart',
    '/images/D-Best-Logos.png',
    '/images/D-Best-favicon.webp',
    '#3498db',
    '#2ecc71',
    '#e74c3c',
    'America/Chicago',
    'MM/DD/YYYY',
    '12h'
) ON DUPLICATE KEY UPDATE `ID` = `ID`;

-- ============================================================================
-- API AUTHENTICATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS `api_tokens` (
    `ID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `UserID` INT NOT NULL,
    `Token` VARCHAR(255) NOT NULL COMMENT 'Hashed refresh token',
    `DeviceInfo` TEXT DEFAULT NULL COMMENT 'JSON with device details',
    `ExpiresAt` DATETIME NOT NULL,
    `CreatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `LastUsedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`UserID`) REFERENCES `users`(`ID`) ON DELETE CASCADE,
    UNIQUE KEY `unique_token` (`Token`),
    INDEX `idx_user` (`UserID`),
    INDEX `idx_expiry` (`ExpiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PUSH NOTIFICATIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `device_tokens` (
    `ID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `UserID` INT NOT NULL,
    `DeviceToken` VARCHAR(255) NOT NULL COMMENT 'Push notification token',
    `Platform` ENUM('ios', 'android', 'web') NOT NULL,
    `AppVersion` VARCHAR(20) DEFAULT NULL,
    `DeviceName` VARCHAR(100) DEFAULT NULL,
    `CreatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `LastUsedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`UserID`) REFERENCES `users`(`ID`) ON DELETE CASCADE,
    UNIQUE KEY `unique_device_token` (`DeviceToken`),
    INDEX `idx_user` (`UserID`),
    INDEX `idx_platform` (`Platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CALENDAR SYNC
-- ============================================================================

CREATE TABLE IF NOT EXISTS `calendar_connections` (
    `ID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `UserID` INT NOT NULL,
    `Provider` ENUM('google', 'outlook') NOT NULL,
    `AccessToken` TEXT NOT NULL COMMENT 'Encrypted access token',
    `RefreshToken` TEXT NOT NULL COMMENT 'Encrypted refresh token',
    `TokenExpiry` DATETIME NOT NULL,
    `CalendarID` VARCHAR(255) DEFAULT NULL,
    `SyncEnabled` TINYINT(1) DEFAULT 1,
    `LastSync` DATETIME DEFAULT NULL,
    `CreatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `UpdatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`UserID`) REFERENCES `users`(`ID`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_provider` (`UserID`, `Provider`),
    INDEX `idx_sync_enabled` (`SyncEnabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SAMPLE DATA (for testing)
-- ============================================================================

-- Insert sample shifts
INSERT INTO `shifts` (`ShiftName`, `StartTime`, `EndTime`, `DaysOfWeek`, `LunchDuration`, `LunchStartTime`, `IsActive`) VALUES
('1st Shift', '07:00:00', '15:00:00', '["Mon","Tue","Wed","Thu","Fri"]', 60, '12:00:00', 1),
('2nd Shift', '15:00:00', '23:00:00', '["Mon","Tue","Wed","Thu","Fri"]', 30, '19:00:00', 1),
('3rd Shift', '23:00:00', '07:00:00', '["Mon","Tue","Wed","Thu","Fri"]', 30, '03:00:00', 1),
('Weekend Shift', '08:00:00', '16:00:00', '["Sat","Sun"]', 30, '12:00:00', 1),
('Part-Time Morning', '08:00:00', '12:00:00', '["Mon","Tue","Wed","Thu","Fri"]', 0, NULL, 1),
('Part-Time Evening', '17:00:00', '21:00:00', '["Mon","Tue","Wed","Thu","Fri"]', 0, NULL, 1)
ON DUPLICATE KEY UPDATE `ShiftID` = `ShiftID`;

-- ============================================================================
-- INDEXES FOR PERFORMANCE
-- ============================================================================

-- Add index to users table for shift queries
ALTER TABLE `users`
ADD INDEX IF NOT EXISTS `idx_clock_status` (`ClockStatus`);

-- Add index to timepunches for auto clockout queries
ALTER TABLE `timepunches`
ADD INDEX IF NOT EXISTS `idx_employee_date` (`EmployeeID`, `Date`);

COMMIT;
