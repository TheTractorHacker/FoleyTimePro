package com.foleyit.foleytimepro.ui.screen.timeoff

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.repository.TimeOffRepository
import com.foleyit.foleytimepro.domain.model.TimeOffItem
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class TimeOffUiState(
    val requests: List<TimeOffItem> = emptyList(),
    val loading: Boolean = true,
    val submitting: Boolean = false,
    val statusMessage: String? = null,
    val statusOk: Boolean = true,
)

@HiltViewModel
class TimeOffViewModel @Inject constructor(
    private val repository: TimeOffRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(TimeOffUiState())
    val state: StateFlow<TimeOffUiState> = _state.asStateFlow()

    init { refresh() }

    fun refresh() {
        _state.update { it.copy(loading = true) }
        viewModelScope.launch {
            when (val res = repository.list()) {
                is ApiResult.Success -> _state.update { it.copy(requests = res.data, loading = false) }
                else                 -> _state.update { it.copy(loading = false) }
            }
        }
    }

    fun submit(startDate: String, endDate: String, reason: String, type: String = "Vacation") {
        _state.update { it.copy(submitting = true, statusMessage = null) }
        viewModelScope.launch {
            when (val res = repository.submit(startDate, endDate, reason, type)) {
                is ApiResult.Success -> {
                    _state.update {
                        it.copy(submitting = false, statusMessage = "Request submitted — pending approval", statusOk = true)
                    }
                    refresh()
                }
                is ApiResult.HttpError    -> _state.update { it.copy(submitting = false, statusMessage = res.message, statusOk = false) }
                is ApiResult.NetworkError -> _state.update { it.copy(submitting = false, statusMessage = "Offline — try again when connected", statusOk = false) }
                is ApiResult.Unauthorized -> _state.update { it.copy(submitting = false, statusMessage = "Session expired", statusOk = false) }
                is ApiResult.Unknown      -> _state.update { it.copy(submitting = false, statusMessage = res.throwable.message ?: "Unexpected error", statusOk = false) }
            }
        }
    }

    fun dismissStatus() = _state.update { it.copy(statusMessage = null) }
}
