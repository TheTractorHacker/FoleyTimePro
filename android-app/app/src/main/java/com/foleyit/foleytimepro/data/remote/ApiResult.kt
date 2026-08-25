package com.foleyit.foleytimepro.data.remote

/**
 * Uniform result type for every API call.
 *
 * Repositories return `ApiResult<T>`; ViewModels branch on it to shape UI state.
 * This replaces the old pattern of ad-hoc `data class XResult(success, error)`
 * types with `success: Boolean` fields scattered across the code base.
 */
sealed interface ApiResult<out T> {
    data class Success<T>(val data: T) : ApiResult<T>
    data class HttpError(val code: Int, val message: String, val serverCode: String? = null) : ApiResult<Nothing>
    data object Unauthorized : ApiResult<Nothing>
    data object NetworkError : ApiResult<Nothing>
    data class Unknown(val throwable: Throwable) : ApiResult<Nothing>
}

/** Convenience helpers so screens don't need explicit `when` blocks for terse cases. */
inline fun <T> ApiResult<T>.onSuccess(block: (T) -> Unit): ApiResult<T> {
    if (this is ApiResult.Success) block(data); return this
}

inline fun <T> ApiResult<T>.onFailure(block: (message: String) -> Unit): ApiResult<T> {
    when (this) {
        is ApiResult.HttpError    -> block(message)
        is ApiResult.Unauthorized -> block("Session expired — please sign in.")
        is ApiResult.NetworkError -> block("Network error — check your connection.")
        is ApiResult.Unknown      -> block(throwable.message ?: "Unexpected error")
        is ApiResult.Success      -> Unit
    }
    return this
}

val <T> ApiResult<T>.dataOrNull: T?
    get() = (this as? ApiResult.Success<T>)?.data

val ApiResult<*>.errorMessage: String
    get() = when (this) {
        is ApiResult.HttpError    -> message
        is ApiResult.Unauthorized -> "Session expired"
        is ApiResult.NetworkError -> "Network error"
        is ApiResult.Unknown      -> throwable.message ?: "Unexpected error"
        is ApiResult.Success      -> ""
    }
