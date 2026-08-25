package com.foleyit.foleytimepro.ui.screen.login

import android.content.Context
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.repository.AuthRepository
import com.foleyit.foleytimepro.data.repository.BrandingRepository
import com.foleyit.foleytimepro.utils.PrefsManager
import com.foleyit.foleytimepro.work.FcmTokenSyncWorker
import dagger.hilt.android.lifecycle.HiltViewModel
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class LoginViewModel @Inject constructor(
    private val authRepository: AuthRepository,
    private val brandingRepository: BrandingRepository,
    private val prefs: PrefsManager,
    @ApplicationContext private val appContext: Context,
) : ViewModel() {

    private val _state = MutableStateFlow(
        LoginUiState(companyName = brandingRepository.cachedCompanyName())
    )
    val state: StateFlow<LoginUiState> = _state.asStateFlow()

    fun onEmailChanged(v: String)    = _state.update { it.copy(email = v.trim(), error = null) }
    fun onPasswordChanged(v: String) = _state.update { it.copy(password = v, error = null) }
    fun onTwoFaChanged(v: String)    = _state.update { it.copy(twoFaCode = v.filter(Char::isDigit).take(6), error = null) }

    fun submit() {
        val current = _state.value
        if (!current.canSubmit) return

        _state.update { it.copy(loading = true, error = null) }
        viewModelScope.launch {
            val res = authRepository.login(
                email = current.email,
                password = current.password,
                twoFaCode = current.twoFaCode.takeIf { current.needs2FA && it.isNotBlank() },
            )
            when (res) {
                is ApiResult.Success -> {
                    val session = res.data
                    if (session.requires2FA) {
                        _state.update { it.copy(loading = false, needs2FA = true, error = null) }
                    } else {
                        scheduleFcmRegistration()
                        _state.update { it.copy(loading = false, loggedIn = true, error = null) }
                    }
                }
                is ApiResult.HttpError    -> _state.update { it.copy(loading = false, error = res.message) }
                is ApiResult.Unauthorized -> _state.update { it.copy(loading = false, error = "Invalid credentials") }
                is ApiResult.NetworkError -> _state.update { it.copy(loading = false, error = "Network error — check your connection") }
                is ApiResult.Unknown      -> _state.update { it.copy(loading = false, error = res.throwable.message ?: "Unexpected error") }
            }
        }
    }

    fun onChangeServer() {
        // Wipe everything, including verified server URL, so Setup starts fresh.
        prefs.clearAll()
    }

    private fun scheduleFcmRegistration() {
        val req = OneTimeWorkRequestBuilder<FcmTokenSyncWorker>().build()
        WorkManager.getInstance(appContext).enqueueUniqueWork(
            FcmTokenSyncWorker.UNIQUE_NAME,
            ExistingWorkPolicy.REPLACE,
            req,
        )
    }
}
