package com.foleyit.foleytimepro.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class TimeOffRequestDto(
    val id: Int = -1,
    @SerialName("start_date") val startDate: String = "",
    @SerialName("end_date")   val endDate: String = "",
    val reason: String = "",
    val status: String = "",
    val type: String = "Vacation",
    @SerialName("created_at") val createdAt: String = "",
)

@Serializable
data class TimeOffListDto(
    val requests: List<TimeOffRequestDto> = emptyList(),
)

@Serializable
data class TimeOffSubmitRequest(
    @SerialName("start_date") val startDate: String,
    @SerialName("end_date")   val endDate: String,
    val reason: String,
    val type: String = "Vacation",
)
