<?php
// admin/manage_users.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

/*
 * Handle GPS enforcement toggle first (POST-redirect-GET)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_gps'])) {
    $newValue = isset($_POST['EnforceGPS']) ? '1' : '0';
    $stmt = $conn->prepare("
        INSERT INTO settings (SettingKey, SettingValue)
        VALUES ('EnforceGPS', ?)
        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
    ");
    $stmt->bind_param("s", $newValue);
    $stmt->execute();
    header("Location: manage_users.php");
    exit;
}

/*
 * Fetch data for page
 */
$users_result = $conn->query("
    SELECT ID, FirstName, LastName, TagID, ClockStatus, Office, TwoFAEnabled, LockOut
    FROM users
    ORDER BY LastName
");

$users_data = [];
while ($row = $users_result->fetch_assoc()) {
    $users_data[] = $row;
}

$gpsSetting   = $conn->query("SELECT SettingValue FROM settings WHERE SettingKey = 'EnforceGPS'")->fetch_assoc();
$gpsEnforced  = isset($gpsSetting['SettingValue']) && $gpsSetting['SettingValue'] === '1';

$offices_result = $conn->query("SELECT OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = [];
while ($row = $offices_result->fetch_assoc()) {
    $offices_data[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users - D-Best TimeClock</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/uman.css">
    <link rel="stylesheet" href="../css/manage_users.css">
</head>
<body>
<header class="banner">
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1>User Management</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="view_punches.php">Timesheets</a>
        <a href="summary.php">Summary</a>
        <a href="reports.php">Reports</a>
        <a href="manage_users.php" class="active">Users</a>
        <a href="manage_offices.php">Offices</a>
        <a href="attendance.php">Attendance</a>
        <a href="manage_admins.php">Admins</a>
        <a href="settings.php">Settings</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<div class="uman-container">
    <div style="text-align: center; margin-bottom: 1rem;">
        <form method="POST" action="generate_backup_codes.php" style="display:inline-block;">
            <input type="hidden" name="mode" value="all">
            <button type="submit" class="btn primary">🔐 Generate Codes for All Users</button>
        </form>

        <form method="POST" action="generate_backup_codes.php" style="display:inline-block; margin-left:1rem;">
            <input type="hidden" name="mode" value="single">
            <select name="userID" required style="padding: 0.5rem; border-radius: 6px; border: 1px solid #ccc;">
                <option value="">Select User</option>
                <?php foreach ($users_data as $user): ?>
                    <option value="<?= (int)$user['ID'] ?>">
                        <?= htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn warning">🔐 Generate for User ID</button>
        </form>
    </div>

    <div class="uman-header">
        <h2>User Management</h2>
        <button class="btn primary" onclick="document.getElementById('addUserModal').style.display='block'">+ Add User</button>
        <a href="archived_users.php" class="btn">View Archived Users</a>
    </div>

    <form method="POST" style="display: flex; justify-content: center; align-items: center; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
        <input type="hidden" name="toggle_gps" value="1">
        <label class="switch-label">Require GPS for All Punches</label>
        <label class="switch">
            <input type="checkbox" name="EnforceGPS" value="1" <?= $gpsEnforced ? 'checked' : '' ?>>
            <span class="slider"></span>
        </label>
        <button type="submit" class="btn primary small">Save</button>
    </form>

    <table class="uman-table">
        <thead>
        <tr>
            <th>Tag ID</th>
            <th>Full Name</th>
            <th>Clock Status</th>
            <th>Office</th>
            <th>2FA</th>
            <th>
                <span class="tooltip">Lockout
                    <span class="tooltiptext">
                        Lock: User account is locked.<br>
                        Unlock: User account is unlocked.
                    </span>
                </span>
            </th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users_data as $user): ?>
            <tr>
                <td><?= htmlspecialchars($user['TagID'] ?? '') ?></td>
                <td><?= htmlspecialchars(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? '')) ?></td>
                <td><?= htmlspecialchars($user['ClockStatus'] ?? 'Out') ?></td>
                <td><?= htmlspecialchars($user['Office'] ?? 'N/A') ?></td>
                <td><?= !empty($user['TwoFAEnabled']) ? '✅ Enabled' : '❌ Disabled' ?></td>
                <td>
                    <form method="POST" action="update_2fa_status.php" style="display:inline;">
                        <input type="hidden" name="id" value="<?= (int)$user['ID'] ?>">
                        <?php if (!empty($user['LockOut'])): ?>
                            <input type="hidden" name="action" value="unlock">
                            <button type="submit" class="btn small primary">Unlock</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="lock">
                            <button type="submit" class="btn small warning">Lock</button>
                        <?php endif; ?>
                    </form>
                </td>
                <td>
                    <div class="actions-menu">
                        <button class="btn small primary" onclick="toggleActionsMenu(this)">Actions</button>
                        <div class="actions-menu-content">
                            <button class="btn" onclick="location.href='edit_user.php?id=<?= (int)$user['ID'] ?>'">Edit</button>
                            <button class="btn" onclick="showResetModal(<?= (int)$user['ID'] ?>)">Reset</button>
                            <button class="btn" onclick="open2FAModal(<?= (int)$user['ID'] ?>)">2FA Options</button>
                            <button class="btn" onclick="showArchiveModal(<?= (int)$user['ID'] ?>)">Archive</button>
                            <button class="btn" onclick="showDeleteModal(<?= (int)$user['ID'] ?>)">Delete</button>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <form action="add_user.php" method="POST">
            <h3>Add New User</h3>
            <input type="text" name="FirstName" placeholder="First Name" required>
            <input type="text" name="LastName" placeholder="Last Name" required>
            <input type="text" name="TagID" placeholder="Tag ID">
            <select name="Office" required>
                <option value="">Select Office</option>
                <?php foreach ($offices_data as $office): ?>
                    <option value="<?= htmlspecialchars($office['OfficeName']) ?>"><?= htmlspecialchars($office['OfficeName']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="password" name="Password" placeholder="Initial Password" required>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="document.getElementById('addUserModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn primary">Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" class="modal">
    <div class="modal-content">
        <h3>Reset Password</h3>
        <p>Are you sure you want to reset this user's password to the default?</p>
        <div class="modal-actions">
            <button class="btn" onclick="closeResetModal()">Cancel</button>
            <button class="btn primary" id="confirmResetBtn">Yes, Reset</button>
        </div>
    </div>
</div>

<!-- 2FA Modal -->
<div id="modal2FA" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <span class="close-btn" onclick="close2FAModal()">&times;</span>
        <h3 style="text-align: center; margin-bottom: 0.5rem;">🔐 2FA Management</h3>
        <p style="text-align: center; font-size: 0.95rem; color: #444;">
            Choose an action for this user's two-factor authentication.
        </p>

        <form id="form2FA" method="POST" action="update_2fa_status.php">
            <input type="hidden" name="id" id="2faUserId">

            <div class="modal-actions" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn small primary" onclick="confirm2FA('enable')">✅ Enable 2FA</button>
                <button type="button" class="btn small danger" onclick="confirm2FA('disable')">❌ Disable 2FA</button>
                <button type="button" class="btn small warning" onclick="confirm2FA('lock')">🔒 Lock User 2FA Control</button>
                <button type="button" class="btn small" style="background:#ddd;" onclick="confirm2FA('unlock')">🔓 Unlock User 2FA Control</button>
            </div>
        </form>
    </div>
</div>

<script src="../js/manage_users.js?v=1.1"></script>

<!-- Archive User Modal -->
<div id="archiveModal" class="modal">
    <div class="modal-content">
        <h3>Archive User</h3>
        <p>Are you sure you want to archive this user? This will remove them from the active user list, but their data will be kept in the archive.</p>
        <div class="modal-actions">
            <button class="btn" onclick="closeArchiveModal()">Cancel</button>
            <form id="archiveForm" action="archive_user.php" method="POST" style="display:inline;">
                <input type="hidden" name="ID" id="archiveUserId">
                <button type="submit" class="btn primary">Yes, Archive</button>
            </form>
        </div>
    </div>
</div>

<!-- Delete User Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <h3>Delete User</h3>
        <p>Are you sure you want to permanently delete this user? This action cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn" onclick="closeDeleteModal()">Cancel</button>
            <form id="deleteForm" action="delete_user.php" method="POST" style="display:inline;">
                <input type="hidden" name="ID" id="deleteUserId">
                <button type="submit" class="btn danger">Yes, Delete</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
