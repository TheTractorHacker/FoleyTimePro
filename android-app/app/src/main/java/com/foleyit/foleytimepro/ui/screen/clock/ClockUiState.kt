package com.foleyit.foleytimepro.ui.screen.clock

import com.foleyit.foleytimepro.domain.model.PunchEntry
import com.foleyit.foleytimepro.domain.model.PunchStatus

data class ClockUiState(
    val status: PunchStatus? = null,
    val recent: List<PunchEntry> = emptyList(),
    val totalHours: String = "0.00",
    val note: String = "",
    val loading: Boolean = false,
    val punching: Boolean = false,
    val queuedCount: Int = 0,
    val actionMessage: String? = null,
    val actionIsError: Boolean = false,
    val companyName: String = "FoleyTimePro",
    val isOffline: Boolean = false,
) {
    val statusLabel: String
        get() = status?.status ?: "Out"
    val isIn: Boolean       get() = statusLabel == "In"
    val isLunch: Boolean    get() = statusLabel == "Lunch"
    val isOut: Boolean      get() = !isIn && !isLunch
}
