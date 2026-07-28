-- 012_offline_sync_hardening.sql
-- Server-side defenses for the offline punch queue that the mobile app drains
-- when it reconnects. Historical trust on client-supplied captured_at let any
-- authenticated user fabricate punches up to 7 days in the past.
--
-- Adds:
--   * punch_idempotency: dedupes replayed offline-drain requests so a lost
--     response can't turn one queued punch into N recorded punches.
--   * users.HireDate: optional floor for how far back captured_at may point.
--     NULL means "no floor" (existing users) so this is non-breaking.

CREATE TABLE IF NOT EXISTS punch_idempotency (
    user_id      INT UNSIGNED NOT NULL,
    idem_key     VARCHAR(64)  NOT NULL,
    first_seen   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    punch_id     INT UNSIGNED NULL,
    PRIMARY KEY (user_id, idem_key),
    KEY idx_first_seen (first_seen)
) ENGINE=InnoDB;

-- Add HireDate only if not already present (idempotent for re-runs).
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'HireDate'
);
SET @stmt := IF(@has_col = 0,
    'ALTER TABLE users ADD COLUMN HireDate DATE NULL DEFAULT NULL AFTER Office',
    'DO 0');
PREPARE p FROM @stmt;
EXECUTE p;
DEALLOCATE PREPARE p;
