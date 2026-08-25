package com.foleyit.foleytimepro.ui.screen.clock

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.repository.BrandingRepository
import com.foleyit.foleytimepro.data.repository.PunchRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.onEach
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class ClockViewModel @Inject constructor(
    private val punchRepository: PunchRepository,
    private val brandingRepository: BrandingRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(
        ClockUiState(companyName = brandingRepository.cachedCompanyName())
    )
    val state: StateFlow<ClockUiState> = _state.asStateFlow()

    val queuedCount: StateFlow<Int> = punchRepository
        .queuedCountForCurrentUser()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)

    init {
        // Merge queued count into the main ui state stream so screens can
        // observe a single StateFlow.
        queuedCount.onEach { count -> _state.update { it.copy(queuedCount = count) } }
            .launchIn(viewModelScope)
        refresh()
    }

    fun onNoteChanged(text: String) = _state.update { it.copy(note = text) }

    /**
     * Clears the one-shot punch result once the UI has shown it. The result is
     * surfaced as a Snackbar now rather than a line of text under the buttons,
     * and a Snackbar that re-fires on every recomposition is worse than none.
     */
    fun onActionMessageShown() = _state.update { it.copy(actionMessage = null) }

    fun refresh() {
        _state.update { it.copy(loading = true) }
        viewModelScope.launch {
            val statusResult = punchRepository.status()
            when (statusResult) {
                is ApiResult.Success -> _state.update { it.copy(status = statusResult.data, isOffline = false) }
                is ApiResult.NetworkError -> _state.update { it.copy(isOffline = true) }
                else -> Unit
            }
            val historyResult = punchRepository.refreshHistory()
            if (historyResult is ApiResult.Success) {
                _state.update {
                    it.copy(
                        recent = historyResult.data.entries.take(7),
                        totalHours = historyResult.data.totalHours,
                        loading = false,
                    )
                }
            } else {
                _state.update { it.copy(loading = false) }
            }
        }
    }

    fun punch(action: String) {
        _state.update { it.copy(punching = true, actionMessage = null) }
        val note = _state.value.note
        viewModelScope.launch {
            val outcome = punchRepository.punchOrQueue(
                action = action,
                note = note,
                latitude = null,
                longitude = null,
            )
            when (outcome) {
                is PunchRepository.PunchOutcome.Committed -> {
                    _state.update {
                        it.copy(
                            note = "",
                            punching = false,
                            actionMessage = "Recorded at ${outcome.timeText}",
                            actionIsError = false,
                            isOffline = false,
                        )
                    }
                    refresh()
                }
                PunchRepository.PunchOutcome.Queued -> _state.update {
                    it.copy(
                        note = "",
                        punching = false,
                        actionMessage = "Offline — punch queued for sync",
                        actionIsError = false,
                        isOffline = true,
                    )
                }
                is PunchRepository.PunchOutcome.Failed -> _state.update {
                    it.copy(
                        punching = false,
                        actionMessage = outcome.message,
                        actionIsError = true,
                    )
                }
            }
        }
    }
}
