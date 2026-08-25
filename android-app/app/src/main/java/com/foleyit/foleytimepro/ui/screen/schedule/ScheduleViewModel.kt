package com.foleyit.foleytimepro.ui.screen.schedule

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.repository.ShiftRepository
import com.foleyit.foleytimepro.domain.model.Shift
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class ScheduleUiState(
    val shift: Shift? = null,
    val loading: Boolean = true,
    val error: String? = null,
    val isOffline: Boolean = false,
)

@HiltViewModel
class ScheduleViewModel @Inject constructor(
    private val repository: ShiftRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(ScheduleUiState())
    val state: StateFlow<ScheduleUiState> = _state.asStateFlow()

    // Cached shift stream; screens render this immediately then refresh takes over.
    val cachedShift: StateFlow<Shift?> = repository
        .observeCached()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), null)

    init { refresh() }

    fun refresh() {
        _state.update { it.copy(loading = true, error = null) }
        viewModelScope.launch {
            when (val res = repository.refresh()) {
                is ApiResult.Success      -> _state.update { it.copy(shift = res.data, loading = false, isOffline = false) }
                is ApiResult.NetworkError -> _state.update { it.copy(loading = false, isOffline = true) }
                is ApiResult.HttpError    -> _state.update { it.copy(loading = false, error = res.message) }
                is ApiResult.Unauthorized -> _state.update { it.copy(loading = false, error = "Session expired") }
                is ApiResult.Unknown      -> _state.update { it.copy(loading = false, error = res.throwable.message) }
            }
        }
    }
}
