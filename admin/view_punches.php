<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('America/Chicago');

$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

$from = $_GET['from'] ?? date('Y-m-d', strtotime('monday this week'));
$to = $_GET['to'] ?? date('Y-m-d', strtotime('sunday this week'));
$employeeID = $_GET['emp'] ?? '';
$mode = $_GET['mode'] ?? 'view';

if (isset($_GET['daterange'])) {
    $dateRange = explode(' - ', $_GET['daterange']);
    if (count($dateRange) == 2) {
        $from_raw = $dateRange[0];
        $to_raw = $dateRange[1];
        $date_obj = DateTime::createFromFormat('m/d/Y', $from_raw);
        if ($date_obj) {
            $from = $date_obj->format('Y-m-d');
        }
        $date_obj = DateTime::createFromFormat('m/d/Y', $to_raw);
        if ($date_obj) {
            $to = $date_obj->format('Y-m-d');
        }
    }
}

?>

<?php
$pageTitle   = 'Timesheets';
$headerTitle = 'Timesheets';
$additionalCSS = [
    '../css/view_punches.css',
    'https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css',
];
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Timesheets</h1>
</div>

<form method="GET" class="filter-bar">
  <div class="form-group">
    <label>Date Range</label>
    <input type="text" name="daterange" id="daterange"
           value="<?= htmlspecialchars(date('m/d/Y', strtotime($from))) . ' - ' . htmlspecialchars(date('m/d/Y', strtotime($to))) ?>">
  </div>
  <div class="form-group">
    <label>Employee</label>
    <select name="emp">
      <option value="">Select Employee</option>
      <?php while ($emp = $employeeList->fetch_assoc()): ?>
        <option value="<?= $emp['ID'] ?>" <?= $emp['ID'] == $employeeID ? 'selected' : '' ?>>
          <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </div>
  <?php if (!empty($employeeID)): ?>
  <div class="form-group">
    <label>View Mode</label>
    <select id="mode-select" onchange="location = this.value;">
      <?php
        $dr = htmlspecialchars(date('m/d/Y', strtotime($from)) . ' - ' . date('m/d/Y', strtotime($to)));
        $eid = urlencode($employeeID);
      ?>
      <option value="?daterange=<?= $dr ?>&emp=<?= $eid ?>&mode=view" <?= $mode=='view' ? 'selected' : '' ?>>View</option>
      <option value="?daterange=<?= $dr ?>&emp=<?= $eid ?>&mode=edit" <?= $mode=='edit' ? 'selected' : '' ?>>Edit</option>
    </select>
  </div>
  <?php endif; ?>
  <div class="filter-actions">
    <button type="submit" class="btn btn-primary">Filter</button>
    <a href="view_punches.php" class="btn btn-secondary">Reset</a>
  </div>
</form>

<?php if (!empty($employeeID)): ?>
<?php
  $_GET['from'] = date('m/d/Y', strtotime($from));
  $_GET['to']   = date('m/d/Y', strtotime($to));
  switch ($mode) {
    case 'edit': include 'timesheet_edit.php'; break;
    case 'add':  include 'timesheet_add.php';  break;
    default:     include 'timesheet_view.php'; break;
  }
?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<script>
  if (typeof Litepicker !== 'undefined') {
    new Litepicker({ element: document.getElementById('daterange'), singleMode: false, format: 'MM/DD/YYYY' });
  }
</script>

<?php include 'includes/footer.php'; ?>
