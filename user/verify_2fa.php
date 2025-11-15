<?php
/*************************************************
 * User 2FA Verification (fixed to ensure EmployeeID is set)
 *************************************************/

use OTPHP\TOTP;

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'");

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require '../vendor/autoload.php';
require '../auth/db.php';

if (empty($_SESSION['temp_user_id'])) {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['user_2fa_pending'])) {
    $_SESSION['user_2fa_pending'] = true;
}

const MAX_2FA_ATTEMPTS_USER   = 5;
const LOCKOUT_SECONDS_USER    = 900;
const FAILURE_DELAY_US_USER   = 350000;

function ensure_csrf_token(): void {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
function csrf_valid(): bool {
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}
function twofa_is_locked_user(): bool {
    $until = $_SESSION['user_twofa_lock_until'] ?? 0;
    return is_int($until) && time() < $until;
}
function twofa_record_failure_and_maybe_lock_user(): void {
    $_SESSION['2fa_attempts'] = (int)($_SESSION['2fa_attempts'] ?? 0) + 1;
    if ($_SESSION['2fa_attempts'] >= MAX_2FA_ATTEMPTS_USER) {
        $_SESSION['user_twofa_lock_until'] = time() + LOCKOUT_SECONDS_USER;
    }
}
function twofa_clear_failures_user(): void {
    unset($_SESSION['2fa_attempts'], $_SESSION['user_twofa_lock_until']);
}
function login_user_and_finish(int $empID, string $firstName): void {
    session_regenerate_id(true);
    $_SESSION['EmployeeID'] = $empID;
    $_SESSION['FirstName']  = $firstName;
    unset($_SESSION['temp_user_id'], $_SESSION['user_2fa_pending'], $_SESSION['2fa_attempts'], $_SESSION['user_twofa_lock_until']);
    header("Location: dashboard.php");
    exit;
}
function normalize_totp_input(string $code): string {
    return preg_replace('/\D+/', '', $code);
}
function constant_time_in_array(string $needle, array $haystack): int {
    foreach ($haystack as $i => $candidate) {
        if (hash_equals((string)$candidate, $needle)) {
            return $i;
        }
    }
    return -1;
}

ensure_csrf_token();
if (!isset($_SESSION['2fa_attempts'])) {
    $_SESSION['2fa_attempts'] = 0;
}

$empID = (int)$_SESSION['temp_user_id'];

$stmt = $conn->prepare("SELECT FirstName, TwoFASecret, TwoFARecoveryCode FROM users WHERE ID = ?");
$stmt->bind_param("i", $empID);
$stmt->execute();
$res  = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$user || empty($user['TwoFASecret'])) {
    unset($_SESSION['temp_user_id'], $_SESSION['2fa_attempts'], $_SESSION['user_2fa_pending']);
    header("Location: login.php");
    exit;
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $msg = "Invalid request.";
    } elseif (twofa_is_locked_user()) {
        $msg = "Too many failed attempts. Please try again later.";
    } else {
        $rawCode  = trim($_POST['code'] ?? '');
        $totpCode = normalize_totp_input($rawCode);
        $success = false;

        if ($totpCode !== '') {
            try {
                $otp = TOTP::create($user['TwoFASecret']);
                if ($otp->verify($totpCode)) {
                    $success = true;
                }
            } catch (Throwable $e) {}
        }

        if (!$success && !empty($user['TwoFARecoveryCode'])) {
            $decoded = json_decode($user['TwoFARecoveryCode'], true);
            if (is_array($decoded)) {
                $idx = constant_time_in_array($rawCode, $decoded);
                if ($idx >= 0) {
                    array_splice($decoded, $idx, 1);
                    $upd = $conn->prepare("UPDATE users SET TwoFARecoveryCode = ? WHERE ID = ?");
                    $jsonCodes = json_encode($decoded);
                    $upd->bind_param("si", $jsonCodes, $empID);
                    $upd->execute();
                    $upd->close();
                    $success = true;
                }
            }
        }

        if ($success) {
            twofa_clear_failures_user();
            login_user_and_finish($empID, $user['FirstName']);
        } else {
            usleep(FAILURE_DELAY_US_USER);
            twofa_record_failure_and_maybe_lock_user();
            $msg = "Invalid code. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>2FA Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/user_verify_2fa.css">

</head>
<body>
    <div class="verify-box" role="main" aria-labelledby="verify-title">
        <div class="logo-wrap">
            <!-- Ensure the path is correct; CSP allows self images -->
            <img src="../images/D-Best.png" alt="Company Logo" class="logo">
        </div>

        <h2 id="verify-title">Two-Factor Verification</h2>
        <p class="sub">Enter the 6-digit code from your authenticator app or a backup code.</p>

        <form method="POST" novalidate>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <div class="field">
                <label for="code">Verification Code</label>
                <input
                    type="text"
                    name="code"
                    id="code"
                    maxlength="20"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    placeholder="123 456 or BACKUPCODE"
                    required>
            </div>

            <button type="submit">Verify</button>
        </form>

        <?php if (!empty($msg)): ?>
            <div class="error" aria-live="assertive"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="help" aria-live="polite">
            <?php if (isset($_SESSION['user_twofa_lock_until']) && time() < ($_SESSION['user_twofa_lock_until'])):
                $remaining = $_SESSION['user_twofa_lock_until'] - time();
                $mins = floor($remaining / 60);
                $secs = $remaining % 60;
            ?>
                Too many attempts. Try again in ~<?= (int)$mins ?>m <?= (int)$secs ?>s.
            <?php else: ?>
                Tip: You can paste the code; spaces will be ignored for TOTP.
            <?php endif; ?>
        </div>
    </div>

    <script src="../js/user_verify_2fa.js"></script>
</body>
</html>
