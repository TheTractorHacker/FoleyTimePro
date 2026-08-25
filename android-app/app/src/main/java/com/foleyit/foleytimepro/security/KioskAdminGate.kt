package com.foleyit.foleytimepro.security

import android.content.Context
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity

/**
 * Admin gate for kiosk-only actions (change server URL, sign out kiosk-wide,
 * exit kiosk mode). NOT used for employee login — every employee shares this
 * kiosk device, so biometric login is the wrong model.
 *
 * Prefers the device credential (PIN/pattern/password set by the kiosk owner)
 * with biometric-secondary. If nothing is enrolled, [prompt] returns
 * [Outcome.NoCredentialAvailable] and the caller should refuse the action
 * rather than fall back to an unauthenticated path.
 */
object KioskAdminGate {

    sealed interface Outcome {
        data object Approved            : Outcome
        data object Cancelled           : Outcome
        data object NoCredentialAvailable : Outcome
        data class Error(val message: String) : Outcome
    }

    fun canAuthenticate(context: Context): Boolean {
        val mgr = BiometricManager.from(context)
        val allowed = BiometricManager.Authenticators.DEVICE_CREDENTIAL or
                      BiometricManager.Authenticators.BIOMETRIC_WEAK
        return mgr.canAuthenticate(allowed) == BiometricManager.BIOMETRIC_SUCCESS
    }

    fun prompt(
        activity: FragmentActivity,
        title: String = "Admin verification",
        subtitle: String = "Confirm your kiosk PIN or biometric to continue.",
        onResult: (Outcome) -> Unit,
    ) {
        val allowed = BiometricManager.Authenticators.DEVICE_CREDENTIAL or
                      BiometricManager.Authenticators.BIOMETRIC_WEAK

        if (BiometricManager.from(activity).canAuthenticate(allowed) !=
            BiometricManager.BIOMETRIC_SUCCESS) {
            onResult(Outcome.NoCredentialAvailable)
            return
        }

        val executor = ContextCompat.getMainExecutor(activity)
        val prompt = BiometricPrompt(
            activity,
            executor,
            object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                    onResult(Outcome.Approved)
                }
                override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                    val outcome =
                        if (errorCode == BiometricPrompt.ERROR_USER_CANCELED ||
                            errorCode == BiometricPrompt.ERROR_NEGATIVE_BUTTON ||
                            errorCode == BiometricPrompt.ERROR_CANCELED)
                            Outcome.Cancelled
                        else
                            Outcome.Error(errString.toString())
                    onResult(outcome)
                }
                override fun onAuthenticationFailed() { /* single attempt failed; prompt stays open */ }
            }
        )

        val info = BiometricPrompt.PromptInfo.Builder()
            .setTitle(title)
            .setSubtitle(subtitle)
            .setAllowedAuthenticators(allowed)
            .build()

        prompt.authenticate(info)
    }
}
