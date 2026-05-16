<?php
$pageTitle = 'Timesheet';
require_once 'header.php';

// Display status messages
$statusMessage = '';
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'submitted') {
        $statusMessage = '<div class="u-alert u-alert-success">Your time adjustment request has been submitted for approval.</div>';
    } elseif ($_GET['status'] === 'nochange') {
        $statusMessage = '<div class="u-alert u-alert-info">No changes were submitted.</div>';
    }
}

if (isset($_GET['email_status'])) {
    $emailStatus = $_GET['email_status'];
    if ($emailStatus === 'sent') {
        $statusMessage .= '<div class="u-alert u-alert-success">Admin notification email sent successfully.</div>';
    } elseif (strpos($emailStatus, 'error:') === 0) {
        $errorMessage = substr($emailStatus, 6);
        $statusMessage .= '<div class="u-alert u-alert-error">Failed to send admin notification email: ' . htmlspecialchars($errorMessage) . '</div>';
    }
}

$start = $_GET['start'] ?? date('Y-m-d', strtotime('monday this week'));
$end   = $_GET['end'] ?? date('Y-m-d', strtotime('friday this week'));

$stmt = $conn->prepare("SELECT * FROM timepunches WHERE EmployeeID = ? AND Date BETWEEN ? AND ? ORDER BY Date ASC");
$stmt->bind_param("sss", $empID, $start, $end);
$stmt->execute();
$result = $stmt->get_result();

$punches = [];
while ($row = $result->fetch_assoc()) {
    $punches[] = $row;
}
?>

<?= $statusMessage ?>

    <div class="u-page-header"><h1>Timesheet</h1><span style="font-size:13px;color:#6b7280">Edit times and submit for approval</span></div>

    <div class="u-card" style="padding:14px 20px;margin-bottom:16px">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
    <div class="u-form-group" style="flex:1;min-width:140px;margin:0">
      <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px">From</label>
      <input type="date" name="start" value="<?= $start ?>" style="padding:8px 11px;border:1.5px solid #e1e4e8;border-radius:8px;font-size:14px;width:100%">
    </div>
    <div class="u-form-group" style="flex:1;min-width:140px;margin:0">
      <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px">To</label>
      <input type="date" name="end" value="<?= $end ?>" style="padding:8px 11px;border:1.5px solid #e1e4e8;border-radius:8px;font-size:14px;width:100%">
    </div>
    <button type="submit" class="u-btn u-btn-primary">Apply</button>
    <a href="timesheet.php" class="u-btn u-btn-secondary">Reset</a>
  </form>
</div>

    <form method="POST" action="submit_timesheet_edits.php" id="editForm">
      <input type="hidden" name="EmployeeID" value="<?= $empID ?>">

      <div class="u-card">
        <div class="u-table-wrap">
              <table class="u-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Clock In</th>
              <th>Lunch Out</th>
              <th>Lunch In</th>
              <th>Clock Out</th>
              <th>Note</th>
              <th>Reason for Change</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($punches as $i => $row): ?>
              <tr>
                <td class="timesheet-date-cell">
                  <?= date('m/d/Y', strtotime($row['Date'])) ?>
                  <input type="hidden" name="entries[<?= $i ?>][Date]" value="<?= $row['Date'] ?>">
                </td>
                <td><input type="time" style="border:1px solid #e1e4e8;border-radius:6px;padding:5px 8px;font-size:13px;font-family:inherit" name="entries[<?= $i ?>][TimeIN]" value="<?= date('H:i', strtotime($row['TimeIN'])) ?>" data-original="<?= date('H:i', strtotime($row['TimeIN'])) ?>"></td>
                <td><input type="time" style="border:1px solid #e1e4e8;border-radius:6px;padding:5px 8px;font-size:13px;font-family:inherit" name="entries[<?= $i ?>][LunchStart]" value="<?= date('H:i', strtotime($row['LunchStart'])) ?>" data-original="<?= date('H:i', strtotime($row['LunchStart'])) ?>"></td>
                <td><input type="time" style="border:1px solid #e1e4e8;border-radius:6px;padding:5px 8px;font-size:13px;font-family:inherit" name="entries[<?= $i ?>][LunchEnd]" value="<?= date('H:i', strtotime($row['LunchEnd'])) ?>" data-original="<?= date('H:i', strtotime($row['LunchEnd'])) ?>"></td>
                <td><input type="time" style="border:1px solid #e1e4e8;border-radius:6px;padding:5px 8px;font-size:13px;font-family:inherit" name="entries[<?= $i ?>][TimeOut]" value="<?= date('H:i', strtotime($row['TimeOut'])) ?>" data-original="<?= date('H:i', strtotime($row['TimeOut'])) ?>"></td>
                <td><input type="text" name="entries[<?= $i ?>][Note]" value="<?= htmlspecialchars($row['Note']) ?>"></td>
                <td><input type="text" name="entries[<?= $i ?>][Reason]" placeholder="Only required if edited" class="reason-field"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
            </div>
          </div>

      <button type="submit" class="u-btn u-btn-primary" style="margin-top:14px">Submit Changes for Approval</button>
    </form>

<div id="popupFeedback" class="modal hidden" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:999">
  <div class="modal-content">
    <p id="popupMessage"></p>
    <button onclick="closePopup()">OK</button>
  </div>
</div>

<script src="../js/user_timesheet.js"></script>
<?php require_once 'footer.php'; ?>