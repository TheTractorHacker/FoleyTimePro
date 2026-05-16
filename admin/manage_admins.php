<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Handle deletion
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_admins.php");
    exit;
}

// Fetch all admins
$admins = [];
$result = $conn->query("SELECT id, username FROM admins ORDER BY id");
if ($result) {
    $admins = $result->fetch_all(MYSQLI_ASSOC);
}
?>

<?php
$pageTitle   = 'Manage Admins';
$headerTitle = 'Manage Admins';
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Admin Accounts</h1>
  <a href="add_admin.php" class="btn btn-primary">+ Add Admin</a>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Username</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($admins as $admin): ?>
      <tr>
        <td style="color:#9ca3af;font-size:12px"><?= (int)$admin['id'] ?></td>
        <td><strong><?= htmlspecialchars($admin['username']) ?></strong></td>
        <td>
          <a class="btn btn-sm btn-secondary" href="edit_admin.php?id=<?= $admin['id'] ?>">Edit</a>
          <a class="btn btn-sm btn-danger" href="?delete=<?= $admin['id'] ?>" onclick="return confirm('Delete this admin?')">Delete</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($admins)): ?>
      <tr><td colspan="3" style="text-align:center;padding:32px;color:#9ca3af">No admin accounts found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include 'includes/footer.php'; ?>
