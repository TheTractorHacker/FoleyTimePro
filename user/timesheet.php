<?php
require_once 'header.php';

// Display status messages
$statusMessage = '';
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'submitted') {
        $statusMessage = '<div class="alert alert-success">Your time adjustment request has been submitted for approval.</div>';
    } elseif ($_GET['status'] === 'nochange') {
        $statusMessage = '<div class="alert alert-info">No changes were submitted.</div>';
    }
}

if (isset($_GET['email_status'])) {
    $emailStatus = $_GET['email_status'];
    if ($emailStatus === 'sent') {
        $statusMessage .= '<div class="alert alert-success">Admin notification email sent successfully.</div>';
    } elseif (strpos($emailStatus, 'error:') === 0) {
        $errorMessage = substr($emailStatus, 6);
        $statusMessage .= '<div class="alert alert-danger">Failed to send admin notification email: ' . htmlspecialchars($errorMessage) . '</div>';
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
<link rel="stylesheet" href="../css/user_timesheet.css">

<?= $statusMessage ?>

    <h2>Submit Time Changes for Approval</h2>

    <form method="get" class="date-range">
      <label>From:</label>
      <input type="date" name="start" value="<?= $start ?>">
      <label>To:</label>
      <input type="date" name="end" value="<?= $end ?>">
      <button type="submit">Apply</button>
    </form>

    <form method="POST" action="submit_timesheet_edits.php" id="editForm">
      <input type="hidden" name="EmployeeID" value="<?= $empID ?>">

      <div class="card">
        <div class="table-responsive">
              <table class="timesheet-table">
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
                <td><input type="time" name="entries[<?= $i ?>][TimeIN]" value="<?= date('H:i', strtotime($row['TimeIN'])) ?>" data-original="<?= date('H:i', strtotime($row['TimeIN'])) ?>"></td>
                <td><input type="time" name="entries[<?= $i ?>][LunchStart]" value="<?= date('H:i', strtotime($row['LunchStart'])) ?>" data-original="<?= date('H:i', strtotime($row['LunchStart'])) ?>"></td>
                <td><input type="time" name="entries[<?= $i ?>][LunchEnd]" value="<?= date('H:i', strtotime($row['LunchEnd'])) ?>" data-original="<?= date('H:i', strtotime($row['LunchEnd'])) ?>"></td>
                <td><input type="time" name="entries[<?= $i ?>][TimeOut]" value="<?= date('H:i', strtotime($row['TimeOut'])) ?>" data-original="<?= date('H:i', strtotime($row['TimeOut'])) ?>"></td>
                <td><input type="text" name="entries[<?= $i ?>][Note]" value="<?= htmlspecialchars($row['Note']) ?>"></td>
                <td><input type="text" name="entries[<?= $i ?>][Reason]" placeholder="Only required if edited" class="reason-field"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
            </div>
          </div>

      <button type="submit" class="toggle-punch" style="margin-top: 20px;">Submit Changes for Approval</button>
    </form>

<div id="popupFeedback" class="modal hidden">
  <div class="modal-content">
    <p id="popupMessage"></p>
    <button onclick="closePopup()">OK</button>
  </div>
</div>

<script src="../js/user_timesheet.js"></script>
<?php require_once 'footer.php'; ?>