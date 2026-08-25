-- Migration 016: Idempotency-Key storage for the /api/external/v1 WRITE
-- surface (POST/PATCH on punches, timeoff, employees).
--
-- This is a NEW table, not a reuse of punch_idempotency (migration 012).
-- punch_idempotency is keyed by (user_id, idem_key): correct for the mobile
-- punch API, where the authenticated actor IS the employee the punch belongs
-- to. The external write API's actor is an API key, which can write on
-- behalf of MANY employees in the same key's lifetime — "the same employee"
-- is not a fixed value to key on here. api_write_idempotency is keyed by
-- (KeyID, IdemKey) instead: one API key's idempotency keys form one
-- namespace, regardless of which employee/resource a given key touches.
--
-- ResourceType records what kind of thing was created (punch | timeoff |
-- employee) alongside the numeric ResourceId, so one key's idempotency
-- namespace can safely span all three write surfaces without a punch id and
-- a time-off request id ever being confused for each other. See
-- WriteRequest::checkIdempotency() for why the lookup filters on
-- ResourceType even though the UNIQUE key below does not include it.
--
-- Idempotency-Key is ALWAYS OPTIONAL on every write here (unlike punch.php,
-- where it is mandatory for offline_sync writes) — there is no live/offline
-- distinction on this surface, so every external write is honored with or
-- without the header; the header only ever adds a safety net, never a
-- requirement.
--
-- Safe to re-run (IF NOT EXISTS guard, same style as migrations 010/012/014).

CREATE TABLE IF NOT EXISTS `api_write_idempotency` (
  `ID`           BIGINT NOT NULL AUTO_INCREMENT,
  `KeyID`        INT NOT NULL COMMENT 'api_keys.ID of the key that made this write',
  `IdemKey`      VARCHAR(64) NOT NULL COMMENT 'Client-supplied Idempotency-Key header value',
  `ResourceType` VARCHAR(20) NOT NULL COMMENT 'punch | timeoff | employee',
  `ResourceId`   INT NOT NULL COMMENT 'Primary key of the created/reviewed resource',
  `CreatedAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uk_write_idem` (`KeyID`, `IdemKey`),
  KEY `idx_write_idem_created` (`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
