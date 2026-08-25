-- 011_offline_punch_source.sql
--
-- Adds columns to record when a punch was actually captured on-device
-- versus when it hit the server, so the Android app's offline queue can
-- send backdated punches without confusing the clock/status logic.
--
-- CapturedAt : timestamp when the user tapped the punch on the device.
--              Nullable so legacy rows and live web punches stay untouched.
-- Source     : enum-like text — 'live' (real time), 'offline_sync'
--              (drained from the mobile queue), 'kiosk' (public kiosk).
--
-- Safe to run multiple times: ALGORITHM=INSTANT on MySQL 8+.

ALTER TABLE timepunches
    ADD COLUMN CapturedAt DATETIME NULL AFTER Note,
    ADD COLUMN Source     VARCHAR(24) NOT NULL DEFAULT 'live' AFTER CapturedAt,
    ADD INDEX  idx_source (Source);
