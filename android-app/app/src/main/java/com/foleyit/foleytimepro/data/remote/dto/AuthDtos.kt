package com.foleyit.foleytimepro.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class LoginRequest(
    val email: String,
    val password: String,
    @SerialName("twofa_code") val twoFaCode: String? = null,
)

@Serializable
data class RefreshRequest(
    @SerialName("refresh_token") val refreshToken: String,
)

@Serializable
data class LoginData(
    @SerialName("access_token")  val accessToken: String = "",
    @SerialName("refresh_token") val refreshToken: String = "",
    @SerialName("expires_in")    val expiresIn: Int = 900,
    val user: UserDto = UserDto(),
    @SerialName("requires_2fa")  val requires2FA: Boolean = false,
)

@Serializable
data class UserDto(
    val id: Int = -1,
    val email: String = "",
    @SerialName("first_name") val firstName: String = "",
    @SerialName("last_name")  val lastName: String = "",
)
