<?php
// admin/archived_users.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$archived_users_result = $conn->query("SELECT ID, FirstName, LastName, Email, ArchivedAt FROM `user-archive` ORDER BY ArchivedAt DESC");
$archived_users_data = [];
while ($row = $archived_users_result->fetch_assoc()) {
    $archived_users_data[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archived Users - D-Best TimeClock</title>
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/uman.css">
    <link rel="stylesheet" href="../css/manage_users.css">
</head>
<body>
<header class="banner">
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1>Archived Users</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="view_punches.php">Timesheets</a>
        <a href="summary.php">Summary</a>
        <a href="reports.php">Reports</a>
        <a href="manage_users.php">Users</a>
        <a href="manage_offices.php">Offices</a>
        <a href="attendance.php">Attendance</a>
        <a href="manage_admins.php">Admins</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<div class="uman-container">
    <div class="uman-header">
        <h2>Archived Users</h2>
    </div>

    <table class="uman-table">
        <thead>
        <tr>
            <th>Full Name</th>
            <th>Email</th>
            <th>Archived At</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($archived_users_data as $user): ?>
            <tr>
                <td><?= htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']) ?></td>
                <td><?= htmlspecialchars($user['Email']) ?></td>
                <td><?= htmlspecialchars($user['ArchivedAt']) ?></td>
                <td>
                    <form action="unarchive_user.php" method="POST" style="display:inline;">
                        <input type="hidden" name="ID" value="<?= (int)$user['ID'] ?>">
                        <button type="submit" class="btn primary small">Unarchive</button>
                    </form>
                    <form action="permanently_delete_user.php" method="POST" style="display:inline;">
                        <input type="hidden" name="ID" value="<?= (int)$user['ID'] ?>">
                        <button type="submit" class="btn danger small" onclick="return confirm('Are you sure you want to permanently delete this user? This action cannot be undone.')">Delete Permanently</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
