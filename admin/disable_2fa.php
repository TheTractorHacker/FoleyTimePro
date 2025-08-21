<?php
session_start();
require '../auth/db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$adminUsername = $_SESSION['admin'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['password'])) {
    header("Location: dashboard.php?error=Password required to disable 2FA.");
    exit;
}

$password = $_POST['password'];

// Verify admin's current password
$stmt = $conn->prepare("SELECT Pass FROM admins WHERE username = ?");
$stmt->bind_param("s", $adminUsername);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if ($admin && password_verify($password, $admin['Pass'])) {
    // Password is correct, disable 2FA
    $updateStmt = $conn->prepare("UPDATE admins SET TwoFAEnabled = 0, TwoFASecret = NULL, TwoFARecoveryCode = NULL WHERE username = ?");
    $updateStmt->bind_param("s", $adminUsername);
    $updateStmt->execute();
    header("Location: dashboard.php?msg=2FA disabled successfully.");
} else {
    header("Location: dashboard.php?error=Incorrect password.");
}
exit;