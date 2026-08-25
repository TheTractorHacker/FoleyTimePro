package com.foleyit.foleytimepro.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class BrandingDto(
    @SerialName("company_name") val companyName: String = "FoleyTimePro",
    @SerialName("logo_url")     val logoUrl: String? = null,
    @SerialName("primary_color") val primaryColor: String? = null,
)
