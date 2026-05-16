<?php
/**
 * Add New Shift
 * Create a new work shift with schedule and lunch break
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
$pageTitle = 'Add New Shift';
$headerTitle = 'Add New Shift';

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_shift'])) {
    // Validate inputs
    $shiftName = trim($_POST['shift_name']);
    $startTime = trim($_POST['start_time']);
    $endTime = trim($_POST['end_time']);
    $lunchDuration = (int)$_POST['lunch_duration'];
    $lunchStartTime = !empty($_POST['lunch_start_time']) ? trim($_POST['lunch_start_time']) : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    // Get selected days
    $days = [];
    $daysList = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    foreach ($daysList as $day) {
        if (isset($_POST['day_' . strtolower($day)])) {
            $days[] = $day;
        }
    }

    // Validation
    if (empty($shiftName)) {
        $error = 'Shift name is required';
    } elseif (empty($startTime) || empty($endTime)) {
        $error = 'Start and end times are required';
    } elseif (empty($days)) {
        $error = 'Please select at least one day of the week';
    } else {
        // Convert days to JSON
        $daysJson = json_encode($days);

        // Insert into database
        $stmt = $conn->prepare("
            INSERT INTO shifts (ShiftName, StartTime, EndTime, DaysOfWeek, LunchDuration, LunchStartTime, IsActive)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssssiis",
            $shiftName,
            $startTime,
            $endTime,
            $daysJson,
            $lunchDuration,
            $lunchStartTime,
            $isActive
        );

        if ($stmt->execute()) {
            $success = 'Shift created successfully!';
            // Redirect after a short delay
            header("refresh:2;url=manage_shifts.php");
        } else {
            $error = 'Error creating shift: ' . $conn->error;
        }

        $stmt->close();
    }
}

?>
<?php include 'includes/header.php'; ?>

<div style="margin-bottom: 20px;">
        <a href="manage_shifts.php" style="text-decoration: none; color: var(--brand-primary);">
            ← Back to Shifts
        </a>
    </div>

    <h1>➕ Add New Shift</h1>

    <?php if ($success): ?>
        <div class="alert alert-success" style="padding: 15px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px; margin-bottom: 20px;">
            <?= htmlspecialchars($success) ?>
            <br><small>Redirecting to shifts list...</small>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="padding: 15px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; margin-bottom: 20px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" style="background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <input type="hidden" name="create_shift" value="1">

        <!-- Shift Name -->
        <div class="form-group" style="margin-bottom: 20px;">
            <label for="shift_name" style="display: block; font-weight: bold; margin-bottom: 5px;">
                Shift Name *
            </label>
            <input type="text" name="shift_name" id="shift_name" required
                   value="<?= htmlspecialchars($_POST['shift_name'] ?? '') ?>"
                   placeholder="e.g., 1st Shift, Morning Shift, etc."
                   style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
        </div>

        <!-- Time Range -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div class="form-group">
                <label for="start_time" style="display: block; font-weight: bold; margin-bottom: 5px;">
                    Start Time *
                </label>
                <input type="time" name="start_time" id="start_time" required
                       value="<?= htmlspecialchars($_POST['start_time'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
            </div>

            <div class="form-group">
                <label for="end_time" style="display: block; font-weight: bold; margin-bottom: 5px;">
                    End Time *
                </label>
                <input type="time" name="end_time" id="end_time" required
                       value="<?= htmlspecialchars($_POST['end_time'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
            </div>
        </div>

        <!-- Days of Week -->
        <div class="form-group" style="margin-bottom: 20px;">
            <label style="display: block; font-weight: bold; margin-bottom: 10px;">
                Days of Week *
            </label>
            <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px;">
                <?php
                $daysList = [
                    'mon' => 'Monday',
                    'tue' => 'Tuesday',
                    'wed' => 'Wednesday',
                    'thu' => 'Thursday',
                    'fri' => 'Friday',
                    'sat' => 'Saturday',
                    'sun' => 'Sunday'
                ];

                foreach ($daysList as $key => $label):
                    $checked = isset($_POST['day_' . $key]) ? 'checked' : '';
                ?>
                    <label style="display: flex; align-items: center; cursor: pointer; padding: 10px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                        <input type="checkbox" name="day_<?= $key ?>" value="1" <?= $checked ?>
                               style="margin-right: 5px;">
                        <span style="font-size: 12px;"><?= substr($label, 0, 3) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <small style="color: #666; display: block; margin-top: 5px;">Select the days this shift applies to</small>
        </div>

        <!-- Lunch Break -->
        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid var(--brand-primary);">
            <h3 style="margin-top: 0;">🍽️ Lunch Break Settings</h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="lunch_duration" style="display: block; font-weight: bold; margin-bottom: 5px;">
                        Lunch Duration (minutes)
                    </label>
                    <input type="number" name="lunch_duration" id="lunch_duration" min="0" max="120" step="15"
                           value="<?= htmlspecialchars($_POST['lunch_duration'] ?? '30') ?>"
                           style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                    <small style="color: #666; display: block; margin-top: 5px;">0 for no lunch break</small>
                </div>

                <div class="form-group">
                    <label for="lunch_start_time" style="display: block; font-weight: bold; margin-bottom: 5px;">
                        Expected Lunch Start (Optional)
                    </label>
                    <input type="time" name="lunch_start_time" id="lunch_start_time"
                           value="<?= htmlspecialchars($_POST['lunch_start_time'] ?? '') ?>"
                           style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                    <small style="color: #666; display: block; margin-top: 5px;">When lunch typically starts</small>
                </div>
            </div>
        </div>

        <!-- Active Status -->
        <div class="form-group" style="margin-bottom: 20px;">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" name="is_active" value="1" checked
                       style="margin-right: 10px; width: 18px; height: 18px;">
                <span style="font-weight: bold;">Shift is Active</span>
            </label>
            <small style="color: #666; display: block; margin-left: 28px;">Inactive shifts cannot be assigned to users</small>
        </div>

        <!-- Buttons -->
        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" class="btn btn-primary" style="flex: 1; padding: 12px; font-size: 16px;">
                ✓ Create Shift
            </button>
            <a href="manage_shifts.php" class="btn btn-secondary" style="flex: 1; padding: 12px; font-size: 16px; text-align: center; text-decoration: none;">
                Cancel
            </a>
        </div>
    </form>

    <!-- Helper Info -->
    <div style="margin-top: 30px; padding: 20px; background: #fffbea; border-left: 4px solid #f39c12; border-radius: 4px;">
        <h3 style="margin-top: 0;">💡 Shift Tips</h3>
        <ul style="line-height: 1.8;">
            <li><strong>Overnight Shifts:</strong> If end time is before start time, it's treated as next day (e.g., 11 PM to 7 AM)</li>
            <li><strong>Lunch Break:</strong> Used for auto clock-out calculations and expected hours</li>
            <li><strong>Auto Clock-Out:</strong> Users will be automatically clocked out 1 hour after shift end time if they forget</li>
            <li><strong>Multiple Shifts:</strong> You can create multiple shifts for different schedules (morning, evening, night, etc.)</li>
        </ul>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<style>
    .btn {
        display: inline-block;
        padding: 8px 16px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        text-decoration: none;
        transition: all 0.3s;
    }
    .btn-primary {
        background-color: var(--brand-primary);
        color: white;
    }
    .btn-primary:hover {
        background-color: var(--brand-primary-dark);
    }
    .btn-secondary {
        background-color: #6c757d;
        color: white;
    }
    .btn-secondary:hover {
        background-color: #5a6268;
    }
</style>
