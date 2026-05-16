<?php
require_once '../auth/db.php';
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

// GPS toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_gps'])) {
    $val = isset($_POST['EnforceGPS']) ? '1' : '0';
    $stmt = $conn->prepare("INSERT INTO settings (SettingKey,SettingValue) VALUES ('EnforceGPS',?) ON DUPLICATE KEY UPDATE SettingValue=VALUES(SettingValue)");
    $stmt->bind_param('s', $val);
    $stmt->execute();
    header('Location: manage_users.php');
    exit;
}

$users_data = $conn->query("SELECT ID,FirstName,LastName,TagID,ClockStatus,Office,TwoFAEnabled,LockOut FROM users ORDER BY LastName")->fetch_all(MYSQLI_ASSOC);
$gpsEnforced = ($conn->query("SELECT SettingValue FROM settings WHERE SettingKey='EnforceGPS'")->fetch_assoc()['SettingValue'] ?? '0') === '1';
$offices_data = $conn->query("SELECT OfficeName FROM Offices ORDER BY OfficeName")->fetch_all(MYSQLI_ASSOC);

$pageTitle   = 'Manage Users';
$headerTitle = 'Manage Users';
$additionalCSS = ['../css/manage_users.css'];
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Users <span style="font-size:14px;font-weight:400;color:#6b7280">(<?= count($users_data) ?>)</span></h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="archived_users.php" class="btn btn-secondary">Archived Users</a>
    <button class="btn btn-primary" onclick="document.getElementById('addUserModal').style.display='block'">+ Add User</button>
  </div>
</div>

<!-- GPS Setting -->
<div class="card" style="margin-bottom:18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:14px 20px">
  <span style="font-size:13px;font-weight:600;color:#374151">Require GPS for all punches</span>
  <form method="POST" style="display:flex;align-items:center;gap:10px">
    <input type="hidden" name="toggle_gps" value="1">
    <label class="switch">
      <input type="checkbox" name="EnforceGPS" value="1" <?= $gpsEnforced ? 'checked' : '' ?>>
      <span class="slider"></span>
    </label>
    <button type="submit" class="btn btn-primary btn-sm">Save</button>
  </form>
  <a href="geofences.php" class="btn btn-secondary btn-sm" style="margin-left:auto">Manage Geofences</a>
</div>

<!-- Users Table -->
<div class="card" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Tag ID</th>
        <th>Status</th>
        <th>Office</th>
        <th>2FA</th>
        <th>Account</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users_data as $u): ?>
      <?php
        $statusClass = match($u['ClockStatus'] ?? 'Out') {
          'In'    => 'badge-in',
          'Lunch' => 'badge-lunch',
          default => 'badge-out',
        };
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($u['FirstName'].' '.$u['LastName']) ?></strong></td>
        <td style="color:#6b7280;font-size:12px"><?= htmlspecialchars($u['TagID'] ?? '—') ?></td>
        <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($u['ClockStatus'] ?? 'Out') ?></span></td>
        <td><?= htmlspecialchars($u['Office'] ?? '—') ?></td>
        <td>
          <?php if (!empty($u['TwoFAEnabled'])): ?>
            <span class="badge badge-approved">On</span>
          <?php else: ?>
            <span class="badge badge-inactive">Off</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="POST" action="update_2fa_status.php" style="display:inline">
            <input type="hidden" name="id" value="<?= (int)$u['ID'] ?>">
            <?php if (!empty($u['LockOut'])): ?>
              <input type="hidden" name="action" value="unlock">
              <button type="submit" class="btn btn-sm btn-primary">Unlock</button>
            <?php else: ?>
              <span class="badge badge-approved" style="font-size:11px">Active</span>
            <?php endif; ?>
          </form>
        </td>
        <td>
          <div class="actions-menu">
            <button class="btn btn-sm btn-secondary" onclick="toggleActionsMenu(this)">Actions ▾</button>
            <div class="actions-menu-content">
              <button onclick="location.href='edit_user.php?id=<?= (int)$u['ID'] ?>'">Edit User</button>
              <button onclick="showResetModal(<?= (int)$u['ID'] ?>)">Reset Password</button>
              <button onclick="open2FAModal(<?= (int)$u['ID'] ?>)">2FA Options</button>
              <button onclick="showArchiveModal(<?= (int)$u['ID'] ?>)">Archive</button>
              <button onclick="showDeleteModal(<?= (int)$u['ID'] ?>" style="color:#e74c3c">Delete</button>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($users_data)): ?>
      <tr><td colspan="7" style="text-align:center;padding:32px;color:#9ca3af">No users found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
  <div class="modal-content">
    <button class="close-btn" onclick="document.getElementById('addUserModal').style.display='none'">&times;</button>
    <h3>Add New User</h3>
    <form action="add_user.php" method="POST" style="display:flex;flex-direction:column;gap:12px;margin-top:16px">
      <div class="form-row">
        <div class="form-group"><label>First Name</label><input type="text" name="FirstName" placeholder="First Name" required></div>
        <div class="form-group"><label>Last Name</label><input type="text" name="LastName" placeholder="Last Name" required></div>
      </div>
      <div class="form-group"><label>Tag ID</label><input type="text" name="TagID" placeholder="Optional"></div>
      <div class="form-group">
        <label>Office</label>
        <select name="Office" required>
          <option value="">Select Office</option>
          <?php foreach ($offices_data as $o): ?>
            <option value="<?= htmlspecialchars($o['OfficeName']) ?>"><?= htmlspecialchars($o['OfficeName']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Initial Password</label><input type="password" name="Password" required></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('addUserModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Add User</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" class="modal">
  <div class="modal-content">
    <h3>Reset Password</h3>
    <p style="color:#6b7280;margin:10px 0 20px">This will reset the user's password to the system default.</p>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
      <button class="btn btn-primary" id="confirmResetBtn">Yes, Reset</button>
    </div>
  </div>
</div>

<!-- 2FA Modal -->
<div id="modal2FA" class="modal">
  <div class="modal-content">
    <button class="close-btn" onclick="close2FAModal()">&times;</button>
    <h3>2FA Management</h3>
    <p style="color:#6b7280;margin:8px 0 20px;font-size:13px">Manage two-factor authentication for this user.</p>
    <form id="form2FA" method="POST" action="update_2fa_status.php">
      <input type="hidden" name="id" id="2faUserId">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <button type="button" class="btn btn-primary" onclick="confirm2FA('enable')">Enable 2FA</button>
        <button type="button" class="btn btn-danger" onclick="confirm2FA('disable')">Disable 2FA</button>
        <button type="button" class="btn btn-warning" onclick="confirm2FA('lock')">Lock 2FA Control</button>
        <button type="button" class="btn btn-secondary" onclick="confirm2FA('unlock')">Unlock 2FA Control</button>
      </div>
    </form>
  </div>
</div>

<!-- Archive Modal -->
<div id="archiveModal" class="modal">
  <div class="modal-content">
    <h3>Archive User</h3>
    <p style="color:#6b7280;margin:10px 0 20px;font-size:13px">This user will be moved to the archive. Their punch history is preserved.</p>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeArchiveModal()">Cancel</button>
      <form id="archiveForm" action="archive_user.php" method="POST" style="display:inline">
        <input type="hidden" name="ID" id="archiveUserId">
        <button type="submit" class="btn btn-primary">Archive</button>
      </form>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal">
  <div class="modal-content">
    <h3>Delete User</h3>
    <p style="color:#e74c3c;margin:10px 0 20px;font-size:13px">This permanently deletes the user and all their data. This cannot be undone.</p>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
      <form id="deleteForm" action="delete_user.php" method="POST" style="display:inline">
        <input type="hidden" name="ID" id="deleteUserId">
        <button type="submit" class="btn btn-danger">Delete Permanently</button>
      </form>
    </div>
  </div>
</div>

<script src="../js/manage_users.js?v=1.1"></script>
<?php include 'includes/footer.php'; ?>
