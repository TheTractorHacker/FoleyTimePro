-- Timeclock schema (clean) — creates DB + tables (no data), then seeds admin
CREATE DATABASE IF NOT EXISTS `timeclock`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `timeclock`;

-- Drop in FK-safe order
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `login_logs`;
DROP TABLE IF EXISTS `timepunches`;
DROP TABLE IF EXISTS `punch_changelog`;
DROP TABLE IF EXISTS `pending_edits`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `users`;
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

-- ===== Seed default admin (bcrypt hash) =====
INSERT INTO `admins` (`username`, `password`)
VALUES ('admin', '$2a$10$bHLa.Sk0R08YNno3Y1cynu.tpk9ADCZitUuXkyXAqImWh.BC1KliG')
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);
