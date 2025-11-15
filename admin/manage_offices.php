<?php
// admin/manage_offices.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$offices_result = $conn->query("SELECT ID, OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = [];
while ($row = $offices_result->fetch_assoc()) {
    $offices_data[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Offices - D-Best TimeClock</title>
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/uman.css">
    <link rel="stylesheet" href="../css/manage_users.css">
</head>
<body>
<header class="banner">
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1>Office Management</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="view_punches.php">Timesheets</a>
        <a href="summary.php">Summary</a>
        <a href="reports.php">Reports</a>
        <a href="manage_users.php">Users</a>
        <a href="manage_offices.php" class="active">Offices</a>
        <a href="attendance.php">Attendance</a>
        <a href="manage_admins.php">Admins</a>
        <a href="settings.php">Settings</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<div class="uman-container">
    <div class="uman-header">
        <h2>Manage Offices</h2>
        <button class="btn primary" onclick="document.getElementById('addOfficeModal').style.display='block'">+ Add Office</button>
    </div>

    <table class="uman-table">
        <thead>
        <tr>
            <th>Office Name</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($offices_data as $office): ?>
            <tr>
                <td><?= htmlspecialchars($office['OfficeName']) ?></td>
                <td>
                    <form action="remove_office.php" method="POST" style="display:inline;">
                        <input type="hidden" name="ID" value="<?= (int)$office['ID'] ?>">
                        <button type="submit" class="btn danger small">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add Office Modal -->
<div id="addOfficeModal" class="modal">
    <div class="modal-content">
        <form action="add_office.php" method="POST">
            <h3>Add New Office</h3>
            <input type="text" name="OfficeName" placeholder="Office Name" required>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="document.getElementById('addOfficeModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn primary">Add Office</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>
