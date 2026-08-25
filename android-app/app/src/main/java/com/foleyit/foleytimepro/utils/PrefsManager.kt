package com.foleyit.foleytimepro.utils

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Encrypted key-value store for auth tokens, user identity, and kiosk settings.
 *
 * Backed by `EncryptedSharedPreferences` (AES-256-GCM values, AES-256-SIV keys)
 * so at-rest reads by another app or a physical attacker require the device
 * keystore. The underlying prefs file is also excluded from cloud backup and
 * device-transfer (see res/xml/backup_rules.xml and data_extraction_rules.xml).
 *
 * Provided as a `@Singleton` via `di/StorageModule.kt`.
 */
class PrefsManager(context: Context) {

    private val masterKey = MasterKey.Builder(context)
        .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
        .build()

    private val prefs = EncryptedSharedPreferences.create(
        context,
        FILE_NAME,
        masterKey,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )

    // ---- Reactive access-token stream ----
    // ViewModels observe this to reflect forced-logout events (token cleared elsewhere).
    private val _accessTokenFlow = MutableStateFlow(getAccessToken())
    val accessTokenFlow: StateFlow<String> = _accessTokenFlow.asStateFlow()

    // ---- Instance URL ----
    fun getInstanceUrl(): String    = prefs.getString(KEY_INSTANCE_URL, "").orEmpty()
    fun setInstanceUrl(url: String) = prefs.edit().putString(KEY_INSTANCE_URL, url.trimEnd('/')).apply()

    // ---- Tokens ----
    fun getAccessToken(): String  = prefs.getString(KEY_ACCESS_TOKEN, "").orEmpty()
    fun getRefreshToken(): String = prefs.getString(KEY_REFRESH_TOKEN, "").orEmpty()
    fun getTokenExpiry(): Long    = prefs.getLong(KEY_TOKEN_EXPIRY, 0)
    fun isTokenExpired(): Boolean = System.currentTimeMillis() > getTokenExpiry() - 30_000

    @Synchronized
    fun saveTokens(access: String, refresh: String, expiresIn: Int = 900) {
        prefs.edit()
            .putString(KEY_ACCESS_TOKEN, access)
            .putString(KEY_REFRESH_TOKEN, refresh)
            .putLong(KEY_TOKEN_EXPIRY, System.currentTimeMillis() + expiresIn * 1000L)
            .apply()
        _accessTokenFlow.value = access
    }

    // Partial update — /auth?action=refresh may return only a new access token.
    @Synchronized
    fun updateAccessToken(access: String, expiresIn: Int = 900) {
        prefs.edit()
            .putString(KEY_ACCESS_TOKEN, access)
            .putLong(KEY_TOKEN_EXPIRY, System.currentTimeMillis() + expiresIn * 1000L)
            .apply()
        _accessTokenFlow.value = access
    }

    // ---- User ----
    fun saveUser(id: Int, email: String, firstName: String, lastName: String) {
        prefs.edit()
            .putInt(KEY_USER_ID, id)
            .putString(KEY_USER_EMAIL, email)
            .putString(KEY_USER_NAME, "$firstName $lastName")
            .apply()
    }

    fun getUserId(): Int         = prefs.getInt(KEY_USER_ID, -1)
    fun getUserEmail(): String   = prefs.getString(KEY_USER_EMAIL, "").orEmpty()
    fun getUserName(): String    = prefs.getString(KEY_USER_NAME, "").orEmpty()

    // ---- Branding ----
    fun getCompanyName(): String        = prefs.getString(KEY_COMPANY_NAME, "FoleyTimePro") ?: "FoleyTimePro"
    fun saveCompanyName(name: String)   = prefs.edit().putString(KEY_COMPANY_NAME, name).apply()

    // ---- FCM ----
    fun getFcmToken(): String            = prefs.getString(KEY_FCM_TOKEN, "").orEmpty()
    fun setFcmToken(token: String)       = prefs.edit().putString(KEY_FCM_TOKEN, token).apply()
    fun isFcmRegistered(): Boolean       = prefs.getBoolean(KEY_FCM_REGISTERED, false)
    fun setFcmRegistered(v: Boolean)     = prefs.edit().putBoolean(KEY_FCM_REGISTERED, v).apply()

    // ---- Kiosk / session settings ----
    fun getInactivityTimeoutMs(): Long                = prefs.getLong(KEY_INACTIVITY_MS, DEFAULT_INACTIVITY_MS)
    fun setInactivityTimeoutMs(ms: Long)              = prefs.edit().putLong(KEY_INACTIVITY_MS, ms).apply()

    // ---- Schema versioning (for future prefs migrations) ----
    fun getSchemaVersion(): Int          = prefs.getInt(KEY_SCHEMA_VERSION, 1)
    fun setSchemaVersion(v: Int)         = prefs.edit().putInt(KEY_SCHEMA_VERSION, v).apply()

    // ---- Session teardown ----
    @Synchronized
    fun clearSession() {
        prefs.edit()
            .remove(KEY_ACCESS_TOKEN)
            .remove(KEY_REFRESH_TOKEN)
            .remove(KEY_TOKEN_EXPIRY)
            .remove(KEY_USER_ID)
            .remove(KEY_USER_EMAIL)
            .remove(KEY_USER_NAME)
            .remove(KEY_FCM_REGISTERED)
            .apply()
        _accessTokenFlow.value = ""
    }

    fun clearAll() {
        prefs.edit().clear().apply()
        _accessTokenFlow.value = ""
    }

    companion object {
        const val FILE_NAME = "foley_timepro_secure"
        const val DEFAULT_INACTIVITY_MS = 90_000L

        private const val KEY_INSTANCE_URL     = "instance_url"
        private const val KEY_ACCESS_TOKEN     = "access_token"
        private const val KEY_REFRESH_TOKEN    = "refresh_token"
        private const val KEY_TOKEN_EXPIRY     = "token_expiry"
        private const val KEY_USER_ID          = "user_id"
        private const val KEY_USER_EMAIL       = "user_email"
        private const val KEY_USER_NAME        = "user_name"
        private const val KEY_COMPANY_NAME     = "company_name"
        private const val KEY_FCM_TOKEN        = "fcm_token"
        private const val KEY_FCM_REGISTERED   = "fcm_registered"
        private const val KEY_INACTIVITY_MS    = "inactivity_ms"
        private const val KEY_SCHEMA_VERSION   = "schema_version"
    }
}
