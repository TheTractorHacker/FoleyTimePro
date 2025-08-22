<?php
require_once '../vendor/autoload.php';
require_once '../auth/db.php';

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

session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$adminUsername = $_SESSION['admin'];

// Fetch admin details
$stmt = $conn->prepare("SELECT TwoFASecret, TwoFAEnabled FROM admins WHERE username = ?");
if (!$stmt) {
    header('Location: ../error.php?code=500&message=' . urlencode('Prepare failed: (' . $conn->errno . ') ' . $conn->error));
    exit;
}
$stmt->bind_param("s", $adminUsername);
if (!$stmt->execute()) {
    header('Location: ../error.php?code=500&message=' . urlencode('Execute failed: (' . $stmt->errno . ') ' . $stmt->error));
    exit;
}
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

if (!$admin || empty($admin['TwoFAEnabled'])) { // Check if admin exists and 2FA is not enabled
    // Generate or reuse TOTP secret
    if (empty($_SESSION['2fa_secret'])) {
        $totp = TOTP::create();
        $totp->setLabel($adminUsername);
                        $totp->setIssuer('D-Best TimeClock');
    }

    // Build QR Code
    $logoPath = realpath(__DIR__ . '/../images/D-Best.png');
    error_log("Logo Path: " . ($logoPath ? $logoPath : "false"));

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
        logoPath: $logoPath,
        logoResizeToWidth: 60,
        logoPunchoutBackground: true,
        labelText: 'Scan with Authenticator',
        labelFont: new OpenSans(16),
        labelAlignment: LabelAlignment::Center
    );

    $result = $builder->build();
    $imgData = base64_encode($result->getString());

    // Verification
    $msg = "";
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
        $inputCode = trim($_POST['code']);
        if ($totp->verify($inputCode)) {
            $secret = $_SESSION['2fa_secret'];
            $stmt = $conn->prepare("UPDATE admins SET TwoFASecret = ?, TwoFAEnabled = 1 WHERE username = ?");
            $stmt->bind_param("ss", $secret, $adminUsername);
            $stmt->execute();
            unset($_SESSION['2fa_secret']);
            header("Location: dashboard.php?2fa=enabled");
            exit;
        } else {
            $msg = "<span style='color: red;'>❌ Invalid code. Please try again.</span>";
        }
    }
} else {
    $msg = "<span style='color: green;'>✅ 2FA is already enabled on your account.</span>";
}
?>
<link rel="stylesheet" href="../css/user_2fa.css">
        <div class="container-2fa">
            <h2>Enable Two-Factor Authentication</h2>
            <?php if (isset($admin['TwoFAEnabled']) && $admin['TwoFAEnabled']): ?>
                <p style="color: green;">✅ 2FA is already enabled on your account.</p>
            <?php else: ?>
                <p>Scan the QR code below using Google Authenticator or Authy:</p>
                <img src="data:image/png;base64,<?= $imgData ?>" alt="2FA QR Code">

                <form method="POST">
                    <label>Enter 6-digit code from your app:</label><br>
                    <input type="text" name="code" maxlength="6" pattern="\d{6}" required><br>
                    <button type="submit">Verify & Enable 2FA</button>
                </form>
                <div class="message"><?= $msg ?></div>
            <?php endif; ?>
        </div>
<script src="../js/script.js"></script>
