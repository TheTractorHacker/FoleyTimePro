<?php
session_start();
if (!isset($_SESSION['admin'])) { http_response_code(403); exit('Forbidden'); }
require_once '../auth/db.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// === INPUTS ===
$startDate = $_POST['start'] ?? '';
$endDate = $_POST['end'] ?? '';
$employeeID = $_POST['emp'] ?? '';
$rounding = intval($_POST['rounding'] ?? 0);
$separatePages = intval($_POST['separate_pages'] ?? 0);


// Log export to report_exports table
function logExport(mysqli $conn, string $type, string $start, string $end, 
                   string $emp, string $empName, string $format, int $rounding, string $by = 'admin'): void {
    $stmt = $conn->prepare(
        "INSERT INTO report_exports (ReportType,StartDate,EndDate,EmployeeID,EmployeeName,Format,Rounding,ExportedBy)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $empId = $emp !== '' ? (int)$emp : null;
    $stmt->bind_param("sssissis", $type, $start, $end, $empId, $empName, $format, $rounding, $by);
    $stmt->execute();
}

if (!$startDate || !$endDate) {
    header('Location: ../error.php?code=400&message=' . urlencode('Start and end dates are required.'));
    exit;
}

// === HELPER FUNCTIONS ===
function hmsToDecimal($hms, $rounding = 0) {
    list($h, $m, $s) = explode(':', $hms);
    $minutes = $h * 60 + $m + ($s / 60);
    if ($rounding > 0) {
        $minutes = round($minutes / $rounding) * $rounding;
    }
    return round($minutes / 60, 2);
}

function getPunches($conn, $start, $end, $emp = '') {
    $sql = "
        SELECT u.FirstName, u.LastName, tp.EmployeeID, tp.Date, tp.TimeIN, tp.TimeOUT, tp.LunchStart, tp.LunchEnd,
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
    return $stmt->get_result();
}

// === DATA FETCH ===
$result = getPunches($conn, $startDate, $endDate, $employeeID);

// === CREATE SPREADSHEET ===
// Resolve employee name for logging
$logEmpName = '';
if ($employeeID) { $r = $conn->query("SELECT CONCAT(FirstName,' ',LastName) n FROM users WHERE ID=".(int)$employeeID." LIMIT 1"); if($r && $row=$r->fetch_assoc()) $logEmpName=$row['n']; }
logExport($conn, 'summary_excel', $startDate, $endDate, (string)$employeeID, $logEmpName, 'excel', $rounding, $_SESSION['admin'] ?? 'admin');

$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0); // we'll add sheets dynamically

$currentUser = '';
$sheet = null;
$rowNum = 2;
$totalHours = 0;
$rangeFormatted = date('m-d', strtotime($startDate)) . '_' . date('m-d', strtotime($endDate));

while ($row = $result->fetch_assoc()) {
    $fullName = $row['FirstName'] . ' ' . $row['LastName'];

    // Create new sheet if needed
    if ($separatePages && $employeeID == '' && $currentUser !== $row['EmployeeID']) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(substr($fullName, 0, 31)); // Excel sheet name limit
        $rowNum = 2;

        // Headers
        $headers = ['Employee', 'Date', 'Time In', 'Time Out', 'Lunch Start', 'Lunch End', 'Rounded Hours'];
        $sheet->fromArray($headers, null, 'A1');

        $sheet->getStyle('A1:G1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $currentUser = $row['EmployeeID'];
    }

    // Default (single sheet) or first time through
    if (!$separatePages && !$sheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        $headers = ['Employee', 'Date', 'Time In', 'Time Out', 'Lunch Start', 'Lunch End', 'Rounded Hours'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:G1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    $roundedHours = hmsToDecimal($row['TotalHours'], $rounding);
    $totalHours += $roundedHours;

    $sheet->setCellValue("A{$rowNum}", $fullName);
    $sheet->setCellValue("B{$rowNum}", date('m/d/Y', strtotime($row['Date'])));
    $sheet->setCellValue("C{$rowNum}", $row['TimeIN']);
    $sheet->setCellValue("D{$rowNum}", $row['TimeOUT']);
    $sheet->setCellValue("E{$rowNum}", $row['LunchStart']);
    $sheet->setCellValue("F{$rowNum}", $row['LunchEnd']);
    $sheet->setCellValue("G{$rowNum}", $roundedHours);
    $rowNum++;
}

// Add total row (last sheet only)
if ($sheet) {
    $sheet->setCellValue("F{$rowNum}", 'Total Hours');
    $sheet->setCellValue("G{$rowNum}", number_format($totalHours, 2));
    $sheet->getStyle("F{$rowNum}:G{$rowNum}")->getFont()->setBold(true);
}

// Set active sheet to first
$spreadsheet->setActiveSheetIndex(0);

// Output Excel file
$employeeLabel = !empty($employeeID) ? preg_replace('/[^a-zA-Z0-9]/', '', $row['LastName']) : 'All';
$filename = "Payroll_{$employeeLabel}_{$rangeFormatted}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;