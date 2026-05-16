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

<?php
$pageTitle   = 'Archived Users';
$headerTitle = 'Archived Users';
include 'includes/header.php';
?>
<div class="page-header">
        <h1>Archived Users</h2>
    </div>

    <table class="data-table">
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
                        <button type="submit" class="btn btn-primary btn-sm">Unarchive</button>
                    </form>
                    <form action="permanently_delete_user.php" method="POST" style="display:inline;">
                        <input type="hidden" name="ID" value="<?= (int)$user['ID'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to permanently delete this user? This action cannot be undone.')">Delete Permanently</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
