package com.foleyit.foleytimepro.security

import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.layout.Box
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.channels.consumeEach
import kotlinx.coroutines.withTimeoutOrNull

/**
 * Kiosk auto-logout timer.
 *
 * Wrap the authenticated content in [KioskInactivityGuard]. Every screen tap
 * bumps an activity signal; when the configured window elapses with no signal,
 * [onTimeout] fires (typically forwarding to `AuthEvents.forceLogout(Inactivity)`).
 *
 * Uses a conflated `Channel` so identical successive taps still bump the
 * timer — `StateFlow` would collapse them.
 */
@Composable
fun KioskInactivityGuard(
    timeoutMs: Long,
    onTimeout: () -> Unit,
    content: @Composable () -> Unit,
) {
    val activity = remember { Channel<Unit>(Channel.CONFLATED) }

    LaunchedEffect(timeoutMs) {
        // Repeatedly wait `timeoutMs` for a tap; if none arrives, fire timeout.
        while (true) {
            val tap: Unit? = withTimeoutOrNull(timeoutMs) { activity.receive() }
            if (tap == null) {
                onTimeout()
                // Drain any queued taps that arrived while onTimeout ran, so the
                // next window starts fresh.
                while (activity.tryReceive().isSuccess) { /* discard */ }
            }
        }
    }

    Box(
        modifier = Modifier.pointerInput(Unit) {
            awaitEachGesture {
                // Observe on the Initial pass without consuming, so children
                // still handle the same gesture.
                awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Initial)
                activity.trySend(Unit)
            }
        }
    ) {
        content()
    }
}

@Suppress("unused")
private suspend fun <T> Channel<T>.drain() = consumeEach { /* discard */ }
