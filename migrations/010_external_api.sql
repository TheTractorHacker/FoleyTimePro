-- Migration 010: External API for server-to-server integrations (e.g. ITFlow)
-- Adds:
--   1. UpdatedAt column on timepunches (delta-sync cursor, auto-updates on INSERT/UPDATE)
--   2. api_keys           - hashed API keys with scopes, expiry, revocation
--   3. api_key_requests   - per-key sliding-window rate-limit bucket
--   4. api_key_audit_log  - audit trail of every external API call
--
-- Safe to re-run (IF NOT EXISTS guards).

ALTER TABLE `timepunches`
  ADD COLUMN IF NOT EXISTS `UpdatedAt` TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    COMMENT 'Auto-touched on any INSERT/UPDATE; delta cursor for /api/external/v1/punches';

ALTER TABLE `timepunches`
  ADD INDEX IF NOT EXISTS `idx_timepunches_updated_at` (`UpdatedAt`);

CREATE TABLE IF NOT EXISTS `api_keys` (
  `ID`         INT NOT NULL AUTO_INCREMENT,
  `Name`       VARCHAR(100) NOT NULL,
  `KeyPrefix`  CHAR(8) NOT NULL COMMENT 'First 8 chars of plaintext; indexed lookup + admin UI identifier',
  `KeyHash`    CHAR(64) NOT NULL COMMENT 'SHA-256(pepper || plaintext), hex',
  `Scopes`     VARCHAR(255) NOT NULL DEFAULT 'punches:read,employees:read',
  `CreatedBy`  VARCHAR(100) DEFAULT NULL,
  `CreatedAt`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `LastUsedAt` DATETIME DEFAULT NULL,
  `LastUsedIP` VARCHAR(45) DEFAULT NULL,
  `RevokedAt`  DATETIME DEFAULT NULL,
  `ExpiresAt`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uk_api_keys_prefix` (`KeyPrefix`),
  KEY `idx_api_keys_active` (`RevokedAt`, `ExpiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `api_key_requests` (
  `ID`          BIGINT NOT NULL AUTO_INCREMENT,
  `KeyID`       INT NOT NULL,
  `RequestedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_api_key_requests_key_time` (`KeyID`, `RequestedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `api_key_audit_log` (
  `ID`          BIGINT NOT NULL AUTO_INCREMENT,
  `KeyID`       INT DEFAULT NULL,
  `Endpoint`    VARCHAR(120) NOT NULL,
  `Method`      VARCHAR(10)  NOT NULL,
  `QueryString` VARCHAR(500) DEFAULT NULL,
  `IP`          VARCHAR(45)  DEFAULT NULL,
  `StatusCode`  SMALLINT     NOT NULL,
  `DurationMs`  INT          DEFAULT NULL,
  `CreatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_api_key_audit_key_time` (`KeyID`, `CreatedAt`),
  CONSTRAINT `fk_api_key_audit_key`
    FOREIGN KEY (`KeyID`) REFERENCES `api_keys`(`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
