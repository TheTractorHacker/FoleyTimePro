package com.foleyit.foleytimepro.utils

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

class PrefsManager(context: Context) {

    private val masterKey = MasterKey.Builder(context)
        .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
        .build()

    private val prefs = EncryptedSharedPreferences.create(
        context,
        "foley_timepro_secure",
        masterKey,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )

    companion object {
        private const val KEY_INSTANCE_URL   = "instance_url"
        private const val KEY_ACCESS_TOKEN   = "access_token"
        private const val KEY_REFRESH_TOKEN  = "refresh_token"
        private const val KEY_TOKEN_EXPIRY   = "token_expiry"
        private const val KEY_USER_ID        = "user_id"
        private const val KEY_USER_EMAIL     = "user_email"
        private const val KEY_USER_NAME      = "user_name"
        private const val KEY_COMPANY_NAME   = "company_name"
    }

    fun getInstanceUrl(): String    = prefs.getString(KEY_INSTANCE_URL, "") ?: ""
    fun setInstanceUrl(url: String) = prefs.edit().putString(KEY_INSTANCE_URL, url.trimEnd('/')).apply()

    fun getAccessToken(): String  = prefs.getString(KEY_ACCESS_TOKEN, "") ?: ""
    fun getRefreshToken(): String = prefs.getString(KEY_REFRESH_TOKEN, "") ?: ""
    fun getTokenExpiry(): Long    = prefs.getLong(KEY_TOKEN_EXPIRY, 0)
    fun isTokenExpired(): Boolean = System.currentTimeMillis() > getTokenExpiry() - 30_000

    fun saveTokens(access: String, refresh: String, expiresIn: Int = 900) {
        prefs.edit()
            .putString(KEY_ACCESS_TOKEN, access)
            .putString(KEY_REFRESH_TOKEN, refresh)
            .putLong(KEY_TOKEN_EXPIRY, System.currentTimeMillis() + expiresIn * 1000L)
            .apply()
    }

    fun saveUser(id: Int, email: String, firstName: String, lastName: String) {
        prefs.edit()
            .putInt(KEY_USER_ID, id)
            .putString(KEY_USER_EMAIL, email)
            .putString(KEY_USER_NAME, "$firstName $lastName")
            .apply()
    }

    fun getUserName(): String    = prefs.getString(KEY_USER_NAME, "") ?: ""
    fun getCompanyName(): String = prefs.getString(KEY_COMPANY_NAME, "FoleyTimePro") ?: "FoleyTimePro"
    fun saveCompanyName(name: String) = prefs.edit().putString(KEY_COMPANY_NAME, name).apply()

    fun clearSession() {
        prefs.edit()
            .remove(KEY_ACCESS_TOKEN).remove(KEY_REFRESH_TOKEN)
            .remove(KEY_TOKEN_EXPIRY).remove(KEY_USER_ID)
            .remove(KEY_USER_EMAIL).remove(KEY_USER_NAME)
            .apply()
    }

    fun clearAll() = prefs.edit().clear().apply()
}
