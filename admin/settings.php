<?php
session_start();
require_once '../auth/db.php';
require_once '../vendor/autoload.php'; // For PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// --- Encryption/Decryption Functions ---
// For demonstration, a simple key. In production, this should be a strong, securely stored key.
define('ENCRYPTION_KEY', 'a_very_secret_key_for_encryption_32_chars'); // 32 bytes for AES-256
define('CIPHER_METHOD', 'aes-256-cbc');

function encrypt_data($data) {
    $iv_len = openssl_cipher_iv_length(CIPHER_METHOD);
    $iv = openssl_random_pseudo_bytes($iv_len);
    $encrypted = openssl_encrypt($data, CIPHER_METHOD, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decrypt_data($data) {
    $parts = explode('::', base64_decode($data), 2);
    if (count($parts) === 2) {
        list($encrypted_data, $iv) = $parts;
        return openssl_decrypt($encrypted_data, CIPHER_METHOD, ENCRYPTION_KEY, 0, $iv);
    }
    return false;
}
// --- End Encryption/Decryption Functions ---


// Fetch all settings from the database
$settings = [];
$result = $conn->query("SELECT * FROM settings");
while ($row = $result->fetch_assoc()) {
    $settings[$row['SettingKey']] = $row['SettingValue'];
}

// Decrypt mail_password for display and use
if (isset($settings['mail_password']) && !empty($settings['mail_password'])) {
    $decrypted_password = decrypt_data($settings['mail_password']);
    if ($decrypted_password !== false) {
        $settings['mail_password_display'] = $decrypted_password; // For displaying in the form
    } else {
        $settings['mail_password_display'] = 'Error decrypting password';
        error_log("Error decrypting mail_password from DB in admin/settings.php");
    }
} else {
    $settings['mail_password_display'] = '';
}


$message = '';

\n    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    // Update settings
    $settingsToUpdate = [
        'mail_server', 'mail_port', 'mail_username',
        'mail_from_address', 'mail_from_name', 'mail_encryption', 'mail_admin_address'
    ];

    foreach ($settingsToUpdate as $key) {
        if (isset($_POST[$key])) {
            $value = $_POST[$key];
            $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
            $stmt->bind_param("sss", $key, $value, $value);
            $stmt->execute();
            $settings[$key] = $value;
        }
    }

    // Handle mail_password separately for encryption
    if (isset($_POST['mail_password']) && !empty($_POST['mail_password'])) {
        $encrypted_password = encrypt_data($_POST['mail_password']);
        $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
        $key = 'mail_password';
        $stmt->bind_param("sss", $key, $encrypted_password, $encrypted_password);
        $stmt->execute();
        $settings['mail_password'] = $encrypted_password; // Update in settings array
        $settings['mail_password_display'] = $_POST['mail_password']; // Update display value
    }


    // Test email functionality
    if (isset($_POST['test_email'])) {
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->isSMTP();
            $mail->Host       = $settings['mail_server'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $settings['mail_username'];
            // Use the decrypted password for sending test email
            $mail->Password   = $settings['mail_password_display']; // This is the decrypted one
            $mail->SMTPSecure = $settings['mail_encryption'];
            $mail->Port       = $settings['mail_port'];

            //Recipients
            $mail->setFrom($settings['mail_from_address'], $settings['mail_from_name']);
            $mail->addAddress($settings['mail_admin_address']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Test Email from FoleyTimePro';
            $mail->Body    = 'This is a test email to verify your mail server settings.';

            $mail->send();
            $message = '<div class="alert alert-success">Test email sent successfully!</div>';
        } catch (Exception $e) {
            $message = '<div class="alert alert-danger">Test email could not be sent. Mailer Error: ' . $mail->ErrorInfo . '</div>';
        }
    } else {
        $message = '<div class="alert alert-success">Settings saved successfully!</div>';
    }
}

?>

<?php
$pageTitle   = 'Settings';
$headerTitle = 'Settings';
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Settings</h1>
</div>
<div class="card">
        <?= $message ?>
        <form method="POST" class="settings-form">
<?= csrf_field() ?>
            <div class="settings-section"><div class="settings-section">
<h2>Mail Server Settings</h2>

            <div class="form-group">
                <label for="mail_server">Mail Server (SMTP Host):</label>
                <input type="text" id="mail_server" name="mail_server" value="<?= htmlspecialchars($settings['mail_server'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="mail_port">SMTP Port:</label>
                <input type="number" id="mail_port" name="mail_port" value="<?= htmlspecialchars($settings['mail_port'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="mail_username">SMTP Username:</label>
                <input type="text" id="mail_username" name="mail_username" value="<?= htmlspecialchars($settings['mail_username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="mail_password">SMTP Password:</label>
                <input type="password" id="mail_password" name="mail_password" value="<?= htmlspecialchars($settings['mail_password_display'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="mail_encryption">SMTP Encryption:</label>
                <select id="mail_encryption" name="mail_encryption">
                    <option value="ssl" <?= ($settings['mail_encryption'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="tls" <?= ($settings['mail_encryption'] ?? '') == 'tls' ? 'selected' : '' ?>>TLS</option>
                </select>
            </div>
            <div class="form-group">
                <label for="mail_from_address">From Email Address:</label>
                <input type="email" id="mail_from_address" name="mail_from_address" value="<?= htmlspecialchars($settings['mail_from_address'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="mail_from_name">From Name:</label>
                <input type="text" id="mail_from_name" name="mail_from_name" value="<?= htmlspecialchars($settings['mail_from_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="mail_admin_address">Admin Email Address (for notifications):</label>
                <input type="email" id="mail_admin_address" name="mail_admin_address" value="<?= htmlspecialchars($settings['mail_admin_address'] ?? '') ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Save Settings</button>
                <button type="submit" class="btn btn-primary" name="test_email" value="1">Save & Send Test Email</button>
            </div>
        </form>
    </div>

<?php include 'includes/footer.php'; ?>
</div><!-- /settings-section -->
