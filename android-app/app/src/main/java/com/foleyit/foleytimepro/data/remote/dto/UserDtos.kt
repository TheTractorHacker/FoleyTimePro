package com.foleyit.foleytimepro.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class ChangePasswordRequest(
    val password: String,
)
