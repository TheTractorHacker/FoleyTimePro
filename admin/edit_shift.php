<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/../auth/db.php';

$shiftId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($shiftId <= 0) { header('Location: manage_shifts.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM shifts WHERE ShiftID = ?");
$stmt->bind_param("i", $shiftId);
$stmt->execute();
$shift = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$shift) { header('Location: manage_shifts.php'); exit; }

$selectedDays = json_decode($shift['DaysOfWeek'], true) ?: [];
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_shift'])) {
    $shiftName      = trim($_POST['shift_name']);
    $startTime      = trim($_POST['start_time']);
    $endTime        = trim($_POST['end_time']);
    $lunchDuration  = (int)$_POST['lunch_duration'];
    $lunchStartTime = !empty($_POST['lunch_start_time']) ? trim($_POST['lunch_start_time']) : null;
    $isActive       = isset($_POST['is_active']) ? 1 : 0;
    $autoClockOut   = isset($_POST['auto_clock_out']) ? 1 : 0;
    $gracePeriod    = max(0, (int)($_POST['grace_period'] ?? 60));

    $daysList = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    $days = [];
    foreach ($daysList as $day) {
        if (isset($_POST['day_' . strtolower($day)])) $days[] = $day;
    }

    if (empty($shiftName))               { $error = 'Shift name is required.'; }
    elseif (empty($startTime) || empty($endTime)) { $error = 'Start and end times are required.'; }
    elseif (empty($days))                { $error = 'Select at least one day.'; }
    else {
        $daysJson = json_encode($days);
        $upd = $conn->prepare("UPDATE shifts SET ShiftName=?,StartTime=?,EndTime=?,DaysOfWeek=?,LunchDuration=?,LunchStartTime=?,IsActive=?,AutoClockOut=?,GracePeriodMinutes=?,UpdatedAt=NOW() WHERE ShiftID=?");
        $upd->bind_param("ssssissiii", $shiftName, $startTime, $endTime, $daysJson, $lunchDuration, $lunchStartTime, $isActive, $autoClockOut, $gracePeriod, $shiftId);

        if ($upd->execute()) {
            $success = 'Shift updated successfully.';
            $shift = array_merge($shift, [
                'ShiftName'=>$shiftName,'StartTime'=>$startTime,'EndTime'=>$endTime,
                'DaysOfWeek'=>$daysJson,'LunchDuration'=>$lunchDuration,'LunchStartTime'=>$lunchStartTime,
                'IsActive'=>$isActive,'AutoClockOut'=>$autoClockOut,'GracePeriodMinutes'=>$gracePeriod,
            ]);
            $selectedDays = $days;
        } else {
            $error = 'Error updating shift: ' . $conn->error;
        }
        $upd->close();
    }
}

$countStmt = $conn->prepare("SELECT COUNT(*) c FROM user_shifts WHERE ShiftID=?");
$countStmt->bind_param("i", $shiftId);
$countStmt->execute();
$assignedUsers = (int)$countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();

$pageTitle   = 'Edit Shift';
$headerTitle = 'Edit Shift';
include 'includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
  <a href="manage_shifts.php" class="btn btn-secondary btn-sm">&#8592; Back</a>
  <div>
    <h1 style="margin:0">Edit Shift</h1>
    <p style="margin:0;font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($shift['ShiftName']) ?>
      <?php if ($assignedUsers > 0): ?>
        &mdash; <strong><?= $assignedUsers ?></strong> user<?= $assignedUsers!==1?'s':'' ?> assigned
      <?php endif; ?>
    </p>
  </div>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
    <span class="badge <?= $shift['IsActive'] ? 'badge-success' : 'badge-muted' ?>">
      <?= $shift['IsActive'] ? 'Active' : 'Inactive' ?>
    </span>
    <?php if ($shift['AutoClockOut'] ?? 0): ?>
    <span class="badge badge-warning">Auto Clock-Out ON</span>
    <?php endif; ?>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<form method="POST">
  <input type="hidden" name="update_shift" value="1">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

    <!-- Left -->
    <div>
      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-bottom:16px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Basic Info</h3>

        <div class="form-group">
          <label>Shift Name *</label>
          <input type="text" name="shift_name" required
                 value="<?= htmlspecialchars($shift['ShiftName']) ?>"
                 placeholder="e.g. Morning Shift, 1st Shift">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label>Start Time *</label>
            <input type="time" name="start_time" required value="<?= htmlspecialchars($shift['StartTime']) ?>">
          </div>
          <div class="form-group">
            <label>End Time *</label>
            <input type="time" name="end_time" required value="<?= htmlspecialchars($shift['EndTime']) ?>">
          </div>
        </div>

        <div class="form-group">
          <label>Days of Week *</label>
          <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px">
            <?php
            $daysList = ['Mon'=>'M','Tue'=>'T','Wed'=>'W','Thu'=>'T','Fri'=>'F','Sat'=>'S','Sun'=>'S'];
            $dayFull  = ['Mon'=>'Mon','Tue'=>'Tue','Wed'=>'Wed','Thu'=>'Thu','Fri'=>'Fri','Sat'=>'Sat','Sun'=>'Sun'];
            foreach ($daysList as $key => $abbr):
              $checked = in_array($key, $selectedDays);
            ?>
            <label style="display:flex;flex-direction:column;align-items:center;gap:4px;cursor:pointer;padding:8px 4px;border:1.5px solid <?= $checked?'#073851':'#e1e4e8' ?>;border-radius:8px;background:<?= $checked?'#eef3ff':'#fff' ?>;font-size:11px;font-weight:700;transition:all .15s" id="dayLabel_<?= $key ?>">
              <input type="checkbox" name="day_<?= strtolower($key) ?>" value="1"
                     <?= $checked?'checked':'' ?>
                     onchange="updateDayStyle(this,'dayLabel_<?= $key ?>')"
                     style="display:none">
              <?= $dayFull[$key] ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-bottom:16px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Lunch Break</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label>Duration (minutes)</label>
            <input type="number" name="lunch_duration" min="0" max="120" step="15"
                   value="<?= (int)$shift['LunchDuration'] ?>" placeholder="0 = no lunch">
          </div>
          <div class="form-group">
            <label>Expected Start Time</label>
            <input type="time" name="lunch_start_time"
                   value="<?= htmlspecialchars($shift['LunchStartTime'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- Right -->
    <div>
      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-bottom:16px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Status</h3>
        <div class="switch-wrapper" style="margin-bottom:10px">
          <span class="switch-label">Shift Active</span>
          <label class="switch">
            <input type="checkbox" name="is_active" value="1" <?= $shift['IsActive']?'checked':'' ?>>
            <span class="slider"></span>
          </label>
        </div>
        <p style="font-size:12px;color:var(--text-muted)">Inactive shifts cannot be assigned to users.</p>
      </div>

      <div class="card" style="margin-bottom:16px;border:2px solid <?= ($shift['AutoClockOut']??0)?'#d97706':'#e1e4e8' ?>">
        <h3 style="margin-bottom:4px;font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Auto Clock-Out</h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
          Automatically clocks out employees who forget. Uses the shift <strong>End Time</strong> as the recorded clock-out — a grace period gives them a window before the system acts.
        </p>

        <div class="switch-wrapper" style="margin-bottom:16px">
          <span class="switch-label" style="font-weight:700;color:#1a1f2e">Enable Auto Clock-Out</span>
          <label class="switch">
            <input type="checkbox" name="auto_clock_out" value="1"
                   <?= ($shift['AutoClockOut']??0)?'checked':'' ?>
                   onchange="document.getElementById('gracePeriodRow').style.opacity=this.checked?1:.4">
            <span class="slider"></span>
          </label>
        </div>

        <div id="gracePeriodRow" style="opacity:<?= ($shift['AutoClockOut']??0)?1:.4 ?>">
          <div class="form-group">
            <label>Grace Period (minutes after shift end)</label>
            <input type="number" name="grace_period" min="0" max="480" step="15"
                   value="<?= (int)($shift['GracePeriodMinutes'] ?? 60) ?>"
                   placeholder="60">
            <small style="color:var(--text-muted);display:block;margin-top:4px">
              e.g. 60 = trigger auto clock-out 1 hour after shift ends. Clock-out is recorded at shift end time.
            </small>
          </div>
        </div>
      </div>

      <?php if ($assignedUsers > 0): ?>
      <div class="card" style="background:#f9fafb;margin-bottom:16px">
        <p style="font-size:13px;color:var(--text-muted);margin:0">
          &#128101; <strong><?= $assignedUsers ?></strong> user<?= $assignedUsers!==1?'s are':' is' ?> on this shift.
          Changes to times and auto clock-out take effect immediately.
          <a href="manage_users.php" style="color:#073851;font-weight:600">Manage users &#8594;</a>
        </p>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:10px">
        <a href="manage_shifts.php" class="btn btn-secondary" style="flex:1;text-align:center">Cancel</a>
        <button type="submit" class="btn btn-primary" style="flex:2">Save Shift</button>
      </div>
    </div>

  </div>
</form>

<script>
function updateDayStyle(cb, labelId) {
  const lbl = document.getElementById(labelId);
  if (cb.checked) {
    lbl.style.borderColor = '#073851';
    lbl.style.background  = '#eef3ff';
  } else {
    lbl.style.borderColor = '#e1e4e8';
    lbl.style.background  = '#fff';
  }
}
</script>

<?php include 'includes/footer.php'; ?>
