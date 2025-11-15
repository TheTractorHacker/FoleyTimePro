<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('America/Chicago');

$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

$from = $_GET['from'] ?? date('Y-m-d', strtotime('monday this week'));
$to = $_GET['to'] ?? date('Y-m-d', strtotime('sunday this week'));
$employeeID = $_GET['emp'] ?? '';
$mode = $_GET['mode'] ?? 'view';

if (isset($_GET['daterange'])) {
    $dateRange = explode(' - ', $_GET['daterange']);
    if (count($dateRange) == 2) {
        $from_raw = $dateRange[0];
        $to_raw = $dateRange[1];
        $date_obj = DateTime::createFromFormat('m/d/Y', $from_raw);
        if ($date_obj) {
            $from = $date_obj->format('Y-m-d');
        }
        $date_obj = DateTime::createFromFormat('m/d/Y', $to_raw);
        if ($date_obj) {
            $to = $date_obj->format('Y-m-d');
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Punches</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/timesheet.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css" />
    <link rel="stylesheet" href="../css/view_punches.css">
    <script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
    <script src="../js/admin_timesheet_edit.js"></script>
</head>
<body>

<header>
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1>Employee Punch Adjustments</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="view_punches.php" class="active">Timesheets</a>
        <a href="summary.php">Summary</a>
        <a href="reports.php">Reports</a>
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
        <form method="GET" class="summary-filter" style="display: flex; align-items: flex-end; gap: 10px;">
            <div class="field" style="flex: 1.5;">
                <label>Date Range:</label>
                <input type="text" name="daterange" id="daterange" value="<?= htmlspecialchars(date('m/d/Y', strtotime($from))) . ' - ' . htmlspecialchars(date('m/d/Y', strtotime($to))) ?>">
            </div>
            <div class="field" style="flex: 1;">
                <label>Employee:</label>
                <select name="emp" required>
                    <option value="">Select Employee</option>
                    <?php while ($emp = $employeeList->fetch_assoc()): ?>
                        <option value="<?= $emp['ID'] ?>" <?= ($emp['ID'] == $employeeID ? 'selected' : '') ?>>
                            <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php if (!empty($employeeID)): ?>
            <div class="field" style="flex: 1;">
                <label for="mode-select">Mode:</label>
                <select id="mode-select" onchange="location = this.value;">
                    <option value="?daterange=<?= htmlspecialchars(date('m/d/Y', strtotime($from))) . ' - ' . htmlspecialchars(date('m/d/Y', strtotime($to))) ?>&emp=<?= $employeeID ?>&mode=view" <?= $mode == 'view' ? 'selected' : '' ?>>View</option>
                    <option value="?daterange=<?= htmlspecialchars(date('m/d/Y', strtotime($from))) . ' - ' . htmlspecialchars(date('m/d/Y', strtotime($to))) ?>&emp=<?= $employeeID ?>&mode=edit" <?= $mode == 'edit' ? 'selected' : '' ?>>Edit</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="buttons">
                <button type="submit">Filter</button>
                <a href="view_punches.php" class="btn-reset">Reset</a>
            </div>
        </form>

        <?php if (!empty($employeeID)): ?>
        

        <?php
        $_GET['from'] = date('m/d/Y', strtotime($from));
        $_GET['to'] = date('m/d/Y', strtotime($to));

        switch ($mode) {
            case 'edit':
                include 'timesheet_edit.php';
                break;
            case 'add':
                include 'timesheet_add.php';
                break;
            default:
                include 'timesheet_view.php';
                break;
        }
        ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>