<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header("Location: manage_users.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM users WHERE ID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    header("Location: manage_users.php");
    exit;
}

$adminUsername = $user['FirstName'] . strtoupper(substr($user['LastName'], 0, 1));
$checkAdmin = $conn->prepare("SELECT ID FROM admins WHERE username = ?");
$checkAdmin->bind_param("s", $adminUsername);
$checkAdmin->execute();
$checkAdmin->store_result();
$isAdmin = $checkAdmin->num_rows > 0;

$offices_result = $conn->query("SELECT OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = $offices_result ? $offices_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch shifts for assignment dropdown
$shiftsResult = $conn->query("SELECT ShiftID, ShiftName FROM shifts WHERE IsActive=1 ORDER BY ShiftName");
$allShifts = $shiftsResult ? $shiftsResult->fetch_all(MYSQLI_ASSOC) : [];

// Current shift assignment
$assignedShift = null;
$shiftStmt = $conn->prepare("SELECT ShiftID FROM user_shifts WHERE UserID=?");
$shiftStmt->bind_param("i", $id);
$shiftStmt->execute();
$shiftRow = $shiftStmt->get_result()->fetch_assoc();
if ($shiftRow) $assignedShift = (int)$shiftRow['ShiftID'];

$error = '';
$success = '';
$newPasswordSet = '';

require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    $firstName   = trim($_POST['FirstName'] ?? '');
    $lastName    = trim($_POST['LastName']  ?? '');
    $email       = trim($_POST['Email']     ?? '');
    $tagID       = trim($_POST['TagID']     ?? '');
    $clockStatus = trim($_POST['ClockStatus'] ?? '');
    $office      = trim($_POST['Office']    ?? '');
    $jobTitle    = trim($_POST['JobTitle']  ?? '');
    $phone       = trim($_POST['PhoneNumber'] ?? '');
    $password    = $_POST['Password'] ?? '';
    $newPin      = trim($_POST['UserPIN'] ?? '');
    $lockOut     = isset($_POST['LockOut']) ? 1 : 0;
    $makeAdmin   = isset($_POST['MakeAdmin']);
    $enable2FA   = isset($_POST['Enable2FA']) ? 1 : 0;
    $newShiftId  = (int)($_POST['ShiftID'] ?? 0);

    if (!$firstName || !$lastName || !$email || !$clockStatus || !$office) {
        $error = "First name, last name, email, clock status, and office are required.";
    } else {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $conn->prepare("UPDATE users SET FirstName=?,LastName=?,Email=?,TagID=?,ClockStatus=?,Office=?,Pass=?,JobTitle=?,PhoneNumber=?,TwoFAEnabled=?,LockOut=? WHERE ID=?");
            $stmt->bind_param("sssssssssiis", $firstName, $lastName, $email, $tagID, $clockStatus, $office, $hashed, $jobTitle, $phone, $enable2FA, $lockOut, $id);
            $newPasswordSet = $password;
        } else {
            $stmt = $conn->prepare("UPDATE users SET FirstName=?,LastName=?,Email=?,TagID=?,ClockStatus=?,Office=?,JobTitle=?,PhoneNumber=?,TwoFAEnabled=?,LockOut=? WHERE ID=?");
            $stmt->bind_param("ssssssssiis", $firstName, $lastName, $email, $tagID, $clockStatus, $office, $jobTitle, $phone, $enable2FA, $lockOut, $id);
        }
        $stmt->execute();

        // Update PIN separately if provided
        if ($newPin !== '') {
            if (!ctype_digit($newPin) || strlen($newPin) < 2 || strlen($newPin) > 6) {
                $error = "Web Portal PIN must be 2–6 digits.";
            } else {
                $pinHash = hash('sha256', $newPin);
                $pinStmt = $conn->prepare("UPDATE users SET UserPIN=? WHERE ID=?");
                $pinStmt->bind_param("si", $pinHash, $id);
                $pinStmt->execute();
            }
        }

        // Admin toggle
        if ($makeAdmin) {
            $check = $conn->prepare("SELECT ID FROM admins WHERE username=?");
            $check->bind_param("s", $adminUsername);
            $check->execute();
            $check->store_result();
            if ($check->num_rows === 0) {
                $adminPass = !empty($password) ? $hashed : $user['Pass'];
                $insert = $conn->prepare("INSERT INTO admins (username, password) VALUES (?,?)");
                $insert->bind_param("ss", $adminUsername, $adminPass);
                $insert->execute();
            }
        } else {
            $del = $conn->prepare("DELETE FROM admins WHERE username=?");
            $del->bind_param("s", $adminUsername);
            $del->execute();
        }

        // Shift assignment
        if ($newShiftId > 0) {
            $conn->prepare("DELETE FROM user_shifts WHERE UserID=?")->execute([$id]) || true;
            $usDel = $conn->prepare("DELETE FROM user_shifts WHERE UserID=?");
            $usDel->bind_param("i", $id);
            $usDel->execute();
            $usIns = $conn->prepare("INSERT INTO user_shifts (UserID,ShiftID) VALUES (?,?) ON DUPLICATE KEY UPDATE ShiftID=?");
            $usIns->bind_param("iii", $id, $newShiftId, $newShiftId);
            $usIns->execute();
        } else {
            $usDel = $conn->prepare("DELETE FROM user_shifts WHERE UserID=?");
            $usDel->bind_param("i", $id);
            $usDel->execute();
        }

        $success = "User updated successfully.";
        // Reload user
        $stmt2 = $conn->prepare("SELECT * FROM users WHERE ID=?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $user = $stmt2->get_result()->fetch_assoc();
        $assignedShift = $newShiftId ?: null;
    }
}

$pageTitle   = 'Edit User — ' . htmlspecialchars(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? ''));
$headerTitle = 'Edit User';
include 'includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
  <a href="manage_users.php" class="btn btn-secondary btn-sm">&#8592; Back</a>
  <div>
    <h1 style="margin:0">Edit User</h1>
    <p style="margin:0;font-size:13px;color:var(--text-muted)">#<?= (int)$id ?> &mdash; <?= htmlspecialchars(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? '')) ?></p>
  </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
  <?= htmlspecialchars($success) ?>
  <?php if ($newPasswordSet): ?>
  &nbsp;&mdash; New password set: <strong style="font-family:monospace;letter-spacing:.05em"><?= htmlspecialchars($newPasswordSet) ?></strong>
  &nbsp;<button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($newPasswordSet,ENT_QUOTES) ?>');this.textContent='Copied!'" style="border:none;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:700">Copy</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<form method="POST">
<?= csrf_field() ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

    <!-- Left column -->
    <div>
      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-bottom:16px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Personal Info</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label>First Name *</label>
            <input type="text" name="FirstName" value="<?= htmlspecialchars($user['FirstName'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label>Last Name *</label>
            <input type="text" name="LastName" value="<?= htmlspecialchars($user['LastName'] ?? '') ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label>Email *</label>
          <input type="email" name="Email" value="<?= htmlspecialchars($user['Email'] ?? '') ?>" required>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label>Phone</label>
            <input type="tel" name="PhoneNumber" value="<?= htmlspecialchars($user['PhoneNumber'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Job Title</label>
            <input type="text" name="JobTitle" value="<?= htmlspecialchars($user['JobTitle'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Tag / Badge ID</label>
          <input type="text" name="TagID" value="<?= htmlspecialchars($user['TagID'] ?? '') ?>">
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-bottom:16px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Login Password</h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Used for the mobile app and web portal. Leave blank to keep existing.</p>
        <div class="form-group">
          <label>New Password</label>
          <div style="display:flex;gap:8px">
            <input type="text" name="Password" id="passwordField"
                   placeholder="Leave blank to keep current"
                   autocomplete="new-password"
                   style="flex:1;font-family:monospace;letter-spacing:.05em">
            <button type="button" onclick="generatePassword()"
                    style="white-space:nowrap;padding:8px 12px;background:#f0f2f5;border:1.5px solid #e1e4e8;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;color:#374151">
              &#9881; Generate
            </button>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-bottom:16px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Web Portal PIN</h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">2–6 digit numeric PIN for the web portal login. Separate from the app password. Leave blank to keep existing.</p>
        <div class="form-group">
          <label>New PIN (2–6 digits)</label>
          <input type="text" name="UserPIN" id="pinField"
                 placeholder="Leave blank to keep current"
                 pattern="\d{2,6}" inputmode="numeric" maxlength="6"
                 style="font-family:monospace;letter-spacing:.2em;font-size:18px;width:160px">
        </div>
      </div>
    </div>

    <!-- Right column -->
    <div>
      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-bottom:16px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Work Settings</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label>Office *</label>
            <select name="Office" required>
              <?php foreach ($offices_data as $off): ?>
                <option value="<?= htmlspecialchars($off['OfficeName']) ?>" <?= $user['Office']===$off['OfficeName']?'selected':'' ?>><?= htmlspecialchars($off['OfficeName']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Clock Status *</label>
            <select name="ClockStatus" required>
              <?php foreach (['In','Out','Lunch','Break'] as $s): ?>
                <option value="<?= $s ?>" <?= $user['ClockStatus']===$s?'selected':'' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Assigned Shift <small style="font-weight:400;color:var(--text-muted)">(required for auto clock-out)</small></label>
          <select name="ShiftID">
            <option value="0">— No shift assigned —</option>
            <?php foreach ($allShifts as $sh): ?>
              <option value="<?= $sh['ShiftID'] ?>" <?= $assignedShift===$sh['ShiftID']?'selected':'' ?>><?= htmlspecialchars($sh['ShiftName']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-bottom:16px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Account Controls</h3>

        <div class="switch-wrapper" style="margin-bottom:12px">
          <span class="switch-label">Account Locked</span>
          <label class="switch">
            <input type="checkbox" name="LockOut" <?= $user['LockOut'] ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>
        <div class="switch-wrapper" style="margin-bottom:12px">
          <span class="switch-label">Enable 2FA</span>
          <label class="switch">
            <input type="checkbox" name="Enable2FA" <?= $user['TwoFAEnabled'] ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>
        <div class="switch-wrapper">
          <span class="switch-label">Admin Access <small style="font-size:11px;color:var(--text-muted)">(<?= htmlspecialchars($adminUsername) ?>)</small></span>
          <label class="switch">
            <input type="checkbox" name="MakeAdmin" <?= $isAdmin ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>
      </div>

      <div style="display:flex;gap:10px">
        <a href="manage_users.php" class="btn btn-secondary" style="flex:1;text-align:center">Cancel</a>
        <button type="submit" class="btn btn-primary" style="flex:2">Save Changes</button>
      </div>
    </div>

  </div>
</form>

<script>
function generatePassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
  let pw = '';
  for (let i = 0; i < 10; i++) pw += chars[Math.floor(Math.random() * chars.length)];
  document.getElementById('passwordField').value = pw;
  document.getElementById('passwordField').select();
}
</script>

<?php include 'includes/footer.php'; ?>
