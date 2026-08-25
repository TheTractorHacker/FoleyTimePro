package com.foleyit.foleytimepro.data.repository

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.UserApi
import com.foleyit.foleytimepro.utils.PrefsManager
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class UserRepository @Inject constructor(
    private val userApi: UserApi,
    private val prefs: PrefsManager,
) {
    fun currentDisplayName(): String = prefs.getUserName()
    fun currentEmail(): String       = prefs.getUserEmail()

    suspend fun changePassword(newPassword: String): ApiResult<Unit> =
        userApi.changePassword(newPassword)
}
