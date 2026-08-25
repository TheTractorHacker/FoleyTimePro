package com.foleyit.foleytimepro.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class DeviceRegisterRequest(
    val token: String,
    val platform: String = "android",
    @SerialName("app_version") val appVersion: String = "",
    @SerialName("device_model") val deviceModel: String = "",
    @SerialName("os_version")  val osVersion: String = "",
)

@Serializable
data class DeviceUnregisterRequest(
    val token: String,
)
