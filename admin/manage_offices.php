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

<?php
$pageTitle   = 'Manage Offices';
$headerTitle = 'Manage Offices';
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Offices <span style="font-size:14px;font-weight:400;color:#6b7280">(<?= count($offices_data) ?>)</span></h1>
  <button class="btn btn-primary" onclick="document.getElementById('addOfficeModal').style.display='block'">+ Add Office</button>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead>
      <tr><th>Office Name</th><th style="width:120px">Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($offices_data as $office): ?>
      <tr>
        <td><strong><?= htmlspecialchars($office['OfficeName']) ?></strong></td>
        <td>
          <form action="remove_office.php" method="POST" style="display:inline" onsubmit="return confirm('Remove this office?')">
<?= csrf_field() ?>
            <input type="hidden" name="ID" value="<?= (int)$office['ID'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($offices_data)): ?>
      <tr><td colspan="2" style="text-align:center;padding:32px;color:#9ca3af">No offices yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add Office Modal -->
<div id="addOfficeModal" class="modal">
  <div class="modal-content" style="max-width:420px">
    <button class="close-btn" onclick="document.getElementById('addOfficeModal').style.display='none'">&times;</button>
    <h3>Add New Office</h3>
    <form action="add_office.php" method="POST" style="margin-top:16px">
<?= csrf_field() ?>
      <div class="form-group">
        <label>Office Name</label>
        <input type="text" name="OfficeName" placeholder="e.g. Main Office" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('addOfficeModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Office</button>
      </div>
    </form>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
