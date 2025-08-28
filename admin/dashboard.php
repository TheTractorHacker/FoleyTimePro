<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require '../auth/db.php';

$adminUsername = $_SESSION['admin'];

// Fetch admin's 2FA status
$stmt = $conn->prepare("SELECT TwoFAEnabled FROM admins WHERE username = ?");
$stmt->bind_param("s", $adminUsername);
$stmt->execute();
$result = $stmt->get_result();
$admin2FAStatus = $result->fetch_assoc();
$is2FAEnabled = $admin2FAStatus['TwoFAEnabled'] ?? 0;

// Get count of pending edits
$pendingCount = 0;
$stmt = $conn->query("SELECT COUNT(*) AS total FROM pending_edits WHERE Status = 'Pending'");
if ($row = $stmt->fetch_assoc()) {
    $pendingCount = $row['total'];
}

// Additional stats
$totalUsers = 0;
$clockedIn = 0;
$onLunch = 0;
$clockedOut = 0;

$statsQuery = "
    SELECT
        COUNT(*) as totalUsers,
        SUM(IF(ClockStatus = 'In', 1, 0)) as clockedIn,
        SUM(IF(ClockStatus = 'Lunch', 1, 0)) as onLunch,
        SUM(IF(ClockStatus = 'Out', 1, 0)) as clockedOut
    FROM users
";
$statsResult = $conn->query($statsQuery);
if ($statsResult && $statsRow = $statsResult->fetch_assoc()) {
    $totalUsers = (int) ($statsRow['totalUsers'] ?? 0);
    $clockedIn = (int) ($statsRow['clockedIn'] ?? 0);
    $onLunch = (int) ($statsRow['onLunch'] ?? 0);
    $clockedOut = (int) ($statsRow['clockedOut'] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>D-Best Admin Dashboard</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/dashboard.css">
</head>
<body>

<header>
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1>Admin Dashboard</h1>
</header>

<div class="info-stats">
    <div class="info-card pending">
        <h3>Needs Approval</h3>
        <div class="count"><?= $pendingCount ?></div>
    </div>
    <div class="info-card users">
        <h3>Total Users</h3>
        <div class="count"><?= $totalUsers ?></div>
    </div>
    <div class="info-card in">
        <h3>Clocked In</h3>
        <div class="count"><?= $clockedIn ?></div>
    </div>
    <div class="info-card lunch">
        <h3>On Lunch</h3>
        <div class="count"><?= $onLunch ?></div>
    </div>
    <div class="info-card out">
        <h3>Clocked Out</h3>
        <div class="count"><?= $clockedOut ?></div>
    </div>
</div>

<div class="dashboard-container">
    <div class="dashboard">
        <div class="card">
            <h2>View Time Punches</h2>
            <p>Review and manage employee punches.</p>
            <a href="view_punches.php">Open</a>
        </div>
        <div class="card">
            <h2>Manage Users</h2>
            <p>Add, edit, or remove employee accounts.</p>
            <a href="manage_users.php">Open</a>
        </div>
        <div class="card">
            <h2>Manage Admins</h2>
            <p>Create, edit, or remove admin accounts.</p>
            <a href="manage_admins.php">Open</a>
        </div>
        <div class="card">
            <h2>Summary Reports</h2>
            <p>View hours worked by day, week, or user.</p>
            <a href="summary.php">Open</a>
        </div>
        <div class="card">
            <h2>Attendance Reports</h2>
            <p>View employee attendance</p>
            <a href="attendance.php">Open</a>
        </div>
        <div class="card">
            <h2>Pending Approvals</h2>
            <p><?= $pendingCount ?> time edits need review.</p>
            <a href="edits_timesheet.php">Review</a>
        </div>
        <div class="card">
            <h2>Reports</h2>
            <p>Browse through reports.</p>
            <a href="reports.php">Open</a>
        </div>
        <?php if (!$is2FAEnabled): ?>
        <div class="card">
            <h2>Enable 2FA</h2>
            <p>Secure your admin account with Two-Factor Authentication.</p>
            <a href="enable_2fa.php">Setup 2FA</a>
        </div>
        <?php else: ?>
        <div class="card">
            <h2>Disable 2FA</h2>
            <p>Remove Two-Factor Authentication from your admin account.</p>
            <a href="disable_2fa.php">Disable 2FA</a>
        </div>
        <?php endif; ?>
        <div class="card">
            <h2>Logout</h2>
            <p>Sign out of the admin dashboard.</p>
            <a href="../logout.php">Logout</a>
        </div>
    </div>
</div>

</body>
</html>
