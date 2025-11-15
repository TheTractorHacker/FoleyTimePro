<?php
session_start();
require_once '../auth/db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports Dashboard</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/reports.css">
</head>
<body>

<header>
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1>Reports Dashboard</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="view_punches.php">Timesheets</a>
        <a href="summary.php">Summary</a>
        <a href="reports.php" class="active">Reports</a>
        <a href="manage_users.php">Users</a>
        <a href="manage_offices.php">Offices</a>
        <a href="attendance.php">Attendance</a>
        <a href="manage_admins.php">Admins</a>
        <a href="settings.php">Settings</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<div class="dashboard-container">
    <div class="container">
        <h2>Available Reports</h2>
        <div class="reports-container">
            <div class="report-card">
                <h3>Summary Report</h3>
                <p>View total hours, regular, and overtime hours across all employees or individually.</p>
                <a href="summary.php">Open Summary</a>
            </div>

            <div class="report-card">
                <h3>Timesheet Report</h3>
                <p>View and edit detailed punch logs including lunch and break periods per employee.</p>
                <a href="view_punches.php">Open Timesheets</a>
            </div>

            <div class="report-card">
                <h3>Export History <em>(Coming Soon)</em></h3>
                <p>View previously exported PDF or Excel reports with download links and filters.</p>
                <a href="#">Not Available</a>
            </div>

            <div class="report-card">
                <h3>Custom Date Reports <em>(Coming Soon)</em></h3>
                <p>Generate custom reports by selecting exact dates, users, and format preferences.</p>
                <a href="#">Not Available</a>
            </div>
        </div>
    </div>
</div>

</body>
</html>
