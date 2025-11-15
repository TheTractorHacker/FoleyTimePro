<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('America/Chicago');

// Helper to calculate total hours
function calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut) {
    if (!$clockIn || !$clockOut) return null;

    $start = strtotime($clockIn);
    $end = strtotime($clockOut);
    if ($end <= $start) return null;

    $total = ($end - $start) / 3600;

    if ($lunchOut && $lunchIn) {
        $lStart = strtotime($lunchOut);
        $lEnd = strtotime($lunchIn);
        if ($lEnd > $lStart) {
            $total -= ($lEnd - $lStart) / 3600;
        }
    }

    return round($total, 2);
}

// ADD PUNCH
if (isset($_POST['add'])) {
    $employeeID = intval($_POST['employeeID']);
    $date = $_POST['date'];
    $clockIn = $_POST['clockin'] ?: null;
    $lunchOut = $_POST['lunchout'] ?: null;
    $lunchIn = $_POST['lunchin'] ?: null;
    $clockOut = $_POST['clockout'] ?: null;
    $reason = trim($_POST['reason'] ?? '');
    $totalHours = calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut);

    $insertStmt = $conn->prepare("INSERT INTO timepunches (EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, Note, TotalHours) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $insertStmt->bind_param("issssssd", $employeeID, $date, $clockIn, $lunchOut, $lunchIn, $clockOut, $reason, $totalHours);
    $insertStmt->execute();

    $logStmt = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $adminUser = $_SESSION['admin'];
    $logStmt->bind_param("issssss", $employeeID, $date, $adminUser, 'ALL', 'NONE', 'CREATED', $reason);
    $logStmt->execute();

    header("Location: view_punches.php?emp=" . urlencode($employeeID) . "&from=" . urlencode($_POST['from']) . "&to=" . urlencode($_POST['to']) . "&success=1");
    exit;
}

// DELETE PUNCH
if (isset($_POST['delete'])) {
    $punchId = intval($_POST['delete'][0]);
    $employeeID = intval($_POST['employeeID']);

    $checkStmt = $conn->prepare("SELECT * FROM timepunches WHERE id = ?");
    $checkStmt->bind_param("i", $punchId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $existing = $result->fetch_assoc();

    if ($existing) {
        $date = $existing['Date'];

        $deleteStmt = $conn->prepare("DELETE FROM timepunches WHERE id = ?");
        $deleteStmt->bind_param("i", $punchId);
        $deleteStmt->execute();

        $logStmt = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $adminUser = $_SESSION['admin'];
        $logStmt->bind_param("issssss", $employeeID, $date, $adminUser, 'ALL', 'DELETED', 'NONE', 'Punch deleted');
        $logStmt->execute();
    }

    header("Location: view_punches.php?emp=" . urlencode($employeeID) . "&from=" . urlencode($_POST['from']) . "&to=" . urlencode($_POST['to']) . "&success=1");
    exit;
}


// Validate input
if (
    !isset($_POST['employeeID'], $_POST['from'], $_POST['to'], $_POST['confirm']) ||
    !is_array($_POST['confirm'])
) {
    header("Location: view_punches.php?success=0&error=missing_fields");
    exit;
}

$employeeID = intval($_POST['employeeID']);
$from = $_POST['from'];
$to = $_POST['to'];
$confirmPunchIDs = $_POST['confirm'];

try {
    foreach ($confirmPunchIDs as $punchId) {
        $punchId = intval($punchId);
        $clockIn = $_POST['clockin'][$punchId] ?? null;
        $lunchOut = $_POST['lunchout'][$punchId] ?? null;
        $lunchIn = $_POST['lunchin'][$punchId] ?? null;
        $clockOut = $_POST['clockout'][$punchId] ?? null;
        $reason = trim($_POST['reason'][$punchId] ?? '');

        $clockIn = $clockIn ?: null;
        $lunchOut = $lunchOut ?: null;
        $lunchIn = $lunchIn ?: null;
        $clockOut = $clockOut ?: null;
        $reason = $reason ?: null;
        $totalHours = calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut);

        // Check for existing entry
        $checkStmt = $conn->prepare("SELECT * FROM timepunches WHERE id = ?");
        $checkStmt->bind_param("i", $punchId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $existing = $result->fetch_assoc();

        if ($existing) {
            $date = $existing['Date'];
            // Log changes
            $fields = [
                "TimeIN" => $clockIn,
                "LunchStart" => $lunchOut,
                "LunchEnd" => $lunchIn,
                "TimeOut" => $clockOut,
                "Note" => $reason,
                "TotalHours" => $totalHours
            ];

            foreach ($fields as $field => $newVal) {
                $oldVal = $existing[$field] ?? null;
                if ($newVal != $oldVal) {
                    $logStmt = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $adminUser = $_SESSION['admin'];
                    $logStmt->bind_param("issssss", $employeeID, $date, $adminUser, $field, $oldVal, $newVal, $reason);
                    $logStmt->execute();
                }
            }

            // Update
            $updateStmt = $conn->prepare("
                UPDATE timepunches 
                SET TimeIN = ?, LunchStart = ?, LunchEnd = ?, TimeOut = ?, Note = ?, TotalHours = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param("sssssdi", $clockIn, $lunchOut, $lunchIn, $clockOut, $reason, $totalHours, $punchId);
            $updateStmt->execute();

        }
    }

    // Success
    header("Location: view_punches.php?emp=" . urlencode($employeeID) . "&from=" . urlencode($from) . "&to=" . urlencode($to) . "&success=1");
    exit;

} catch (Exception $e) {
    // Log or debug as needed
    header("Location: view_punches.php?emp=" . urlencode($employeeID) . "&from=" . urlencode($from) . "&to=" . urlencode($to) . "&success=0&error=exception");
    exit;
}