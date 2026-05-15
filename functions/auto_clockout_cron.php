<?php
/**
 * Auto Clock-Out Cron Job
 * Automatically clocks out users who forgot to clock out
 * based on their assigned shift schedules
 *
 * Schedule: Run daily at 11:00 PM
 * Crontab: 0 23 * * * /usr/bin/php /var/www/timeclock/functions/auto_clockout_cron.php
 */

// Ensure this is being run from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Start output buffering for logging
ob_start();

echo "===========================================\n";
echo "Auto Clock-Out Cron Job\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "===========================================\n\n";

// Include database connection
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/get_branding.php';

// Get branding for email
$branding = getBranding($conn);

// Get current date and time
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');
$currentDayOfWeek = date('D'); // Mon, Tue, etc.

echo "Current Date: $currentDate\n";
echo "Current Time: $currentTime\n";
echo "Current Day: $currentDayOfWeek\n\n";

// Find users who need to be auto-clocked out (only shifts with AutoClockOut enabled)
$query = "
    SELECT
        u.ID as UserID,
        u.FirstName,
        u.LastName,
        u.Email,
        u.ClockStatus,
        s.ShiftID,
        s.ShiftName,
        s.EndTime as ShiftEndTime,
        s.LunchStartTime,
        s.LunchDuration,
        s.DaysOfWeek,
        s.GracePeriodMinutes,
        tp.id as PunchID,
        tp.TimeIN,
        tp.LunchStart,
        tp.LunchEnd
    FROM users u
    INNER JOIN user_shifts us ON u.ID = us.UserID
    INNER JOIN shifts s ON us.ShiftID = s.ShiftID
    LEFT JOIN timepunches tp ON u.ID = tp.EmployeeID AND tp.Date = ?
    WHERE
        u.ClockStatus IN ('In', 'Lunch')
        AND s.IsActive = 1
        AND s.AutoClockOut = 1
        AND tp.TimeOUT IS NULL
";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $currentDate);
$stmt->execute();
$result = $stmt->get_result();

$usersToClockOut = [];
while ($row = $result->fetch_assoc()) {
    // Check if shift applies to today
    $shiftDays = json_decode($row['DaysOfWeek'], true);
    if (!in_array($currentDayOfWeek, $shiftDays)) {
        continue; // Skip if shift doesn't apply today
    }

    // Trigger when current time >= shift end + grace period
    // but record the clock-out AT the shift end time (not current time)
    $shiftEnd    = strtotime($row['ShiftEndTime']);
    $graceMins   = (int)($row['GracePeriodMinutes'] ?? 60);
    $triggerTime = date('H:i:s', strtotime("+$graceMins minutes", $shiftEnd));

    if ($currentTime >= $triggerTime) {
        $usersToClockOut[] = $row;
    }
}

$stmt->close();

echo "Found " . count($usersToClockOut) . " user(s) to auto clock-out\n\n";

// Process auto clock-outs
$processedCount = 0;
$errorCount = 0;
$autoClockOutLogs = [];

foreach ($usersToClockOut as $user) {
    echo "Processing: {$user['FirstName']} {$user['LastName']} (ID: {$user['UserID']})\n";
    echo "  Shift: {$user['ShiftName']}\n";
    echo "  Shift End: {$user['ShiftEndTime']}\n";
    echo "  Status: {$user['ClockStatus']}\n";

    // Clock-out is recorded AT the shift end time, not at current/trigger time
    $autoClockOutTime = $user['ShiftEndTime'];

    // If user is still on lunch, auto-close lunch first
    if ($user['ClockStatus'] === 'Lunch' && empty($user['LunchEnd'])) {
        // Calculate expected lunch end time
        $lunchStart = $user['LunchStart'] ? strtotime($user['LunchStart']) : strtotime($user['LunchStartTime']);
        $lunchDuration = $user['LunchDuration'] * 60; // Convert minutes to seconds
        $lunchEnd = date('H:i:s', $lunchStart + $lunchDuration);

        echo "  Auto-closing lunch at: $lunchEnd\n";

        $updateLunch = $conn->prepare("UPDATE timepunches SET LunchEnd = ? WHERE id = ?");
        $updateLunch->bind_param("si", $lunchEnd, $user['PunchID']);
        $updateLunch->execute();
        $updateLunch->close();
    }

    // Calculate total hours
    $timeIn = strtotime($user['TimeIN']);
    $timeOut = strtotime($autoClockOutTime);

    $lunchStart = $user['LunchStart'] ? strtotime($user['LunchStart']) : null;
    $lunchEnd = $user['LunchEnd'] ? strtotime($user['LunchEnd']) : ($lunchStart ? $lunchStart + ($user['LunchDuration'] * 60) : null);

    $totalSeconds = $timeOut - $timeIn;
    if ($lunchStart && $lunchEnd) {
        $lunchSeconds = $lunchEnd - $lunchStart;
        $totalSeconds -= $lunchSeconds;
    }

    $totalHours = round($totalSeconds / 3600, 2);

    echo "  Auto Clock-Out Time: $autoClockOutTime\n";
    echo "  Total Hours: $totalHours\n";

    // Update timepunch record — use shift end time as clock-out
    $updateStmt = $conn->prepare("
        UPDATE timepunches
        SET TimeOUT = ?, TotalHours = ?
        WHERE id = ?
    ");
    $updateStmt->bind_param("sdi", $autoClockOutTime, $totalHours, $user['PunchID']);

    if ($updateStmt->execute()) {
        // Update user clock status
        $statusUpdate = $conn->prepare("UPDATE users SET ClockStatus = 'Out' WHERE ID = ?");
        $statusUpdate->bind_param("i", $user['UserID']);
        $statusUpdate->execute();
        $statusUpdate->close();

        // Log to auto_clockout_log table
        $logStmt = $conn->prepare("
            INSERT INTO auto_clockout_log (EmployeeID, PunchID, OriginalShiftEndTime, AutoClockOutTime, NotificationSent)
            VALUES (?, ?, ?, ?, 0)
        ");
        $logStmt->bind_param("iiss", $user['UserID'], $user['PunchID'], $user['ShiftEndTime'], $autoClockOutTime);
        $logStmt->execute();
        $logStmt->close();

        // Log to punch_changelog
        $changedBy = 'Auto Clock-Out System';
        $reason = "Automatic clock-out: Shift ended at {$user['ShiftEndTime']}, auto-clocked out at $autoClockOutTime ({$graceMins} minute grace period).";
        $changeStmt = $conn->prepare("
            INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, ChangeTime, FieldChanged, OldValue, NewValue, Reason)
            VALUES (?, ?, ?, NOW(), 'TimeOut', NULL, ?, ?)
        ");
        $changeStmt->bind_param("issss", $user['UserID'], $currentDate, $changedBy, $autoClockOutTime, $reason);
        $changeStmt->execute();
        $changeStmt->close();

        echo "  ✓ Successfully auto-clocked out\n\n";
        $processedCount++;

        // Push notification to the employee
        try {
            require_once __DIR__ . '/../api/services/NotificationService.php';
            $ns = new NotificationService($conn);
            $ns->sendToUser($user['UserID'], [
                'title' => 'Auto Clock-Out',
                'body'  => "You were automatically clocked out at " . date('g:i A', strtotime($autoClockOutTime)) . " ({$user['ShiftName']} end time). Please verify your timesheet.",
                'url'   => '/user/timesheet.php',
            ]);
        } catch (Throwable $e) {
            echo "  ⚠ Push notification failed: " . $e->getMessage() . "\n";
        }

        // Add to notification log
        $autoClockOutLogs[] = [
            'name'           => "{$user['FirstName']} {$user['LastName']}",
            'shift'          => $user['ShiftName'],
            'shift_end'      => date('g:i A', strtotime($user['ShiftEndTime'])),
            'clock_out_time' => date('g:i A', strtotime($autoClockOutTime)),
            'hours'          => $totalHours,
        ];
    } else {
        echo "  ✗ Error: " . $conn->error . "\n\n";
        $errorCount++;
    }

    $updateStmt->close();
}

// Send email notification to admin
if (count($autoClockOutLogs) > 0) {
    echo "Sending email notification to admin...\n";

    // Get admin email from settings or use default
    $adminEmail = $branding['ContactEmail'] ?? 'admin@localhost';
    if (empty($adminEmail) || $adminEmail === 'admin@localhost') {
        // Try to get from settings table
        $emailResult = $conn->query("SELECT SettingValue FROM settings WHERE SettingKey = 'mail_admin_address'");
        if ($emailResult && $emailRow = $emailResult->fetch_assoc()) {
            $adminEmail = $emailRow['SettingValue'];
        }
    }

    // Build email content
    $subject = "[{$branding['CompanyName']}] Auto Clock-Out Report - " . date('F j, Y');
    $message = "<html><body style='font-family: Arial, sans-serif;'>";
    $message .= "<h2 style='color: {$branding['PrimaryColor']};'>Auto Clock-Out Report</h2>";
    $message .= "<p>The following employees were automatically clocked out because they forgot to clock out:</p>";
    $message .= "<table style='border-collapse: collapse; width: 100%;'>";
    $message .= "<thead><tr style='background-color: {$branding['PrimaryColor']}; color: white;'>";
    $message .= "<th style='border: 1px solid #ddd; padding: 12px;'>Employee</th>";
    $message .= "<th style='border: 1px solid #ddd; padding: 12px;'>Shift</th>";
    $message .= "<th style='border: 1px solid #ddd; padding: 12px;'>Shift End</th>";
    $message .= "<th style='border: 1px solid #ddd; padding: 12px;'>Auto Clock-Out</th>";
    $message .= "<th style='border: 1px solid #ddd; padding: 12px;'>Total Hours</th>";
    $message .= "</tr></thead><tbody>";

    foreach ($autoClockOutLogs as $log) {
        $message .= "<tr>";
        $message .= "<td style='border: 1px solid #ddd; padding: 8px;'>{$log['name']}</td>";
        $message .= "<td style='border: 1px solid #ddd; padding: 8px;'>{$log['shift']}</td>";
        $message .= "<td style='border: 1px solid #ddd; padding: 8px;'>{$log['shift_end']}</td>";
        $message .= "<td style='border: 1px solid #ddd; padding: 8px;'>{$log['clock_out_time']}</td>";
        $message .= "<td style='border: 1px solid #ddd; padding: 8px;'>{$log['hours']} hrs</td>";
        $message .= "</tr>";
    }

    $message .= "</tbody></table>";
    $message .= "<p style='margin-top: 20px; font-size: 0.9em; color: #666;'>";
    $message .= "This is an automated message from the {$branding['CompanyName']} time tracking system. ";
    $message .= "Users are automatically clocked out 1 hour after their shift ends if they forget to clock out.";
    $message .= "</p>";
    $message .= getEmailSignature($branding);
    $message .= "</body></html>";

    // Send email (using mail() function - should be replaced with PHPMailer if SMTP is configured)
    $headers = "From: {$branding['CompanyName']} <noreply@{$_SERVER['SERVER_NAME']}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    if (@mail($adminEmail, $subject, $message, $headers)) {
        echo "✓ Email sent to: $adminEmail\n";

        // Update notification sent flag
        $conn->query("UPDATE auto_clockout_log SET NotificationSent = 1 WHERE ProcessedAt >= CURDATE()");
    } else {
        echo "✗ Failed to send email\n";
    }
}

echo "\n===========================================\n";
echo "Auto Clock-Out Complete\n";
echo "===========================================\n";
echo "Successfully processed: $processedCount\n";
echo "Errors: $errorCount\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
echo "===========================================\n";

// Get output for logging
$output = ob_get_clean();

// Display output
echo $output;

// Log to file
$logFile = __DIR__ . '/../logs/auto_clockout.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

file_put_contents($logFile, $output, FILE_APPEND);

// Close database connection
$conn->close();

exit(0);
?>
