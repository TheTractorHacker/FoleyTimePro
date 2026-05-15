<?php
session_start();
require_once '../auth/db.php';
require_once './tcpdf/tcpdf.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('America/Chicago');

$startDate = $_GET['start'] ?? date('Y-m-d', strtotime('monday this week'));
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime('friday this week'));
$selectedEmp = $_GET['emp'] ?? 'all';
$exportPDF = isset($_GET['export']) && $_GET['export'] === 'pdf';

$employees = [];
$empResult = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");
while ($row = $empResult->fetch_assoc()) {
    $employees[$row['ID']] = $row['FirstName'] . ' ' . $row['LastName'];
}

$query = "SELECT EmployeeID, Date, TimeIN, TimeOUT FROM timepunches WHERE Date BETWEEN ? AND ?";
if ($selectedEmp !== 'all') $query .= " AND EmployeeID = ?";
$stmt = $conn->prepare($query);
if ($selectedEmp !== 'all') $stmt->bind_param("ssi", $startDate, $endDate, $selectedEmp);
else $stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

$attendance = [];
while ($row = $result->fetch_assoc()) {
    $eid = $row['EmployeeID'];
    $date = $row['Date'];
    $in = $row['TimeIN'];
    $out = $row['TimeOUT'];
    $status = 'Absent';
    $hours = '';
    if ($in && $out) {
        $status = 'Present';
        $hours = round((strtotime($out) - strtotime($in)) / 3600, 2);
    } elseif ($in || $out) {
        $status = 'Incomplete';
    }
    $attendance[$eid][$date] = [
        'status' => $status,
        'TimeIN' => $in,
        'TimeOUT' => $out,
        'hours' => $hours
    ];
}

$dates = [];
$cur = strtotime($startDate);
while ($cur <= strtotime($endDate)) {
    $dates[] = date('Y-m-d', $cur);
    $cur = strtotime('+1 day', $cur);
}

$totalPresent = $totalIncomplete = $totalAbsent = 0;
foreach (($selectedEmp === 'all' ? array_keys($employees) : [$selectedEmp]) as $eid) {
    foreach ($dates as $date) {
        $status = $attendance[$eid][$date]['status'] ?? 'Absent';
        if ($status === 'Present') $totalPresent++;
        elseif ($status === 'Incomplete') $totalIncomplete++;
        else $totalAbsent++;
    }
}

if ($exportPDF) {
    $pdf = new TCPDF();
    $pdf->AddPage('L', 'A4');
    $pdf->SetCreator('TimeClock');
    $pdf->SetAuthor('TimeClock System');
    $pdf->SetTitle('Attendance Report');

    $html = "<h2>Attendance Report</h2>";
    $html .= "<strong>From:</strong> $startDate &nbsp;&nbsp; <strong>To:</strong> $endDate<br>";
    if ($selectedEmp !== 'all') {
        $html .= "<strong>Employee:</strong> " . htmlspecialchars($employees[$selectedEmp]) . "<br>";
    }

    $html .= "
    <table style='margin: 10px 0; width: 100%; text-align: center; font-size: 12px;'>
        <tr>
            <td style='background-color:#d4edda; padding: 10px;'><strong>Present</strong><br>$totalPresent</td>
            <td style='background-color:#fff3cd; padding: 10px;'><strong>Incomplete</strong><br>$totalIncomplete</td>
            <td style='background-color:#f8d7da; padding: 10px;'><strong>Absent</strong><br>$totalAbsent</td>
        </tr>
    </table>";

    $html .= "<style>
        table { border-collapse: collapse; width: 100%; font-size: 10px; }
        th, td { border: 1px solid #000; padding: 4px; text-align: center; }
        .present { background-color: #d4edda; }
        .incomplete { background-color: #fff3cd; }
        .absent { background-color: #f8d7da; }
    </style>";

    $html .= "<table class='data-table'><tr><th>Employee</th>";
    foreach ($dates as $d) {
        $html .= "<th>" . date('D m/d', strtotime($d)) . "</th>";
    }
    $html .= "</tr>";

    $empIDs = ($selectedEmp === 'all') ? array_keys($employees) : [$selectedEmp];
    foreach ($empIDs as $eid) {
        $html .= "<tr><td>" . htmlspecialchars($employees[$eid]) . "</td>";
        foreach ($dates as $date) {
            $e = $attendance[$eid][$date] ?? ['status' => 'Absent', 'hours' => null, 'TimeIN' => null, 'TimeOUT' => null];
            $label = $e['status'];
            if ($e['hours']) $label .= "<br>{$e['hours']} hrs";
            $class = strtolower($e['status']);
            $html .= "<td class=\"$class\">$label</td>";
        }
        $html .= "</tr>";
    }
    $html .= "</table>";

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('attendance_report.pdf', 'D');
    exit;
}
?>

<?php
$pageTitle   = 'Attendance';
$headerTitle = 'Attendance';
$additionalCSS = [
    '../css/attendance.css',
];
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Attendance Report</h1>
</div>

<form method="get" class="filter-bar">
  <div class="form-group">
    <label>From</label>
    <input type="date" name="start" value="<?= $startDate ?>">
  </div>
  <div class="form-group">
    <label>To</label>
    <input type="date" name="end" value="<?= $endDate ?>">
  </div>
  <div class="form-group">
    <label>Employee</label>
    <select name="emp">
      <option value="all">All Employees</option>
      <?php foreach ($employees as $id => $name): ?>
        <option value="<?= $id ?>" <?= $selectedEmp == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <button type="submit" name="export" value="pdf" class="btn btn-secondary">Export PDF</button>
  </div>
</form>

<!-- Status summary cards -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px">
  <div class="card" style="text-align:center;padding:18px;border-top:4px solid #16a34a">
    <div style="font-size:28px;font-weight:800;color:#16a34a"><?= $totalPresent ?></div>
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-top:4px">Present</div>
  </div>
  <div class="card" style="text-align:center;padding:18px;border-top:4px solid #d97706">
    <div style="font-size:28px;font-weight:800;color:#d97706"><?= $totalIncomplete ?></div>
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-top:4px">Incomplete</div>
  </div>
  <div class="card" style="text-align:center;padding:18px;border-top:4px solid #e74c3c">
    <div style="font-size:28px;font-weight:800;color:#e74c3c"><?= $totalAbsent ?></div>
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-top:4px">Absent</div>
  </div>
</div>

<!-- Legend -->
<div style="display:flex;gap:16px;align-items:center;margin-bottom:12px;font-size:13px;color:#6b7280">
  <strong>Legend:</strong>
  <span style="display:flex;align-items:center;gap:5px"><span style="width:12px;height:12px;background:#d1fae5;border-radius:3px;display:inline-block"></span>Present</span>
  <span style="display:flex;align-items:center;gap:5px"><span style="width:12px;height:12px;background:#fef3c7;border-radius:3px;display:inline-block"></span>Incomplete</span>
  <span style="display:flex;align-items:center;gap:5px"><span style="width:12px;height:12px;background:#fee2e2;border-radius:3px;display:inline-block"></span>Absent</span>
</div>

<div class="card" style="padding:0;overflow:auto">
  <table class="att-table">
    <thead>
      <tr>
        <th style="position:sticky;left:0;z-index:2">Employee</th>
        <?php foreach ($dates as $date): ?>
          <th><?= date('D', strtotime($date)) ?><br><?= date('m/d', strtotime($date)) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach (($selectedEmp === 'all' ? array_keys($employees) : [$selectedEmp]) as $eid): ?>
      <tr>
        <td style="font-weight:600;white-space:nowrap;position:sticky;left:0;background:#fff;z-index:1"><?= htmlspecialchars($employees[$eid]) ?></td>
        <?php foreach ($dates as $d): ?>
          <?php
            $e = $attendance[$eid][$d] ?? ['status'=>'Absent','hours'=>null,'TimeIN'=>null,'TimeOUT'=>null];
            $class = strtolower($e['status']);
            $label = $e['status'];
            if ($e['hours']) $label .= '<br><small>' . $e['hours'] . 'h</small>';
          ?>
          <?php if ($e['TimeIN'] || $e['TimeOUT']): ?>
            <td class="<?= $class ?> tooltip"><?= $label ?>
              <div class="tooltiptext">In: <?= $e['TimeIN'] ?: '—' ?><br>Out: <?= $e['TimeOUT'] ?: '—' ?></div>
            </td>
          <?php else: ?>
            <td class="<?= $class ?>"><?= $label ?></td>
          <?php endif; ?>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include 'includes/footer.php'; ?>
