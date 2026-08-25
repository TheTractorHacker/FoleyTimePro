-- Migration 018: fix api_write_idempotency's unique key to include ResourceType.
--
-- Migration 016 defined UNIQUE KEY uk_write_idem (KeyID, IdemKey) — ResourceType
-- was NOT part of it, even though WriteRequest::checkIdempotency() has always
-- filtered lookups by (KeyID, IdemKey, ResourceType). That mismatch is a real,
-- silent dedup failure, not a cosmetic one:
--
--   1. POST /punches with Idempotency-Key=K creates punch #501;
--      recordIdempotency(keyId,'K','punch',501) succeeds — the only row
--      possible for (keyId,'K') under the OLD constraint now exists.
--   2. POST /timeoff with the SAME key K (plausible: one API key shared by
--      multiple integration workers, or a client that scopes an idempotency
--      key to a business event rather than a single call). checkIdempotency
--      (ResourceType='timeoff') correctly misses — no such row exists yet —
--      so a new request #88 is created and returned as a normal 201. Its own
--      recordIdempotency(keyId,'K','timeoff',88) then collides with the
--      pre-existing (keyId,'K') row and is silently dropped by INSERT IGNORE.
--      The mapping for ResourceType='timeoff' is never persisted.
--   3. Any LATER retry of that exact POST /timeoff request with the same key
--      K — the exact scenario Idempotency-Key exists to make safe — misses on
--      checkIdempotency again (there is still no row with ResourceType=
--      'timeoff' for that key; there never can be) and creates ANOTHER new
--      time-off request. This repeats on every retry, forever, for that
--      (KeyID, IdemKey) pair. If both duplicate requests are later approved,
--      the employee's leave balance is deducted twice for what the caller
--      believed was one deduplicated submission.
--
-- Fix: widen the unique key to (KeyID, IdemKey, ResourceType), so each
-- resource type gets its own dedup row instead of racing the other two for
-- the same (KeyID, IdemKey) slot. No application code change is required —
-- WriteRequest::checkIdempotency()/recordIdempotency() already scope every
-- query by ResourceType; they only needed the DB constraint to actually
-- allow one row per (KeyID, IdemKey, ResourceType) rather than colliding
-- across types.
--
-- MariaDB does not support "ADD UNIQUE KEY IF NOT EXISTS" the way migration
-- 010/014 use "ADD INDEX IF NOT EXISTS", so this is idempotent by checking
-- INFORMATION_SCHEMA first via a prepared statement, matching how a
-- straight ALTER would fail non-idempotently on a second run otherwise.

SET @old_key_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'api_write_idempotency'
      AND INDEX_NAME = 'uk_write_idem'
      AND SEQ_IN_INDEX = 1
);

SET @new_key_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'api_write_idempotency'
      AND INDEX_NAME = 'uk_write_idem'
      AND SEQ_IN_INDEX = 3
      AND COLUMN_NAME = 'ResourceType'
);

SET @sql = IF(
    @old_key_exists > 0 AND @new_key_exists = 0,
    'ALTER TABLE `api_write_idempotency` DROP KEY `uk_write_idem`, ADD UNIQUE KEY `uk_write_idem` (`KeyID`, `IdemKey`, `ResourceType`)',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
