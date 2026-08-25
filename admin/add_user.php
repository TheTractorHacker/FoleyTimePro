<?php
require_once '../auth/db.php';
require_once __DIR__ . '/../functions/pin_helper.php';
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
    // See admin/edit_user.php for why this must be NULL, not '', when blank —
    // users.TagID has a UNIQUE index and a live row (#1011) already had
    // TagID='' before this fix; two blank-badge creates would otherwise
    // collide on the second one.
    if ($tagID === '') { $tagID = null; }
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
                 ? pin_hash($pin) : null;

    $stmt = $conn->prepare("
        INSERT INTO users (FirstName, LastName, Email, TagID, Office, ClockStatus, Pass, UserPIN, TwoFAEnabled, AdminOverride2FA)
        VALUES (?, ?, ?, ?, ?, 'Out', ?, ?, 0, 1)
    ");
    $stmt->bind_param("sssssss", $firstName, $lastName, $email, $tagID, $office, $hashed, $hashedPin);

    // The SELECT-based duplicate check above is TOCTOU-racy on its own (two
    // concurrent submissions for the same new email can both pass it before
    // either INSERTs) — migration 019's UNIQUE index on users.Email is the
    // real backstop. Under this app's default strict mysqli error mode,
    // execute() THROWS rather than returning false on a violation, so the
    // `if ($stmt->execute())` / `else` shape below never actually reached its
    // else branch for a real DB error; catch the specific duplicate-email
    // case with a clean message instead of an uncaught 500.
    try {
        $stmt->execute();
        header("Location: manage_users.php");
        exit;
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062 && str_contains($e->getMessage(), 'uk_users_email')) {
            header('Location: ../error.php?code=400&message=' . urlencode('An account with that email already exists.'));
            exit;
        }
        if ((int)$e->getCode() === 1062 && str_contains($e->getMessage(), 'TagID')) {
            header('Location: ../error.php?code=400&message=' . urlencode('That Tag ID is already assigned to another employee.'));
            exit;
        }
        throw $e;
    }
}
?>