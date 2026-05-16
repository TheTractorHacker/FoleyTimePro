<?php
session_start();
require_once '../auth/db.php';
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

// Filters
$fType   = $_GET['type']  ?? '';
$fEmp    = $_GET['emp']   ?? '';
$fFrom   = $_GET['from']  ?? date('Y-m-d', strtotime('-30 days'));
$fTo     = $_GET['to']    ?? date('Y-m-d');

$sql = "SELECT * FROM report_exports WHERE ExportedAt BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
$params = [$fFrom, $fTo]; $types = 'ss';
if ($fType) { $sql .= " AND ReportType = ?"; $params[] = $fType; $types .= 's'; }
if ($fEmp)  { $sql .= " AND EmployeeID = ?"; $params[] = (int)$fEmp; $types .= 'i'; }
$sql .= " ORDER BY ExportedAt DESC LIMIT 200";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$exports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$employees = $conn->query("SELECT ID,CONCAT(FirstName,' ',LastName) name FROM users ORDER BY LastName")->fetch_all(MYSQLI_ASSOC);

$pageTitle   = 'Export History';
$headerTitle = 'Export History';
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Export History</h1>
  <a href="custom_reports.php" class="btn btn-primary">New Report</a>
</div>

<form method="GET" class="filter-bar">
  <div class="form-group">
    <label>From</label>
    <input type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>">
  </div>
  <div class="form-group">
    <label>To</label>
    <input type="date" name="to" value="<?= htmlspecialchars($fTo) ?>">
  </div>
  <div class="form-group">
    <label>Report Type</label>
    <select name="type">
      <option value="">All Types</option>
      <option value="summary_excel" <?= $fType==='summary_excel'?'selected':'' ?>>Summary Excel</option>
      <option value="summary_pdf"   <?= $fType==='summary_pdf'  ?'selected':'' ?>>Summary PDF</option>
      <option value="custom_excel"  <?= $fType==='custom_excel' ?'selected':'' ?>>Custom Excel</option>
      <option value="custom_pdf"    <?= $fType==='custom_pdf'   ?'selected':'' ?>>Custom PDF</option>
    </select>
  </div>
  <div class="form-group">
    <label>Employee</label>
    <select name="emp">
      <option value="">All Employees</option>
      <?php foreach ($employees as $e): ?>
        <option value="<?= $e['ID'] ?>" <?= $fEmp==$e['ID']?'selected':'' ?>><?= htmlspecialchars($e['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn btn-primary">Filter</button>
    <a href="export_history.php" class="btn btn-secondary">Reset</a>
  </div>
</form>

<div class="card" style="padding:0;overflow:hidden">
  <?php if (empty($exports)): ?>
    <div style="text-align:center;padding:56px 32px">
      <div style="font-size:40px;margin-bottom:12px">📋</div>
      <h3 style="margin:0 0 8px;color:#374151">No exports yet</h3>
      <p style="color:#6b7280;font-size:14px;margin:0 0 20px">Exports from Summary Reports will appear here.</p>
      <a href="custom_reports.php" class="btn btn-primary">Generate a Report</a>
    </div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th>When</th>
        <th>Report</th>
        <th>Period</th>
        <th>Employee</th>
        <th>Format</th>
        <th>Exported By</th>
        <th>Re-export</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($exports as $ex): ?>
      <?php
        $typeLabel = match($ex['ReportType']) {
          'summary_excel' => 'Summary',
          'summary_pdf'   => 'Summary',
          'custom_excel'  => 'Custom',
          'custom_pdf'    => 'Custom',
          default         => ucfirst($ex['ReportType']),
        };
        $fmtBadge = $ex['Format'] === 'excel'
          ? '<span class="badge badge-in">Excel</span>'
          : '<span class="badge badge-out">PDF</span>';
        $reExportUrl = $ex['Format'] === 'excel'
          ? "export_summary_excel.php"
          : "export_summary_pdf.php";
      ?>
      <tr>
        <td style="white-space:nowrap;font-size:12px;color:#6b7280">
          <?= date('M j Y', strtotime($ex['ExportedAt'])) ?><br>
          <?= date('g:ia', strtotime($ex['ExportedAt'])) ?>
        </td>
        <td><?= htmlspecialchars($typeLabel) ?></td>
        <td style="font-size:12px;white-space:nowrap">
          <?= $ex['StartDate'] ? date('M j Y', strtotime($ex['StartDate'])) : '—' ?>
          <?= $ex['EndDate'] && $ex['EndDate'] !== $ex['StartDate'] ? ' – '.date('M j Y', strtotime($ex['EndDate'])) : '' ?>
        </td>
        <td><?= $ex['EmployeeName'] ? htmlspecialchars($ex['EmployeeName']) : '<span style="color:#9ca3af">All</span>' ?></td>
        <td><?= $fmtBadge ?></td>
        <td style="font-size:12px;color:#6b7280"><?= htmlspecialchars($ex['ExportedBy']) ?></td>
        <td>
          <form method="POST" action="<?= $reExportUrl ?>" style="margin:0">
            <input type="hidden" name="start"          value="<?= htmlspecialchars($ex['StartDate'] ?? '') ?>">
            <input type="hidden" name="end"            value="<?= htmlspecialchars($ex['EndDate'] ?? '') ?>">
            <input type="hidden" name="emp"            value="<?= (int)($ex['EmployeeID'] ?? 0) ?: '' ?>">
            <input type="hidden" name="rounding"       value="<?= (int)$ex['Rounding'] ?>">
            <input type="hidden" name="separate_pages" value="0">
            <button type="submit" class="btn btn-sm btn-secondary">Re-export</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="padding:10px 16px;font-size:12px;color:#9ca3af;border-top:1px solid #f3f4f6">
    Showing <?= count($exports) ?> export<?= count($exports) !== 1 ? 's' : '' ?>
  </div>
  <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
