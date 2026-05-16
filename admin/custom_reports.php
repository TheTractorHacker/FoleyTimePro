<?php
session_start();
require_once '../auth/db.php';
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

$employees = $conn->query("SELECT ID,CONCAT(FirstName,' ',LastName) name FROM users ORDER BY LastName")->fetch_all(MYSQLI_ASSOC);
$offices   = $conn->query("SELECT DISTINCT Office FROM users WHERE Office IS NOT NULL AND Office != '' ORDER BY Office")->fetch_all(MYSQLI_ASSOC);

$pageTitle   = 'Custom Reports';
$headerTitle = 'Custom Reports';
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Custom Reports</h1>
  <a href="export_history.php" class="btn btn-secondary">Export History</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Summary Report -->
  <div class="card">
    <h2>Summary Report</h2>
    <p style="color:#6b7280;font-size:13px;margin-bottom:16px">Total hours, regular time, and overtime broken down by employee and date.</p>
    <form method="POST" id="summaryForm">
<?= csrf_field() ?>
      <div class="form-group">
        <label>Date Range</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <input type="date" name="start" id="sum_start" value="<?= date('Y-m-d', strtotime('monday this week')) ?>">
          <input type="date" name="end"   id="sum_end"   value="<?= date('Y-m-d', strtotime('sunday this week')) ?>">
        </div>
      </div>
      <div class="form-group">
        <label>Employee</label>
        <select name="emp">
          <option value="">All Employees</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= $e['ID'] ?>"><?= htmlspecialchars($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Rounding</label>
        <select name="rounding">
          <option value="0">No rounding</option>
          <?php foreach ([5,10,15,20,30] as $m): ?>
            <option value="<?= $m ?>"><?= $m ?> minutes</option>
          <?php endforeach; ?>
        </select>
      </div>
      <input type="hidden" name="separate_pages" value="0">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px">
        <button type="submit" formaction="export_summary_excel.php" class="btn btn-secondary">Export Excel</button>
        <button type="submit" formaction="export_summary_pdf.php"   class="btn btn-primary">Export PDF</button>
      </div>
    </form>
  </div>

  <!-- Attendance Report -->
  <div class="card">
    <h2>Attendance Report</h2>
    <p style="color:#6b7280;font-size:13px;margin-bottom:16px">Daily present/incomplete/absent grid for the selected period and employees.</p>
    <form method="GET" action="attendance.php">
      <div class="form-group">
        <label>Date Range</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <input type="date" name="start" value="<?= date('Y-m-d', strtotime('monday this week')) ?>">
          <input type="date" name="end"   value="<?= date('Y-m-d', strtotime('friday this week')) ?>">
        </div>
      </div>
      <div class="form-group">
        <label>Employee</label>
        <select name="emp">
          <option value="all">All Employees</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= $e['ID'] ?>"><?= htmlspecialchars($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:20px">
        <button type="submit" class="btn btn-secondary">View Report</button>
        <button type="submit" name="export" value="pdf" class="btn btn-primary">Export PDF</button>
      </div>
    </form>
  </div>

  <!-- Timesheet Detail -->
  <div class="card">
    <h2>Timesheet Detail</h2>
    <p style="color:#6b7280;font-size:13px;margin-bottom:16px">Full punch-by-punch view with clock in/out times and GPS locations.</p>
    <form method="GET" action="view_punches.php">
      <div class="form-group">
        <label>Date Range</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <?php
            $monStr = date('m/d/Y', strtotime('monday this week'));
            $sunStr = date('m/d/Y', strtotime('sunday this week'));
          ?>
          <input type="hidden" name="daterange" id="ts_daterange" value="<?= $monStr ?> - <?= $sunStr ?>">
          <input type="date" id="ts_from" value="<?= date('Y-m-d', strtotime('monday this week')) ?>"
                 onchange="updateTsDR()">
          <input type="date" id="ts_to"   value="<?= date('Y-m-d', strtotime('sunday this week')) ?>"
                 onchange="updateTsDR()">
        </div>
      </div>
      <div class="form-group">
        <label>Employee <span style="color:#e74c3c;font-size:11px">Required</span></label>
        <select name="emp" required>
          <option value="">Select Employee</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= $e['ID'] ?>"><?= htmlspecialchars($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-top:20px">
        <button type="submit" class="btn btn-primary" style="width:100%">View Timesheet</button>
      </div>
    </form>
  </div>

  <!-- Time Off Summary -->
  <div class="card">
    <h2>Time Off Report</h2>
    <p style="color:#6b7280;font-size:13px;margin-bottom:16px">Review all time off requests with status filters and date ranges.</p>
    <form method="GET" action="time_off_requests.php">
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="All">All Requests</option>
          <option value="Pending">Pending Only</option>
          <option value="Approved">Approved Only</option>
          <option value="Rejected">Rejected Only</option>
        </select>
      </div>
      <div class="form-group">
        <label>Employee</label>
        <select name="user_id">
          <option value="">All Employees</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= $e['ID'] ?>"><?= htmlspecialchars($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-top:20px">
        <button type="submit" class="btn btn-primary" style="width:100%">View Time Off</button>
      </div>
    </form>
  </div>

</div>

<script>
function updateTsDR() {
  var from = document.getElementById('ts_from').value;
  var to   = document.getElementById('ts_to').value;
  if (from && to) {
    var fmt = function(d) {
      var p = d.split('-'); return p[1]+'/'+p[2]+'/'+p[0];
    };
    document.getElementById('ts_daterange').value = fmt(from) + ' - ' + fmt(to);
  }
}
</script>

<?php include 'includes/footer.php'; ?>
