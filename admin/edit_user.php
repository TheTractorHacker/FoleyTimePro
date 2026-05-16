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

$pageTitle   = 'Edit User';
$headerTitle = 'Edit User';
include 'includes/header.php';

$initials = strtoupper(substr($user['FirstName'] ?? 'U', 0, 1) . substr($user['LastName'] ?? '', 0, 1));
$statusColor = match($user['ClockStatus'] ?? 'Out') {
    'In'    => '#16a34a',
    'Lunch' => '#d97706',
    default => '#6b7280',
};
?>

<style>
.eu-wrap { max-width: 960px; margin: 0 auto; }

/* ── Page header ── */
.eu-hero {
  display: flex;
  align-items: center;
  gap: 20px;
  background: linear-gradient(135deg, #073851 0%, #0a4a6b 100%);
  border-radius: 16px;
  padding: 24px 28px;
  margin-bottom: 28px;
  color: #fff;
}
.eu-avatar {
  width: 68px; height: 68px;
  border-radius: 50%;
  background: rgba(255,255,255,.18);
  border: 3px solid rgba(255,255,255,.35);
  display: flex; align-items: center; justify-content: center;
  font-size: 26px; font-weight: 800; letter-spacing: .02em;
  flex-shrink: 0;
}
.eu-hero-info h1 { margin: 0; font-size: 22px; font-weight: 700; }
.eu-hero-info p  { margin: 4px 0 0; font-size: 13px; opacity: .7; }
.eu-hero-back {
  margin-left: auto;
  padding: 8px 16px;
  background: rgba(255,255,255,.12);
  border: 1.5px solid rgba(255,255,255,.25);
  border-radius: 8px;
  color: #fff;
  text-decoration: none;
  font-size: 13px; font-weight: 600;
  transition: background .15s;
}
.eu-hero-back:hover { background: rgba(255,255,255,.22); color: #fff; }
.eu-status-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 10px;
  border-radius: 20px;
  background: rgba(255,255,255,.15);
  font-size: 12px; font-weight: 600;
  margin-top: 6px;
}
.eu-status-dot { width:7px; height:7px; border-radius:50%; }

/* ── Grid ── */
.eu-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  align-items: start;
}
@media (max-width: 720px) { .eu-grid { grid-template-columns: 1fr; } }

/* ── Section cards ── */
.eu-card {
  background: #fff;
  border-radius: 14px;
  border: 1.5px solid #e8eaed;
  overflow: hidden;
  margin-bottom: 18px;
  box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.eu-card-head {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 20px;
  border-bottom: 1.5px solid #f0f2f5;
  background: #fafbfc;
}
.eu-card-icon {
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
}
.eu-card-head h3 { margin: 0; font-size: 14px; font-weight: 700; color: #111827; }
.eu-card-head p  { margin: 1px 0 0; font-size: 11px; color: #6b7280; }
.eu-card-body { padding: 20px; }

/* ── Form fields ── */
.eu-row { display: grid; gap: 14px; margin-bottom: 14px; }
.eu-row:last-child { margin-bottom: 0; }
.eu-row-2 { grid-template-columns: 1fr 1fr; }
.eu-field { display: flex; flex-direction: column; gap: 5px; }
.eu-field label { font-size: 12px; font-weight: 600; color: #374151; }
.eu-field input, .eu-field select {
  height: 40px;
  border: 1.5px solid #d1d5db;
  border-radius: 8px;
  padding: 0 12px;
  font-size: 14px;
  color: #111827;
  background: #fff;
  transition: border-color .15s, box-shadow .15s;
  width: 100%;
  box-sizing: border-box;
}
.eu-field input:focus, .eu-field select:focus {
  outline: none;
  border-color: #073851;
  box-shadow: 0 0 0 3px rgba(7,56,81,.1);
}
.eu-field .hint { font-size: 11px; color: #9ca3af; margin-top: 2px; }

/* Password row */
.eu-pw-row { display: flex; gap: 8px; }
.eu-pw-row input { flex: 1; font-family: monospace; letter-spacing: .05em; }
.eu-gen-btn {
  height: 40px; padding: 0 14px;
  background: #f3f4f6;
  border: 1.5px solid #d1d5db;
  border-radius: 8px;
  font-size: 12px; font-weight: 700;
  cursor: pointer; color: #374151;
  white-space: nowrap;
  transition: background .15s;
}
.eu-gen-btn:hover { background: #e5e7eb; }

/* PIN field */
.eu-pin-input {
  height: 52px !important;
  font-family: monospace !important;
  letter-spacing: .4em !important;
  font-size: 24px !important;
  text-align: center;
}

/* ── Switches ── */
.eu-switch-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid #f3f4f6;
}
.eu-switch-row:last-child { border-bottom: none; padding-bottom: 0; }
.eu-switch-label { font-size: 14px; color: #111827; }
.eu-switch-label small { font-size: 11px; color: #9ca3af; display:block; margin-top:1px; }

/* ── Sticky save bar ── */
.eu-save-bar {
  position: sticky; bottom: 0;
  background: rgba(255,255,255,.95);
  backdrop-filter: blur(8px);
  border-top: 1.5px solid #e8eaed;
  padding: 14px 0;
  margin-top: 8px;
  display: flex; align-items: center; justify-content: flex-end; gap: 12px;
  z-index: 10;
}
.eu-cancel-btn {
  padding: 10px 22px;
  border: 1.5px solid #d1d5db;
  border-radius: 9px;
  background: #fff;
  color: #374151;
  font-size: 14px; font-weight: 600;
  cursor: pointer; text-decoration: none;
  transition: background .15s;
}
.eu-cancel-btn:hover { background: #f3f4f6; }
.eu-save-btn {
  padding: 10px 28px;
  background: #073851;
  border: none; border-radius: 9px;
  color: #fff;
  font-size: 14px; font-weight: 700;
  cursor: pointer;
  transition: background .15s;
}
.eu-save-btn:hover { background: #0a4a6b; }
</style>

<div class="eu-wrap">

<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success" style="margin-bottom:16px">
  <?= htmlspecialchars($success) ?>
  <?php if ($newPasswordSet): ?>
  &nbsp;&mdash; Password: <strong style="font-family:monospace"><?= htmlspecialchars($newPasswordSet) ?></strong>
  &nbsp;<button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($newPasswordSet,ENT_QUOTES) ?>');this.textContent='✓ Copied'" style="border:none;background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700">Copy</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Hero header -->
<div class="eu-hero">
  <div class="eu-avatar"><?= htmlspecialchars($initials) ?></div>
  <div class="eu-hero-info">
    <h1><?= htmlspecialchars(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? '')) ?></h1>
    <p><?= htmlspecialchars($user['Email'] ?? 'No email set') ?> &nbsp;·&nbsp; ID #<?= (int)$id ?></p>
    <div class="eu-status-pill">
      <span class="eu-status-dot" style="background:<?= $statusColor ?>"></span>
      <?= htmlspecialchars($user['ClockStatus'] ?? 'Out') ?>
    </div>
  </div>
  <a href="manage_users.php" class="eu-hero-back">&#8592; All Users</a>
</div>

<form method="POST">
<?= csrf_field() ?>

<div class="eu-grid">

  <!-- ── Left column ── -->
  <div>

    <!-- Personal Info -->
    <div class="eu-card">
      <div class="eu-card-head">
        <div class="eu-card-icon" style="background:#eff6ff">👤</div>
        <div>
          <h3>Personal Info</h3>
          <p>Name, contact details, and badge</p>
        </div>
      </div>
      <div class="eu-card-body">
        <div class="eu-row eu-row-2">
          <div class="eu-field">
            <label>First Name *</label>
            <input type="text" name="FirstName" value="<?= htmlspecialchars($user['FirstName'] ?? '') ?>" required>
          </div>
          <div class="eu-field">
            <label>Last Name *</label>
            <input type="text" name="LastName" value="<?= htmlspecialchars($user['LastName'] ?? '') ?>" required>
          </div>
        </div>
        <div class="eu-row">
          <div class="eu-field">
            <label>Email * <span style="color:#9ca3af;font-weight:400">(used for app login)</span></label>
            <input type="email" name="Email" value="<?= htmlspecialchars($user['Email'] ?? '') ?>" required>
          </div>
        </div>
        <div class="eu-row eu-row-2">
          <div class="eu-field">
            <label>Phone</label>
            <input type="tel" name="PhoneNumber" value="<?= htmlspecialchars($user['PhoneNumber'] ?? '') ?>" placeholder="Optional">
          </div>
          <div class="eu-field">
            <label>Job Title</label>
            <input type="text" name="JobTitle" value="<?= htmlspecialchars($user['JobTitle'] ?? '') ?>" placeholder="Optional">
          </div>
        </div>
        <div class="eu-row">
          <div class="eu-field">
            <label>Badge / Tag ID <span style="color:#9ca3af;font-weight:400">(NFC kiosk)</span></label>
            <input type="text" name="TagID" value="<?= htmlspecialchars($user['TagID'] ?? '') ?>" placeholder="Optional">
          </div>
        </div>
      </div>
    </div>

    <!-- Login Password -->
    <div class="eu-card">
      <div class="eu-card-head">
        <div class="eu-card-icon" style="background:#fefce8">🔑</div>
        <div>
          <h3>Login Password</h3>
          <p>Mobile app login — leave blank to keep current</p>
        </div>
      </div>
      <div class="eu-card-body">
        <div class="eu-field">
          <label>New Password</label>
          <div class="eu-pw-row">
            <input type="text" name="Password" id="passwordField"
                   placeholder="Leave blank to keep current"
                   autocomplete="new-password">
            <button type="button" class="eu-gen-btn" onclick="generatePassword()">⚙ Generate</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Web Portal PIN -->
    <div class="eu-card">
      <div class="eu-card-head">
        <div class="eu-card-icon" style="background:#f0fdf4">🔢</div>
        <div>
          <h3>Web Portal PIN</h3>
          <p>2–6 digit PIN for web login — leave blank to keep current</p>
        </div>
      </div>
      <div class="eu-card-body">
        <div class="eu-field">
          <label>New PIN</label>
          <input type="text" name="UserPIN" id="pinField" class="eu-pin-input"
                 placeholder="••••"
                 pattern="\d{2,6}" inputmode="numeric" maxlength="6">
          <span class="hint">2–6 digits only. Separate from the app password.</span>
        </div>
      </div>
    </div>

  </div>

  <!-- ── Right column ── -->
  <div>

    <!-- Work Settings -->
    <div class="eu-card">
      <div class="eu-card-head">
        <div class="eu-card-icon" style="background:#f0f9ff">🏢</div>
        <div>
          <h3>Work Settings</h3>
          <p>Office, status, and shift assignment</p>
        </div>
      </div>
      <div class="eu-card-body">
        <div class="eu-row eu-row-2">
          <div class="eu-field">
            <label>Office *</label>
            <select name="Office" required>
              <?php foreach ($offices_data as $off): ?>
                <option value="<?= htmlspecialchars($off['OfficeName']) ?>" <?= $user['Office']===$off['OfficeName']?'selected':'' ?>><?= htmlspecialchars($off['OfficeName']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="eu-field">
            <label>Clock Status *</label>
            <select name="ClockStatus" required>
              <?php foreach (['In','Out','Lunch','Break'] as $s): ?>
                <option value="<?= $s ?>" <?= $user['ClockStatus']===$s?'selected':'' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="eu-row">
          <div class="eu-field">
            <label>Assigned Shift</label>
            <select name="ShiftID">
              <option value="0">— No shift assigned —</option>
              <?php foreach ($allShifts as $sh): ?>
                <option value="<?= $sh['ShiftID'] ?>" <?= $assignedShift===$sh['ShiftID']?'selected':'' ?>><?= htmlspecialchars($sh['ShiftName']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="hint">Required for auto clock-out.</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Account Controls -->
    <div class="eu-card">
      <div class="eu-card-head">
        <div class="eu-card-icon" style="background:#fff7ed">🛡️</div>
        <div>
          <h3>Account Controls</h3>
          <p>Security and access permissions</p>
        </div>
      </div>
      <div class="eu-card-body" style="padding-top:8px;padding-bottom:8px">

        <div class="eu-switch-row">
          <div class="eu-switch-label">
            Account Locked
            <small>Prevents this user from logging in</small>
          </div>
          <label class="switch">
            <input type="checkbox" name="LockOut" <?= $user['LockOut'] ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>

        <div class="eu-switch-row">
          <div class="eu-switch-label">
            Two-Factor Auth
            <small>Require TOTP code on login</small>
          </div>
          <label class="switch">
            <input type="checkbox" name="Enable2FA" <?= $user['TwoFAEnabled'] ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>

        <div class="eu-switch-row">
          <div class="eu-switch-label">
            Admin Access
            <small>Username: <?= htmlspecialchars($adminUsername) ?></small>
          </div>
          <label class="switch">
            <input type="checkbox" name="MakeAdmin" <?= $isAdmin ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>

      </div>
    </div>

  </div>
</div>

<!-- Sticky save bar -->
<div class="eu-save-bar">
  <a href="manage_users.php" class="eu-cancel-btn">Cancel</a>
  <button type="submit" class="eu-save-btn">Save Changes</button>
</div>

</form>
</div>

<script>
function generatePassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
  let pw = '';
  for (let i = 0; i < 10; i++) pw += chars[Math.floor(Math.random() * chars.length)];
  const f = document.getElementById('passwordField');
  f.value = pw; f.select();
}
</script>

<?php include 'includes/footer.php'; ?>
