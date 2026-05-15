<?php
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

date_default_timezone_set('America/Chicago');
require_once __DIR__ . '/../auth/db.php';

$empID = $_SESSION['EmployeeID'] ?? null;

if (!$empID) {
    header('Location: ../error.php?code=401&message=' . urlencode('Employee ID not found. Please log in again.'));
    exit;
}

// Fetch user info
$uStmt = $conn->prepare("SELECT FirstName, LastName FROM users WHERE ID = ?");
$uStmt->bind_param("i", $empID);
$uStmt->execute();
$uRow     = $uStmt->get_result()->fetch_assoc();
$name     = $uRow['FirstName'] ?? $_SESSION['FirstName'] ?? 'Employee';
$fullName = ($uRow['FirstName'] ?? '') . ' ' . ($uRow['LastName'] ?? '');

// Current status — must be fetched BEFORE last-punch block so timer check works
$currentStatus = 'Out';
$statusQuery = $conn->prepare("SELECT ClockStatus FROM users WHERE id = ?");
if ($statusQuery) {
    $statusQuery->bind_param("s", $empID);
    $statusQuery->execute();
    $sRow = $statusQuery->get_result()->fetch_assoc();
    if ($sRow && isset($sRow['ClockStatus'])) {
        $currentStatus = $sRow['ClockStatus'];
    }
}

// Punch history (this week)
$start = $_GET['start'] ?? date('Y-m-d', strtotime('monday this week'));
$end   = $_GET['end']   ?? date('Y-m-d', strtotime('friday this week'));

$query = $conn->prepare("SELECT Date, TimeIN, LunchStart, LunchEnd, TimeOUT, Note FROM timepunches WHERE EmployeeID = ? AND Date BETWEEN ? AND ? ORDER BY Date DESC, TimeIN DESC");
$query->bind_param("sss", $empID, $start, $end);
$query->execute();
$result = $query->get_result();

$punches = [];
$totalSeconds = 0;

while ($row = $result->fetch_assoc()) {
    $in  = $row['TimeIN']  ? strtotime($row['Date'] . ' ' . $row['TimeIN'])  : 0;
    $out = $row['TimeOUT'] ? strtotime($row['Date'] . ' ' . $row['TimeOUT']) : 0;
    $lS  = $row['LunchStart'] ? strtotime($row['Date'] . ' ' . $row['LunchStart']) : 0;
    $lE  = $row['LunchEnd']   ? strtotime($row['Date'] . ' ' . $row['LunchEnd'])   : 0;

    $worked = max(0, (($in && $out) ? $out - $in : 0) - (($lS && $lE) ? $lE - $lS : 0));
    $totalSeconds += $worked;

    $punches[] = [
        'date'        => date("m/d/Y", strtotime($row['Date'])),
        'in'          => $in ? date("g:i A", $in) : '-',
        'lunch_start' => $lS ? date("g:i A", $lS) : '-',
        'lunch_end'   => $lE ? date("g:i A", $lE) : '-',
        'out'         => $out ? date("g:i A", $out) : '-',
        'hours'       => $worked ? round($worked / 3600, 2) : '-',
    ];
}

$totalHours = round($totalSeconds / 3600, 2);

// Last punch info
$last = $conn->query("SELECT * FROM timepunches WHERE EmployeeID = '$empID' ORDER BY Date DESC, TimeIN DESC LIMIT 1")->fetch_assoc();
$lastLabel = $lastTime = '-';
$clockInUnix = null;

if ($last) {
    // Last punch label — what was the most recent action
    if (!empty($last['TimeOUT'])) {
        $lastLabel = "Clock Out";
        $lastTime  = date("g:i A", strtotime($last['TimeOUT']));
    } elseif (!empty($last['LunchEnd'])) {
        $lastLabel = "Lunch End";
        $lastTime  = date("g:i A", strtotime($last['LunchEnd']));
    } elseif (!empty($last['LunchStart'])) {
        $lastLabel = "Lunch Start";
        $lastTime  = date("g:i A", strtotime($last['LunchStart']));
    } elseif (!empty($last['TimeIN'])) {
        $lastLabel = "Clock In";
        $lastTime  = date("g:i A", strtotime($last['TimeIN']));
    }

    // Timer — independent of last action; runs whenever status is In and punch is open today
    if ($currentStatus === 'In' && empty($last['TimeOUT']) && !empty($last['TimeIN']) && $last['Date'] === date('Y-m-d')) {
        $clockInUnix = strtotime($last['Date'] . ' ' . $last['TimeIN']);
        // Shift start forward by any completed lunch so timer shows net time worked
        if (!empty($last['LunchStart']) && !empty($last['LunchEnd'])) {
            $lunchSec = strtotime($last['Date'].' '.$last['LunchEnd'])
                      - strtotime($last['Date'].' '.$last['LunchStart']);
            if ($lunchSec > 0) $clockInUnix += $lunchSec;
        }
    }
}

// Edit requests
$editStmt = $conn->prepare("SELECT Date, TimeIN, LunchStart, LunchEnd, TimeOUT, Note, Reason, Status FROM pending_edits WHERE EmployeeID = ? ORDER BY SubmittedAt DESC LIMIT 10");
$editStmt->bind_param("i", $empID);
$editStmt->execute();
$editRequests = $editStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Branding + derived display values
require_once __DIR__ . '/../functions/get_branding.php';
$branding    = getBranding($conn);
$statusClass = strtolower($currentStatus ?: 'out');
$statusLabel = match($currentStatus) {
    'In'    => 'Clocked In',
    'Lunch' => 'On Lunch',
    default => 'Clocked Out',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — <?= htmlspecialchars($branding['CompanyName']) ?></title>
  <link rel="icon" type="image/webp" href="<?= htmlspecialchars($branding['FaviconPath']) ?>">
  <link rel="manifest" href="/manifest.php">
  <link rel="stylesheet" href="../css/user-portal.css">
</head>
<body>
<?php include '../functions/modal.html'; ?>

<header class="u-nav">
  <a href="dashboard.php" class="u-nav-brand">
    <img src="<?= htmlspecialchars($branding['LogoPath']) ?>" alt="" class="u-nav-logo"
         onerror="this.onerror=null;this.src='../images/FoleyTimePro-favicon.webp'">
    <span><?= htmlspecialchars($branding['CompanyName']) ?></span>
  </a>
  <nav class="u-nav-links">
    <a href="dashboard.php" class="active">Dashboard</a>
    <a href="timesheet.php">Timesheet</a>
    <a href="time_off.php">Time Off</a>
    <a href="settings.php">Settings</a>
  </nav>
  <div class="u-nav-user">
    Hi, <?= htmlspecialchars($name) ?>
    <a href="../logout.php" class="u-nav-logout">Logout</a>
  </div>
</header>

<nav class="u-bottom-nav">
  <div class="u-bottom-nav-inner">
    <a href="dashboard.php" class="active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Home
    </a>
    <a href="timesheet.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Timesheet
    </a>
    <a href="time_off.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Time Off
    </a>
    <a href="settings.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Settings
    </a>
    <a href="../logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</nav>

<div class="u-content">

  <!-- ── Hero Banner ─────────────────────────────────────────── -->
  <div class="db-hero db-hero-<?= $statusClass ?>">
    <div class="db-hero-left">
      <p class="db-hero-greeting">Welcome back</p>
      <h1 class="db-hero-name"><?= htmlspecialchars($name) ?></h1>
      <div class="db-status-pill db-status-<?= $statusClass ?>">
        <span class="db-status-dot"></span>
        <?= htmlspecialchars($statusLabel) ?>
      </div>
    </div>
    <div class="db-hero-right">
      <?php if ($clockInUnix !== null): ?>
      <div class="db-hero-timer-wrap">
        <div class="db-hero-timer-label">Time Worked Today</div>
        <div class="db-hero-timer" id="liveTimer">0:00:00</div>
      </div>
      <?php else: ?>
      <div class="db-hero-stats">
        <div class="db-hero-stat">
          <div class="db-hero-stat-val"><?= number_format($totalHours, 1) ?></div>
          <div class="db-hero-stat-lbl">Hrs This Week</div>
        </div>
        <?php if ($lastTime !== '-'): ?>
        <div class="db-hero-stat-div"></div>
        <div class="db-hero-stat">
          <div class="db-hero-stat-val"><?= htmlspecialchars($lastTime) ?></div>
          <div class="db-hero-stat-lbl">Last <?= htmlspecialchars($lastLabel) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Main Grid (actions left, punches right) ────────────── -->
  <div class="db-grid">

    <!-- Clock Actions -->
    <div class="u-card db-actions-card">
      <h2>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Clock Actions
      </h2>
      <textarea class="db-note" id="dashNote" placeholder="Optional note for this punch…"></textarea>
      <div class="u-punch-grid">
        <button class="u-punch-btn u-punch-in"  onclick="submitAction('clockin')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Clock In
        </button>
        <button class="u-punch-btn u-punch-ls" onclick="submitAction('lunchstart')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
          Lunch Start
        </button>
        <button class="u-punch-btn u-punch-le" onclick="submitAction('lunchend')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          Lunch End
        </button>
        <button class="u-punch-btn u-punch-out" onclick="submitAction('clockout')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Clock Out
        </button>
      </div>
    </div>

    <!-- This Week -->
    <div class="u-card db-week-card" style="padding:0;overflow:hidden">
      <div class="db-card-header">
        <h2>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          This Week
        </h2>
        <a href="timesheet.php" class="u-btn u-btn-secondary u-btn-sm">View All</a>
      </div>
      <?php if (empty($punches)): ?>
      <div class="db-empty-state">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <p>No punches this week.</p>
      </div>
      <?php else: ?>
      <div class="u-table-wrap">
        <table class="u-table">
          <thead>
            <tr><th>Date</th><th>In</th><th>Lunch</th><th>Out</th><th>Hrs</th></tr>
          </thead>
          <tbody>
            <?php foreach ($punches as $p): ?>
            <tr>
              <td class="db-td-date"><?= htmlspecialchars($p['date']) ?></td>
              <td><?= htmlspecialchars($p['in']) ?></td>
              <td class="db-td-muted"><?= $p['lunch_start'] !== '-' ? htmlspecialchars($p['lunch_start']).'–'.htmlspecialchars($p['lunch_end']) : '—' ?></td>
              <td><?= htmlspecialchars($p['out']) ?></td>
              <td><strong><?= $p['hours'] !== '-' ? $p['hours'].' h' : '—' ?></strong></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="db-week-total">
        Total this period: <strong><?= $totalHours ?> hrs</strong>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /.db-grid -->

  <?php if (!empty($editRequests)): ?>
  <!-- Edit Requests -->
  <div class="u-card" style="padding:0;overflow:hidden">
    <div class="db-card-header">
      <h2>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit Requests
      </h2>
    </div>
    <div class="u-table-wrap">
      <table class="u-table">
        <thead><tr><th>Date</th><th>Change</th><th>Reason</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($editRequests as $edit):
          $s = strtolower($edit['Status'] ?? 'pending');
          $field = '-'; $value = '-';
          foreach (['TimeIN','LunchStart','LunchEnd','TimeOUT','Note'] as $k) {
            if (!empty($edit[$k])) { $field=$k; $value=htmlspecialchars($edit[$k]); break; }
          }
        ?>
        <tr>
          <td class="db-td-date"><?= htmlspecialchars($edit['Date'] ?? '') ?></td>
          <td class="db-td-sm"><?= $field ?>: <?= $value ?></td>
          <td class="db-td-sm db-td-muted"><?= htmlspecialchars($edit['Reason'] ?? '') ?></td>
          <td><span class="u-badge u-badge-<?= $s ?>"><?= ucfirst($s) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /.u-content -->

<footer class="u-footer">
  <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($branding['CompanyName']) ?> — Powered by Foley IT</span>
  <span><a href="/docs/privacy.php">Privacy</a> <a href="/docs/terms.php">Terms</a></span>
</footer>

<?php if ($clockInUnix !== null): ?>
<script>
(function(){
  var start = <?= $clockInUnix * 1000 ?>;
  function tick(){
    var d = Math.max(0, Math.floor((Date.now()-start)/1000));
    var h = Math.floor(d/3600), m = Math.floor((d%3600)/60), s = d%60;
    document.getElementById('liveTimer').textContent =
      h+':'+(m<10?'0':'')+m+':'+(s<10?'0':'')+s;
  }
  tick(); setInterval(tick,1000);
})();
</script>
<?php endif; ?>

<script src="../js/script.js"></script>
<script>
document.getElementById('modalEmployeeID').value = <?= json_encode($empID) ?>;

// Sync dashboard note textarea → modal note field before every punch submit,
// because getElementById('note') returns the modal's textarea (first in DOM).
(function() {
  var orig = window.confirmSubmit;
  window.confirmSubmit = function() {
    var dash  = document.getElementById('dashNote');
    var modal = document.getElementById('note');
    if (dash && modal) modal.value = dash.value;
    orig.call(this);
  };
})();
</script>
</body>
</html>
