package com.foleyit.foleytimepro.security

import kotlinx.coroutines.channels.BufferOverflow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * App-wide auth event bus.
 *
 * Emits when the app must return to the Login screen and wipe in-memory state:
 * refresh-token failure, forced sign-out by the server, inactivity timeout on a
 * shared kiosk device, biometric admin gate refused, or user-initiated sign-out.
 *
 * `MainActivity` (or the root `AppNavigation`) collects these events and drives
 * the navigation transition. Replays 0 — this is a one-shot signal.
 */
@Singleton
class AuthEvents @Inject constructor() {

    enum class Reason {
        UserSignOut,
        TokenRefreshFailed,
        Inactivity,
        AdminGateFailed,
        ServerRevoked,
    }

    private val _events = MutableSharedFlow<Reason>(
        replay = 0,
        extraBufferCapacity = 4,
        onBufferOverflow = BufferOverflow.DROP_OLDEST
    )
    val events: SharedFlow<Reason> = _events.asSharedFlow()

    fun forceLogout(reason: Reason) {
        _events.tryEmit(reason)
    }
}
