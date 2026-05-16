-- Migration 009: Add UserPIN column for separate kiosk PIN
-- Run once on existing installs. Safe to re-run (IF NOT EXISTS check).
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `UserPIN` varchar(64) DEFAULT NULL
    COMMENT 'SHA-256 hash of kiosk PIN (4-8 digits, separate from login password)';
