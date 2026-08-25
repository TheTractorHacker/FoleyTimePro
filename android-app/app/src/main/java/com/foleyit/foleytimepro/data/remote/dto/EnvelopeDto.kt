package com.foleyit.foleytimepro.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/**
 * The PHP API's uniform response envelope:
 *   { "success": bool, "message": string?, "data": T?, "code": string? }
 *
 * Each domain API decodes its `data` with the concrete type it expects.
 */
@Serializable
data class Envelope<T>(
    val success: Boolean = false,
    val message: String? = null,
    @SerialName("code") val serverCode: String? = null,
    val data: T? = null,
)

@Serializable
data class EmptyPayload(val ignored: String? = null)

/** Raw envelope when the caller wants to inspect `data` manually. */
@Serializable
data class RawEnvelope(
    val success: Boolean = false,
    val message: String? = null,
    val data: JsonElement? = null,
)
