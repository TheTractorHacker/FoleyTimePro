<?php
date_default_timezone_set('America/Chicago'); // or your desired timezone

require_once __DIR__ . '/../auth/db.php';

$action = $_POST['action'] ?? null;
$empID = $_POST['EmployeeID'] ?? null;
$pass = $_POST['Pass'] ?? null;
$note = $_POST['Note'] ?? null;

$now = date("H:i:s");
$date = date("Y-m-d");

if (!$action || !$empID) {
    http_response_code(400);
    echo json_encode(["error" => "Missing parameters"]);
    exit;
}

// 🔐 Step 1: PIN verification (used for "verify" and all actions)
$user = $conn->query("SELECT * FROM users WHERE ID = '$empID' AND Pass = '$pass'");
if ($action === "verify") {
    if ($user && $user->num_rows === 1) {
        $userRow = $user->fetch_assoc();
        $status = $userRow['ClockStatus'];

        // Fetch latest clock time
        $last = $conn->query("SELECT * FROM timepunches WHERE EmployeeID = '$empID' ORDER BY Date DESC, TimeIN DESC LIMIT 1");
        $lastTime = "N/A";
        if ($last && $last->num_rows > 0) {
            $row = $last->fetch_assoc();
            $lastTime = $row['TimeOut'] ?? $row['LunchEnd'] ?? $row['LunchStart'] ?? $row['TimeIN'];
        }

        echo json_encode(["success" => true, "status" => $status, "time" => $lastTime]);
    } else {
        echo json_encode(["success" => false]);
    }
    exit;
}

// Validate PIN again for actions
if (!$user || $user->num_rows !== 1) {
    echo json_encode(["success" => false, "error" => "Invalid credentials"]);
    exit;
}

// 🔄 Step 2: Perform the clock action
switch ($action) {
    case "clockin":
        $conn->query("INSERT INTO timepunches (EmployeeID, Date, TimeIN) VALUES ('$empID', '$date', '$now')");
        $conn->query("UPDATE users SET ClockStatus = 'In' WHERE ID = '$empID'");
        break;

    case "lunchstart":
        $conn->query("UPDATE timepunches SET LunchStart='$now' WHERE EmployeeID='$empID' AND Date='$date' ORDER BY TimeIN DESC LIMIT 1");
        $conn->query("UPDATE users SET ClockStatus = 'Lunch' WHERE ID = '$empID'");
        break;

    case "lunchend":
        $conn->query("UPDATE timepunches SET LunchEnd='$now' WHERE EmployeeID='$empID' AND Date='$date' ORDER BY TimeIN DESC LIMIT 1");
        $conn->query("UPDATE users SET ClockStatus = 'In' WHERE ID = '$empID'");
        break;

    case "clockout":
        $conn->query("UPDATE timepunches SET TimeOut='$now' WHERE EmployeeID='$empID' AND Date='$date' ORDER BY TimeIN DESC LIMIT 1");

        // Recalculate total time
        $row = $conn->query("SELECT * FROM timepunches WHERE EmployeeID='$empID' AND Date='$date' ORDER BY TimeIN DESC LIMIT 1")->fetch_assoc();
        if ($row && $row['TimeIN']) {
            $start = strtotime($row['TimeIN']);
            $end = strtotime($now);
            $duration = round(($end - $start) / 3600, 2);
            $conn->query("UPDATE timepunches SET TotalHours='$duration' WHERE EmployeeID='$empID' AND Date='$date' ORDER BY TimeIN DESC LIMIT 1");
        }

        $conn->query("UPDATE users SET ClockStatus = 'Out' WHERE ID = '$empID'");
        break;
}

// 📝 Optional: Log notes
if (!empty($note)) {
    $escapedNote = $conn->real_escape_string($note);
    $conn->query("UPDATE timepunches SET Note='$escapedNote' WHERE EmployeeID='$empID' AND Date='$date' ORDER BY TimeIN DESC LIMIT 1");
}

echo json_encode(["success" => true]);
exit;
?>
