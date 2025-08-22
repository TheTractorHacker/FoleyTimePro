<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../auth/db.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';

$start = $_POST['start'] ?? '';
$end = $_POST['end'] ?? '';
$emp = $_POST['emp'] ?? '';
$rounding = intval($_POST['rounding'] ?? 0);
$separatePages = intval($_POST['separate_pages'] ?? 0);

if (!$start || !$end) {
    header('Location: ../error.php?code=400&message=' . urlencode('Start and end dates are required.'));
    exit;
}

// Helper to convert HH:MM:SS to decimal and round
function hmsToDecimal($time, $rounding = 0) {
    list($h, $m, $s) = explode(':', $time);
    $minutes = $h * 60 + $m + ($s / 60);
    if ($rounding > 0) {
        $minutes = round($minutes / $rounding) * $rounding;
    }
    return round($minutes / 60, 2);
}

// Fetch punches
$sql = "
    SELECT u.FirstName, u.LastName, tp.EmployeeID, tp.Date,
           tp.TimeIN, tp.TimeOUT, tp.LunchStart, tp.LunchEnd,
           SEC_TO_TIME(
               TIME_TO_SEC(TIMEDIFF(tp.TimeOUT, tp.TimeIN)) -
               TIME_TO_SEC(TIMEDIFF(IFNULL(tp.LunchEnd, '00:00:00'), IFNULL(tp.LunchStart, '00:00:00')))
           ) AS TotalHours
    FROM timepunches tp
    JOIN users u ON u.ID = tp.EmployeeID
    WHERE tp.TimeIN IS NOT NULL AND tp.TimeOUT IS NOT NULL
      AND tp.Date BETWEEN ? AND ?
";
$params = [$start, $end];
if (!empty($emp)) {
    $sql .= " AND tp.EmployeeID = ?";
    $params[] = $emp;
}
$sql .= " ORDER BY tp.EmployeeID, tp.Date ASC";

$stmt = $conn->prepare($sql);
$types = str_repeat('s', count($params));
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Organize rows
$grouped = [];
$totals = [];

while ($row = $result->fetch_assoc()) {
    $employeeKey = $row['EmployeeID'];
    $rounded = hmsToDecimal($row['TotalHours'], $rounding);
    $row['RoundedHours'] = $rounded;
    $grouped[$employeeKey]['name'] = $row['FirstName'] . ' ' . $row['LastName'];
    $grouped[$employeeKey]['rows'][] = $row;
    $totals[$employeeKey] = ($totals[$employeeKey] ?? 0) + $rounded;
}

// PDF Setup
$pdf = new TCPDF();
$pdf->SetCreator('TimeClock System');
$pdf->SetAuthor('D-Best Technologies');
$pdf->SetTitle('Payroll Summary Report');
$pdf->SetMargins(15, 15, 15);
$pdf->SetFont('helvetica', '', 11);

// Page for each user if requested
$first = true;
foreach ($grouped as $empId => $data) {
    if (!$first && $separatePages) {
        $pdf->AddPage();
    } else if ($first) {
        $pdf->AddPage();
        $first = false;
    }

    $pdf->SetFont('helvetica', '', 11);
    $name = htmlspecialchars($data['name']);
    $html = '<h2 style="text-align:center; color:#0078D7;">Payroll Summary Report</h2>';
    $html .= "<p><strong>Employee:</strong> $name<br>";
    $html .= "<strong>Date Range:</strong> " . date("m/d/Y", strtotime($start)) . " to " . date("m/d/Y", strtotime($end)) . "</p>";

    $html .= '<table border="1" cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse;">
                <thead style="background-color: #e6f0ff;">
                    <tr>
                        <th><b>Date</b></th>
                        <th><b>Time In</b></th>
                        <th><b>Time Out</b></th>
                        <th><b>Lunch Start</b></th>
                        <th><b>Lunch End</b></th>
                        <th><b>Rounded Hours</b></th>
                    </tr>
                </thead>
                <tbody>';

    foreach ($data['rows'] as $r) {
        $date = date("m/d/Y", strtotime($r['Date']));
        $html .= "<tr>
                    <td>$date</td>
                    <td>{$r['TimeIN']}</td>
                    <td>{$r['TimeOUT']}</td>
                    <td>{$r['LunchStart']}</td>
                    <td>{$r['LunchEnd']}</td>
                    <td style='text-align:right;'>" . number_format($r['RoundedHours'], 2) . "</td>
                  </tr>";
    }

    $html .= "<tr style='font-weight:bold; background-color:#f1f1f1;'>
                <td colspan='5'>Total Hours</td>
                <td style='text-align:right;'>" . number_format($totals[$empId], 2) . "</td>
              </tr>";

    $html .= '</tbody></table>';
    $pdf->writeHTML($html, true, false, true, false, '');
}

// Clean buffer and output PDF
while (ob_get_level()) ob_end_clean();
$pdf->Output('payroll_summary.pdf', 'D');
exit;