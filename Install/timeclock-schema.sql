-- Timeclock schema (clean) — creates DB + tables (no data), then seeds admin
CREATE DATABASE IF NOT EXISTS `DB_NAME_PLACEHOLDER`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `DB_NAME_PLACEHOLDER`;

-- Drop in FK-safe order
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `calendar_sync_log`;
DROP TABLE IF EXISTS `calendar_connections`;
DROP TABLE IF EXISTS `auto_clockout_log`;
DROP TABLE IF EXISTS `notification_logs`;
DROP TABLE IF EXISTS `notification_preferences`;
DROP TABLE IF EXISTS `device_tokens`;
DROP TABLE IF EXISTS `api_tokens`;
DROP TABLE IF EXISTS `quickbooks_sync_log`;
DROP TABLE IF EXISTS `quickbooks_employee_map`;
DROP TABLE IF EXISTS `quickbooks_connection`;
DROP TABLE IF EXISTS `report_exports`;
DROP TABLE IF EXISTS `user_shifts`;
DROP TABLE IF EXISTS `shifts`;
DROP TABLE IF EXISTS `time_off_requests`;
DROP TABLE IF EXISTS `leave_balances`;
DROP TABLE IF EXISTS `leave_accrual_policy`;
DROP TABLE IF EXISTS `geofences`;
DROP TABLE IF EXISTS `login_logs`;
DROP TABLE IF EXISTS `timepunches`;
DROP TABLE IF EXISTS `punch_changelog`;
DROP TABLE IF EXISTS `pending_edits`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `branding`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `Offices`;
DROP TABLE IF EXISTS `user-archive`;
SET FOREIGN_KEY_CHECKS = 1;

-- ===== Tables =====

CREATE TABLE `users` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `LastName` varchar(255) NOT NULL,
  `Email` varchar(255) DEFAULT NULL,
  `FirstName` varchar(255) NOT NULL,
  `Pass` varchar(255) DEFAULT NULL,
  `TagID` varchar(50) DEFAULT NULL,
  `ProfilePhoto` varchar(255) DEFAULT NULL,
  `ClockStatus` varchar(255) DEFAULT NULL,
  `Office` varchar(100) DEFAULT NULL,
  `TwoFASecret` varchar(255) DEFAULT NULL,
  `TwoFAEnabled` tinyint(1) DEFAULT 0,
  `RecoveryCodeHash` varchar(255) DEFAULT NULL,
  `AdminOverride2FA` tinyint(1) DEFAULT 1,
  `JobTitle` varchar(100) DEFAULT NULL,
  `PhoneNumber` varchar(20) DEFAULT NULL,
  `ThemePref` varchar(10) DEFAULT 'light',
  `TwoFARecoveryCode` text DEFAULT NULL,
  `LockOut` TINYINT(1) NOT NULL DEFAULT 0,
  `UserPIN` varchar(64) DEFAULT NULL COMMENT 'SHA-256 hash of kiosk PIN (separate from login password)',
  PRIMARY KEY (`ID`),
  UNIQUE KEY `TagID` (`TagID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `TwoFASecret` varchar(255) DEFAULT NULL,
  `TwoFAEnabled` tinyint(1) NOT NULL DEFAULT 0,
  `TwoFARecoveryCode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`TwoFARecoveryCode`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `settings` (
  `SettingKey` varchar(50) NOT NULL,
  `SettingValue` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`SettingKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pending_edits` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) NOT NULL,
  `Date` date NOT NULL,
  `TimeIN` time DEFAULT NULL,
  `LunchStart` time DEFAULT NULL,
  `LunchEnd` time DEFAULT NULL,
  `TimeOut` time DEFAULT NULL,
  `Note` text DEFAULT NULL,
  `Reason` text NOT NULL,
  `Status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `SubmittedAt` datetime NOT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  `ReviewedBy` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `punch_changelog` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) NOT NULL,
  `Date` date NOT NULL,
  `ChangedBy` varchar(100) DEFAULT NULL,
  `ChangeTime` datetime DEFAULT current_timestamp(),
  `FieldChanged` varchar(50) DEFAULT NULL,
  `OldValue` varchar(10) DEFAULT NULL,
  `NewValue` varchar(10) DEFAULT NULL,
  `Reason` text DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `timepunches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) DEFAULT NULL,
  `Date` date DEFAULT NULL,
  `TimeIN` time DEFAULT NULL,
  `LatitudeIN` decimal(10,8) DEFAULT NULL,
  `LongitudeIN` decimal(11,8) DEFAULT NULL,
  `AccuracyIN` float DEFAULT NULL,
  `IPAddressIN` varchar(45) DEFAULT NULL,
  `LunchStart` time DEFAULT NULL,
  `LatitudeLunchStart` decimal(10,8) DEFAULT NULL,
  `LongitudeLunchStart` decimal(11,8) DEFAULT NULL,
  `AccuracyLunchStart` float DEFAULT NULL,
  `IPAddressLunchStart` varchar(45) DEFAULT NULL,
  `LunchEnd` time DEFAULT NULL,
  `LatitudeLunchEnd` decimal(10,8) DEFAULT NULL,
  `LongitudeLunchEnd` decimal(11,8) DEFAULT NULL,
  `AccuracyLunchEnd` float DEFAULT NULL,
  `IPAddressLunchEnd` varchar(45) DEFAULT NULL,
  `TimeOut` time DEFAULT NULL,
  `LatitudeOut` decimal(10,8) DEFAULT NULL,
  `LongitudeOut` decimal(11,8) DEFAULT NULL,
  `AccuracyOut` float DEFAULT NULL,
  `IPAddressOut` varchar(45) DEFAULT NULL,
  `TotalHours` decimal(5,2) DEFAULT NULL,
  `Note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `EmployeeID` (`EmployeeID`),
  CONSTRAINT `timepunches_ibfk_1` FOREIGN KEY (`EmployeeID`) REFERENCES `users` (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_logs` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) DEFAULT NULL,
  `IP` varchar(45) DEFAULT NULL,
  `Timestamp` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `EmployeeID` (`EmployeeID`),
  CONSTRAINT `login_logs_ibfk_1` FOREIGN KEY (`EmployeeID`) REFERENCES `users` (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Offices` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `OfficeName` varchar(255) NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `user-archive` (
  `ID` int(11) NOT NULL,
  `LastName` varchar(255) NOT NULL,
  `Email` varchar(255) DEFAULT NULL,
  `FirstName` varchar(255) NOT NULL,
  `Pass` varchar(255) DEFAULT NULL,
  `TagID` varchar(50) DEFAULT NULL,
  `ProfilePhoto` varchar(255) DEFAULT NULL,
  `ClockStatus` varchar(255) DEFAULT NULL,
  `Office` varchar(100) DEFAULT NULL,
  `TwoFASecret` varchar(255) DEFAULT NULL,
  `TwoFAEnabled` tinyint(1) DEFAULT 0,
  `RecoveryCodeHash` varchar(255) DEFAULT NULL,
  `AdminOverride2FA` tinyint(1) DEFAULT 1,
  `JobTitle` varchar(100) DEFAULT NULL,
  `PhoneNumber` varchar(20) DEFAULT NULL,
  `ThemePref` varchar(10) DEFAULT 'light',
  `TwoFARecoveryCode` text DEFAULT NULL,
  `LockOut` TINYINT(1) NOT NULL DEFAULT 0,
  `ArchivedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `branding` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `CompanyName` varchar(255) NOT NULL DEFAULT 'FoleyTimePro',
  `LogoPath` varchar(255) DEFAULT NULL,
  `FaviconPath` varchar(255) DEFAULT NULL,
  `PrimaryColor` varchar(7) DEFAULT '#073851',
  `SecondaryColor` varchar(7) DEFAULT '#2ecc71',
  `AccentColor` varchar(7) DEFAULT '#e74c3c',
  `LoginBackground` varchar(255) DEFAULT NULL,
  `EmailSignature` text DEFAULT NULL,
  `ContactEmail` varchar(255) DEFAULT NULL,
  `ContactPhone` varchar(20) DEFAULT NULL,
  `Address` text DEFAULT NULL,
  `Timezone` varchar(50) DEFAULT 'America/Chicago',
  `DateFormat` varchar(20) DEFAULT 'MM/DD/YYYY',
  `TimeFormat` enum('12h','24h') DEFAULT '12h',
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shifts` (
  `ShiftID` int(11) NOT NULL AUTO_INCREMENT,
  `ShiftName` varchar(100) NOT NULL,
  `StartTime` time NOT NULL,
  `EndTime` time NOT NULL,
  `DaysOfWeek` varchar(50) NOT NULL,
  `LunchDuration` int(11) DEFAULT 0,
  `LunchStartTime` time DEFAULT NULL,
  `IsActive` tinyint(1) DEFAULT 1,
  `GracePeriodMinutes` int(11) NOT NULL DEFAULT 60,
  `AutoClockOut` tinyint(1) NOT NULL DEFAULT 0,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ShiftID`),
  KEY `idx_active` (`IsActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_shifts` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `ShiftID` int(11) NOT NULL,
  `AssignedAt` datetime DEFAULT current_timestamp(),
  `AssignedBy` int(11) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `unique_user_shift` (`UserID`),
  KEY `AssignedBy` (`AssignedBy`),
  KEY `idx_shift` (`ShiftID`),
  CONSTRAINT `user_shifts_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `users` (`ID`) ON DELETE CASCADE,
  CONSTRAINT `user_shifts_ibfk_2` FOREIGN KEY (`ShiftID`) REFERENCES `shifts` (`ShiftID`) ON DELETE CASCADE,
  CONSTRAINT `user_shifts_ibfk_3` FOREIGN KEY (`AssignedBy`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `time_off_requests` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) NOT NULL,
  `StartDate` date NOT NULL,
  `EndDate` date NOT NULL,
  `RequestType` enum('Vacation','Sick','Personal','Other') NOT NULL DEFAULT 'Vacation',
  `Reason` text DEFAULT NULL,
  `Status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `RequestedAt` datetime DEFAULT current_timestamp(),
  `ReviewedAt` datetime DEFAULT NULL,
  `ReviewedBy` int(11) DEFAULT NULL,
  `ReviewNotes` text DEFAULT NULL,
  `HoursRequested` decimal(5,2) DEFAULT NULL,
  `AdminNotes` text DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `ReviewedBy` (`ReviewedBy`),
  KEY `idx_employee` (`EmployeeID`),
  KEY `idx_status` (`Status`),
  KEY `idx_dates` (`StartDate`,`EndDate`),
  CONSTRAINT `time_off_requests_ibfk_1` FOREIGN KEY (`EmployeeID`) REFERENCES `users` (`ID`) ON DELETE CASCADE,
  CONSTRAINT `time_off_requests_ibfk_2` FOREIGN KEY (`ReviewedBy`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_accrual_policy` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `LeaveType` enum('Vacation','Sick','Personal','Other') NOT NULL,
  `HoursPerYear` decimal(6,2) NOT NULL DEFAULT 40.00,
  `MaxCarryover` decimal(6,2) NOT NULL DEFAULT 40.00,
  `AccrualFrequency` enum('annual','monthly','biweekly') NOT NULL DEFAULT 'annual',
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `LeaveType` (`LeaveType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `leave_balances` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) NOT NULL,
  `LeaveType` enum('Vacation','Sick','Personal','Other') NOT NULL DEFAULT 'Vacation',
  `YearNumber` year(4) NOT NULL,
  `AccruedHours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `UsedHours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `CarryoverHours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_emp_type_year` (`EmployeeID`,`LeaveType`,`YearNumber`),
  CONSTRAINT `leave_balances_ibfk_1` FOREIGN KEY (`EmployeeID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `geofences` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(100) NOT NULL,
  `Latitude` decimal(10,7) NOT NULL,
  `Longitude` decimal(10,7) NOT NULL,
  `RadiusMeters` int(11) NOT NULL DEFAULT 200,
  `OfficeID` int(11) DEFAULT NULL,
  `IsRequired` tinyint(1) NOT NULL DEFAULT 1,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `OfficeID` (`OfficeID`),
  CONSTRAINT `geofences_ibfk_1` FOREIGN KEY (`OfficeID`) REFERENCES `Offices` (`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `api_tokens` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `Token` varchar(255) NOT NULL,
  `Type` enum('access','refresh','device') DEFAULT 'refresh',
  `DeviceInfo` text DEFAULT NULL,
  `ExpiresAt` datetime NOT NULL,
  `IsRevoked` tinyint(1) DEFAULT 0,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `LastUsedAt` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `unique_token` (`Token`),
  KEY `idx_user` (`UserID`),
  KEY `idx_expiry` (`ExpiresAt`),
  CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_tokens` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `DeviceToken` varchar(255) NOT NULL,
  `IsActive` tinyint(1) DEFAULT 1,
  `Platform` enum('ios','android','web') NOT NULL,
  `AppVersion` varchar(20) DEFAULT NULL,
  `DeviceName` varchar(100) DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `LastUsedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `unique_device_token` (`DeviceToken`),
  KEY `idx_user` (`UserID`),
  KEY `idx_platform` (`Platform`),
  CONSTRAINT `device_tokens_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_preferences` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `ClockReminders` tinyint(1) DEFAULT 1,
  `TimeOffUpdates` tinyint(1) DEFAULT 1,
  `ShiftChanges` tinyint(1) DEFAULT 1,
  `GeneralAnnouncements` tinyint(1) DEFAULT 1,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `unique_user_prefs` (`UserID`),
  CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_logs` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `DeviceCount` int(11) NOT NULL,
  `Title` varchar(255) NOT NULL,
  `Body` text NOT NULL,
  `Data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`Data`)),
  `Status` enum('sent','failed','pending') DEFAULT 'pending',
  `SentAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `idx_sent_at` (`SentAt`),
  KEY `idx_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auto_clockout_log` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) NOT NULL,
  `PunchID` int(11) NOT NULL,
  `OriginalShiftEndTime` time NOT NULL,
  `AutoClockOutTime` time NOT NULL,
  `ProcessedAt` datetime DEFAULT current_timestamp(),
  `NotificationSent` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `PunchID` (`PunchID`),
  KEY `idx_employee` (`EmployeeID`),
  KEY `idx_processed` (`ProcessedAt`),
  CONSTRAINT `auto_clockout_log_ibfk_1` FOREIGN KEY (`EmployeeID`) REFERENCES `users` (`ID`) ON DELETE CASCADE,
  CONSTRAINT `auto_clockout_log_ibfk_2` FOREIGN KEY (`PunchID`) REFERENCES `timepunches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quickbooks_connection` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `RealmID` varchar(50) NOT NULL,
  `AccessToken` text NOT NULL,
  `RefreshToken` text NOT NULL,
  `TokenExpiry` datetime NOT NULL,
  `RefreshExpiry` datetime NOT NULL,
  `CompanyName` varchar(255) DEFAULT NULL,
  `ConnectedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `RealmID` (`RealmID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `quickbooks_employee_map` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) NOT NULL,
  `QBEmployeeID` varchar(50) NOT NULL,
  `QBDisplayName` varchar(255) DEFAULT NULL,
  `LastSyncAt` datetime DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `EmployeeID` (`EmployeeID`),
  CONSTRAINT `quickbooks_employee_map_ibfk_1` FOREIGN KEY (`EmployeeID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `quickbooks_sync_log` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `SyncType` enum('employee','timesheet') NOT NULL,
  `StartDate` date DEFAULT NULL,
  `EndDate` date DEFAULT NULL,
  `RecordsIn` int(11) DEFAULT 0,
  `RecordsOk` int(11) DEFAULT 0,
  `RecordsFail` int(11) DEFAULT 0,
  `Notes` text DEFAULT NULL,
  `SyncedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `report_exports` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `ReportType` varchar(30) NOT NULL,
  `StartDate` date DEFAULT NULL,
  `EndDate` date DEFAULT NULL,
  `EmployeeID` int(11) DEFAULT NULL,
  `EmployeeName` varchar(120) DEFAULT NULL,
  `Format` varchar(10) NOT NULL DEFAULT 'pdf',
  `Rounding` int(11) NOT NULL DEFAULT 0,
  `ExportedBy` varchar(80) NOT NULL DEFAULT 'admin',
  `ExportedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `Params` text DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `calendar_connections` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `Provider` enum('google','outlook') NOT NULL,
  `AccessToken` text NOT NULL,
  `RefreshToken` text NOT NULL,
  `TokenExpiry` datetime NOT NULL,
  `CalendarID` varchar(255) DEFAULT NULL,
  `SyncEnabled` tinyint(1) DEFAULT 1,
  `LastSync` datetime DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `unique_user_provider` (`UserID`,`Provider`),
  KEY `idx_sync_enabled` (`SyncEnabled`),
  CONSTRAINT `calendar_connections_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `calendar_sync_log` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `ConnectionID` int(11) NOT NULL,
  `EventType` enum('timeoff','shift','punch') NOT NULL,
  `LocalID` int(11) NOT NULL,
  `CalendarEventID` varchar(255) DEFAULT NULL,
  `SyncAction` enum('created','updated','deleted') NOT NULL,
  `SyncStatus` enum('success','failed','pending') NOT NULL DEFAULT 'pending',
  `ErrorMessage` text DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `idx_connection` (`ConnectionID`),
  KEY `idx_local` (`EventType`,`LocalID`),
  CONSTRAINT `fk_sync_connection` FOREIGN KEY (`ConnectionID`) REFERENCES `calendar_connections` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Default seed data =====

INSERT INTO `branding` (`CompanyName`, `PrimaryColor`, `SecondaryColor`, `AccentColor`, `Timezone`, `DateFormat`, `TimeFormat`)
VALUES ('FoleyTimePro', '#073851', '#2ecc71', '#e74c3c', 'America/Chicago', 'MM/DD/YYYY', '12h');

INSERT INTO `leave_accrual_policy` (`LeaveType`, `HoursPerYear`, `MaxCarryover`, `AccrualFrequency`) VALUES
  ('Vacation', 80.00, 40.00, 'annual'),
  ('Sick',     40.00, 40.00, 'annual'),
  ('Personal', 24.00, 0.00,  'annual'),
  ('Other',    0.00,  0.00,  'annual');

-- ===== Seed default admin (bcrypt hash) =====
INSERT INTO `admins` (`username`, `password`)
VALUES ('admin', '$2a$10$bHLa.Sk0R08YNno3Y1cynu.tpk9ADCZitUuXkyXAqImWh.BC1KliG')
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);