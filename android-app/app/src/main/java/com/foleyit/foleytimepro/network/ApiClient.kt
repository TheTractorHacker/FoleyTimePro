package com.foleyit.foleytimepro.network

import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

data class BrandingResult(val success: Boolean, val companyName: String = "", val error: String = "")

data class PunchStatus(
    val success: Boolean,
    val name: String = "",
    val status: String = "Out",   // "In", "Out", "Lunch"
    val clockInTime: String? = null,
    val lunchStartTime: String? = null,
    val elapsedSeconds: Long = 0,
    val error: String = ""
)

data class PunchResult(
    val success: Boolean,
    val message: String = "",
    val time: String = "",
    val hoursWorked: String = "",
    val error: String = ""
)

data class PunchEntry(
    val date: String,
    val inTime: String?,
    val lunchStart: String?,
    val lunchEnd: String?,
    val outTime: String?,
    val hours: String?,
    val note: String
)

data class PunchHistory(
    val success: Boolean,
    val punches: List<PunchEntry> = emptyList(),
    val totalHours: String = "0.00",
    val error: String = ""
)

data class LoginResult(
    val success: Boolean,
    val accessToken: String = "",
    val refreshToken: String = "",
    val expiresIn: Int = 900,
    val userId: Int = -1,
    val email: String = "",
    val firstName: String = "",
    val lastName: String = "",
    val requires2FA: Boolean = false,
    val error: String = ""
)

data class SessionResult(val success: Boolean, val cookies: List<String> = emptyList(), val error: String = "")

class ApiClient(private val baseUrl: String) {

    private val http = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    private val JSON_TYPE = "application/json; charset=utf-8".toMediaType()

    fun fetchBranding(): BrandingResult = try {
        val resp = http.newCall(Request.Builder().url("$baseUrl/api/v1/branding").build()).execute()
        val json = JSONObject(resp.body?.string() ?: "{}")
        if (json.optBoolean("success")) {
            val data = json.optJSONObject("data")
            BrandingResult(true, data?.optString("company_name", "FoleyTimePro") ?: "FoleyTimePro")
        } else {
            BrandingResult(false, error = "Server did not respond as a FoleyTimePro instance")
        }
    } catch (e: IOException) {
        BrandingResult(false, error = "Cannot reach server: ${e.message}")
    } catch (e: Exception) {
        BrandingResult(false, error = "Invalid server response")
    }

    fun login(email: String, password: String, twoFaCode: String? = null): LoginResult = try {
        val body = JSONObject().apply {
            put("email", email); put("password", password)
            twoFaCode?.let { put("twofa_code", it) }
        }.toString().toRequestBody(JSON_TYPE)

        val resp = http.newCall(
            Request.Builder().url("$baseUrl/api/v1/auth/login").post(body).build()
        ).execute()
        val json = JSONObject(resp.body?.string() ?: "{}")

        if (json.optBoolean("success")) {
            val d = json.getJSONObject("data")
            val u = d.getJSONObject("user")
            LoginResult(
                success = true,
                accessToken = d.optString("access_token"),
                refreshToken = d.optString("refresh_token"),
                expiresIn  = d.optInt("expires_in", 900),
                userId     = u.optInt("id"),
                email      = u.optString("email"),
                firstName  = u.optString("first_name"),
                lastName   = u.optString("last_name")
            )
        } else {
            val requires2FA = json.optJSONObject("data")?.optBoolean("requires_2fa") ?: false
            LoginResult(false, requires2FA = requires2FA, error = json.optString("message", "Login failed"))
        }
    } catch (e: Exception) {
        LoginResult(false, error = "Network error: ${e.message}")
    }

    fun createWebViewSession(accessToken: String): SessionResult = try {
        val resp = http.newCall(
            Request.Builder()
                .url("$baseUrl/api/v1/auth/webview-session")
                .header("Authorization", "Bearer $accessToken")
                .post("".toRequestBody(null))
                .build()
        ).execute()
        SessionResult(resp.isSuccessful, resp.headers("Set-Cookie"))
    } catch (e: Exception) {
        SessionResult(false, error = e.message ?: "Session creation failed")
    }

    fun getPunchStatus(token: String): PunchStatus = try {
        val resp = http.newCall(
            Request.Builder()
                .url("$baseUrl/api/v1/punch/status")
                .header("Authorization", "Bearer $token")
                .build()
        ).execute()
        val json = JSONObject(resp.body?.string() ?: "{}")
        if (json.optBoolean("success")) {
            val d = json.getJSONObject("data")
            PunchStatus(
                success        = true,
                name           = d.optString("name"),
                status         = d.optString("status", "Out"),
                clockInTime    = d.optString("clock_in_time").takeIf { it.isNotEmpty() },
                lunchStartTime = d.optString("lunch_start_time").takeIf { it.isNotEmpty() },
                elapsedSeconds = d.optLong("elapsed_seconds")
            )
        } else PunchStatus(false, error = json.optString("message", "Failed to get status"))
    } catch (e: Exception) { PunchStatus(false, error = e.message ?: "Network error") }

    fun punch(token: String, action: String, note: String = "", lat: Double? = null, lon: Double? = null): PunchResult = try {
        val body = JSONObject().apply {
            put("note", note)
            lat?.let { put("latitude", it) }
            lon?.let { put("longitude", it) }
        }.toString().toRequestBody(JSON_TYPE)
        val resp = http.newCall(
            Request.Builder()
                .url("$baseUrl/api/v1/punch/$action")
                .header("Authorization", "Bearer $token")
                .post(body)
                .build()
        ).execute()
        val json = JSONObject(resp.body?.string() ?: "{}")
        if (json.optBoolean("success")) {
            val d = json.optJSONObject("data")
            PunchResult(true, json.optString("message"), d?.optString("time") ?: "", d?.optString("hours_worked") ?: "")
        } else PunchResult(false, error = json.optString("message", "Punch failed"))
    } catch (e: Exception) { PunchResult(false, error = e.message ?: "Network error") }

    fun getPunchHistory(token: String): PunchHistory = try {
        val resp = http.newCall(
            Request.Builder()
                .url("$baseUrl/api/v1/punch/history")
                .header("Authorization", "Bearer $token")
                .build()
        ).execute()
        val json = JSONObject(resp.body?.string() ?: "{}")
        if (json.optBoolean("success")) {
            val d = json.getJSONObject("data")
            val arr = d.optJSONArray("punches") ?: org.json.JSONArray()
            val list = (0 until arr.length()).map { i ->
                val p = arr.getJSONObject(i)
                PunchEntry(
                    date       = p.optString("date"),
                    inTime     = p.optString("in").takeIf { it.isNotEmpty() },
                    lunchStart = p.optString("lunch_start").takeIf { it.isNotEmpty() },
                    lunchEnd   = p.optString("lunch_end").takeIf { it.isNotEmpty() },
                    outTime    = p.optString("out").takeIf { it.isNotEmpty() },
                    hours      = p.optString("hours").takeIf { it.isNotEmpty() },
                    note       = p.optString("note")
                )
            }
            PunchHistory(true, list, d.optString("total_hours", "0.00"))
        } else PunchHistory(false, error = json.optString("message", "Failed to load history"))
    } catch (e: Exception) { PunchHistory(false, error = e.message ?: "Network error") }

    fun refreshToken(refreshToken: String): LoginResult = try {
        val body = JSONObject().apply { put("refresh_token", refreshToken) }
            .toString().toRequestBody(JSON_TYPE)
        val resp = http.newCall(
            Request.Builder().url("$baseUrl/api/v1/auth/refresh").post(body).build()
        ).execute()
        val json = JSONObject(resp.body?.string() ?: "{}")
        if (json.optBoolean("success")) {
            val d = json.getJSONObject("data")
            LoginResult(true, accessToken = d.optString("access_token"), expiresIn = d.optInt("expires_in", 900))
        } else {
            LoginResult(false, error = json.optString("message", "Token refresh failed"))
        }
    } catch (e: Exception) {
        LoginResult(false, error = e.message ?: "Token refresh failed")
    }
}
