package com.foleyit.foleytimepro.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class PunchRequest(
    val note: String = "",
    val latitude: Double? = null,
    val longitude: Double? = null,
    // Epoch millis when the punch was actually captured on-device. Server may
    // treat this as a backdated punch if it arrives via the offline queue.
    @SerialName("captured_at") val capturedAt: Long? = null,
    // Marks the source so the server can flag offline-drained rows.
    val source: String = "live",
)

@Serializable
data class PunchStatusDto(
    val name: String = "",
    val status: String = "Out",                     // "In" | "Out" | "Lunch"
    @SerialName("clock_in_time")    val clockInTime: String? = null,
    @SerialName("lunch_start_time") val lunchStartTime: String? = null,
    @SerialName("elapsed_seconds")  val elapsedSeconds: Long = 0,
)

@Serializable
data class PunchResultDto(
    val time: String = "",
    @SerialName("hours_worked") val hoursWorked: String = "",
)

@Serializable
data class PunchEntryDto(
    val date: String = "",
    @SerialName("in")          val inTime: String? = null,
    @SerialName("lunch_start") val lunchStart: String? = null,
    @SerialName("lunch_end")   val lunchEnd: String? = null,
    @SerialName("out")         val outTime: String? = null,
    val hours: String? = null,
    val note: String = "",
    val source: String? = null,
)

@Serializable
data class PunchHistoryDto(
    val punches: List<PunchEntryDto> = emptyList(),
    @SerialName("total_hours") val totalHours: String = "0.00",
)
