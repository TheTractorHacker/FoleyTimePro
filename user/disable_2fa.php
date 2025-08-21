<?php
session_start();
require '../auth/db.php';

if (!isset($_SESSION['EmployeeID'])) {
    header("Location: login.php");
    exit;
}

$empID = $_SESSION['EmployeeID'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['password'])) {
    header("Location: settings.php?error=Password required to disable 2FA.");
    exit;
}

$password = $_POST['password'];

// Verify user's current password
$stmt = $conn->prepare("SELECT Pass FROM users WHERE ID = ?");
$stmt->bind_param("i", $empID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify($password, $user['Pass'])) {
    // Password is correct, disable 2FA
    $updateStmt = $conn->prepare("UPDATE users SET TwoFAEnabled = 0, TwoFASecret = NULL, RecoveryCodeHash = NULL WHERE ID = ?");
    $updateStmt->bind_param("i", $empID);
    $updateStmt->execute();
    header("Location: settings.php?msg=2FA disabled successfully.");
} else {
    header("Location: settings.php?error=Incorrect password.");
}
exit;
