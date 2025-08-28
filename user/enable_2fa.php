<?php
require_once 'header.php';            // must start session and set $conn, $empID
require_once '../vendor/autoload.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use OTPHP\TOTP;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

// --- Load user ---
$stmt = $conn->prepare("SELECT FirstName, Email, TwoFAEnabled FROM users WHERE ID = ?");
$stmt->bind_param("i", $empID);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result ? $result->fetch_assoc() : null;
if ($result) { $result->free(); }
$stmt->close();

if (!$user || empty($user['Email'])) {
    header('Location: ../error.php?code=404&message=' . urlencode('User not found or missing email.'));
    exit;
}

// --- Build or reuse TOTP (persist secret during setup) ---
$ISSUER = 'D-Best Timeclock'; // <- exact issuer text requested
if (empty($_SESSION['2fa_secret'])) {
    $totp = TOTP::create(); // 30s period, 6 digits
    $totp->setLabel($user['Email']);
    $totp->setIssuer($ISSUER);
    $_SESSION['2fa_secret'] = $totp->getSecret();
} else {
    $totp = TOTP::create($_SESSION['2fa_secret']);
    $totp->setLabel($user['Email']);
    $totp->setIssuer($ISSUER);
}

// --- QR (constructor style for your package version) ---
$logoPath = realpath(__DIR__ . '/../images/D-Best.png'); // keep PNG logo

$builder = new Builder(
    writer: new PngWriter(),
    writerOptions: [],
    validateResult: false,
    data: $totp->getProvisioningUri(),
    encoding: new Encoding('UTF-8'),
    errorCorrectionLevel: ErrorCorrectionLevel::High,
    size: 300,
    margin: 10,
    roundBlockSizeMode: RoundBlockSizeMode::Margin,
    logoPath: $logoPath && is_readable($logoPath) ? $logoPath : null,
    logoResizeToWidth: 60,
    logoPunchoutBackground: true,
    labelText: 'Scan with Authenticator',
    labelFont: new OpenSans(16),
    labelAlignment: LabelAlignment::Center
);

$result = $builder->build();
$imgData = base64_encode($result->getString());

// --- Verify code ---
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    // Allow spaces/dashes in UI; strip for verification
    $inputCode = preg_replace('/[\s-]+/', '', trim($_POST['code']));

    // Allow 1 time window drift (helps with slight clock skew)
    $isValid = $totp->verify($inputCode, null, 1);

    if ($isValid) {
        $secret = $_SESSION['2fa_secret'];
        $upd = $conn->prepare("UPDATE users SET TwoFASecret = ?, TwoFAEnabled = 1 WHERE ID = ?");
        if ($upd) {
            $upd->bind_param("si", $secret, $empID);
            $upd->execute();
            $upd->close();
        }
        unset($_SESSION['2fa_secret']);
        header("Location: dashboard.php?2fa=enabled");
        exit;
    } else {
        $msg = "<span style='color: #dc2626;'>❌ Invalid code. Please try again.</span>";
    }
}
?>
<link rel="stylesheet" href="../css/user_enable_2fa.css">


<div class="container">
    <h2>Enable Two-Factor Authentication</h2>

    <?php if (!empty($user['TwoFAEnabled'])): ?>
        <p class="already">✅ 2FA is already enabled on your account.</p>
    <?php else: ?>
        <p class="sub">Scan this QR with your authenticator app, then enter the 6-digit code.</p>

        <img class="qr" src="data:image/png;base64,<?= htmlspecialchars($imgData, ENT_QUOTES, 'UTF-8') ?>" alt="2FA QR Code">

        <form method="POST" autocomplete="off" novalidate>
            <div class="field">
                <label for="code">Enter 6-digit code</label>
                <input type="text" name="code" id="code" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="123 456" required>
            </div>
            <button type="submit">Verify & Enable 2FA</button>
        </form>

        <div class="message"><?= $msg ?></div>
    <?php endif; ?>
</div>

<script src="../js/user_enable_2fa.js"></script>
<script src="../js/script.js"></script>
<?php require_once 'footer.php'; ?>
