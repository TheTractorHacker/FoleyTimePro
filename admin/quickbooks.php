<?php
/**
 * QuickBooks Payroll Integration
 * Handles OAuth2 connect/disconnect and timesheet export to QB Time Activities
 */
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

require_once '../auth/db.php';
require_once '../functions/get_branding.php';
require_once '../functions/quickbooks_helper.php';

$branding = getBranding($conn);
$msg = ''; $msgType = 'success';

$action = $_GET['action'] ?? '';

// Handle OAuth callback
if ($action === 'callback') {
    $code      = $_GET['code'] ?? '';
    $realmId   = $_GET['realmId'] ?? '';
    $state     = $_GET['state'] ?? '';

    if (!hash_equals($_SESSION['qb_oauth_state'] ?? '', $state)) {
        $msg = 'Invalid OAuth state — possible CSRF attack.'; $msgType = 'error';
    } elseif ($code && $realmId) {
        $result = qbExchangeCode($conn, $code, $realmId);
        $msg     = $result['success'] ? 'QuickBooks connected successfully!' : $result['error'];
        $msgType = $result['success'] ? 'success' : 'error';
    }
}

require_once __DIR__ . '/includes/csrf.php';

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $msg = 'Invalid request.'; $msgType = 'error';
    } else {
        $postAction = $_POST['action'] ?? '';

        if ($postAction === 'disconnect') {
            $conn->query("TRUNCATE TABLE quickbooks_connection");
            $conn->query("UPDATE quickbooks_employee_map SET QBEmployeeID=''");
            $msg = 'QuickBooks disconnected.';

        } elseif ($postAction === 'sync_employees') {
            $result = qbSyncEmployees($conn);
            $msg     = $result['success'] ? "Synced {$result['matched']} employees." : $result['error'];
            $msgType = $result['success'] ? 'success' : 'error';

        } elseif ($postAction === 'export_timesheets') {
            $startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('monday last week'));
            $endDate   = $_POST['end_date']   ?? date('Y-m-d', strtotime('sunday last week'));
            $result    = qbExportTimesheets($conn, $startDate, $endDate);
            $msg     = $result['success'] ? "Exported {$result['exported']} time entries." : $result['error'];
            $msgType = $result['success'] ? 'success' : 'error';

        } elseif ($postAction === 'map_employee') {
            $empId = (int)$_POST['employee_id'];
            $qbId  = trim($_POST['qb_employee_id'] ?? '');
            if ($empId && $qbId) {
                $stmt = $conn->prepare("INSERT INTO quickbooks_employee_map (EmployeeID,QBEmployeeID) VALUES(?,?) ON DUPLICATE KEY UPDATE QBEmployeeID=VALUES(QBEmployeeID)");
                $stmt->bind_param("is", $empId, $qbId);
                $stmt->execute();
                $msg = 'Employee mapping saved.';
            }
        }
    }
}

// Fetch state
$qbConn   = $conn->query("SELECT * FROM quickbooks_connection LIMIT 1")->fetch_assoc();
$isLinked = !empty($qbConn);

$syncLog  = $conn->query("SELECT * FROM quickbooks_sync_log ORDER BY SyncedAt DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
$empMaps  = $conn->query(
    "SELECT u.ID, u.FirstName, u.LastName, m.QBEmployeeID, m.LastSyncAt
     FROM users u LEFT JOIN quickbooks_employee_map m ON u.ID=m.EmployeeID
     ORDER BY u.LastName, u.FirstName"
)->fetch_all(MYSQLI_ASSOC);
?>
<?php $pageTitle = 'QuickBooks';
$headerTitle = 'QuickBooks';
include 'includes/header.php'; ?>

<div class="page-header">
  <h1>QuickBooks Payroll</h1>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Connection status card -->
<div class="card" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px;padding:16px 20px">
  <span style="width:12px;height:12px;border-radius:50%;background:<?= $isLinked ? '#16a34a' : '#e74c3c' ?>;flex-shrink:0"></span>
  <div style="flex:1">
    <strong style="font-size:15px"><?= $isLinked ? 'Connected to QuickBooks' : 'Not Connected' ?></strong><br>
    <span style="font-size:12px;color:#6b7280">
      <?php if ($isLinked): ?>
        <?= htmlspecialchars($qbConn['CompanyName'] ?? 'QuickBooks') ?> &bull; Connected <?= date('M j Y', strtotime($qbConn['ConnectedAt'])) ?>
      <?php else: ?>
        Connect your QuickBooks Online account to sync employees and export timesheets to payroll.
      <?php endif; ?>
    </span>
  </div>
  <?php if ($isLinked): ?>
    <form method="post" style="margin:0">
<?= csrf_field() ?>
      <input type="hidden" name="action" value="disconnect">
      <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Disconnect QuickBooks?')">Disconnect</button>
    </form>
  <?php else: ?>
    <a href="<?= htmlspecialchars(qbGetAuthUrl($conn)) ?>" class="btn btn-primary">Connect QuickBooks</a>
  <?php endif; ?>
</div>

<?php if ($isLinked): ?>

<!-- Export timesheets -->
<div class="card" style="margin-bottom:20px">
  <h2>Export Timesheets to Payroll</h2>
  <p style="color:#6b7280;font-size:13px;margin-bottom:16px">Push approved hours for a pay period to QuickBooks as Time Activity records.</p>
  <form method="post" class="filter-bar" style="margin:0">
<?= csrf_field() ?>
    <input type="hidden" name="action" value="export_timesheets">
    <div class="form-group">
      <label>Start Date</label>
      <input type="date" name="start_date" value="<?= date('Y-m-d', strtotime('monday last week')) ?>">
    </div>
    <div class="form-group">
      <label>End Date</label>
      <input type="date" name="end_date" value="<?= date('Y-m-d', strtotime('sunday last week')) ?>">
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn btn-primary">Export to QuickBooks</button>
    </div>
  </form>
</div>

<!-- Employee mapping -->
<div class="card" style="margin-bottom:20px;padding:0;overflow:hidden">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e1e4e8">
    <div>
      <h2 style="margin:0">Employee Mapping</h2>
      <p style="color:#6b7280;font-size:12px;margin:4px 0 0">Map employees to their QuickBooks counterparts. Auto-Sync matches by name.</p>
    </div>
    <form method="post" style="margin:0">
<?= csrf_field() ?>
      <input type="hidden" name="action" value="sync_employees">
      <button type="submit" class="btn btn-sm btn-secondary">Auto-Sync from QB</button>
    </form>
  </div>
  <table class="data-table">
    <thead>
      <tr><th>Employee</th><th>QuickBooks Employee ID</th><th>Last Synced</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($empMaps as $e): ?>
      <tr>
        <td><strong><?= htmlspecialchars($e['FirstName'] . ' ' . $e['LastName']) ?></strong></td>
        <td>
          <form method="post" style="display:flex;gap:8px;align-items:center;margin:0">
<?= csrf_field() ?>
                  <input type="hidden" name="action" value="map_employee">
            <input type="hidden" name="employee_id" value="<?= $e['ID'] ?>">
            <input type="text" name="qb_employee_id" value="<?= htmlspecialchars($e['QBEmployeeID'] ?? '') ?>" placeholder="Enter QB Employee ID" style="max-width:200px">
            <button type="submit" class="btn btn-sm btn-secondary">Save</button>
          </form>
        </td>
        <td style="color:#6b7280;font-size:12px"><?= $e['LastSyncAt'] ? date('M j Y', strtotime($e['LastSyncAt'])) : '—' ?></td>
        <td>
          <?php if ($e['QBEmployeeID']): ?>
            <span class="badge badge-approved">Mapped</span>
          <?php else: ?>
            <span class="badge badge-inactive">Unmapped</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Sync history -->
<?php if (!empty($syncLog)): ?>
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:16px 20px;border-bottom:1px solid #e1e4e8"><h2 style="margin:0">Sync History</h2></div>
  <table class="data-table">
    <thead>
      <tr><th>Type</th><th>Date Range</th><th>Sent</th><th>OK</th><th>Failed</th><th>Notes</th><th>When</th></tr>
    </thead>
    <tbody>
      <?php foreach ($syncLog as $log): ?>
      <tr>
        <td><?= ucfirst($log['SyncType']) ?></td>
        <td style="font-size:12px"><?= $log['StartDate'] ? "{$log['StartDate']} – {$log['EndDate']}" : '—' ?></td>
        <td><?= $log['RecordsIn'] ?></td>
        <td><span class="badge badge-approved"><?= $log['RecordsOk'] ?></span></td>
        <td><?= $log['RecordsFail'] > 0 ? '<span class="badge badge-rejected">'.$log['RecordsFail'].'</span>' : '—' ?></td>
        <td style="font-size:12px;color:#6b7280;max-width:200px"><?= htmlspecialchars($log['Notes'] ?? '') ?></td>
        <td style="font-size:12px;color:#6b7280"><?= date('M j g:ia', strtotime($log['SyncedAt'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php else: ?>

<div class="card" style="text-align:center;padding:60px 32px">
  <div style="font-size:48px;margin-bottom:16px">💼</div>
  <h3 style="font-size:18px;margin:0 0 8px">Get Started with QuickBooks Payroll</h3>
  <p style="color:#6b7280;max-width:440px;margin:0 auto 24px;font-size:14px">Connect your QuickBooks Online account to automatically export approved hours to payroll. Employees are matched by name.</p>
  <a href="<?= htmlspecialchars(qbGetAuthUrl($conn)) ?>" class="btn btn-primary">Connect QuickBooks Account</a>
</div>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
</body>
</html>
