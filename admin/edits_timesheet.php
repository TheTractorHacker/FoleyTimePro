<?php 
session_start();
require '../auth/db.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Fetch all pending edits with user info
$stmt = $conn->prepare("SELECT pe.*, u.FirstName, u.LastName FROM pending_edits pe 
                        JOIN users u ON pe.EmployeeID = u.ID 
                        WHERE pe.Status = 'Pending' 
                        ORDER BY pe.SubmittedAt DESC");
$stmt->execute();
$result = $stmt->get_result();

$edits = [];
while ($row = $result->fetch_assoc()) {
    $empID = $row['EmployeeID'];
    $date = $row['Date'];

    // Get original row from timepunches
    $origStmt = $conn->prepare("SELECT * FROM timepunches WHERE EmployeeID = ? AND Date = ?");
    $origStmt->bind_param("is", $empID, $date);
    $origStmt->execute();
    $original = $origStmt->get_result()->fetch_assoc();

    if (!$original) continue;

    // Check the pending_edits
    foreach (['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOut'] as $field) {
        if (array_key_exists($field, $row) && !is_null($row[$field]) && $row[$field] !== '' && $row[$field] !== $original[$field]) {
            $edits[] = [
                'ID' => $row['ID'],
                'FirstName' => $row['FirstName'],
                'LastName' => $row['LastName'],
                'Date' => $date,
                'Field' => $field,
                'Original' => $original[$field] ?? '',
                'Requested' => $row[$field],
                'Note' => $row['Note'],
                'Reason' => $row['Reason'],
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Punch Adjustments</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
  <title>Employee Punch Adjustments</title>
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/edits_timesheet.css">
</head>
<body>
<header>
    <img src="/images/D-Best.png" alt="Logo" class="logo">
    <h1>Employee Punch Adjustments</h1>
</header>

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

<div class="dashboard-container">
    <?php if (count($edits) === 0): ?>
        <p class="no-edits">✅ No pending time edits to review at the moment.</p>
    <?php else: ?>
        <form method="POST" action="process_edits.php">
            <table class="approval-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Field</th>
                        <th>Original</th>
                        <th>Requested</th>
                        <th>Note</th>
                        <th>Reason</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($edits as $edit): ?>
                        <tr>
                            <td><?= htmlspecialchars($edit['FirstName'] . ' ' . $edit['LastName']) ?></td>
                            <td><?= htmlspecialchars($edit['Date']) ?></td>
                            <td><strong><?= htmlspecialchars($edit['Field']) ?></strong></td>
                            <td><?= htmlspecialchars($edit['Original']) ?></td>
                            <td style="color:#0078D7;"><strong><?= htmlspecialchars($edit['Requested']) ?></strong></td>
                            <td><?= htmlspecialchars($edit['Note']) ?: '-' ?></td>
                            <td class="note-box"><?= htmlspecialchars($edit['Reason']) ?></td>
                            <td class="action-buttons">
                                <button type="submit" class="approve-btn" name="action[<?= $edit['ID'] ?>][<?= $edit['Field'] ?>]" value="approve">Approve</button>
                                <button type="submit" class="reject-btn" name="action[<?= $edit['ID'] ?>][<?= $edit['Field'] ?>]" value="reject">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
