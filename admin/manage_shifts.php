<?php
/**
 * Manage Shifts
 * View, create, edit, and manage employee shifts
 */

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../functions/get_branding.php';

$branding = getBranding($conn);
$pageTitle = 'Manage Shifts';
$headerTitle = 'Manage Shifts';

// Handle shift deletion/deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_shift'])) {
    $shiftId = (int)$_POST['shift_id'];

    // Check if any users are assigned to this shift
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM user_shifts WHERE ShiftID = ?");
    $checkStmt->bind_param("i", $shiftId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $checkRow = $checkResult->fetch_assoc();

    if ($checkRow['count'] > 0) {
        $error = "Cannot delete shift: {$checkRow['count']} user(s) are assigned to this shift. Please reassign them first.";
    } else {
        // Soft delete by setting IsActive = 0
        $stmt = $conn->prepare("UPDATE shifts SET IsActive = 0 WHERE ShiftID = ?");
        $stmt->bind_param("i", $shiftId);

        if ($stmt->execute()) {
            $success = "Shift deactivated successfully!";
        } else {
            $error = "Error deactivating shift: " . $conn->error;
        }
        $stmt->close();
    }
    $checkStmt->close();
}

// Handle shift activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_shift'])) {
    $shiftId = (int)$_POST['shift_id'];

    $stmt = $conn->prepare("UPDATE shifts SET IsActive = 1 WHERE ShiftID = ?");
    $stmt->bind_param("i", $shiftId);

    if ($stmt->execute()) {
        $success = "Shift activated successfully!";
    } else {
        $error = "Error activating shift: " . $conn->error;
    }
    $stmt->close();
}

// Fetch all shifts
$shiftsQuery = "
    SELECT
        s.*,
        COUNT(us.ID) as AssignedUsers
    FROM shifts s
    LEFT JOIN user_shifts us ON s.ShiftID = us.ShiftID
    GROUP BY s.ShiftID
    ORDER BY s.IsActive DESC, s.StartTime ASC
";
$shiftsResult = $conn->query($shiftsQuery);

// Helper function to format days
function formatDays($daysJson) {
    $days = json_decode($daysJson, true);
    if (!$days) return 'N/A';

    $dayAbbr = [
        'Mon' => 'M', 'Tue' => 'T', 'Wed' => 'W',
        'Thu' => 'Th', 'Fri' => 'F', 'Sat' => 'S', 'Sun' => 'Su'
    ];

    $formatted = array_map(function($day) use ($dayAbbr) {
        return $dayAbbr[$day] ?? $day;
    }, $days);

    return implode('-', $formatted);
}

?>
<?php include 'includes/header.php'; ?>

<div class="page-header">
  <h1>Shifts</h1>
  <a href="add_shift.php" class="btn btn-primary">+ New Shift</a>
</div>

<?php if (isset($success)): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($shiftsResult && $shiftsResult->num_rows > 0): ?>
<div class="card" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead>
      <tr>
        <th>Shift Name</th>
        <th>Hours</th>
        <th>Days</th>
        <th>Lunch</th>
        <th style="text-align:center">Assigned</th>
        <th style="text-align:center">Status</th>
        <th style="text-align:center">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php while ($shift = $shiftsResult->fetch_assoc()): ?>
      <tr style="<?= $shift['IsActive'] ? '' : 'opacity:.55' ?>">
        <td><strong><?= htmlspecialchars($shift['ShiftName']) ?></strong></td>
        <td style="white-space:nowrap;font-size:13px">
          <?= date('g:i A', strtotime($shift['StartTime'])) ?> &ndash; <?= date('g:i A', strtotime($shift['EndTime'])) ?>
        </td>
        <td style="font-size:13px;letter-spacing:.05em"><?= htmlspecialchars(formatDays($shift['DaysOfWeek'])) ?></td>
        <td style="font-size:13px">
          <?php if ($shift['LunchDuration'] > 0): ?>
            <?= (int)$shift['LunchDuration'] ?> min<?= $shift['LunchStartTime'] ? ' @ '.date('g:ia', strtotime($shift['LunchStartTime'])) : '' ?>
          <?php else: ?>
            <span style="color:#9ca3af">None</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center">
          <span class="badge <?= $shift['AssignedUsers'] > 0 ? 'badge-approved' : 'badge-inactive' ?>"><?= (int)$shift['AssignedUsers'] ?> user<?= $shift['AssignedUsers'] != 1 ? 's' : '' ?></span>
        </td>
        <td style="text-align:center">
          <?php if ($shift['IsActive']): ?>
            <span class="badge badge-approved">Active</span>
          <?php else: ?>
            <span class="badge badge-inactive">Inactive</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;white-space:nowrap">
          <a href="edit_shift.php?id=<?= $shift['ShiftID'] ?>" class="btn btn-sm btn-secondary">Edit</a>
          <?php if ($shift['IsActive']): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate this shift?')">
              <input type="hidden" name="shift_id" value="<?= $shift['ShiftID'] ?>">
              <button type="submit" name="delete_shift" class="btn btn-sm btn-danger">Deactivate</button>
            </form>
          <?php else: ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="shift_id" value="<?= $shift['ShiftID'] ?>">
              <button type="submit" name="activate_shift" class="btn btn-sm btn-success">Activate</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:56px 32px">
  <h3 style="font-size:18px;margin:0 0 8px;color:#374151">No shifts yet</h3>
  <p style="color:#6b7280;margin:0 0 24px;font-size:14px">Create a shift to start scheduling employees and enable auto clock-out.</p>
  <a href="add_shift.php" class="btn btn-primary">Create First Shift</a>
</div>
<?php endif; ?>

<div class="card" style="margin-top:16px;border-left:4px solid #073851">
  <h2>About Shifts</h2>
  <ul style="margin:0;padding-left:20px;line-height:2;font-size:13px;color:#4b5563">
    <li><strong>Assign users</strong> to shifts from the Edit User page</li>
    <li><strong>Auto clock-out</strong> triggers 1 hour after shift end if an employee forgets</li>
    <li><strong>Deactivate</strong> instead of delete to preserve historical data</li>
  </ul>
</div>

<?php include 'includes/footer.php'; ?>
