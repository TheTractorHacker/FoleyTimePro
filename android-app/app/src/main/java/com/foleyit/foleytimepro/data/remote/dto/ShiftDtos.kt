package com.foleyit.foleytimepro.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class ShiftDto(
    val name: String = "",
    @SerialName("start_time")       val startTime: String = "",
    @SerialName("end_time")         val endTime: String = "",
    val days: List<String> = emptyList(),
    @SerialName("lunch_duration")   val lunchDuration: Int = 0,
    @SerialName("lunch_start_time") val lunchStartTime: String = "",
)

@Serializable
data class ShiftListDto(
    val shifts: List<ShiftDto> = emptyList(),
)
