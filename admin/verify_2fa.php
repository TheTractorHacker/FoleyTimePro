<?php
/*************************************************
 * Verify 2FA (hardened)
 * - CSRF protection
 * - Session cookie hardening
 * - Simple cooldown lockout on failures
 * - Neutral errors (no info leaks)
 * - Constant-time compare for recovery codes
 * - Security headers
 * - Proper 2FA flow hygiene (pending flag, ID regen)
 *************************************************/

use OTPHP\TOTP;

// ---------- Security Headers (adjust CSP if you load externals) ----------
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'");

// ---------- Error Handling (prod defaults) ----------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ---------- Session Cookie Hardening (must be before session_start) ----------
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',        // set your domain if needed
    'secure'   => true,      // true in production (requires HTTPS)
    'httponly' => true,
    'samesite' => 'Strict',  // 'Lax' if you need cross-site GET flows
]);
session_start();

// ---------- Autoload & DB ----------
require_once '../vendor/autoload.php';
require_once '../auth/db.php'; // must set $conn (mysqli)

// ---------- Gate: must be in 2FA pending state ----------
if (empty($_SESSION['2fa_admin_username']) || empty($_SESSION['2fa_pending'])) {
    header("Location: login.php");
    exit;
}

// ---------- Constants ----------
const MAX_2FA_ATTEMPTS          = 5;
const LOCKOUT_SECONDS_2FA       = 15 * 60;     // 15 minutes
const FAILURE_DELAY_US_2FA      = 350000;      // ~350ms artificial delay

// ---------- Helpers ----------
function ensure_csrf_token(): void {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
function csrf_valid(): bool {
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}
function twofa_is_locked(): bool {
    $until = $_SESSION['twofa_lock_until'] ?? 0;
    return is_int($until) && time() < $until;
}
function twofa_record_failure_and_maybe_lock(): void {
    $_SESSION['2fa_attempts'] = (int)($_SESSION['2fa_attempts'] ?? 0) + 1;
    if ($_SESSION['2fa_attempts'] >= MAX_2FA_ATTEMPTS) {
        $_SESSION['twofa_lock_until'] = time() + LOCKOUT_SECONDS_2FA;
    }
}
function twofa_clear_failures(): void {
    unset($_SESSION['2fa_attempts'], $_SESSION['twofa_lock_until']);
}
function login_admin_and_finish(string $username): void {
    // On success, end the 2FA flow securely
    session_regenerate_id(true);
    $_SESSION['admin'] = $username;
    unset($_SESSION['2fa_admin_username'], $_SESSION['2fa_pending'], $_SESSION['2fa_attempts'], $_SESSION['twofa_lock_until']);
    header("Location: dashboard.php");
    exit;
}
function normalize_totp_input(string $code): string {
    // TOTP is digits; strip non-digits for user convenience
    return preg_replace('/\D+/', '', $code);
}
function constant_time_in_array(string $needle, array $haystack): int {
    // Returns index if found via hash_equals, else -1 (strict, case-sensitive)
    foreach ($haystack as $i => $candidate) {
        $cand = (string)$candidate;
        if (hash_equals($cand, $needle)) {
            return (int)$i;
        }
    }
    return -1;
}

// ---------- Init ----------
$error = '';
ensure_csrf_token();
if (!isset($_SESSION['2fa_attempts'])) {
    $_SESSION['2fa_attempts'] = 0;
}

// ---------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = "Invalid request.";
    } elseif (twofa_is_locked()) {
        $error = "Too many failed attempts. Please try again later.";
    } else {
        $username   = (string)$_SESSION['2fa_admin_username'];
        $rawCode    = trim((string)($_POST['code'] ?? ''));
        $totpCode   = normalize_totp_input($rawCode);

        if ($rawCode === '') {
            $error = "Please enter your code.";
        } else {
            // Look up the admin's 2FA secret and recovery codes
            $stmt = $conn->prepare("SELECT TwoFASecret, TwoFARecoveryCode FROM admins WHERE username = ?");
            if ($stmt === false) {
                // Don't leak DB details to user
                usleep(FAILURE_DELAY_US_2FA);
                $error = "Something went wrong. Please try again.";
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                $admin  = $result ? $result->fetch_assoc() : null;

                if ($result) { $result->free(); }
                $stmt->close();

                $success = false;

                if ($admin && !empty($admin['TwoFASecret'])) {
                    // Verify TOTP first
                    try {
                        $otp = TOTP::create((string)$admin['TwoFASecret']);
                        if ($totpCode !== '' && $otp->verify($totpCode)) {
                            $success = true;
                        }
                    } catch (Throwable $e) {
                        // Invalid secret format, treat as failure w/out details
                    }

                    // If not TOTP, try recovery codes (stored as JSON array of strings)
                    if (!$success) {
                        $recoveryCodes = [];
                        if (!empty($admin['TwoFARecoveryCode'])) {
                            $decoded = json_decode($admin['TwoFARecoveryCode'], true);
                            if (is_array($decoded)) {
                                $recoveryCodes = array_values(array_map('strval', $decoded));
                            }
                        }

                        if ($recoveryCodes) {
                            $idx = constant_time_in_array($rawCode, $recoveryCodes); // exact match
                            if ($idx >= 0) {
                                // Remove used recovery code
                                array_splice($recoveryCodes, $idx, 1);
                                $newCodesJson = json_encode($recoveryCodes);

                                $upd = $conn->prepare("UPDATE admins SET TwoFARecoveryCode = ? WHERE username = ?");
                                if ($upd) {
                                    $upd->bind_param("ss", $newCodesJson, $username);
                                    $upd->execute();
                                    $upd->close();
                                }
                                $success = true;
                            }
                        }
                    }
                }

                if ($success) {
                    twofa_clear_failures();
                    login_admin_and_finish($username);
                } else {
                    usleep(FAILURE_DELAY_US_2FA);     // uniform slowdown
                    twofa_record_failure_and_maybe_lock();
                    $error = "Invalid code. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify 2FA</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="../css/verify_2fa.css">
</head>
<body>
    <div class="container-2fa" role="main" aria-labelledby="verify-title">
        <h2 id="verify-title">Verify Your Identity</h2>
        <p>Enter the 6-digit code from your authenticator app or a backup code.</p>

        <form method="POST" novalidate>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <div class="field">
                <label for="code">Verification Code</label>
                <input
                    type="text"
                    name="code"
                    id="code"
                    required
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    placeholder="123 456 or BACKUPCODE">
            </div>

            <div class="buttons">
                <button type="submit">Verify</button>
            </div>
        </form>

        <?php if (!empty($error)): ?>
            <div class="error" aria-live="assertive"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="help" aria-live="polite">
            <?php if (isset($_SESSION['twofa_lock_until']) && time() < ($_SESSION['twofa_lock_until'])):
                $remaining = $_SESSION['twofa_lock_until'] - time();
                $mins = floor($remaining / 60);
                $secs = $remaining % 60;
            ?>
                Too many attempts. Try again in ~<?= (int)$mins ?>m <?= (int)$secs ?>s.
            <?php else: ?>
                Lost your device? Use a backup code instead.
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
