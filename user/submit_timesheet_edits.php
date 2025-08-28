<?php
session_start();
require '../auth/db.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['EmployeeID'])) {
    header("Location: login.php");
    exit;
}

$sessionEmpID = $_SESSION['EmployeeID'];
$postEmpID = $_POST['EmployeeID'] ?? null;
$entries = $_POST['entries'] ?? [];

if ($sessionEmpID != $postEmpID || empty($entries)) {
    header('Location: ../error.php?code=400&message=' . urlencode('Invalid submission.'));
    exit;
}

$inserted = 0;

foreach ($entries as $entry) {
    $date = $entry['Date'];

    // Fetch original punch row
    $stmt = $conn->prepare("SELECT * FROM timepunches WHERE EmployeeID = ? AND Date = ?");
    $stmt->bind_param("is", $sessionEmpID, $date);
    $stmt->execute();
    $original = $stmt->get_result()->fetch_assoc();

    if (!$original) continue;

    $fields = ['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOut'];
    $changes = [];

    foreach ($fields as $field) {
        $new = trim($entry[$field] ?? '');
        $old = $original[$field];

        if ($new !== $old && $new !== '') {
            $changes[$field] = $new;
        }
    }

    if (!empty($changes)) {
        $note = trim($entry['Note'] ?? '');
        $reason = trim($entry['Reason'] ?? '');

        if ($reason === '') continue; // Skip if reason not provided

        // Check for existing pending edit on same date
        $check = $conn->prepare("SELECT ID FROM pending_edits WHERE EmployeeID = ? AND Date = ? AND Status = 'Pending'");
        $check->bind_param("is", $sessionEmpID, $date);
        $check->execute();
        if ($check->get_result()->num_rows > 0) continue;

        // Prepare insert with only changed fields
        $columns = ['EmployeeID', 'Date', 'Note', 'Reason', 'Status', 'SubmittedAt'];
        $values = [$sessionEmpID, $date, $note, $reason, 'Pending', date('Y-m-d H:i:s')];
        $placeholders = ['?', '?', '?', '?', '?', '?'];
        $types = 'isssss';

        foreach (['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOut'] as $field) {
            if (isset($changes[$field])) {
                $columns[] = $field;
                $values[] = $changes[$field];
                $placeholders[] = '?';
                $types .= 's';
            }
        }

        $sql = "INSERT INTO pending_edits (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();

        $inserted++;
    }
}

header("Location: timesheet.php?status=" . ($inserted ? "submitted" : "nochange"));
exit;
?>