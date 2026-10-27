<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

require '../auth/db.php';
$adminUsername = $_SESSION['admin'];

// Stats
$stats = $conn->query("
  SELECT
    COUNT(*) totalUsers,
    SUM(ClockStatus='In')    clockedIn,
    SUM(ClockStatus='Lunch') onLunch,
    SUM(ClockStatus='Out')   clockedOut
  FROM users
")->fetch_assoc();

$pendingEdits = (int)$conn->query("SELECT COUNT(*) c FROM pending_edits WHERE Status='Pending'")->fetch_assoc()['c'];
$pendingTO    = (int)$conn->query("SELECT COUNT(*) c FROM time_off_requests WHERE Status='Pending'")->fetch_assoc()['c'];
$todayHours   = $conn->query("SELECT ROUND(SUM(TotalHours),1) h FROM timepunches WHERE Date=CURDATE()")->fetch_assoc()['h'] ?? 0;

// Recent punches
$recent = $conn->query("
  SELECT u.FirstName, u.LastName, tp.TimeIN, tp.TimeOUT, tp.Date
  FROM timepunches tp JOIN users u ON tp.EmployeeID=u.ID
  ORDER BY tp.Date DESC, tp.TimeIN DESC LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// Currently clocked in
$clockedInList = $conn->query("
  SELECT FirstName, LastName, ClockStatus FROM users
  WHERE ClockStatus IN ('In','Lunch') ORDER BY LastName
")->fetch_all(MYSQLI_ASSOC);

// Admin 2FA
$stmt2fa = $conn->prepare("SELECT TwoFAEnabled FROM admins WHERE username = ? LIMIT 1");
$stmt2fa->bind_param("s", $adminUsername);
$stmt2fa->execute();
$twoFAEnabled = (int)($stmt2fa->get_result()->fetch_assoc()['TwoFAEnabled'] ?? 0);
$stmt2fa->close();

$pageTitle   = 'Dashboard';
$headerTitle = 'Dashboard';
include 'includes/header.php';
?>

<!-- Stat Cards -->
<div class="stats-grid">
  <a href="edits_timesheet.php" class="stat-card <?= $pendingEdits > 0 ? 'danger' : '' ?>" style="text-decoration:none">
    <span class="stat-icon">✏️</span>
    <span class="stat-value"><?= $pendingEdits ?></span>
    <span class="stat-label">Pending Edits</span>
  </a>
  <a href="time_off_requests.php" class="stat-card <?= $pendingTO > 0 ? 'warning' : '' ?>" style="text-decoration:none">
    <span class="stat-icon">🏖</span>
    <span class="stat-value"><?= $pendingTO ?></span>
    <span class="stat-label">Time Off Requests</span>
  </a>
  <div class="stat-card success">
    <span class="stat-icon">🟢</span>
    <span class="stat-value"><?= (int)$stats['clockedIn'] ?></span>
    <span class="stat-label">Clocked In Now</span>
  </div>
  <div class="stat-card warning">
    <span class="stat-icon">🍽</span>
    <span class="stat-value"><?= (int)$stats['onLunch'] ?></span>
    <span class="stat-label">On Lunch</span>
  </div>
  <div class="stat-card">
    <span class="stat-icon">👥</span>
    <span class="stat-value"><?= (int)$stats['totalUsers'] ?></span>
    <span class="stat-label">Total Employees</span>
  </div>
  <div class="stat-card info">
    <span class="stat-icon">⏱</span>
    <span class="stat-value"><?= number_format((float)$todayHours, 1) ?></span>
    <span class="stat-label">Hours Today</span>
  </div>
</div>

<div class="dash-split">

  <!-- Currently In -->
  <div class="card">
    <h2>Currently Working</h2>
    <?php if (empty($clockedInList)): ?>
      <p style="color:#888;font-size:14px">No employees clocked in.</p>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Employee</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($clockedInList as $e): ?>
      <tr>
        <td><?= htmlspecialchars($e['FirstName'].' '.$e['LastName']) ?></td>
        <td><span class="badge badge-<?= strtolower($e['ClockStatus']) ?>"><?= $e['ClockStatus'] === 'Lunch' ? 'On Lunch' : 'Clocked In' ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Recent punches -->
  <div class="card">
    <h2>Recent Punches <a href="view_punches.php" style="font-size:12px;font-weight:400;color:#073851;float:right">View all →</a></h2>
    <?php if (empty($recent)): ?>
      <p style="color:#888;font-size:14px">No punches yet today.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Employee</th><th>Date</th><th>In</th><th>Out</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['FirstName'].' '.$p['LastName']) ?></td>
        <td style="font-size:12px;color:#888"><?= date('M j', strtotime($p['Date'])) ?></td>
        <td><?= $p['TimeIN'] ? date('g:ia', strtotime($p['TimeIN'])) : '—' ?></td>
        <td><?= $p['TimeOUT'] ? date('g:ia', strtotime($p['TimeOUT'])) : '<span style="color:#888">—</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Quick Actions -->
<div class="card">
  <h2>Quick Actions</h2>
  <div class="actions-grid">
    <a href="view_punches.php" class="action-card">
      <span class="action-icon">⏱</span>
      <span class="action-title">Timesheets</span>
      <span class="action-desc">View &amp; edit punch records</span>
    </a>
    <a href="manage_users.php" class="action-card">
      <span class="action-icon">👥</span>
      <span class="action-title">Manage Users</span>
      <span class="action-desc">Add, edit or archive employees</span>
    </a>
    <a href="time_off_requests.php" class="action-card">
      <span class="action-icon">🏖</span>
      <span class="action-title">Time Off <?= $pendingTO > 0 ? "<span class='nav-badge'>$pendingTO</span>" : '' ?></span>
      <span class="action-desc">Review leave requests</span>
    </a>
    <a href="edits_timesheet.php" class="action-card">
      <span class="action-icon">✏️</span>
      <span class="action-title">Edit Requests <?= $pendingEdits > 0 ? "<span class='nav-badge'>$pendingEdits</span>" : '' ?></span>
      <span class="action-desc">Approve punch corrections</span>
    </a>
    <a href="summary.php" class="action-card">
      <span class="action-icon">📊</span>
      <span class="action-title">Summary</span>
      <span class="action-desc">Hours by employee &amp; period</span>
    </a>
    <a href="manage_shifts.php" class="action-card">
      <span class="action-icon">🗓</span>
      <span class="action-title">Shifts</span>
      <span class="action-desc">Create &amp; assign work schedules</span>
    </a>
    <a href="geofences.php" class="action-card">
      <span class="action-icon">📍</span>
      <span class="action-title">Geofences</span>
      <span class="action-desc">GPS work zone enforcement</span>
    </a>
    <a href="quickbooks.php" class="action-card">
      <span class="action-icon">💼</span>
      <span class="action-title">QuickBooks</span>
      <span class="action-desc">Sync payroll data</span>
    </a>
    <a href="branding.php" class="action-card">
      <span class="action-icon">🎨</span>
      <span class="action-title">Branding</span>
      <span class="action-desc">Logo, colors &amp; company info</span>
    </a>
    <a href="settings.php" class="action-card">
      <span class="action-icon">⚙️</span>
      <span class="action-title">Settings</span>
      <span class="action-desc">GPS, email, 2FA &amp; more</span>
    </a>
    <?php if (!$twoFAEnabled): ?>
    <a href="enable_2fa.php" class="action-card" style="border-color:#f39c12">
      <span class="action-icon">🔐</span>
      <span class="action-title">Enable 2FA</span>
      <span class="action-desc">Secure your admin account</span>
    </a>
    <?php endif; ?>
    <a href="../logout.php" class="action-card" style="border-color:#e74c3c">
      <span class="action-icon">🚪</span>
      <span class="action-title">Logout</span>
      <span class="action-desc">Sign out of admin</span>
    </a>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
