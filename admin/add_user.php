<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['FirstName'] ?? '');
    $lastName  = trim($_POST['LastName'] ?? '');
    $tagID     = trim($_POST['TagID'] ?? '');
    $office    = trim($_POST['Office'] ?? '');
    $password  = $_POST['Password'] ?? '';

    // Basic validation
    if (!$firstName || !$lastName || !$office || !$password) {
        header('Location: ../error.php?code=400&message=' . urlencode('First name, last name, office, and password are required.'));
        exit;
    }

    // Check for duplicate TagID if provided
    if (!empty($tagID)) {
        $stmt = $conn->prepare("SELECT ID FROM users WHERE TagID = ?");
        $stmt->bind_param("s", $tagID);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            header('Location: ../error.php?code=400&message=' . urlencode('Tag ID already exists.'));
            exit;
        }
        $stmt->close();
    }

    // Hash the password securely
   $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);


    // Insert user with default 2FA disabled and admin override ON
    $stmt = $conn->prepare("
        INSERT INTO users (FirstName, LastName, TagID, Office, ClockStatus, Pass, TwoFAEnabled, AdminOverride2FA)
        VALUES (?, ?, ?, ?, 'Out', ?, 0, 1)
    ");
    $stmt->bind_param("sssss", $firstName, $lastName, $tagID, $office, $hashed);

    if ($stmt->execute()) {
        header("Location: manage_users.php");
        exit;
    } else {
        header('Location: ../error.php?code=500&message=' . urlencode('Error: ' . $stmt->error));
        exit;
    }
}
?>