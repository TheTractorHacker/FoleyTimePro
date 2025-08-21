<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    exit("Unauthorized");
}

function generateRecoveryCodes($count = 5) {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = bin2hex(random_bytes(4)); // 8-digit alphanumeric
    }
    return $codes;
}

if (isset($_POST['mode'])) {
    $mode = $_POST['mode'];

    if ($mode === 'single' && isset($_POST['userID'])) {
        $id = intval($_POST['userID']);
        $codes = json_encode(generateRecoveryCodes());

        $stmt = $conn->prepare("UPDATE users SET TwoFARecoveryCode = ? WHERE ID = ?");
        $stmt->bind_param("si", $codes, $id);
        $stmt->execute();
        echo "Recovery codes generated for user ID $id.";
    }

    if ($mode === 'all') {
        $users = $conn->query("SELECT ID FROM users");
        while ($user = $users->fetch_assoc()) {
            $codes = json_encode(generateRecoveryCodes());
            $stmt = $conn->prepare("UPDATE users SET TwoFARecoveryCode = ? WHERE ID = ?");
            $stmt->bind_param("si", $codes, $user['ID']);
            $stmt->execute();
        }
        echo "Recovery codes generated for all users.";
    }
}
?>
