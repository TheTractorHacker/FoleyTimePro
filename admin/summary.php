<?php
session_start();
require_once '../auth/db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Default to current week's Monday to Sunday
$defaultStart = date('Y-m-d', strtotime('monday this week'));
$defaultEnd = date('Y-m-d', strtotime('sunday this week'));

// Parse the 'dates' parameter
if (isset($_GET['dates']) && strpos($_GET['dates'], ' - ') !== false) {
    list($startDate, $endDate) = explode(' - ', $_GET['dates']);
    $startDate = date('Y-m-d', strtotime($startDate));
    $endDate = date('Y-m-d', strtotime($endDate));
} else {
    $startDate = $defaultStart;
    $endDate = $defaultEnd;
}

$employeeID = (isset($_GET['emp']) && is_numeric($_GET['emp'])) ? (int)$_GET['emp'] : '';
$rounding = isset($_GET['rounding']) ? (int)$_GET['rounding'] : 0;
$separatePages = isset($_GET['separate_pages']) ? 1 : 0;

// Fetch employees for dropdown
$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

// Build SQL query
$sql = "
    SELECT u.FirstName, u.LastName, tp.EmployeeID, tp.Date,
        SEC_TO_TIME(SUM(
            TIME_TO_SEC(TIMEDIFF(tp.TimeOUT, tp.TimeIN)) - 
            TIME_TO_SEC(TIMEDIFF(IFNULL(tp.LunchEnd, '00:00:00'), IFNULL(tp.LunchStart, '00:00:00')))
        )) AS TotalHours
    FROM timepunches tp
    JOIN users u ON u.ID = tp.EmployeeID
    WHERE tp.TimeIN IS NOT NULL AND tp.TimeOUT IS NOT NULL
      AND tp.Date BETWEEN ? AND ?
";

$params = [$startDate, $endDate];
$types = 'ss';

if ($employeeID !== '') {
    $sql .= " AND tp.EmployeeID = ?";
    $params[] = $employeeID;
    $types .= 'i';
}

$sql .= " GROUP BY tp.EmployeeID, tp.Date ORDER BY tp.Date DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    header('Location: ../error.php?code=500&message=' . urlencode('Query error: ' . $conn->error));
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Helpers
function hmsToDecimal($hms) {
    list($h, $m, $s) = explode(':', $hms);
    return round($h + ($m / 60) + ($s / 3600), 2);
}
function roundToNearestMinutes($decimalHours, $interval = 0) {
    if ($interval <= 0) return $decimalHours;
    $totalMinutes = $decimalHours * 60;
    $roundedMinutes = round($totalMinutes / $interval) * $interval;
    return round($roundedMinutes / 60, 2);
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $decimal = hmsToDecimal($row['TotalHours']);
    $rounded = roundToNearestMinutes($decimal, $rounding);
    $row['DecimalHours'] = $rounded;
    $rows[] = $row;
}

// Reorganize for employee + week
$employeeWeeklyHours = [];
foreach ($rows as $row) {
    $empID = $row['EmployeeID'];
    $date = new DateTime($row['Date']);
    $weekStart = (clone $date)->modify('monday this week')->format('Y-m-d');

    if (!isset($employeeWeeklyHours[$empID])) {
        $employeeWeeklyHours[$empID] = [];
    }
    if (!isset($employeeWeeklyHours[$empID][$weekStart])) {
        $employeeWeeklyHours[$empID][$weekStart] = 0;
    }

    $employeeWeeklyHours[$empID][$weekStart] += $row['DecimalHours'];
}

// Accurate total regular/overtime
$totalRegular = 0;
$totalOvertime = 0;

foreach ($employeeWeeklyHours as $weeks) {
    foreach ($weeks as $hours) {
        $totalRegular += min($hours, 40);
        $totalOvertime += max($hours - 40, 0);
    }
}
$totalHoursDecimal = $totalRegular + $totalOvertime;

// Per-employee totals
$totalsPerEmployee = [];
if (empty($employeeID)) {
    foreach ($rows as $row) {
        $name = $row['FirstName'] . ' ' . $row['LastName'];
        $totalsPerEmployee[$name] = ($totalsPerEmployee[$name] ?? 0) + $row['DecimalHours'];
    }
}
?>

<?php
$pageTitle   = 'Summary Report';
$headerTitle = 'Summary';
$additionalCSS = [
    '../css/summary.css',
    'https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css',
];
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Summary Report</h1>
</div>

<form method="GET" class="filter-bar">
  <div class="form-group">
    <label>Date Range</label>
    <input type="text" name="dates" id="dateRange" class="date-input"
           value="<?= date('m/d/Y', strtotime($startDate)) ?> - <?= date('m/d/Y', strtotime($endDate)) ?>">
  </div>
  <div class="form-group">
    <label>Employee</label>
    <select name="emp">
      <option value="">All Employees</option>
      <?php while ($emp = $employeeList->fetch_assoc()): ?>
        <option value="<?= $emp['ID'] ?>" <?= ($emp['ID'] == $employeeID ? 'selected' : '') ?>>
          <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Round to Nearest</label>
    <select name="rounding">
      <?php foreach ([0, 5, 10, 15, 20, 25, 30] as $min): ?>
        <option value="<?= $min ?>" <?= ($rounding == $min ? 'selected' : '') ?>><?= $min === 0 ? 'No rounding' : "$min minutes" ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="justify-content:flex-end;flex-direction:row;align-items:center;gap:8px;min-width:auto">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px">
      <input type="checkbox" name="separate_pages" value="1" <?= $separatePages ? 'checked' : '' ?>>
      Separate pages per user
    </label>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a href="summary.php" class="btn btn-secondary">Reset</a>
  </div>
</form>

<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:16px 20px;border-bottom:1px solid #e1e4e8;display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:14px;font-weight:600;color:#374151">
      <?= date('M j Y', strtotime($startDate)) ?> — <?= date('M j Y', strtotime($endDate)) ?>
    </span>
    <div style="display:flex;gap:8px">
      <form method="POST" action="export_summary_excel.php" style="margin:0">
<?= csrf_field() ?>
        <input type="hidden" name="start" value="<?= $startDate ?>">
        <input type="hidden" name="end" value="<?= $endDate ?>">
        <input type="hidden" name="emp" value="<?= $employeeID ?>">
        <input type="hidden" name="rounding" value="<?= $rounding ?>">
        <input type="hidden" name="separate_pages" value="<?= $separatePages ?>">
        <button type="submit" class="btn btn-sm btn-secondary">Export Excel</button>
      </form>
      <form method="POST" action="export_summary_pdf.php" style="margin:0">
<?= csrf_field() ?>
        <input type="hidden" name="start" value="<?= $startDate ?>">
        <input type="hidden" name="end" value="<?= $endDate ?>">
        <input type="hidden" name="emp" value="<?= $employeeID ?>">
        <input type="hidden" name="rounding" value="<?= $rounding ?>">
        <input type="hidden" name="separate_pages" value="<?= $separatePages ?>">
        <button type="submit" class="btn btn-sm btn-secondary">Export PDF</button>
      </form>
    </div>
  </div>

  <table class="data-table">
    <thead>
      <tr>
        <th>Employee</th>
        <th>Date</th>
        <th>Hours</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($rows)): ?>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></td>
          <td><?= date('M j Y', strtotime($row['Date'])) ?></td>
          <td><?= number_format($row['DecimalHours'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="3" style="text-align:center;padding:40px;color:#9ca3af">No punches found for this period.</td></tr>
      <?php endif; ?>
      <tr class="summary-total">
        <td colspan="2"><strong>Total Hours</strong></td>
        <td><strong><?= number_format($totalHoursDecimal, 2) ?></strong></td>
      </tr>
      <tr class="summary-regular">
        <td colspan="2">Regular (≤ 40 hrs/week)</td>
        <td><?= number_format($totalRegular, 2) ?></td>
      </tr>
      <tr class="summary-overtime">
        <td colspan="2">Overtime (&gt; 40 hrs/week)</td>
        <td><?= number_format($totalOvertime, 2) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php if (empty($employeeID) && count($totalsPerEmployee)): ?>
<div class="card" style="margin-top:16px">
  <h2>Per Employee Totals</h2>
  <table class="data-table">
    <thead><tr><th>Employee</th><th>Total Hours</th></tr></thead>
    <tbody>
      <?php foreach ($totalsPerEmployee as $name => $total): ?>
      <tr>
        <td><?= htmlspecialchars($name) ?></td>
        <td><strong><?= number_format($total, 2) ?></strong></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<script src="../js/summary.js"></script>

<?php include 'includes/footer.php'; ?>
