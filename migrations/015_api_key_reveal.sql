-- Migration 015: reversible storage for API keys, so a key can be viewed
-- again after creation instead of only ever being shown once.
--
-- SECURITY MODEL (read before touching this column)
-- KeyHash is UNCHANGED and remains the only thing authentication ever reads —
-- ApiKeyAuth::authenticate() still verifies against the one-way SHA-256 hash,
-- exactly as before. EncryptedKey is a SEPARATE, additive column holding the
-- same plaintext encrypted with AES-256-GCM under API_KEY_ENCRYPTION_KEY, a
-- secret that lives ONLY in .env — never in this database. That split means a
-- database-only compromise (SQL injection, a stolen mysqldump, a leaked
-- snapshot) yields ciphertext and nothing else: the pepper alone still can't
-- reverse KeyHash, and API_KEY_ENCRYPTION_KEY alone can't decrypt EncryptedKey
-- without this table. Only an attacker with BOTH the database AND the
-- filesystem's .env can recover a live key — the same trust boundary
-- API_KEY_PEPPER already establishes for hashing, applied to encryption.
--
-- API_KEY_ENCRYPTION_KEY MUST be a value distinct from API_KEY_PEPPER. Reusing
-- one secret for both hashing and encryption means a future weakness or leak
-- in either mechanism compromises both; independent secrets can also be
-- rotated independently (rotating the encryption key only blanks the
-- reveal feature for existing rows — it does not invalidate any key's
-- ability to authenticate, since KeyHash/KeyPrefix are untouched).
--
-- EncryptedKey is NULL for:
--   * every key created before this migration (the plaintext was never
--     retained, so there is nothing to encrypt after the fact — those keys
--     are not retroactively made viewable, only newly created ones are)
--   * any key created while API_KEY_ENCRYPTION_KEY is unset in .env
-- The admin UI must treat NULL as "not available", never as an error.
--
-- Safe to re-run (IF NOT EXISTS guard, MariaDB feature already relied on by
-- migration 010).

ALTER TABLE `api_keys`
  ADD COLUMN IF NOT EXISTS `EncryptedKey` TEXT DEFAULT NULL
    COMMENT 'base64(iv[12] . gcmTag[16] . ciphertext), AES-256-GCM under API_KEY_ENCRYPTION_KEY. NULL = not revealable (pre-migration key, or encryption key unset at creation time).'
  AFTER `KeyHash`;
