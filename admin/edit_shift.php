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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Shift details update ──────────────────────────────────────
    if (isset($_POST['update_shift'])) {
        $shiftName      = trim($_POST['shift_name']);
        $startTime      = trim($_POST['start_time']);
        $endTime        = trim($_POST['end_time']);
        $lunchDuration  = (int)$_POST['lunch_duration'];
        $lunchStartTime = !empty($_POST['lunch_start_time']) ? trim($_POST['lunch_start_time']) : null;
        $isActive       = isset($_POST['is_active'])       ? 1 : 0;
        $autoClockOut   = isset($_POST['auto_clock_out'])  ? 1 : 0;
        $gracePeriod    = max(0, (int)($_POST['grace_period'] ?? 60));

        $daysList = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $days = [];
        foreach ($daysList as $day) {
            if (isset($_POST['day_' . strtolower($day)])) $days[] = $day;
        }

        if (!$shiftName)                             { $error = 'Shift name is required.'; }
        elseif (!$startTime || !$endTime)            { $error = 'Start and end times are required.'; }
        elseif (empty($days))                        { $error = 'Select at least one day.'; }
        else {
            $daysJson = json_encode($days);
            $upd = $conn->prepare("UPDATE shifts SET ShiftName=?,StartTime=?,EndTime=?,DaysOfWeek=?,LunchDuration=?,LunchStartTime=?,IsActive=?,AutoClockOut=?,GracePeriodMinutes=?,UpdatedAt=NOW() WHERE ShiftID=?");
            $upd->bind_param("ssssissiii", $shiftName, $startTime, $endTime, $daysJson, $lunchDuration, $lunchStartTime, $isActive, $autoClockOut, $gracePeriod, $shiftId);
            if ($upd->execute()) {
                $success = 'Shift saved.';
                $shift = array_merge($shift, [
                    'ShiftName'=>$shiftName,'StartTime'=>$startTime,'EndTime'=>$endTime,
                    'DaysOfWeek'=>$daysJson,'LunchDuration'=>$lunchDuration,'LunchStartTime'=>$lunchStartTime,
                    'IsActive'=>$isActive,'AutoClockOut'=>$autoClockOut,'GracePeriodMinutes'=>$gracePeriod,
                ]);
                $selectedDays = $days;
            } else {
                $error = 'Error: ' . $conn->error;
            }
            $upd->close();
        }
    }

    // ── Bulk user assignment ──────────────────────────────────────
    if (isset($_POST['assign_users'])) {
        $selectedUsers = array_map('intval', (array)($_POST['assigned_users'] ?? []));
        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        // Remove this shift from anyone currently on it who wasn't re-selected
        $conn->prepare("DELETE FROM user_shifts WHERE ShiftID=?")->execute([$shiftId]) || true;
        $delStmt = $conn->prepare("DELETE FROM user_shifts WHERE ShiftID=?");
        $delStmt->bind_param("i", $shiftId);
        $delStmt->execute();

        // Also clear this shift from users who were on OTHER shifts
        // (each user can only be on one shift, so overwrite)
        if (!empty($selectedUsers)) {
            foreach ($selectedUsers as $uid) {
                $ins = $conn->prepare("INSERT INTO user_shifts (UserID,ShiftID,AssignedBy) VALUES (?,?,?) ON DUPLICATE KEY UPDATE ShiftID=?,AssignedBy=?,AssignedAt=NOW()");
                $ins->bind_param("iiiii", $uid, $shiftId, $adminId, $shiftId, $adminId);
                $ins->execute();
            }
        }
        $success = 'User assignments saved — ' . count($selectedUsers) . ' user(s) assigned.';
    }
}

// Reload assigned users list after any POST
$assignedStmt = $conn->prepare("SELECT UserID FROM user_shifts WHERE ShiftID=?");
$assignedStmt->bind_param("i", $shiftId);
$assignedStmt->execute();
$assignedUserIds = array_column($assignedStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'UserID');
$assignedUsers = count($assignedUserIds);

// All active users for the assignment panel
$allUsersResult = $conn->query("SELECT ID, FirstName, LastName, Office, ClockStatus FROM users ORDER BY LastName, FirstName");
$allUsers = $allUsersResult ? $allUsersResult->fetch_all(MYSQLI_ASSOC) : [];

// Group by office for display
$usersByOffice = [];
foreach ($allUsers as $u) {
    $usersByOffice[$u['Office'] ?: 'No Office'][] = $u;
}
ksort($usersByOffice);

$pageTitle   = 'Edit Shift';
$headerTitle = 'Edit Shift';
include 'includes/header.php';
?>

<!-- Page header with inline save buttons -->
<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="manage_shifts.php" class="btn btn-secondary btn-sm">&#8592; Shifts</a>
    <div>
      <h1 style="margin:0;font-size:18px"><?= htmlspecialchars($shift['ShiftName']) ?></h1>
      <div style="display:flex;gap:6px;align-items:center;margin-top:4px">
        <span class="badge <?= $shift['IsActive'] ? 'badge-approved' : 'badge-rejected' ?>">
          <?= $shift['IsActive'] ? 'Active' : 'Inactive' ?>
        </span>
        <?php if ($shift['AutoClockOut'] ?? 0): ?>
          <span class="badge badge-pending">Auto Clock-Out</span>
        <?php endif; ?>
        <?php if ($assignedUsers > 0): ?>
          <span style="font-size:12px;color:var(--text-muted)"><?= $assignedUsers ?> user<?= $assignedUsers!==1?'s':'' ?> assigned</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="manage_shifts.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" form="shiftForm" class="btn btn-primary">Save Shift</button>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start">

  <!-- ── Left column: shift details ───────────────────────────── -->
  <div>
    <form method="POST" id="shiftForm">
      <input type="hidden" name="update_shift" value="1">

      <!-- Basic details -->
      <div class="card" style="margin-bottom:16px">
        <h2>Shift Details</h2>

        <div class="form-group">
          <label>Shift Name *</label>
          <input type="text" name="shift_name" required
                 value="<?= htmlspecialchars($shift['ShiftName']) ?>"
                 placeholder="e.g. Morning Shift, 1st Shift"
                 style="font-size:15px;font-weight:600">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label>Start Time *</label>
            <input type="time" name="start_time" required value="<?= htmlspecialchars($shift['StartTime']) ?>">
          </div>
          <div class="form-group">
            <label>End Time *</label>
            <input type="time" name="end_time" required value="<?= htmlspecialchars($shift['EndTime']) ?>">
          </div>
        </div>

        <!-- Day picker -->
        <div class="form-group" style="margin-bottom:0">
          <label>Days of Week *</label>
          <div style="display:flex;gap:8px;margin-top:2px">
            <?php
            $dayDefs = [
              'Mon'=>'Mon','Tue'=>'Tue','Wed'=>'Wed',
              'Thu'=>'Thu','Fri'=>'Fri','Sat'=>'Sat','Sun'=>'Sun'
            ];
            foreach ($dayDefs as $key => $label):
              $checked = in_array($key, $selectedDays);
            ?>
            <label id="dl_<?= $key ?>"
                   style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;
                          cursor:pointer;padding:10px 4px;border-radius:10px;
                          border:2px solid <?= $checked?'#073851':'#e1e4e8' ?>;
                          background:<?= $checked?'#073851':'#fff' ?>;
                          color:<?= $checked?'#fff':'#6b7280' ?>;
                          font-size:11px;font-weight:700;transition:all .15s;user-select:none">
              <input type="checkbox" name="day_<?= strtolower($key) ?>" value="1"
                     <?= $checked?'checked':'' ?>
                     onchange="toggleDay(this,'dl_<?= $key ?>')"
                     style="display:none">
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Lunch -->
      <div class="card" style="margin-bottom:16px">
        <h2>Lunch Break</h2>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group" style="margin-bottom:0">
            <label>Duration (minutes)</label>
            <input type="number" name="lunch_duration" min="0" max="120" step="15"
                   value="<?= (int)$shift['LunchDuration'] ?>" placeholder="0 = no lunch">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label>Expected Start Time <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
            <input type="time" name="lunch_start_time"
                   value="<?= htmlspecialchars($shift['LunchStartTime'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Status + Auto clock-out -->
      <div class="card">
        <h2>Controls</h2>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border)">
          <div>
            <div style="font-size:14px;font-weight:600">Shift Active</div>
            <div style="font-size:12px;color:var(--text-muted)">Inactive shifts cannot be assigned to users</div>
          </div>
          <label class="switch">
            <input type="checkbox" name="is_active" value="1" <?= $shift['IsActive']?'checked':'' ?>>
            <span class="slider"></span>
          </label>
        </div>

        <div style="padding:16px 0 12px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <div>
              <div style="font-size:14px;font-weight:600">Auto Clock-Out</div>
              <div style="font-size:12px;color:var(--text-muted)">Clock out employees who forget, using the shift end time</div>
            </div>
            <label class="switch">
              <input type="checkbox" name="auto_clock_out" id="autoClockOutToggle" value="1"
                     <?= ($shift['AutoClockOut']??0)?'checked':'' ?>
                     onchange="document.getElementById('graceRow').style.display=this.checked?'block':'none'">
              <span class="slider"></span>
            </label>
          </div>

          <div id="graceRow" style="display:<?= ($shift['AutoClockOut']??0)?'block':'none' ?>;
               background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px">
            <div class="form-group" style="margin-bottom:0">
              <label style="color:#92400e">Grace Period — minutes after shift end before triggering</label>
              <div style="display:flex;align-items:center;gap:10px">
                <input type="number" name="grace_period" min="0" max="480" step="15"
                       value="<?= (int)($shift['GracePeriodMinutes'] ?? 60) ?>"
                       style="max-width:120px">
                <span style="font-size:12px;color:#92400e">
                  Clock-out is recorded at the shift <strong>end time</strong>, not trigger time.
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

    </form>
  </div>

  <!-- ── Right column: user assignment ────────────────────────── -->
  <div>
    <form method="POST" id="assignForm">
      <input type="hidden" name="assign_users" value="1">

      <div class="card" style="padding:0;overflow:hidden">
        <!-- Header -->
        <div style="padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-size:14px;font-weight:700">Assigned Users</div>
            <div style="font-size:12px;color:var(--text-muted)" id="assignCount">
              <?= $assignedUsers ?> of <?= count($allUsers) ?> selected
            </div>
          </div>
          <div style="display:flex;gap:6px">
            <button type="button" class="btn btn-secondary btn-sm" onclick="selectAll(true)">All</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="selectAll(false)">None</button>
          </div>
        </div>

        <!-- Search -->
        <div style="padding:10px 14px;border-bottom:1px solid var(--border)">
          <input type="text" id="userSearch" placeholder="Search users…"
                 oninput="filterUsers(this.value)"
                 style="width:100%;padding:7px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;font-family:inherit;outline:none"
                 class="no-reset">
        </div>

        <!-- User list by office -->
        <div style="max-height:520px;overflow-y:auto" id="userList">
          <?php foreach ($usersByOffice as $office => $users): ?>
          <div class="office-group" data-office="<?= htmlspecialchars(strtolower($office)) ?>">
            <div style="padding:8px 16px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);background:#f9fafb;border-bottom:1px solid var(--border);position:sticky;top:0">
              <?= htmlspecialchars($office) ?>
            </div>
            <?php foreach ($users as $u): ?>
            <?php
              $isAssigned = in_array($u['ID'], $assignedUserIds);
              $statusColor = match($u['ClockStatus']) {
                'In'    => '#16a34a',
                'Lunch' => '#d97706',
                default => '#9ca3af',
              };
            ?>
            <label class="user-row" data-name="<?= htmlspecialchars(strtolower($u['FirstName'].' '.$u['LastName'])) ?>"
                   style="display:flex;align-items:center;gap:10px;padding:9px 16px;cursor:pointer;border-bottom:1px solid #f3f4f6;transition:background .1s">
              <input type="checkbox" name="assigned_users[]" value="<?= $u['ID'] ?>"
                     <?= $isAssigned?'checked':'' ?>
                     onchange="updateCount()"
                     style="width:16px;height:16px;accent-color:#073851;flex-shrink:0">
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= htmlspecialchars($u['LastName'].', '.$u['FirstName']) ?>
                </div>
              </div>
              <span style="width:8px;height:8px;border-radius:50%;background:<?= $statusColor ?>;flex-shrink:0" title="<?= htmlspecialchars($u['ClockStatus']??'Out') ?>"></span>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>

          <?php if (empty($allUsers)): ?>
          <div style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px">No users found.</div>
          <?php endif; ?>
        </div>

        <!-- Footer action -->
        <div style="padding:12px 16px;border-top:1px solid var(--border);background:#f9fafb">
          <button type="submit" class="btn btn-primary" style="width:100%">Save Assignments</button>
        </div>
      </div>
    </form>
  </div>

</div>

<script>
function toggleDay(cb, labelId) {
  const lbl = document.getElementById(labelId);
  lbl.style.borderColor = cb.checked ? '#073851' : '#e1e4e8';
  lbl.style.background  = cb.checked ? '#073851' : '#fff';
  lbl.style.color       = cb.checked ? '#fff'    : '#6b7280';
}

function updateCount() {
  const checked = document.querySelectorAll('#userList input[type=checkbox]:checked').length;
  const total   = document.querySelectorAll('#userList input[type=checkbox]').length;
  document.getElementById('assignCount').textContent = checked + ' of ' + total + ' selected';
}

function selectAll(state) {
  document.querySelectorAll('#userList input[type=checkbox]').forEach(cb => {
    if (cb.closest('.user-row') && cb.closest('.user-row').style.display !== 'none') {
      cb.checked = state;
    }
  });
  updateCount();
}

function filterUsers(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.user-row').forEach(row => {
    const name = row.dataset.name || '';
    row.style.display = (!q || name.includes(q)) ? '' : 'none';
  });
  document.querySelectorAll('.office-group').forEach(g => {
    const visible = [...g.querySelectorAll('.user-row')].some(r => r.style.display !== 'none');
    g.style.display = visible ? '' : 'none';
  });
}

// Style hover on user rows
document.querySelectorAll('.user-row').forEach(row => {
  row.addEventListener('mouseenter', () => row.style.background = '#f0f5ff');
  row.addEventListener('mouseleave', () => row.style.background = '');
});
</script>

<?php include 'includes/footer.php'; ?>
