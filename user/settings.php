<?php
$pageTitle = 'Settings';
require_once 'header.php';

$msg = ''; $msgType = 'success';

if (empty($_SESSION['user_csrf'])) {
    $_SESSION['user_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['user_csrf'], $_POST['csrf'])) {
        http_response_code(403); die('Invalid CSRF token.');
    }
    $action = $_POST['_action'] ?? '';

    if ($action === 'profile') {
        $firstName = trim($_POST['FirstName'] ?? '');
        $lastName  = trim($_POST['LastName']  ?? '');
        $email     = trim($_POST['Email']     ?? '');
        $tagID     = trim($_POST['TagID']     ?? '');
        $jobTitle  = trim($_POST['JobTitle']  ?? '');
        $phone     = trim($_POST['PhoneNumber'] ?? '');

        if (!$firstName || !$lastName) {
            $msg = 'First and Last Name are required.'; $msgType = 'error';
        } else {
            // users.Email and users.TagID both have UNIQUE indexes. A blank
            // badge must be stored as NULL (any number of NULLs are allowed),
            // never as '' — two employees both saving a blank badge would
            // otherwise collide on the second one.
            $tagIDForWrite = $tagID === '' ? null : $tagID;

            $stmt = $conn->prepare("UPDATE users SET FirstName=?,LastName=?,Email=?,TagID=?,JobTitle=?,PhoneNumber=? WHERE ID=?");
            $stmt->bind_param("ssssssi", $firstName, $lastName, $email, $tagIDForWrite, $jobTitle, $phone, $empID);
            try {
                $stmt->execute();
                $_SESSION['FirstName'] = $firstName;
                $msg = 'Profile updated successfully.';
            } catch (mysqli_sql_exception $e) {
                if ((int)$e->getCode() === 1062 && str_contains($e->getMessage(), 'uk_users_email')) {
                    $msg = "Email \"$email\" is already in use by another employee.";
                    $msgType = 'error';
                } elseif ((int)$e->getCode() === 1062 && str_contains($e->getMessage(), 'TagID')) {
                    $msg = "Tag ID \"$tagID\" is already assigned to another employee.";
                    $msgType = 'error';
                } else {
                    throw $e;
                }
            }
        }

    } elseif ($action === 'password') {
        $new  = $_POST['NewPassword']     ?? '';
        $conf = $_POST['ConfirmPassword'] ?? '';
        if ($new !== $conf)       { $msg = 'Passwords do not match.';                $msgType = 'error'; }
        elseif (strlen($new) < 6) { $msg = 'Password must be at least 6 characters.'; $msgType = 'error'; }
        else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 14]);
            $s = $conn->prepare("UPDATE users SET Pass=? WHERE ID=?");
            $s->bind_param("si", $hash, $empID);
            $s->execute();
            $msg = 'Password updated successfully.';
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM users WHERE ID=?");
$stmt->bind_param("i", $empID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$logs = $conn->prepare("SELECT IP, Timestamp FROM login_logs WHERE EmployeeID=? ORDER BY Timestamp DESC LIMIT 10");
$logs->bind_param("i", $empID);
$logs->execute();
$loginLogs = $logs->get_result()->fetch_all(MYSQLI_ASSOC);

$tab = $_GET['tab'] ?? 'profile';
$initials = strtoupper(substr($user['FirstName'] ?? 'E', 0, 1) . substr($user['LastName'] ?? '', 0, 1));
?>

<?php if ($msg): ?>
<div class="u-alert u-alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="st-layout">

  <!-- Sidebar -->
  <aside class="st-sidebar">
    <div class="st-sidebar-avatar"><?= $initials ?></div>
    <div class="st-sidebar-name"><?= htmlspecialchars(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? '')) ?></div>
    <div class="st-sidebar-role"><?= htmlspecialchars($user['JobTitle'] ?? 'Employee') ?></div>

    <nav class="st-nav">
      <a href="?tab=profile"  class="st-nav-link <?= $tab==='profile'  ?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile
      </a>
      <a href="?tab=security" class="st-nav-link <?= $tab==='security' ?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Security
      </a>
      <a href="?tab=history"  class="st-nav-link <?= $tab==='history'  ?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Login History
        <?php if (!empty($loginLogs)): ?>
          <span class="st-badge"><?= count($loginLogs) ?></span>
        <?php endif; ?>
      </a>
    </nav>

    <div class="st-sidebar-footer">
      <a href="dashboard.php" class="st-back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Dashboard
      </a>
    </div>
  </aside>

  <!-- Content -->
  <div class="st-content">

  <?php if ($tab === 'profile'): ?>
  <!-- ── Profile ──────────────────────────────────────── -->
  <form method="POST">
    <input type="hidden" name="_action" value="profile">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['user_csrf']) ?>">

    <div class="u-card st-card">
      <div class="st-card-header">
        <div class="st-card-icon st-icon-blue">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <div>
          <h2>Personal Information</h2>
          <p>Update your name and contact details.</p>
        </div>
      </div>

      <div class="u-form-row">
        <div class="u-form-group">
          <label>First Name <span class="st-req">*</span></label>
          <input type="text" name="FirstName" value="<?= htmlspecialchars($user['FirstName'] ?? '') ?>" required>
        </div>
        <div class="u-form-group">
          <label>Last Name <span class="st-req">*</span></label>
          <input type="text" name="LastName" value="<?= htmlspecialchars($user['LastName'] ?? '') ?>" required>
        </div>
      </div>
      <div class="u-form-row">
        <div class="u-form-group">
          <label>Email Address</label>
          <input type="email" name="Email" value="<?= htmlspecialchars($user['Email'] ?? '') ?>" placeholder="you@example.com">
        </div>
        <div class="u-form-group">
          <label>Phone Number</label>
          <input type="tel" name="PhoneNumber" value="<?= htmlspecialchars($user['PhoneNumber'] ?? '') ?>" placeholder="(555) 000-0000">
        </div>
      </div>
      <div class="u-form-row">
        <div class="u-form-group">
          <label>Job Title</label>
          <input type="text" name="JobTitle" value="<?= htmlspecialchars($user['JobTitle'] ?? '') ?>" placeholder="e.g. Field Technician">
        </div>
        <div class="u-form-group">
          <label>Tag / Badge ID</label>
          <input type="text" name="TagID" value="<?= htmlspecialchars($user['TagID'] ?? '') ?>" placeholder="Your badge number">
        </div>
      </div>

      <div class="st-form-actions">
        <button type="submit" class="u-btn u-btn-primary">Save Changes</button>
      </div>
    </div>

    <!-- Account Details (read-only) -->
    <div class="u-card st-card">
      <div class="st-card-header">
        <div class="st-card-icon st-icon-gray">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
        </div>
        <div>
          <h2>Account Details</h2>
          <p>Read-only information managed by your admin.</p>
        </div>
      </div>
      <div class="st-info-grid">
        <div class="st-info-item">
          <span class="st-info-label">Employee ID</span>
          <span class="st-info-value">#<?= (int)$empID ?></span>
        </div>
        <div class="st-info-item">
          <span class="st-info-label">Office</span>
          <span class="st-info-value"><?= htmlspecialchars($user['Office'] ?? '—') ?></span>
        </div>
        <div class="st-info-item">
          <span class="st-info-label">Account Status</span>
          <span class="st-info-value">
            <?php if ($user['LockOut']): ?>
              <span class="u-badge u-badge-rejected">Locked</span>
            <?php else: ?>
              <span class="u-badge u-badge-approved">Active</span>
            <?php endif; ?>
          </span>
        </div>
        <div class="st-info-item">
          <span class="st-info-label">Two-Factor Auth</span>
          <span class="st-info-value">
            <?php if (!empty($user['TwoFAEnabled'])): ?>
              <span class="u-badge u-badge-approved">Enabled</span>
            <?php else: ?>
              <span class="u-badge" style="background:#f3f4f6;color:#6b7280">Disabled</span>
            <?php endif; ?>
          </span>
        </div>
      </div>
    </div>
  </form>

  <?php elseif ($tab === 'security'): ?>
  <!-- ── Security ──────────────────────────────────────── -->

  <div class="u-card st-card">
    <div class="st-card-header">
      <div class="st-card-icon st-icon-amber">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
      <div>
        <h2>Change Password / PIN</h2>
        <p>Your password is also your PIN for the shared time clock.</p>
      </div>
    </div>
    <form method="POST" class="st-form-narrow">
      <input type="hidden" name="_action" value="password">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['user_csrf']) ?>">
      <div class="u-form-group">
        <label>New Password</label>
        <input type="password" name="NewPassword" placeholder="Minimum 6 characters" autocomplete="new-password">
      </div>
      <div class="u-form-group">
        <label>Confirm Password</label>
        <input type="password" name="ConfirmPassword" placeholder="Type it again" autocomplete="new-password">
      </div>
      <div class="st-form-actions">
        <button type="submit" class="u-btn u-btn-primary">Update Password</button>
      </div>
    </form>
  </div>

  <div class="u-card st-card">
    <div class="st-card-header">
      <div class="st-card-icon st-icon-green">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <div>
        <h2>Two-Factor Authentication</h2>
        <p>Adds a one-time code requirement at every login.</p>
      </div>
      <?php if (!empty($user['TwoFAEnabled'])): ?>
        <span class="u-badge u-badge-approved st-tfa-badge">Enabled</span>
      <?php else: ?>
        <span class="u-badge st-tfa-badge" style="background:#f3f4f6;color:#6b7280">Disabled</span>
      <?php endif; ?>
    </div>

    <div class="st-tfa-body">
      <?php if (!empty($user['TwoFAEnabled'])): ?>
        <?php if (!empty($user['AdminOverride2FA'])): ?>
        <form method="POST" action="disable_2fa.php" class="st-form-narrow">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['user_csrf']) ?>">
          <div class="u-form-group">
            <label>Enter your password to disable 2FA</label>
            <input type="password" name="password" placeholder="Current password" required>
          </div>
          <button type="submit" class="u-btn u-btn-danger">Disable 2FA</button>
        </form>
        <?php else: ?>
        <p class="st-hint">2FA settings are managed by your administrator.</p>
        <?php endif; ?>
      <?php else: ?>
        <?php if (!empty($user['AdminOverride2FA'])): ?>
        <div class="st-tfa-setup">
          <p class="st-hint">Set up an authenticator app like Google Authenticator or Authy to get one-time codes.</p>
          <a href="enable_2fa.php" class="u-btn u-btn-primary">Set Up 2FA</a>
        </div>
        <?php else: ?>
        <p class="st-hint">Contact your administrator to enable 2FA on your account.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- ── Login History ─────────────────────────────────── -->
  <div class="u-card st-card" style="padding:0;overflow:hidden">
    <div class="st-card-header" style="padding:18px 22px;border-bottom:1px solid #f3f4f6">
      <div class="st-card-icon st-icon-blue">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div>
        <h2>Recent Sign-ins</h2>
        <p>Last <?= count($loginLogs) ?> sign-ins to your account.</p>
      </div>
    </div>

    <?php if (empty($loginLogs)): ?>
      <div class="db-empty-state" style="padding:48px">No login history found.</div>
    <?php else: ?>
    <table class="u-table">
      <thead>
        <tr><th>Date</th><th>Time</th><th>IP Address</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($loginLogs as $i => $log): ?>
        <tr>
          <td style="font-weight:600"><?= date('M j, Y', strtotime($log['Timestamp'])) ?></td>
          <td style="color:#6b7280"><?= date('g:i:s A', strtotime($log['Timestamp'])) ?></td>
          <td style="font-family:monospace;font-size:13px"><?= htmlspecialchars($log['IP']) ?></td>
          <td>
            <?php if ($i === 0): ?>
              <span class="u-badge u-badge-approved">Most Recent</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  </div><!-- /.st-content -->
</div><!-- /.st-layout -->

<style>
/* ── Settings Layout ──────────────────────────────────────── */
.st-layout {
  display: grid;
  grid-template-columns: 240px 1fr;
  gap: 24px;
  align-items: start;
}

/* ── Sidebar ──────────────────────────────────────────────── */
.st-sidebar {
  background: #fff;
  border: 1px solid var(--u-border);
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
  position: sticky;
  top: calc(var(--u-nav-h) + 16px);
}
.st-sidebar-avatar {
  width: 60px; height: 60px;
  background: linear-gradient(135deg, #073851, #1a6a9c);
  color: #fff;
  font-size: 22px;
  font-weight: 800;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 24px auto 10px;
  letter-spacing: .02em;
}
.st-sidebar-name {
  text-align: center;
  font-size: 15px;
  font-weight: 700;
  color: var(--u-text);
  padding: 0 16px;
}
.st-sidebar-role {
  text-align: center;
  font-size: 12px;
  color: var(--u-muted);
  margin-top: 3px;
  padding: 0 16px 16px;
}
.st-nav {
  border-top: 1px solid var(--u-border);
  padding: 8px;
}
.st-nav-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  text-decoration: none;
  transition: background .12s, color .12s;
  position: relative;
}
.st-nav-link svg { width: 16px; height: 16px; stroke: #9ca3af; flex-shrink: 0; transition: stroke .12s; }
.st-nav-link:hover        { background: #f3f4f6; color: #073851; }
.st-nav-link:hover svg    { stroke: #073851; }
.st-nav-link.active       { background: #eef3ff; color: #073851; font-weight: 700; }
.st-nav-link.active svg   { stroke: #073851; }
.st-nav-link.active::before {
  content: '';
  position: absolute;
  left: 0; top: 6px; bottom: 6px;
  width: 3px;
  background: #073851;
  border-radius: 0 2px 2px 0;
}
.st-badge {
  margin-left: auto;
  background: #073851;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 10px;
}
.st-sidebar-footer {
  border-top: 1px solid var(--u-border);
  padding: 8px;
}
.st-back-link {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 12px;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 600;
  color: var(--u-muted);
  text-decoration: none;
  transition: background .12s, color .12s;
}
.st-back-link svg { width: 16px; height: 16px; stroke: currentColor; }
.st-back-link:hover { background: #f3f4f6; color: var(--u-text); }

/* ── Content ──────────────────────────────────────────────── */
.st-content { min-width: 0; }

/* ── Cards ────────────────────────────────────────────────── */
.st-card { margin-bottom: 18px; }
.st-card-header {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  margin-bottom: 20px;
}
.st-card-header h2 { font-size: 15px; font-weight: 700; margin-bottom: 3px; }
.st-card-header p  { font-size: 12px; color: var(--u-muted); margin: 0; }
.st-card-icon {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.st-card-icon svg { width: 18px; height: 18px; }
.st-icon-blue  { background: #dbeafe; }
.st-icon-blue svg  { stroke: #2563eb; }
.st-icon-gray  { background: #f3f4f6; }
.st-icon-gray svg  { stroke: #6b7280; }
.st-icon-amber { background: #fef3c7; }
.st-icon-amber svg { stroke: #d97706; }
.st-icon-green { background: #dcfce7; }
.st-icon-green svg { stroke: #16a34a; }

/* ── Info grid ────────────────────────────────────────────── */
.st-info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
}
.st-info-item {
  background: #f9fafb;
  border: 1px solid var(--u-border);
  border-radius: 10px;
  padding: 14px 16px;
}
.st-info-label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--u-muted);
  margin-bottom: 6px;
}
.st-info-value { font-size: 14px; font-weight: 700; color: var(--u-text); }

/* ── Form helpers ─────────────────────────────────────────── */
.st-form-narrow { max-width: 440px; }
.st-form-actions { margin-top: 6px; display: flex; gap: 10px; }
.st-req { color: #e74c3c; }
.st-hint { font-size: 13px; color: var(--u-muted); }

/* ── 2FA ──────────────────────────────────────────────────── */
.st-tfa-badge { margin-left: auto; font-size: 12px; padding: 4px 12px; }
.st-tfa-body  { margin-top: 6px; }
.st-tfa-setup { display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.st-tfa-setup .st-hint { flex: 1; min-width: 200px; }

/* ── Mobile ───────────────────────────────────────────────── */
@media (max-width: 760px) {
  .st-layout { grid-template-columns: 1fr; }
  .st-sidebar {
    position: static;
    display: grid;
    grid-template-columns: auto 1fr;
    grid-template-rows: auto auto;
    gap: 0;
  }
  .st-sidebar-avatar { width: 48px; height: 48px; font-size: 18px; margin: 16px 0 16px 16px; }
  .st-sidebar-name   { text-align: left; padding: 18px 16px 0; font-size: 14px; }
  .st-sidebar-role   { text-align: left; padding: 2px 16px 16px; }
  .st-nav {
    grid-column: 1 / -1;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 8px;
  }
  .st-nav-link { flex: 1; min-width: 100px; justify-content: center; padding: 8px 10px; }
  .st-nav-link.active::before { display: none; }
  .st-sidebar-footer { grid-column: 1 / -1; }
  .st-back-link { justify-content: center; }
}
</style>

<?php require_once 'footer.php'; ?>
