package com.foleyit.foleytimepro.security

import android.os.Build
import com.foleyit.foleytimepro.BuildConfig
import java.io.File

/**
 * Lightweight, advisory root / emulator detection.
 *
 * This is NOT a security boundary — a determined attacker can defeat every
 * check here. It exists to raise a visible warning to end users when the
 * device is obviously in an untrusted state (rooted, running under an
 * emulator with obvious markers) so they know to escalate. For real device
 * attestation, add Google Play Integrity server-side verification.
 */
object RootEmulatorDetector {

    fun report(): Report {
        if (!BuildConfig.ENABLE_ROOT_CHECK) return Report(rooted = false, emulator = false)
        return Report(rooted = isRooted(), emulator = isEmulator())
    }

    data class Report(val rooted: Boolean, val emulator: Boolean) {
        val hasWarning: Boolean get() = rooted || emulator
    }

    private fun isRooted(): Boolean {
        // Common su locations
        val suPaths = arrayOf(
            "/system/bin/su", "/system/xbin/su", "/sbin/su", "/system/sd/xbin/su",
            "/system/bin/failsafe/su", "/data/local/xbin/su", "/data/local/bin/su",
            "/data/local/su", "/su/bin/su",
        )
        if (suPaths.any { runCatching { File(it).exists() }.getOrDefault(false) }) return true

        // Common root-manager packages leave visible artifacts
        val markers = arrayOf(
            "/system/app/Superuser.apk",
            "/system/etc/init.d/99SuperSUDaemon",
        )
        if (markers.any { runCatching { File(it).exists() }.getOrDefault(false) }) return true

        // `which su` succeeds on rooted devices
        return try {
            val proc = ProcessBuilder(listOf("which", "su")).redirectErrorStream(true).start()
            proc.inputStream.bufferedReader().use { it.readText().isNotBlank() }
        } catch (_: Throwable) {
            false
        }
    }

    private fun isEmulator(): Boolean {
        val fp = Build.FINGERPRINT.lowercase()
        val model = Build.MODEL.lowercase()
        val product = Build.PRODUCT.lowercase()
        val hardware = Build.HARDWARE.lowercase()
        return fp.startsWith("generic") ||
            fp.startsWith("unknown") ||
            model.contains("emulator") || model.contains("android sdk built for") ||
            product.contains("sdk") || product.contains("emulator") || product == "google_sdk" ||
            hardware.contains("goldfish") || hardware.contains("ranchu") || hardware == "vbox86"
    }
}
