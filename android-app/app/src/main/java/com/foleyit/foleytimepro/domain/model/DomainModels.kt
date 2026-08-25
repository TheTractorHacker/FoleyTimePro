package com.foleyit.foleytimepro.domain.model

/**
 * Plain domain models used by ViewModels and screens.
 *
 * Kept separate from the data/remote/dto package so the UI doesn't depend on the JSON
 * wire format — if the server ever changes shape, only DTO → domain mappers
 * change, not every screen.
 */

data class PunchStatus(
    val name: String,
    val status: String,                 // In | Out | Lunch
    val clockInTime: String?,
    val lunchStartTime: String?,
    val elapsedSeconds: Long,
)

data class PunchEntry(
    val date: String,
    val inTime: String?,
    val lunchStart: String?,
    val lunchEnd: String?,
    val outTime: String?,
    val hours: String?,
    val note: String,
    val source: String? = null,
)

data class PunchHistory(
    val entries: List<PunchEntry>,
    val totalHours: String,
)

data class TimeOffItem(
    val id: Int,
    val startDate: String,
    val endDate: String,
    val reason: String,
    val status: String,
    val type: String,
    val createdAt: String,
)

data class Shift(
    val name: String,
    val startTime: String,
    val endTime: String,
    val daysOfWeek: List<String>,
    val lunchDurationMinutes: Int,
    val lunchStartTime: String,
)

data class Session(
    val userId: Int,
    val email: String,
    val displayName: String,
    val requires2FA: Boolean,
)
