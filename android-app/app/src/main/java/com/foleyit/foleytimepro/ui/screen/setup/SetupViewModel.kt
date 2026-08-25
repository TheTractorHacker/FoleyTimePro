package com.foleyit.foleytimepro.ui.screen.setup

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.repository.BrandingRepository
import com.foleyit.foleytimepro.utils.PrefsManager
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class SetupViewModel @Inject constructor(
    private val brandingRepository: BrandingRepository,
    private val prefs: PrefsManager,
) : ViewModel() {

    private val _state = MutableStateFlow(SetupUiState())
    val state: StateFlow<SetupUiState> = _state.asStateFlow()

    fun onUrlChanged(newUrl: String) {
        // Enforce the https:// prefix — matches the legacy behavior.
        val normalized = if (newUrl.startsWith(HTTPS)) newUrl else HTTPS
        _state.update { it.copy(url = normalized, error = null, verifiedCompanyName = null) }
    }

    fun connect() {
        val raw = _state.value.url.trimEnd('/')
        if (raw.length <= HTTPS.length) return

        _state.update { it.copy(loading = true, error = null) }
        viewModelScope.launch {
            // Persist provisionally so the repository resolves the URL through PrefsManager.
            prefs.setInstanceUrl(raw)
            val result = brandingRepository.probe()
            when (result) {
                is ApiResult.Success -> _state.update {
                    it.copy(loading = false, verifiedCompanyName = result.data, error = null)
                }
                is ApiResult.HttpError -> {
                    // Roll back the URL so a bad probe doesn't leave stale state.
                    prefs.setInstanceUrl("")
                    _state.update { it.copy(loading = false, error = result.message.ifBlank { "Not a FoleyTimePro server" }) }
                }
                is ApiResult.Unauthorized -> {
                    prefs.setInstanceUrl("")
                    _state.update { it.copy(loading = false, error = "Unauthorized") }
                }
                is ApiResult.NetworkError -> {
                    prefs.setInstanceUrl("")
                    _state.update { it.copy(loading = false, error = "Cannot reach server") }
                }
                is ApiResult.Unknown -> {
                    prefs.setInstanceUrl("")
                    _state.update {
                        it.copy(loading = false, error = "Server returned an unexpected response")
                    }
                }
            }
        }
    }

    companion object { const val HTTPS = "https://" }
}
