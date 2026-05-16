<?php
session_start();
require_once '../auth/db.php';
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

$totalExports = (int)($conn->query("SELECT COUNT(*) c FROM report_exports")->fetch_assoc()['c'] ?? 0);
$lastExport   = $conn->query("SELECT ExportedAt FROM report_exports ORDER BY ExportedAt DESC LIMIT 1")->fetch_assoc()['ExportedAt'] ?? null;

$pageTitle   = 'Reports';
$headerTitle = 'Reports';
$additionalCSS = ['../css/reports.css'];
include 'includes/header.php';
?>

<div class="page-header">
  <h1>Reports</h1>
</div>

<div class="reports-grid">

  <div class="report-card">
    <div class="report-card-icon" style="background:#e0f2fe">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
    </div>
    <h3>Summary Report</h3>
    <p>Total hours, regular time, and overtime by employee and date range. Exportable to Excel and PDF.</p>
    <a href="summary.php" class="report-card-link">Open Report</a>
  </div>

  <div class="report-card">
    <div class="report-card-icon" style="background:#dcfce7">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
    </div>
    <h3>Timesheet Detail</h3>
    <p>Full punch-by-punch log with clock in/out times, lunch breaks, and GPS location links per employee.</p>
    <a href="view_punches.php" class="report-card-link">Open Report</a>
  </div>

  <div class="report-card">
    <div class="report-card-icon" style="background:#fef9c3">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a16207" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
    <h3>Attendance Report</h3>
    <p>Daily present / incomplete / absent grid across all employees for any date range. Exportable to PDF.</p>
    <a href="attendance.php" class="report-card-link">Open Report</a>
  </div>

  <div class="report-card">
    <div class="report-card-icon" style="background:#fce7f3">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#9d174d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <h3>Time Off Report</h3>
    <p>All leave requests filtered by status, employee, and date. Approve or reject directly from the view.</p>
    <a href="time_off_requests.php" class="report-card-link">Open Report</a>
  </div>

  <div class="report-card report-card-featured">
    <div class="report-card-icon" style="background:#ede9fe">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6d28d9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
    </div>
    <h3>Custom Report Builder</h3>
    <p>Pick your date range, employees, and format — then generate any report type from one place.</p>
    <a href="custom_reports.php" class="report-card-link">Open Builder</a>
  </div>

  <div class="report-card">
    <div class="report-card-icon" style="background:#f0fdf4">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    </div>
    <h3>Export History</h3>
    <p><?= $totalExports > 0
        ? htmlspecialchars($totalExports . ' export' . ($totalExports !== 1 ? 's' : '') . ' on record.' . ($lastExport ? ' Last: ' . date('M j Y', strtotime($lastExport)) . '.' : ''))
        : 'No exports yet. Every Summary and Custom report export is logged here for re-download.'
      ?></p>
    <a href="export_history.php" class="report-card-link">
      View History<?= $totalExports > 0 ? " ($totalExports)" : '' ?>
    </a>
  </div>

</div>

<?php include 'includes/footer.php'; ?>
