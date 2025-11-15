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

if ($inserted > 0) {
    // Email sending logic
    $emailStatus = 'not_attempted'; // Default status

    require_once '../vendor/autoload.php';

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

    // Fetch mail settings from database
    $mailSettings = [];
    $result = $conn->query("SELECT SettingKey, SettingValue FROM settings WHERE SettingKey LIKE 'mail_%'");
    while ($row = $result->fetch_assoc()) {
        $mailSettings[$row['SettingKey']] = $row['SettingValue'];
    }

    if (
        isset($mailSettings['mail_server']) &&
        isset($mailSettings['mail_port']) &&
        isset($mailSettings['mail_username']) &&
        isset($mailSettings['mail_password']) &&
        isset($mailSettings['mail_from_address']) &&
        isset($mailSettings['mail_from_name']) &&
        isset($mailSettings['mail_encryption']) &&
        isset($mailSettings['mail_admin_address'])
    ) {
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->isSMTP();
            $mail->Host       = $mailSettings['mail_server'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailSettings['mail_username'];
            $mail->Password   = $mailSettings['mail_password'];
            $mail->SMTPSecure = $mailSettings['mail_encryption'];
            $mail->Port       = $mailSettings['mail_port'];

            //Recipients
            $mail->setFrom($mailSettings['mail_from_address'], $mailSettings['mail_from_name']);
            $mail->addAddress($mailSettings['mail_admin_address']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Time Adjustment Request Submitted';
            $mail->Body    = "A new time adjustment request has been submitted by Employee ID: {$sessionEmpID} for the following dates:<br><br>";
            foreach ($entries as $entry) {
                $mail->Body .= "Date: " . htmlspecialchars($entry['Date']) . "<br>";
                $mail->Body .= "Reason: " . htmlspecialchars($entry['Reason']) . "<br><br>";
            }
            $mail->Body .= "Please review the pending edits in the admin panel.";

            $mail->send();
            $emailStatus = 'sent';
        } catch (Exception $e) {
            error_log("Mailer Error: " . $mail->ErrorInfo);
            $emailStatus = 'error:' . $mail->ErrorInfo;
        }
    } else {
        error_log("Mail settings are incomplete. Email not sent.");
        $emailStatus = 'error:incomplete_settings';
    }
}

header("Location: timesheet.php?status=" . ($inserted ? "submitted" : "nochange") . "&email_status=" . urlencode($emailStatus));
exit;
?>