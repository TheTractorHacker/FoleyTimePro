package com.foleyit.foleytimepro.ui.screen.profile

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.repository.AuthRepository
import com.foleyit.foleytimepro.data.repository.BrandingRepository
import com.foleyit.foleytimepro.data.repository.UserRepository
import com.foleyit.foleytimepro.security.AuthEvents
import com.foleyit.foleytimepro.utils.PrefsManager
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class ProfileUiState(
    val displayName: String = "",
    val email: String = "",
    val companyName: String = "",
    val instanceHost: String = "",
    val newPassword: String = "",
    val confirmPassword: String = "",
    val passwordMessage: String? = null,
    val passwordIsError: Boolean = false,
    val passwordLoading: Boolean = false,
) {
    val initials: String
        get() = displayName.split(" ").filter { it.isNotEmpty() }
            .take(2).joinToString("") { it.first().uppercase() }
}

@HiltViewModel
class ProfileViewModel @Inject constructor(
    private val userRepository: UserRepository,
    private val authRepository: AuthRepository,
    private val brandingRepository: BrandingRepository,
    private val prefs: PrefsManager,
) : ViewModel() {

    private val _state = MutableStateFlow(
        ProfileUiState(
            displayName  = userRepository.currentDisplayName(),
            email        = userRepository.currentEmail(),
            companyName  = brandingRepository.cachedCompanyName(),
            instanceHost = prefs.getInstanceUrl().removePrefix("https://").removePrefix("http://"),
        )
    )
    val state: StateFlow<ProfileUiState> = _state.asStateFlow()

    fun onNewPasswordChanged(v: String)     = _state.update { it.copy(newPassword = v, passwordMessage = null) }
    fun onConfirmPasswordChanged(v: String) = _state.update { it.copy(confirmPassword = v, passwordMessage = null) }

    fun submitPasswordChange() {
        val s = _state.value
        val error = when {
            s.newPassword.isEmpty()          -> "Enter a new password"
            s.newPassword != s.confirmPassword -> "Passwords don't match"
            s.newPassword.length < 6          -> "Must be at least 6 characters"
            else                              -> null
        }
        if (error != null) {
            _state.update { it.copy(passwordMessage = error, passwordIsError = true) }
            return
        }
        _state.update { it.copy(passwordLoading = true, passwordMessage = null) }
        viewModelScope.launch {
            when (val res = userRepository.changePassword(s.newPassword)) {
                is ApiResult.Success -> _state.update {
                    it.copy(
                        passwordLoading = false,
                        passwordMessage = "Password updated!",
                        passwordIsError = false,
                        newPassword = "",
                        confirmPassword = "",
                    )
                }
                is ApiResult.HttpError    -> _state.update { it.copy(passwordLoading = false, passwordMessage = res.message, passwordIsError = true) }
                is ApiResult.Unauthorized -> _state.update { it.copy(passwordLoading = false, passwordMessage = "Session expired", passwordIsError = true) }
                is ApiResult.NetworkError -> _state.update { it.copy(passwordLoading = false, passwordMessage = "Offline — try again when connected", passwordIsError = true) }
                is ApiResult.Unknown      -> _state.update { it.copy(passwordLoading = false, passwordMessage = res.throwable.message ?: "Unexpected error", passwordIsError = true) }
            }
        }
    }

    fun signOut() {
        viewModelScope.launch { authRepository.signOut(AuthEvents.Reason.UserSignOut) }
    }

    /** Wipe all session state so Setup can re-verify a different URL. */
    fun changeServer() {
        prefs.clearAll()
    }
}
