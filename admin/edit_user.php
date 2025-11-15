<?php
require_once '../auth/db.php';
session_start();


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header("Location: manage_users.php");
    exit;
}

// Get user
$stmt = $conn->prepare("SELECT * FROM users WHERE ID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    echo "User not found.";
    exit;
}

// Check if user is admin
$adminUsername = $user['FirstName'] . strtoupper(substr($user['LastName'], 0, 1));
$checkAdmin = $conn->prepare("SELECT ID FROM admins WHERE username = ?");
$checkAdmin->bind_param("s", $adminUsername);
$checkAdmin->execute();
$checkAdmin->store_result();
$isAdmin = $checkAdmin->num_rows > 0;

$offices_result = $conn->query("SELECT OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = [];
while ($row = $offices_result->fetch_assoc()) {
    $offices_data[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['FirstName']);
    $lastName = trim($_POST['LastName']);
    $email = trim($_POST['Email']);
    $tagID = trim($_POST['TagID']);
    $clockStatus = trim($_POST['ClockStatus']);
    $office = trim($_POST['Office']);
    $jobTitle = trim($_POST['JobTitle']);
    $phone = trim($_POST['PhoneNumber']);
    $password = $_POST['Password'];
    $makeAdmin = isset($_POST['MakeAdmin']);
    $enable2FA = isset($_POST['Enable2FA']) ? 1 : 0;
    $recoveryCode = trim($_POST['RecoveryCode']);

    if ($firstName && $lastName && $email && $clockStatus && $office) {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $conn->prepare("UPDATE users SET FirstName = ?, LastName = ?, Email = ?, TagID = ?, ClockStatus = ?, Office = ?, Pass = ?, JobTitle = ?, PhoneNumber = ?, TwoFAEnabled = ?, TwoFARecoveryCode = ? WHERE ID = ?");
            $stmt->bind_param("ssssssssissi", $firstName, $lastName, $email, $tagID, $clockStatus, $office, $hashed, $jobTitle, $phone, $enable2FA, $recoveryCode, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET FirstName = ?, LastName = ?, Email = ?, TagID = ?, ClockStatus = ?, Office = ?, JobTitle = ?, PhoneNumber = ?, TwoFAEnabled = ?, TwoFARecoveryCode = ? WHERE ID = ?");
            $stmt->bind_param("sssssssissi", $firstName, $lastName, $email, $tagID, $clockStatus, $office, $jobTitle, $phone, $enable2FA, $recoveryCode, $id);
        }

        $stmt->execute();

        // Handle Admin switch
        if ($makeAdmin) {
            $check = $conn->prepare("SELECT ID FROM admins WHERE username = ?");
            $check->bind_param("s", $adminUsername);
            $check->execute();
            $check->store_result();

            if ($check->num_rows === 0) {
                $adminPass = !empty($password) ? $hashed : $user['Pass'];
                $insert = $conn->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
                $insert->bind_param("ss", $adminUsername, $adminPass);
                $insert->execute();
            }
        } else {
            $delete = $conn->prepare("DELETE FROM admins WHERE username = ?");
            $delete->bind_param("s", $adminUsername);
            $delete->execute();
        }

        header("Location: manage_users.php");
        exit;
    } else {
        $error = "All fields except password are required.";
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User - D-Best TimeClock</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/edit_user.css">

</head>
<body>

<header class="banner">
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1>Edit User - <?= htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']) ?></h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="view_punches.php">Timesheets</a>
        <a href="summary.php">Summary</a>
        <a href="reports.php" class="active">Reports</a>
        <a href="manage_users.php">Users</a>
        <a href="manage_offices.php">Offices</a>
        <a href="attendance.php">Attendance</a>
        <a href="manage_admins.php">Admins</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<div class="uman-container">
    <h2>Edit User</h2>
    <?php if (!empty($error)): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="uman-form">
        <label>First Name
            <input type="text" name="FirstName" value="<?= htmlspecialchars($user['FirstName'] ?? '') ?>" required>
        </label>

        <label>Last Name
            <input type="text" name="LastName" value="<?= htmlspecialchars($user['LastName'] ?? '') ?>" required>
        </label>

        <label>Email
            <input type="text" name="Email" value="<?= htmlspecialchars($user['Email'] ?? '') ?>" required>
        </label>

        <label>Tag ID
            <input type="text" name="TagID" value="<?= htmlspecialchars($user['TagID'] ?? '') ?>">
        </label>

        <label>Clock Status
            <select name="ClockStatus" required>
                <option value="In" <?= $user['ClockStatus'] === 'In' ? 'selected' : '' ?>>In</option>
                <option value="Out" <?= $user['ClockStatus'] === 'Out' ? 'selected' : '' ?>>Out</option>
                <option value="Break" <?= $user['ClockStatus'] === 'Break' ? 'selected' : '' ?>>Break</option>
                <option value="Lunch" <?= $user['ClockStatus'] === 'Lunch' ? 'selected' : '' ?>>Lunch</option>
            </select>
        </label>

        <label>Office
            <select name="Office" required>
                <?php foreach ($offices_data as $office): ?>
                    <option value="<?= htmlspecialchars($office['OfficeName']) ?>" <?= ($user['Office'] === $office['OfficeName']) ? 'selected' : '' ?>><?= htmlspecialchars($office['OfficeName']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Job Title
            <input type="text" name="JobTitle" value="<?= htmlspecialchars($user['JobTitle'] ?? '') ?>">
        </label>

        <label>Phone Number
            <input type="text" name="PhoneNumber" value="<?= htmlspecialchars($user['PhoneNumber'] ?? '') ?>">
        </label>

        <label>Password <small>(leave blank to keep current)</small>
            <input type="password" name="Password">
        </label>

        <label>Recovery Code
            <input type="text" name="RecoveryCode" value="<?= htmlspecialchars($user['TwoFARecoveryCode'] ?? '') ?>">
        </label>

        <div class="switch-wrapper">
    <span class="switch-label">Enable 2FA</span>
    <label class="switch">
        <input type="checkbox" name="Enable2FA" <?= $user['TwoFAEnabled'] ? 'checked' : '' ?>>
        <span class="slider"></span>
    </label>
</div>

<div class="switch-wrapper">
    <span class="switch-label">Make Admin (<?= $adminUsername ?>)</span>
    <label class="switch">
        <input type="checkbox" name="MakeAdmin" <?= $isAdmin ? 'checked' : '' ?>>
        <span class="slider"></span>
    </label>
</div>


        <div class="modal-actions">
            <a href="manage_users.php" class="btn danger">Cancel</a>
            <button type="submit" class="btn primary">Save Changes</button>
        </div>
    </form>
</div>

</body>
</html>