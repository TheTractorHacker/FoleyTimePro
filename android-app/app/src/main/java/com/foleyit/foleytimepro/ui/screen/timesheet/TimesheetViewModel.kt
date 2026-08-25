package com.foleyit.foleytimepro.ui.screen.timesheet

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.repository.PunchRepository
import com.foleyit.foleytimepro.domain.model.PunchEntry
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import javax.inject.Inject

data class TimesheetUiState(
    val entries: List<PunchEntry> = emptyList(),
    val totalHours: String = "0.00",
    val weekOffset: Int = 0,
    val loading: Boolean = true,
    val error: String? = null,
    val isOffline: Boolean = false,
) {
    val weekLabel: String get() = weekLabelFor(weekOffset)
    val canGoForward: Boolean get() = weekOffset < 0
}

@HiltViewModel
class TimesheetViewModel @Inject constructor(
    private val punchRepository: PunchRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(TimesheetUiState())
    val state: StateFlow<TimesheetUiState> = _state.asStateFlow()

    init { loadWeek() }

    fun previousWeek() { _state.update { it.copy(weekOffset = it.weekOffset - 1) }; loadWeek() }
    fun nextWeek()     { if (_state.value.canGoForward) { _state.update { it.copy(weekOffset = it.weekOffset + 1) }; loadWeek() } }
    fun refresh()      { loadWeek() }

    private fun loadWeek() {
        _state.update { it.copy(loading = true, error = null) }
        val (start, end) = weekDatesFor(_state.value.weekOffset)
        viewModelScope.launch {
            when (val res = punchRepository.refreshHistory(start, end)) {
                is ApiResult.Success -> _state.update {
                    it.copy(
                        entries = res.data.entries,
                        totalHours = res.data.totalHours,
                        loading = false,
                        isOffline = false,
                    )
                }
                is ApiResult.NetworkError -> _state.update {
                    it.copy(loading = false, isOffline = true, error = "Offline — showing cached data")
                }
                is ApiResult.HttpError    -> _state.update { it.copy(loading = false, error = res.message) }
                is ApiResult.Unauthorized -> _state.update { it.copy(loading = false, error = "Session expired") }
                is ApiResult.Unknown      -> _state.update { it.copy(loading = false, error = res.throwable.message) }
            }
        }
    }
}

private fun weekDatesFor(offset: Int): Pair<String, String> {
    val iso = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    val cal = Calendar.getInstance()
    cal.add(Calendar.WEEK_OF_YEAR, offset)
    cal.set(Calendar.DAY_OF_WEEK, Calendar.MONDAY)
    val start = iso.format(cal.time)
    cal.add(Calendar.DAY_OF_MONTH, 6)
    val end = iso.format(cal.time)
    return Pair(start, end)
}

private fun weekLabelFor(offset: Int): String {
    val cal = Calendar.getInstance()
    cal.add(Calendar.WEEK_OF_YEAR, offset)
    cal.set(Calendar.DAY_OF_WEEK, Calendar.MONDAY)
    val fmt = SimpleDateFormat("MMM d", Locale.US)
    val end = (cal.clone() as Calendar).apply { add(Calendar.DAY_OF_MONTH, 6) }
    return if (offset == 0) "This Week  •  ${fmt.format(cal.time)} – ${fmt.format(end.time)}"
           else "${fmt.format(cal.time)} – ${SimpleDateFormat("MMM d, yyyy", Locale.US).format(end.time)}"
}
