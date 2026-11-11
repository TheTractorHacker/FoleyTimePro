-- Migration 020: api_login_attempts
--
-- api/v1/auth.php (login throttling) and api/middleware/ApiKeyAuth.php (per-IP
-- throttle on failed API-key auth) both read and write this table, but no
-- migration or Install/timeclock-schema.sql ever created it. A fresh install
-- therefore answered every API login with a 500 ("Table ... api_login_attempts
-- doesn't exist"). Idempotent: harmless on installs that already have it.

CREATE TABLE IF NOT EXISTS `api_login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_identifier_time` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
