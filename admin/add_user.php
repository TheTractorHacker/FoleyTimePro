<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    $firstName = trim($_POST['FirstName'] ?? '');
    $lastName  = trim($_POST['LastName'] ?? '');
    $email     = trim($_POST['Email'] ?? '');
    $tagID     = trim($_POST['TagID'] ?? '');
    $office    = trim($_POST['Office'] ?? '');
    $password  = $_POST['Password'] ?? '';
    $pin       = trim($_POST['UserPIN'] ?? '');

    if (!$firstName || !$lastName || !$email || !$office || !$password) {
        header('Location: ../error.php?code=400&message=' . urlencode('First name, last name, email, office, and password are required.'));
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: ../error.php?code=400&message=' . urlencode('Invalid email address.'));
        exit;
    }

    // Duplicate email check
    $chk = $conn->prepare("SELECT ID FROM users WHERE Email = ?");
    $chk->bind_param("s", $email);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        header('Location: ../error.php?code=400&message=' . urlencode('An account with that email already exists.'));
        exit;
    }
    $chk->close();

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

    $hashed    = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $hashedPin = (!empty($pin) && ctype_digit($pin) && strlen($pin) >= 2 && strlen($pin) <= 6)
                 ? hash('sha256', $pin) : null;

    $stmt = $conn->prepare("
        INSERT INTO users (FirstName, LastName, Email, TagID, Office, ClockStatus, Pass, UserPIN, TwoFAEnabled, AdminOverride2FA)
        VALUES (?, ?, ?, ?, ?, 'Out', ?, ?, 0, 1)
    ");
    $stmt->bind_param("sssssss", $firstName, $lastName, $email, $tagID, $office, $hashed, $hashedPin);

    if ($stmt->execute()) {
        header("Location: manage_users.php");
        exit;
    } else {
        header('Location: ../error.php?code=500&message=' . urlencode('Error: ' . $stmt->error));
        exit;
    }
}
?>