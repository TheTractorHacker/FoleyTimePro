<?php
// admin/update_2fa_status.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Forbidden');
}

// Require POST + valid CSRF so a page under the attacker's control can't
// silently flip 2FA / lockout for any user on behalf of a logged-in admin.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
require_once __DIR__ . '/includes/csrf.php';
if (!csrf_valid()) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = $_POST['action'] ?? '';

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid user id');
}

switch ($action) {
    case 'enable':
        $stmt = $conn->prepare("UPDATE users SET TwoFAEnabled = 1 WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        break;

    case 'disable':
        // Disable 2FA and clear secrets/recovery codes
        $stmt = $conn->prepare("UPDATE users SET TwoFAEnabled = 0, TwoFASecret = NULL, TwoFARecoveryCode = NULL WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        break;

    case 'lock':
        // Lock the user from managing their own 2FA
        $stmt = $conn->prepare("UPDATE users SET LockOut = 1 WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        break;

    case 'unlock':
        // Allow the user to manage their own 2FA
        $stmt = $conn->prepare("UPDATE users SET LockOut = 0 WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        break;

    default:
        http_response_code(400);
        exit('Unknown action');
}

header('Location: manage_users.php');
