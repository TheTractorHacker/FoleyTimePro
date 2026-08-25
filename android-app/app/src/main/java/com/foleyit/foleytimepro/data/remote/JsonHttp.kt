package com.foleyit.foleytimepro.data.remote

import com.foleyit.foleytimepro.data.remote.dto.Envelope
import com.foleyit.foleytimepro.data.remote.dto.RawEnvelope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.KSerializer
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import java.io.IOException
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Shared JSON I/O built on OkHttp (per CLAUDE.md — no Retrofit).
 *
 * All calls suspend on `Dispatchers.IO`, return `ApiResult<T>`, and decode via
 * the API's uniform `Envelope` wrapper. `IOException` becomes `NetworkError`,
 * `401` becomes `Unauthorized` (the `TokenAuthenticator` has already had its
 * chance to refresh at that point), other non-2xx becomes `HttpError`, and any
 * decode/other failure becomes `Unknown` with the raw throwable.
 */
@Singleton
class JsonHttp @Inject constructor(
    private val client: OkHttpClient,
    val json: Json,
) {

    val jsonMediaType = "application/json; charset=utf-8".toMediaType()

    fun get(url: String, headersMap: Map<String, String> = emptyMap()): Request =
        buildRequest("GET", url, headersMap, null)

    /**
     * For endpoints where the caller only cares whether the request succeeded
     * — the envelope's `success` boolean and `message` are all that matters.
     * Handles a null/absent `data` field cleanly (which the generic
     * [execute] path would treat as a decode failure).
     */
    suspend fun executeNoPayload(request: Request): ApiResult<Unit> = withContext(Dispatchers.IO) {
        try {
            client.newCall(request).execute().use { resp ->
                val bodyStr = resp.body?.string().orEmpty()
                if (resp.code == 401) return@use ApiResult.Unauthorized
                if (!resp.isSuccessful) {
                    return@use ApiResult.HttpError(
                        resp.code,
                        tryExtractMessage(bodyStr) ?: "HTTP ${resp.code}",
                        tryExtractServerCode(bodyStr),
                    )
                }
                val env = json.decodeFromString(RawEnvelope.serializer(), bodyStr.ifBlank { "{}" })
                if (env.success) ApiResult.Success(Unit)
                else ApiResult.HttpError(resp.code, env.message ?: "Request failed")
            }
        } catch (io: IOException) {
            ApiResult.NetworkError
        } catch (t: Throwable) {
            ApiResult.Unknown(t)
        }
    }

    /**
     * `execute` is `suspend` and off-loads to Dispatchers.IO before invoking
     * the (blocking) OkHttp call. Made public + non-inline so DI/testing works.
     */
    suspend fun <T> execute(
        request: Request,
        payloadSerializer: KSerializer<T>,
    ): ApiResult<T> = withContext(Dispatchers.IO) {
        try {
            client.newCall(request).execute().use { resp ->
                parse(resp, payloadSerializer)
            }
        } catch (io: IOException) {
            ApiResult.NetworkError
        } catch (t: Throwable) {
            ApiResult.Unknown(t)
        }
    }

    fun buildRequest(
        method: String,
        url: String,
        headers: Map<String, String>,
        body: RequestBody?,
    ): Request {
        val builder = Request.Builder().url(url)
        headers.forEach { (k, v) -> builder.header(k, v) }
        when (method) {
            "GET"  -> builder.get()
            "POST" -> builder.post(body ?: "".toRequestBody(null))
            else   -> builder.method(method, body)
        }
        return builder.build()
    }

    private fun <T> parse(
        resp: Response,
        payloadSerializer: KSerializer<T>,
    ): ApiResult<T> {
        val bodyStr = resp.body?.string().orEmpty()
        if (resp.code == 401) return ApiResult.Unauthorized
        if (!resp.isSuccessful) {
            val message = tryExtractMessage(bodyStr) ?: "HTTP ${resp.code}"
            val code    = tryExtractServerCode(bodyStr)
            return ApiResult.HttpError(resp.code, message, code)
        }
        return try {
            val envSerializer = Envelope.serializer(payloadSerializer)
            val env = json.decodeFromString(envSerializer, bodyStr.ifBlank { "{}" })
            if (env.success) {
                @Suppress("UNCHECKED_CAST")
                val data: T = env.data ?: Unit as T
                ApiResult.Success(data)
            } else {
                ApiResult.HttpError(resp.code, env.message ?: "Request failed", env.serverCode)
            }
        } catch (t: Throwable) {
            ApiResult.Unknown(t)
        }
    }

    private fun tryExtractMessage(body: String): String? = try {
        val env = json.decodeFromString(Envelope.serializer(String.serializer()), body.ifBlank { "{}" })
        env.message
    } catch (_: Throwable) { null }

    private fun tryExtractServerCode(body: String): String? = try {
        val env = json.decodeFromString(Envelope.serializer(String.serializer()), body.ifBlank { "{}" })
        env.serverCode
    } catch (_: Throwable) { null }
}
