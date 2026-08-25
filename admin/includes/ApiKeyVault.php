<?php
/**
 * ApiKeyVault — reversible storage for API keys, so admin/api_keys.php can show
 * a key's value again after creation.
 *
 * SECURITY MODEL
 * KeyHash (SHA-256 + API_KEY_PEPPER) remains the ONLY thing authentication ever
 * reads — this class has nothing to do with ApiKeyAuth::authenticate() and
 * changes no runtime auth behavior. This class only manages a SEPARATE,
 * additive encrypted copy for the admin UI's benefit.
 *
 * Encryption key (API_KEY_ENCRYPTION_KEY) lives only in .env, never in the
 * database — the same secret-splitting pattern API_KEY_PEPPER already uses for
 * hashing. A database-only leak (SQL injection, a stolen backup) yields
 * ciphertext and nothing else. See migrations/015_api_key_reveal.sql for the
 * full threat-model writeup; do not change that split without re-reading it.
 *
 * Storage format: base64(iv[12 bytes] . tag[16 bytes] . ciphertext).
 * AES-256-GCM: the tag makes tampering or a wrong key fail decryption loudly
 * (openssl_decrypt returns false) rather than silently returning garbage.
 */

final class ApiKeyVault
{
    private const CIPHER   = 'aes-256-gcm';
    private const IV_BYTES = 12;   // NIST-recommended GCM nonce length
    private const TAG_BYTES = 16;

    /** True when API_KEY_ENCRYPTION_KEY is configured. */
    public static function isEnabled(): bool
    {
        return self::rawKey() !== null;
    }

    /**
     * Encrypt a plaintext API key for storage. Returns null (never throws) when
     * the encryption key is unset — callers must treat that as "store nothing,
     * this key just won't be revealable," not as a hard failure: key CREATION
     * must keep working even when this optional feature isn't configured.
     */
    public static function encrypt(string $plaintext): ?string
    {
        $key = self::rawKey();
        if ($key === null) return null;

        $iv  = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES
        );
        if ($ciphertext === false) {
            error_log('[ApiKeyVault] openssl_encrypt failed: ' . (openssl_error_string() ?: 'unknown'));
            return null;
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a stored value. Returns null on ANY failure — missing key,
     * malformed blob, wrong key, or a failed GCM auth-tag check (tampered or
     * corrupted data) — and logs which, but never leaks that detail to the
     * caller's return value. Callers must render a generic "not available"
     * state on null, never a stack trace or the specific reason.
     */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') return null;

        $key = self::rawKey();
        if ($key === null) {
            error_log('[ApiKeyVault] decrypt requested but API_KEY_ENCRYPTION_KEY is unset');
            return null;
        }

        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            error_log('[ApiKeyVault] stored value is not a valid base64/length envelope');
            return null;
        }

        $iv         = substr($raw, 0, self::IV_BYTES);
        $tag        = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag
        );
        if ($plaintext === false) {
            error_log('[ApiKeyVault] decrypt failed — wrong key, or data was tampered/corrupted');
            return null;
        }

        return $plaintext;
    }

    /** Raw binary key from the hex .env value, or null if unset/malformed. */
    private static function rawKey(): ?string
    {
        $hex = $_ENV['API_KEY_ENCRYPTION_KEY'] ?? '';
        if (!is_string($hex) || !preg_match('/^[a-f0-9]{64}$/i', $hex)) return null;
        return hex2bin($hex);
    }
}
