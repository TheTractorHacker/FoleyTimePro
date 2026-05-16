<?php
require './auth/db.php';
date_default_timezone_set('America/Chicago');

require_once './functions/get_branding.php';
$branding = getBranding($conn);

// GPS enforcement
$gpsRequired = false;
$q = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = 'EnforceGPS' LIMIT 1");
$q->execute();
$q->bind_result($v);
if ($q->fetch()) $gpsRequired = ($v === '1');
$q->close();

// Fetch all employees + latest punch
$sql = "
  WITH LatestPunches AS (
    SELECT EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOUT, Note,
           ROW_NUMBER() OVER(PARTITION BY EmployeeID ORDER BY Date DESC, TimeIN DESC) AS rn
    FROM timepunches
  )
  SELECT u.ID, u.FirstName, u.LastName, u.ClockStatus,
         lp.Date AS PunchDate, lp.TimeIN, lp.LunchStart, lp.LunchEnd, lp.TimeOUT, lp.Note AS PunchNote
  FROM users u
  LEFT JOIN LatestPunches lp ON u.ID = lp.EmployeeID AND lp.rn = 1
  ORDER BY u.LastName, u.FirstName
";
$result = $conn->query($sql);
$employees = [];
$countIn = 0; $countLunch = 0; $countOut = 0;
while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
    $s = $row['ClockStatus'] ?? 'Out';
    if ($s === 'In') $countIn++;
    elseif ($s === 'Lunch') $countLunch++;
    else $countOut++;
}

function fmt12($t) { return $t ? date('g:i A', strtotime($t)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($branding['CompanyName']) ?> — TimeClock</title>
  <link rel="icon" type="image/webp" href="<?= htmlspecialchars($branding['FaviconPath']) ?>">
  <link rel="manifest" href="/manifest.php">
  <meta name="theme-color" content="<?= htmlspecialchars($branding['PrimaryColor'] ?? '#073851') ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <link rel="stylesheet" href="css/home.css">
</head>
<body>

<!-- Nav -->
<header class="home-nav">
  <div class="home-nav-brand">
    <img src="<?= htmlspecialchars($branding['LogoPath']) ?>" alt="" class="home-nav-logo">
    <span><?= htmlspecialchars($branding['CompanyName']) ?></span>
  </div>
  <div class="home-nav-date" id="liveClock"></div>
  <div class="home-nav-actions">
    <a href="./user/login.php" class="home-btn home-btn-primary">Employee Login</a>
    <a href="./admin/login.php" class="home-btn home-btn-ghost">Admin</a>
  </div>
</header>

<!-- Status summary -->
<div class="home-stats">
  <div class="home-stat home-stat-in">
    <span class="home-stat-num"><?= $countIn ?></span>
    <span class="home-stat-label">Clocked In</span>
  </div>
  <div class="home-stat home-stat-lunch">
    <span class="home-stat-num"><?= $countLunch ?></span>
    <span class="home-stat-label">On Lunch</span>
  </div>
  <div class="home-stat home-stat-out">
    <span class="home-stat-num"><?= $countOut ?></span>
    <span class="home-stat-label">Not Working</span>
  </div>
  <div class="home-stat home-stat-total">
    <span class="home-stat-num"><?= count($employees) ?></span>
    <span class="home-stat-label">Total Staff</span>
  </div>
</div>

<!-- Search + table -->
<div class="home-content">
  <div class="home-toolbar">
    <input type="search" id="empSearch" class="home-search" placeholder="Search employees…" autocomplete="off">
    <span class="home-count" id="empCount"><?= count($employees) ?> employees</span>
  </div>

  <div class="home-table-wrap">
    <table class="home-table" id="empTable">
      <thead>
        <tr>
          <th>Employee</th>
          <th>Status</th>
          <th>Last Activity</th>
          <th>Date</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $row):
          $name   = htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']);
          $status = $row['ClockStatus'] ?? 'Out';
          $rawTime = $row['TimeOUT'] ?? $row['LunchEnd'] ?? $row['LunchStart'] ?? $row['TimeIN'];
          $lastTime = fmt12($rawTime);
          $lastDate = $row['PunchDate'] ? date('M j, Y', strtotime($row['PunchDate'])) : '—';
          $note     = htmlspecialchars($row['PunchNote'] ?? '');
          $statusClass = strtolower($status);
          $statusLabel = match($status) {
            'In'    => 'Clocked In',
            'Lunch' => 'On Lunch',
            default => 'Clocked Out',
          };
        ?>
        <tr class="emp-row" data-name="<?= strtolower($row['FirstName'] . ' ' . $row['LastName']) ?>">
          <td>
            <button class="emp-name-btn" onclick="openModal(<?= (int)$row['ID'] ?>, '<?= addslashes($name) ?>')">
              <?= $name ?>
            </button>
          </td>
          <td><span class="home-status home-status-<?= $statusClass ?>"><?= $statusLabel ?></span></td>
          <td class="home-time"><?= $lastTime ?></td>
          <td class="home-date"><?= $lastDate ?></td>
          <td class="home-note"><?= $note ?: '<span style="color:#9ca3af">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($employees)): ?>
        <tr><td colspan="5" class="home-empty">No employees found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Footer -->
<footer class="home-footer">
  <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($branding['CompanyName']) ?> &mdash; Powered by Foley IT</span>
  <span class="home-footer-links">
    <a href="/docs/privacy.php">Privacy</a>
    <a href="/docs/terms.php">Terms</a>
    <a href="/docs/report.php">Report Issue</a>
  </span>
</footer>

<?php include 'functions/modal.html'; ?>

<script src="js/script.js"></script>
<script src="js/index.js"></script>
<script>
// Live clock
(function tick(){
  var d = new Date();
  var h = d.getHours(), m = d.getMinutes(), s = d.getSeconds();
  var ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  var time = h + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0') + ' ' + ampm;
  var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var full = days[d.getDay()] + ', ' + months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
  var el = document.getElementById('liveClock');
  if(el) el.innerHTML = '<span class="clock-time">'+time+'</span><span class="clock-date">'+full+'</span>';
  setTimeout(tick, 1000);
})();

// Employee search
document.getElementById('empSearch').addEventListener('input', function(){
  var q = this.value.toLowerCase().trim();
  var rows = document.querySelectorAll('.emp-row');
  var shown = 0;
  rows.forEach(function(r){
    var match = !q || r.dataset.name.includes(q);
    r.style.display = match ? '' : 'none';
    if(match) shown++;
  });
  document.getElementById('empCount').textContent = shown + ' employee' + (shown !== 1 ? 's' : '');
});

// Auto-refresh after 60s idle
var refreshTimer;
function resetTimer(){ clearTimeout(refreshTimer); refreshTimer = setTimeout(function(){ location.reload(); }, 60000); }
['mousedown','mousemove','keypress','touchstart','scroll'].forEach(function(e){ document.addEventListener(e, resetTimer); });
resetTimer();
</script>
</body>
</html>
