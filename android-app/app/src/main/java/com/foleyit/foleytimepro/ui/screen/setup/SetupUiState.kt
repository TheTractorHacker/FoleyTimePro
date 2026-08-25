package com.foleyit.foleytimepro.ui.screen.setup

data class SetupUiState(
    val url: String = "https://",
    val loading: Boolean = false,
    val error: String? = null,
    val verifiedCompanyName: String? = null,
) {
    val verified: Boolean get() = !verifiedCompanyName.isNullOrBlank()
}
