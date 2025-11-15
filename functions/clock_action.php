<?php
// For debugging: uncomment the following lines to display errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// clock_action.php
// --- SETUP AND DEPENDENCIES ---
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../vendor/autoload.php'; // For PHPMailer
require_once __DIR__ . '/settings_helper.php'; // Use the new helper for settings
date_default_timezone_set('America/Chicago');
header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Encryption/Decryption Functions ---
// For demonstration, a simple key. In production, this should be a strong, securely stored key.
define('ENCRYPTION_KEY', 'a_very_secret_key_for_encryption_32_chars'); // 32 bytes for AES-256
define('CIPHER_METHOD', 'aes-256-cbc');

function encrypt_data($data) {
    $iv_len = openssl_cipher_iv_length(CIPHER_METHOD);
    $iv = openssl_random_pseudo_bytes($iv_len);
    $encrypted = openssl_encrypt($data, CIPHER_METHOD, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decrypt_data($data) {
    $parts = explode('::', base64_decode($data), 2);
    if (count($parts) === 2) {
        list($encrypted_data, $iv) = $parts;
        return openssl_decrypt($encrypted_data, CIPHER_METHOD, ENCRYPTION_KEY, 0, $iv);
    }
    return false;
}
// --- End Encryption/Decryption Functions ---


/**
 * Sends an email notification for a time adjustment.
 *
 * @param int $employeeID The ID of the employee whose time was adjusted.
 * @param string $oldTime The original time.
 * @param string $newTime The new adjusted time.
 * @param string $note The note/reason for the adjustment.
 * @param mysqli $conn The database connection.
 * @return bool True on success, false on failure.
 */
function sendAdjustmentEmail($employeeID, $oldTime, $newTime, $note, $conn) {
    try {
        // Fetch mail settings from the database using the helper function
        $mailSettings = [];
        $mailSettingKeys = ['mail_server', 'mail_port', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name', 'mail_encryption', 'mail_admin_address'];
        foreach ($mailSettingKeys as $key) {
            $mailSettings[$key] = getSettingValue($key, $conn);
        }

        // Check if essential mail server settings are configured
        if (empty($mailSettings['mail_server']) || empty($mailSettings['mail_username']) || empty($mailSettings['mail_from_address'])) {
            error_log("Skipping email notification: Essential mail server settings are not configured.");
            return true; // Indicate success as email sending was intentionally skipped
        }

        // Decrypt password
        $mailSettings['mail_password'] = decrypt_data($mailSettings['mail_password']);
        if ($mailSettings['mail_password'] === false) {
            error_log("Failed to decrypt mail password.");
            return false;
        }

        $employeeName = 'Unknown Employee';
        $stmt = $conn->prepare("SELECT FirstName, LastName FROM users WHERE ID = ?");
        if ($stmt) {
            $stmt->bind_param("i", $employeeID);
            $stmt->execute();
            $stmt->bind_result($firstName, $lastName);
            if ($stmt->fetch()) {
                $employeeName = $firstName . ' ' . $lastName;
            }
            $stmt->close();
        }

        $mail = new PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host       = $mailSettings['mail_server'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailSettings['mail_username'];
        $mail->Password   = $mailSettings['mail_password'];
        $mail->SMTPSecure = $mailSettings['mail_encryption'];
        $mail->Port       = $mailSettings['mail_port'];

        // Recipients
        $mail->setFrom($mailSettings['mail_from_address'], $mailSettings['mail_from_name']);
        $mail->addAddress($mailSettings['mail_admin_address']);

        // Format times for email
        $oldTimeFormatted = date('g:i A', strtotime($oldTime));
        $newTimeFormatted = date('g:i A', strtotime($newTime));

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Time Adjustment Notification';
        $mail->Body    = "
            <h2>Time Adjustment Notification</h2>
            <p>Employee: {$employeeName} (ID: {$employeeID})</p>
            <p>Original Time: {$oldTimeFormatted}</p>
            <p>Adjusted Time: {$newTimeFormatted}</p>
            <p>Note: {$note}</p>
            <p>Adjusted by: " . ($_SESSION['admin_username'] ?? 'System/User') . "</p>
            <p>Date of Adjustment: " . date('Y-m-d H:i:s') . "</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Gets the real client IP address, accounting for proxies.
 * @return string|null The client's IP address or null if not found.
 */
function get_client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    }
    return $ip ? trim($ip) : null;
}

/**
 * Sends a JSON response and exits.
 * @param bool $success Whether the operation was successful.
 * @param string $message The message to send to the client.
 * @param int $http_code The HTTP status code to send.
 * @param string|null $log_error An optional internal error message to log.
 */
function send_json_response($success, $message, $http_code = 200, $log_error = null, $data = []) {
    if ($log_error !== null) {
        error_log('TimeClock API Error: ' . $log_error);
    }
    http_response_code($http_code);
    $response = ['success' => $success, 'message' => $message];
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response);
    exit;
}

/**
 * Updates the user's clock status.
 * @param mysqli $conn The database connection object.
 * @param int $empID The ID of the employee to update.
 * @param string $status The new status ('In', 'Out', 'Lunch').
 */
function setClockStatus($conn, $empID, $status) {
    $stmt = $conn->prepare("UPDATE users SET ClockStatus = ? WHERE ID = ?");
    if ($stmt === false) { send_json_response(false, "DB prepare error (setClockStatus)", 500, $conn->error); }
    $stmt->bind_param("si", $status, $empID);
    if (!$stmt->execute()) {
        send_json_response(false, "DB execute error (setClockStatus)", 500, $stmt->error);
    }
    $stmt->close();
}

/**
 * Calculates total work hours, excluding lunch.
 * @param string|null $clockIn
 * @param string|null $lunchOut
 * @param string|null $lunchIn
 * @param string|null $clockOut
 * @return float|null
 */
function calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut) {
    if (empty($clockIn) || empty($clockOut)) { return null; }
    
    $start = strtotime($clockIn);
    $end = strtotime($clockOut);
    if ($end <= $start) { return 0.0; } // Return 0 if end time is before start time

    $totalSeconds = ($end - $start);

    if (!empty($lunchOut) && !empty($lunchIn)) {
        $lStart = strtotime($lunchOut);
        $lEnd = strtotime($lunchIn);
        if ($lEnd > $lStart) {
            $totalSeconds -= ($lEnd - $lStart);
        }
    }
    return round($totalSeconds / 3600, 2);
}

/**
 * Logs a pending edit when a punch time is adjusted.
 *
 * @param mysqli      $conn      Database connection
 * @param int         $empID     Employee ID
 * @param string      $date      Date of the punch
 * @param string      $field     Column to adjust (TimeIN, TimeOut, etc.)
 * @param string|null $newTime   Adjusted time (H:i:s) or null
 * @param string      $note      Optional note from user
 * @return bool                  True if pending edit inserted
 */
function logPendingEdit($conn, $empID, $date, $field, $newTime, $note) {
    if (!$newTime) { return false; }

    $reason = $note ?: 'Time adjustment requested at punch';
    $sql = "INSERT INTO pending_edits (EmployeeID, Date, Note, Reason, Status, SubmittedAt, $field) VALUES (?, ?, ?, ?, 'Pending', NOW(), ? )";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return false; }
    $stmt->bind_param('issss', $empID, $date, $note, $reason, $newTime);
    $stmt->execute();
    $stmt->close();
    return true;
}

// --- Check TagID Status (kiosk_status) ---
if (!empty($_POST['mode']) && $_POST['mode'] === 'kiosk_status' && !empty($_POST['TagID'])) {
    $tagID = trim($_POST['TagID']);

    // Lookup user
    $stmt = $conn->prepare("SELECT ID, FirstName FROM users WHERE TagID = ?");
    if (!$stmt) send_json_response(false, "DB prepare error", 500, $conn->error);
    $stmt->bind_param("s", $tagID);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($empID, $firstName);
    if (!$stmt->fetch()) {
        send_json_response(false, "❌ Tag not recognized.");
    }
    $stmt->close();

    // Determine current punch status
    $punch = $conn->prepare("SELECT TimeIN, LunchStart, LunchEnd, TimeOUT FROM timepunches WHERE EmployeeID = ? ORDER BY Date DESC, TimeIN DESC LIMIT 1");
    $punch->bind_param("i", $empID);
    $punch->execute();
    $punch->store_result();
    $status = 'Out';
    $action = 'clockin';
    $actionText = 'Clock In';

    if ($punch->fetch() && empty($timeOut)) {
        if (empty($lunchStart)) {
            $status = 'In';
            $action = 'lunchstart';
            $actionText = 'Start Lunch';
        } elseif (empty($lunchEnd)) {
            $status = 'Lunch';
            $action = 'lunchend';
            $actionText = 'End Lunch';
        } else {
            $status = 'In';
            $action = 'clockout';
            $actionText = 'Clock Out';
        }
    }

    $punch->close();

    echo json_encode([
        'success' => true,
        'firstName' => $firstName,
        'status' => $status,
        'action' => $action,
        'actionText' => $actionText
    ]);
    exit;
}

// --- Kiosk Mode Support ---
if (!empty($_POST['mode']) && $_POST['mode'] === 'kiosk' && !empty($_POST['TagID'])) {
    $tagID = trim($_POST['TagID']);
    $now   = date("H:i:s");
    $date  = date("Y-m-d");
    $ip    = get_client_ip();

    // Lookup user by tag
    $stmt = $conn->prepare("SELECT ID, FirstName FROM users WHERE TagID = ?");
    if (!$stmt) send_json_response(false, "DB prepare error (kiosk lookup)", 500, $conn->error);
    $stmt->bind_param("s", $tagID);
    if (!$stmt->execute()) send_json_response(false, "DB execute error (kiosk lookup)", 500, $stmt->error);
    $stmt->store_result();
    $stmt->bind_result($empID, $firstName);
    if (!$stmt->fetch()) {
        send_json_response(false, "❌ Tag not recognized.", 404);
    }
    $stmt->close();

    // Get current open punch
    $punch = $conn->prepare("SELECT ID, TimeIN, LunchStart, LunchEnd FROM timepunches WHERE EmployeeID = ? AND TimeOUT IS NULL ORDER BY Date DESC, TimeIN DESC LIMIT 1");
    $punch->bind_param("i", $empID);
    $punch->execute();
    $punch->store_result();

    $status = 'new'; // default

    if ($punch->num_rows > 0) {
        $punch->bind_result($punchID, $timeIn, $lunchStart, $lunchEnd);
        $punch->fetch();

        if (empty($lunchStart)) {
            $status = 'lunchstart';
        } elseif (empty($lunchEnd)) {
            $status = 'lunchend';
        } else {
            $status = 'clockout';
        }
    } else {
        $status = 'clockin';
    }
    $punch->close();

    // Execute the appropriate action
    switch ($status) {
        case 'clockin':
            $stmt = $conn->prepare("INSERT INTO timepunches (EmployeeID, Date, TimeIN, IPAddressIN) VALUES (?, ?, ?, INET_ATON(?))");
            $stmt->bind_param("isss", $empID, $date, $now, $ip);
            if (!$stmt->execute()) send_json_response(false, "DB error (clockin)", 500, $stmt->error);
            $stmt->close();
            setClockStatus($conn, $empID, 'In');
            echo json_encode([
                'success' => true,
                'firstName' => $firstName,
                'message' => "✅ Clocked in at " . date("g:i A", strtotime($now))
            ]);
            exit;

        case 'lunchstart':
            $stmt = $conn->prepare("UPDATE timepunches SET LunchStart = ?, IPAddressLunchStart = INET_ATON(?) WHERE ID = ?");
            $stmt->bind_param("ssi", $now, $ip, $punchID);
            if (!$stmt->execute()) send_json_response(false, "DB error (lunchstart)", 500, $stmt->error);
            $stmt->close();
            setClockStatus($conn, $empID, 'Lunch');
            echo json_encode([
                'success' => true,
                'firstName' => $firstName,
                'message' => "🍽️ Lunch started at " . date("g:i A", strtotime($now))
            ]);
            exit;

        case 'lunchend':
            $stmt = $conn->prepare("UPDATE timepunches SET LunchEnd = ?, IPAddressLunchEnd = INET_ATON(?) WHERE ID = ?");
            $stmt->bind_param("ssi", $now, $ip, $punchID);
            if (!$stmt->execute()) send_json_response(false, "DB error (lunchend)", 500, $stmt->error);
            $stmt->close();
            setClockStatus($conn, $empID, 'In');
            echo json_encode([
                'success' => true,
                'firstName' => $firstName,
                'message' => "✅ Lunch ended at " . date("g:i A", strtotime($now))
            ]);
            exit;

        case 'clockout':
            // Auto close any open lunch if they forgot to end it
            if (!empty($lunchStart) && empty($lunchEnd)) {
                $lunchEnd = $now;
            }

            $total = calculateTotalHours($timeIn, $lunchStart, $lunchEnd, $now);

            $stmt = $conn->prepare("UPDATE timepunches SET TimeOUT = ?, LunchEnd = ?, TotalHours = ?, IPAddressOut = INET_ATON(?) WHERE ID = ?");
            $stmt->bind_param("ssdsi", $now, $lunchEnd, $total, $ip, $punchID);
            if (!$stmt->execute()) send_json_response(false, "DB error (clockout)", 500, $stmt->error);
            $stmt->close();
            setClockStatus($conn, $empID, 'Out');
            echo json_encode([
                'success' => true,
                'firstName' => $firstName,
                'message' => "🕔 Clocked out at " . date("g:i A", strtotime($now)) . ". Worked: " . number_format($total, 2) . " hrs"
            ]);
            exit;

        default:
            send_json_response(false, "Unknown punch state", 500);
    }
}

// --- Input Processing ---
$input = json_decode(file_get_contents('php://input'), true);

$empID        = (int) ($input['EmployeeID'] ?? 0);
$action       = $input['action'] ?? '';
$note         = trim($input['note'] ?? '');
$adjustedTime = $input['time'] ?? '';
$clientTime   = $input['clientTime'] ?? '';

$lat      = (isset($input['latitude']) && $input['latitude'] !== '') ? (float) $input['latitude'] : null;
$lon      = (isset($input['longitude']) && $input['longitude'] !== '') ? (float) $input['longitude'] : null;
$accuracy = (isset($input['accuracy']) && $input['accuracy'] !== '') ? (float) $input['accuracy'] : null;
$ip       = get_client_ip();

// --- Validation ---
if (!$empID || !$action) {
    send_json_response(false, "❌ Missing employee ID or action.", 400);
}


// Fetch GPS requirement from settings
$gpsRequired = false;
$gpsQuery = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = 'EnforceGPS' LIMIT 1");
if ($gpsQuery) {
    $gpsQuery->execute();
    $gpsQuery->bind_result($value);
    if ($gpsQuery->fetch()) {
        $gpsRequired = ($value === '1');
    }
    $gpsQuery->close();
}

// If GPS is required, we must have EITHER valid GPS coordinates OR a valid IP address.
if ($gpsRequired && ($lat === null || $lon === null) && $ip === null) {
    send_json_response(false, "📍 Location is required, but neither GPS nor IP address could be determined.", 400);
}

// --- Time Calculation ---
try {
    // Always base the punch on the actual client/server time

    if ($clientTime) {
        $dateTime = new DateTime($clientTime);
        $dateTime->setTimezone(new DateTimeZone('America/Chicago'));
    } else {
        $dateTime = new DateTime('now', new DateTimeZone('America/Chicago'));
    }
    $now  = $dateTime->format('H:i:s');
    $date = $dateTime->format('Y-m-d');
} catch (Exception $e) {
    send_json_response(false, "Invalid time format provided.", 400, $e->getMessage());
}

// Parse adjusted time separately for pending approval
$adjustedTimeFormatted = null;
if ($adjustedTime) {
    $formats = ['g:i A', 'g:i a', 'H:i', 'H:i:s'];
    $adjDate = null;
    foreach ($formats as $fmt) {
        $adjDate = DateTime::createFromFormat($fmt, $adjustedTime, new DateTimeZone('America/Chicago'));
        if ($adjDate !== false) { break; }
    }
    if ($adjDate) {
        $adjustedTimeFormatted = $adjDate->format('H:i:s');
    } else {
        send_json_response(false, "Invalid adjusted time format provided.", 400);
    }
}

$pendingTime = ($adjustedTimeFormatted && $adjustedTimeFormatted !== $now) ? $adjustedTimeFormatted : null;

// --- Get User Status & Latest Punch ---

// Get current user status
$userStmt = $conn->prepare("SELECT ClockStatus FROM users WHERE ID = ?");
if ($userStmt === false) { send_json_response(false, "DB prepare error (user)", 500, $conn->error); }
$userStmt->bind_param("i", $empID);
if (!$userStmt->execute()) { send_json_response(false, "DB execute error (user)", 500, $userStmt->error); }
$userStmt->store_result();
$userStmt->bind_result($userStatus);
if (!$userStmt->fetch()) {
    send_json_response(false, "Employee with ID " . $empID . " not found.", 404);
}
$userStatus = $userStatus ?? 'Out'; // Default to 'Out' if ClockStatus is NULL
$userStmt->close();


// Find the last open punch record for the user to handle any ongoing shift (e.g., overnight)
$punchStmt = $conn->prepare("SELECT TimeIN, LunchStart, LunchEnd FROM timepunches WHERE EmployeeID = ? AND TimeOUT IS NULL ORDER BY Date DESC, TimeIN DESC LIMIT 1");
if ($punchStmt === false) { send_json_response(false, "DB prepare error (get open punch)", 500, $conn->error); }
$punchStmt->bind_param("i", $empID);
if (!$punchStmt->execute()) { send_json_response(false, "DB execute error (get open punch)", 500, $punchStmt->error); }
$punchStmt->store_result();

$lastPunch = null;
if ($punchStmt->num_rows > 0) {
    $punchStmt->bind_result($timeIn, $lunchStart, $lunchEnd);
    $punchStmt->fetch();
    $lastPunch = [
        'TimeIN' => $timeIn,
        'LunchStart' => $lunchStart,
        'LunchEnd' => $lunchEnd
    ];
}
$punchStmt->close();


// --- Handle Actions ---
switch ($action) {
    case "clockin":
        if ($lastPunch) {
            $clockInTime = date("g:i A", strtotime($lastPunch['TimeIN']));
            send_json_response(false, "⚠️ You are already clocked in from " . $clockInTime . ". Please clock out first.", 409); // 409 Conflict
        }
        $stmt = $conn->prepare("INSERT INTO timepunches (EmployeeID, Date, TimeIN, Note, LatitudeIN, LongitudeIN, AccuracyIN, IPAddressIN) VALUES (?, ?, ?, ?, ?, ?, ?, INET_ATON(?))");
        $stmt->bind_param("isssddds", $empID, $date, $now, $note, $lat, $lon, $accuracy, $ip);
        if (!$stmt->execute()) {
            send_json_response(false, "DB execute error (clockin)", 500, $stmt->error);
        }
        setClockStatus($conn, $empID, 'In');
        $msg = "✅ Clocked in at " . date("g:i A", strtotime($now));
        if (logPendingEdit($conn, $empID, $date, 'TimeIN', $pendingTime, $note)) {
            $msg .= " (submitted for approval)";
            if ($pendingTime !== null) {
                sendAdjustmentEmail($empID, $now, $pendingTime, $note, $conn);
            }
        }
        send_json_response(true, $msg);
        break;

    case "lunchstart":
        if ($userStatus !== 'In') { send_json_response(false, "⚠️ You must be clocked in to start lunch.", 400); }
        if (!$lastPunch || !empty($lastPunch['LunchStart'])) { send_json_response(false, "⚠️ No active punch, or lunch already started.", 400); }
        
        if (!empty($note)) {
            $stmt = $conn->prepare("UPDATE timepunches SET LunchStart = ?, Note = CONCAT(Note, ?), LatitudeLunchStart = ?, LongitudeLunchStart = ?, AccuracyLunchStart = ?, IPAddressLunchStart = INET_ATON(?) WHERE EmployeeID = ? AND TimeOUT IS NULL");
            $full_note = "\nLunch Start Note: " . $note;
            $stmt->bind_param("ssdddsi", $now, $full_note, $lat, $lon, $accuracy, $ip, $empID);
        } else {
            $stmt = $conn->prepare("UPDATE timepunches SET LunchStart = ?, LatitudeLunchStart = ?, LongitudeLunchStart = ?, AccuracyLunchStart = ?, IPAddressLunchStart = INET_ATON(?) WHERE EmployeeID = ? AND TimeOUT IS NULL");
            $stmt->bind_param("sdddsi", $now, $lat, $lon, $accuracy, $ip, $empID);
        }

        if (!$stmt->execute()) {
            send_json_response(false, "DB execute error (lunchstart)", 500, $stmt->error);
        }

        setClockStatus($conn, $empID, 'Lunch');
        $msg = "🍽️ Lunch started at " . date("g:i A", strtotime($now));
        if (logPendingEdit($conn, $empID, $date, 'LunchStart', $pendingTime, $note)) {
            $msg .= " (submitted for approval)";
            if ($pendingTime !== null) {
                sendAdjustmentEmail($empID, $now, $pendingTime, $note, $conn);
            }
        }
        send_json_response(true, $msg);
        break;

    case "lunchend":
        if ($userStatus !== 'Lunch') { send_json_response(false, "⚠️ You are not on lunch.", 400); }
        if (!$lastPunch || empty($lastPunch['LunchStart']) || !empty($lastPunch['LunchEnd'])) { send_json_response(false, "⚠️ No active lunch punch found.", 400); }

        if (!empty($note)) {
            $stmt = $conn->prepare("UPDATE timepunches SET LunchEnd = ?, Note = CONCAT(Note, ?), LatitudeLunchEnd = ?, LongitudeLunchEnd = ?, AccuracyLunchEnd = ?, IPAddressLunchEnd = INET_ATON(?) WHERE EmployeeID = ? AND TimeOUT IS NULL");
            $full_note = "\nLunch End Note: " . $note;
            $stmt->bind_param("ssdddsi", $now, $full_note, $lat, $lon, $accuracy, $ip, $empID);
        } else {
            $stmt = $conn->prepare("UPDATE timepunches SET LunchEnd = ?, LatitudeLunchEnd = ?, LongitudeLunchEnd = ?, AccuracyLunchEnd = ?, IPAddressLunchEnd = INET_ATON(?) WHERE EmployeeID = ? AND TimeOUT IS NULL");
            $stmt->bind_param("sdddsi", $now, $lat, $lon, $accuracy, $ip, $empID);
        }
        
        if (!$stmt->execute()) { send_json_response(false, "DB execute error (lunchend)", 500, $stmt->error); }

        setClockStatus($conn, $empID, 'In');
        $msg = "✅ Lunch ended at " . date("g:i A", strtotime($now));
        if (logPendingEdit($conn, $empID, $date, 'LunchEnd', $pendingTime, $note)) {
            $msg .= " (submitted for approval)";
            if ($pendingTime !== null) {
                sendAdjustmentEmail($empID, $now, $pendingTime, $note, $conn);
            }
        }
        send_json_response(true, $msg);
        break;

    case "clockout":
        if (!$lastPunch) {
            // No open punch – create a zero-duration entry so the attempt is recorded
            $stmt = $conn->prepare("INSERT INTO timepunches (EmployeeID, Date, TimeIN, TimeOUT, TotalHours, IPAddressIN, IPAddressOut) VALUES (?, ?, ?, ?, 0, INET_ATON(?), INET_ATON(?))");
            if ($stmt === false) { send_json_response(false, "DB prepare error (clockout-new)", 500, $conn->error); }
            $stmt->bind_param("isssss", $empID, $date, $now, $now, $ip, $ip);
            if (!$stmt->execute()) { send_json_response(false, "DB execute error (clockout-new)", 500, $stmt->error); }
            $stmt->close();

            setClockStatus($conn, $empID, 'Out');
            send_json_response(true, "🕔 Clocked out at " . date("g:i A", strtotime($now)) . " (new punch created)");
        }

        // Ensure lunch is properly ended if user clocks out while on lunch
        if ($userStatus === 'Lunch' && empty($lastPunch['LunchEnd'])) {
            $lastPunch['LunchEnd'] = $now;
        }

        $totalHours = calculateTotalHours($lastPunch['TimeIN'], $lastPunch['LunchStart'], $lastPunch['LunchEnd'], $now);

        if (!empty($note)) {
            $stmt = $conn->prepare("UPDATE timepunches SET TimeOUT = ?, LunchEnd = ?, TotalHours = ?, Note = CONCAT(Note, ?), LatitudeOut = ?, LongitudeOut = ?, AccuracyOut = ?, IPAddressOut = INET_ATON(?) WHERE EmployeeID = ? AND TimeOUT IS NULL");
            $full_note = "\nClock Out Note: " . $note;
            $stmt->bind_param("ssdsdddsi", $now, $lastPunch['LunchEnd'], $totalHours, $full_note, $lat, $lon, $accuracy, $ip, $empID);
        } else {
            $stmt = $conn->prepare("UPDATE timepunches SET TimeOUT = ?, LunchEnd = ?, TotalHours = ?, LatitudeOut = ?, LongitudeOut = ?, AccuracyOut = ?, IPAddressOut = INET_ATON(?) WHERE EmployeeID = ? AND TimeOUT IS NULL");
            $stmt->bind_param("ssdddssi", $now, $lastPunch['LunchEnd'], $totalHours, $lat, $lon, $accuracy, $ip, $empID);
        }

        if (!$stmt->execute()) {
            send_json_response(false, "DB execute error (clockout)", 500, $stmt->error);
        }

        setClockStatus($conn, $empID, 'Out');
        $message = "🕔 Clocked out at " . date("g:i A", strtotime($now));
        if (logPendingEdit($conn, $empID, $date, 'TimeOut', $pendingTime, $note)) {
            $message .= " (submitted for approval)";
            if ($pendingTime !== null) {
                sendAdjustmentEmail($empID, $now, $pendingTime, $note, $conn);
            }
        }
        send_json_response(true, $message, 200, null, ['hoursWorked' => number_format($totalHours, 2)]);
        break;

    default:
        send_json_response(false, "Unknown punch state", 500);
        break;
}

