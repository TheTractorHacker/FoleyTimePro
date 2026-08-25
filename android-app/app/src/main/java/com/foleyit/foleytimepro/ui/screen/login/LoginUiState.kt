package com.foleyit.foleytimepro.ui.screen.login

data class LoginUiState(
    val email: String = "",
    val password: String = "",
    val twoFaCode: String = "",
    val needs2FA: Boolean = false,
    val loading: Boolean = false,
    val error: String? = null,
    val companyName: String = "FoleyTimePro",
    val loggedIn: Boolean = false,
) {
    val canSubmit: Boolean
        get() = email.isNotBlank() && password.isNotBlank() && !loading &&
                (!needs2FA || twoFaCode.length == 6)
}
