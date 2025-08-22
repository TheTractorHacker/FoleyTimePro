<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'header.php';
$empID = $_SESSION['EmployeeID'] ?? null;
$name = $_SESSION['Name'] ?? 'User';

if (!$empID) {
    header('Location: ../error.php?code=401&message=' . urlencode('Employee ID not found. Please log in again.'));
    exit;
}

// Punch history
$start = $_GET['start'] ?? date('Y-m-d', strtotime('monday this week'));
$end   = $_GET['end'] ?? date('Y-m-d', strtotime('friday this week'));

$query = $conn->prepare("SELECT Date, TimeIN, LunchStart, LunchEnd, TimeOUT, Note FROM timepunches WHERE EmployeeID = ? AND Date BETWEEN ? AND ? ORDER BY Date DESC, TimeIN DESC");
$query->bind_param("sss", $empID, $start, $end);
$query->execute();
$result = $query->get_result();

$punches = [];
$totalSeconds = 0;

while ($row = $result->fetch_assoc()) {
    $date = $row['Date'];
    $in   = $row['TimeIN'] ? strtotime($row['Date'] . ' ' . $row['TimeIN']) : 0;
    $out  = $row['TimeOUT'] ? strtotime($row['Date'] . ' ' . $row['TimeOUT']) : 0;
    $worked = ($in && $out) ? $out - $in : 0;
    $totalSeconds += $worked;

    $punches[] = [
        'date' => date("m/d/Y", strtotime($date)),
        'in' => $in ? date("g:i A", $in) : '-',
        'out' => $out ? date("g:i A", $out) : '-',
        'hours' => $worked ? round($worked / 3600, 2) : '-',
        'note' => $row['Note'] ?? '-'
    ];
}

$totalHours = round($totalSeconds / 3600, 2);

// Last punch info
$last = $conn->query("SELECT * FROM timepunches WHERE EmployeeID = '$empID' ORDER BY Date DESC, TimeIN DESC LIMIT 1")->fetch_assoc();
$lastLabel = $lastTime = $lastDate = $lastNote = '-';
$clockInUnix = null;

if ($last) {
    $lastDate = $last['Date'];
    $lastNote = $last['Note'] ?? '-';

    if (!empty($last['TimeOUT'])) {
        $lastLabel = "Clock Out";
        $lastTime = date("g:i A", strtotime($last['TimeOUT']));
    } elseif (!empty($last['LunchEnd'])) {
        $lastLabel = "Lunch End";
        $lastTime = date("g:i A", strtotime($last['LunchEnd']));
    } elseif (!empty($last['LunchStart'])) {
        $lastLabel = "Lunch Start";
        $lastTime = date("g:i A", strtotime($last['LunchStart']));
    } elseif (!empty($last['TimeIN'])) {
        $lastLabel = "Clock In";
        $lastTime = date("g:i A", strtotime($last['TimeIN']));
        if (empty($last['TimeOUT'])) {
            $clockInUnix = strtotime($last['Date'] . ' ' . $last['TimeIN']);
        }
    }
}

// Determine current status from the users table
$currentStatus = 'Out'; // Default status
$statusQuery = $conn->prepare("SELECT ClockStatus FROM users WHERE id = ?");
if ($statusQuery) {
    $statusQuery->bind_param("s", $empID);
    $statusQuery->execute();
    $statusResult = $statusQuery->get_result();
    if ($statusResult) {
        $statusRow = $statusResult->fetch_assoc();
        if ($statusRow && isset($statusRow['ClockStatus'])) {
            $currentStatus = $statusRow['ClockStatus'];
        }
    }
}


// Edit requests
$editStmt = $conn->prepare("SELECT Date, TimeIN, LunchStart, LunchEnd, TimeOUT, Note, Reason, Status FROM pending_edits WHERE EmployeeID = ? ORDER BY SubmittedAt DESC LIMIT 10");
$editStmt->bind_param("i", $empID);
$editStmt->execute();
$editResults = $editStmt->get_result();
$editRequests = [];
while ($row = $editResults->fetch_assoc()) {
    $editRequests[] = $row;
}
?>
<style>
    .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: bold;
        color: white;
        text-transform: capitalize;
    }
    .badge-pending { background-color: #FFA500; }
    .badge-approved { background-color: #28a745; }
    .badge-rejected { background-color: #dc3545; }
    .badge-unknown { background-color: #6c757d; }

    .edit-status-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }
    .edit-status-table th,
    .edit-status-table td {
        padding: 0.5rem;
        border-bottom: 1px solid #ccc;
        text-align: left;
    }

    .current-status-badge {
        display: inline-block;
        padding: 8px 12px;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: bold;
        color: white;
        text-transform: uppercase;
    }

    .status-in {
        background-color: #28a745;
        padding: 20px;
        font-size: 17px;
    }

    .status-out {
        background-color: #dc3545;
        padding: 20px;
        font-size: 17px;
    }

    .status-on-lunch {
        background-color: #FFA500;
        padding: 20px;
        font-size: 17px;
    }

</style>
        <h2>Welcome, <?= htmlspecialchars($name) ?>!</h2>
        
        <div class="card">
            <h3>Status & Actions</h3>
            <div class="current-status-badge status-<?= strtolower(str_replace(' ', '-', $currentStatus)) ?>">
                Current Status: <?= $currentStatus ?>
            </div>

            <button class="toggle-punch" onclick="togglePunch()">⏱ Show Punch In / Out</button>
            <div class="punch-area" id="punchArea" style="display: none;">
                <table>
                    <thead><tr><th>Type</th><th>Time</th><th>Date</th><th>Note</th></tr></thead>
                    <tbody><tr>
                        <td><?= $lastLabel ?></td>
                        <td><?= $lastTime ?></td>
                        <td><?= $lastDate ?></td>
                        <td><?= htmlspecialchars($lastNote) ?></td>
                    </tr></tbody>
                </table>
                <?php if ($clockInUnix !== null): ?>
                <p class="hours-live" id="liveHours">⏳ Hours Worked Since Clock In: <strong>Loading...</strong></p>
                <script>
                    const clockInTime = <?= $clockInUnix * 1000 ?>;
                    function formatTimeSince(start) {
                        const now = Date.now();
                        const diff = now - start;
                        const hours = Math.floor(diff / 3600000);
                        const minutes = Math.floor((diff % 3600000) / 60000);
                        const seconds = Math.floor((diff % 60000) / 1000);
                        return `${hours} hrs ${minutes} mins ${seconds} secs`;
                    }
                    function updateLiveHours() {
                        document.querySelector("#liveHours strong").textContent = formatTimeSince(clockInTime);
                    }
                    updateLiveHours();
                    setInterval(updateLiveHours, 1000);
                </script>
                <?php endif; ?>
                <input type="text" id="note" placeholder="Optional note...">
                <div class="punch-buttons">
                    <button class="clockin" onclick="submitAction('clockin')">Clock In</button>
                    <button class="lunchstart" onclick="submitAction('lunchstart')">Lunch Start</button>
                    <button class="lunchend" onclick="submitAction('lunchend')">Lunch End</button>
                    <button class="clockout" onclick="submitAction('clockout')">Clock Out</button>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>Time History</h3>
            <form method="get" class="date-range">
                <label>From:</label>
                <input type="date" name="start" value="<?= $start ?>">
                <label>To:</label>
                <input type="date" name="end" value="<?= $end ?>">
                <button type="submit">Apply</button>
            </form>

            <table>
                <thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th></tr></thead>
                <tbody>
                    <?php foreach ($punches as $p): ?>
                    <tr>
                        <td><?= $p['date'] ?></td>
                        <td><?= $p['in'] ?></td>
                        <td><?= $p['out'] ?></td>
                        <td><?= $p['hours'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h4>Total Hours This Range: <strong><?= $totalHours ?> hrs</strong></h4>
        </div>

        <?php if (!empty($editRequests)): ?>
        <div class="card">
            <h3>Your Recent Edit Requests</h3>
            <table class="edit-status-table">
                <thead><tr><th>Date</th><th>Field</th><th>New Value</th><th>Reason</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($editRequests as $edit): ?>
                    <?php
                        $status = strtolower($edit['Status']);
                        $badgeClass = match ($status) {
                            'pending' => 'badge-pending',
                            'approved' => 'badge-approved',
                            'rejected' => 'badge-rejected',
                            default => 'badge-unknown'
                        };
                        $field = '-';
                        $value = '-';
                        foreach (['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOUT', 'Note'] as $key) {
                            if (!empty($edit[$key])) {
                                $field = $key;
                                $value = htmlspecialchars($edit[$key]);
                                break;
                            }
                        }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($edit['Date']) ?></td>
                        <td><?= $field ?></td>
                        <td><?= $value ?></td>
                        <td><?= htmlspecialchars($edit['Reason']) ?></td>
                        <td><span class="status-badge <?= $badgeClass ?>"><?= ucfirst($status) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
<script>const empID = <?= (int)$empID ?>;</script>
<script src="../js/user_dashboard.js"></script>
<?php require_once 'footer.php'; ?>