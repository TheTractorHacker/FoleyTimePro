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

    $html .= "<table><tr><th>Employee</th>";
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
<!DOCTYPE html>
<html>
<head>
    <title>Attendance Report</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/attendance.css">
</head>
<body>

<header>
    <img src="/images/D-Best.png" alt="TimeClock Logo" class="logo">
    <h1>Attendance Report</h1>
</header>

<nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="view_punches.php">Timesheets</a>
    <a href="summary.php">Summary</a>
    <a href="manage_users.php">Users</a>
    <a href="attendance.php" class="active">Attendance</a>
    <a href="manage_admins.php">Admins</a>
    <a href="../logout.php">Logout</a>
</nav>

<div class="container">

    <form method="get">
        <label>From:</label>
        <input type="date" name="start" value="<?= $startDate ?>">
        <label>To:</label>
        <input type="date" name="end" value="<?= $endDate ?>">
        <label>Employee:</label>
        <select name="emp">
            <option value="all">All Employees</option>
            <?php foreach ($employees as $id => $name): ?>
                <option value="<?= $id ?>" <?= $selectedEmp == $id ? 'selected' : '' ?>><?= $name ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
        <button type="submit" name="export" value="pdf">📄 Export PDF</button>
    </form>

    <div class="status-cards">
        <div class="card present-card">
            <h3>Present</h3>
            <p><?= $totalPresent ?></p>
        </div>
        <div class="card incomplete-card">
            <h3>Incomplete</h3>
            <p><?= $totalIncomplete ?></p>
        </div>
        <div class="card absent-card">
            <h3>Absent</h3>
            <p><?= $totalAbsent ?></p>
        </div>
    </div>

    <div class="legend">
        <strong>Legend:</strong>
        <span class="green"></span> Present
        <span class="yellow"></span> Incomplete
        <span class="red"></span> Absent
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <?php foreach ($dates as $date): ?>
                    <th><?= date('D m/d', strtotime($date)) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($selectedEmp === 'all' ? array_keys($employees) : [$selectedEmp]) as $eid): ?>
                <tr>
                    <td><?= htmlspecialchars($employees[$eid]) ?></td>
                    <?php foreach ($dates as $d): ?>
                        <?php
                        $e = $attendance[$eid][$d] ?? ['status' => 'Absent', 'hours' => null, 'TimeIN' => null, 'TimeOUT' => null];
                        $label = $e['status'];
                        if ($e['hours']) $label .= "<br>{$e['hours']} hrs";
                        $class = strtolower($e['status']);
                        $tip = '';
                        if ($e['TimeIN'] || $e['TimeOUT']) {
                            $tip = "<div class='tooltiptext'>IN: {$e['TimeIN']}<br>OUT: {$e['TimeOUT']}</div>";
                            echo "<td class='$class tooltip'>$label$tip</td>";
                        } else {
                            echo "<td class='$class'>$label</td>";
                        }
                        ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<footer>&copy; <?= date('Y') ?> TimeClock System. All rights reserved.</footer>

</body>
</html>