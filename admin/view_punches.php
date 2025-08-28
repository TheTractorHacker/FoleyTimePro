<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('America/Chicago');

$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

$from = $_GET['from'] ?? date('Y-m-d', strtotime('monday this week'));
$to = $_GET['to'] ?? date('Y-m-d', strtotime('sunday this week'));
$employeeID = $_GET['emp'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Punches</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/timesheet.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css" />
    <link rel="stylesheet" href="../css/view_punches.css">
</head>
<body>

<header>
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1>Employee Punch Adjustments</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="view_punches.php" class="active">Timesheets</a>
        <a href="summary.php">Summary</a>
        <a href="reports.php">Reports</a>
        <a href="manage_users.php">Users</a>
        <a href="attendance.php">Attendance</a>
        <a href="manage_admins.php">Admins</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<div class="dashboard-container">
    <div class="container">
        <form method="GET" class="summary-filter">
            <div class="field">
                <label>Date Range From:
                    <input type="text" name="from" id="weekFrom" value="<?= htmlspecialchars(date('m/d/Y', strtotime($from))) ?>">
                </label>
            </div>
            <div class="field">
                <label>To:
                    <input type="text" name="to" id="weekTo" value="<?= htmlspecialchars(date('m/d/Y', strtotime($to))) ?>">
                </label>
            </div>
            <div class="field">
                <label>Employee:
                    <select name="emp" required>
                        <option value="">Select Employee</option>
                        <?php while ($emp = $employeeList->fetch_assoc()): ?>
                            <option value="<?= $emp['ID'] ?>" <?= ($emp['ID'] == $employeeID ? 'selected' : '') ?>>
                                <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </label>
            </div>
            <div class="buttons">
                <button type="submit">Submit</button>
                <a href="view_punches.php" class="btn-reset">Reset</a>
            </div>
        </form>

        <?php if (!empty($employeeID)): ?>
        <form method="POST" action="save_punches.php">
            <input type="hidden" name="employeeID" value="<?= $employeeID ?>">
            <input type="hidden" name="from" value="<?= $from ?>">
            <input type="hidden" name="to" value="<?= $to ?>">

            <table class="timesheet-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Clock In</th>
                        <th>Clock In Location</th>
                        <th>Lunch Out</th>
                        <th>Lunch Out Location</th>
                        <th>Lunch In</th>
                        <th>Lunch In Location</th>
                        <th>Clock Out</th>
                        <th>Clock Out Location</th>
                        <th>Total</th>
                        <th>Reason for Adjustment</th>
                        <th>Confirm</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $start = new DateTime($from);
                    $end = new DateTime($to);
                    $interval = new DateInterval('P1D');
                    $range = new DatePeriod($start, $interval, $end->modify('+1 day'));

                    foreach ($range as $day):
                        $date = $day->format('Y-m-d');

                        $stmt = $conn->prepare("SELECT * FROM timepunches WHERE EmployeeID = ? AND Date = ? ORDER BY TimeIN");
                        $stmt->bind_param("is", $employeeID, $date);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0):
                            while ($punch = $result->fetch_assoc()):
                                $clockIn   = !empty($punch['TimeIN'])      ? date('H:i', strtotime($punch['TimeIN']))     : '';
                                $clockOut  = !empty($punch['TimeOut'])     ? date('H:i', strtotime($punch['TimeOut']))    : '';
                                $lunchOut  = !empty($punch['LunchStart'])  ? date('H:i', strtotime($punch['LunchStart'])) : '';
                                $lunchIn   = !empty($punch['LunchEnd'])    ? date('H:i', strtotime($punch['LunchEnd']))   : '';
                    ?>
                    <tr>
                        <td><?= $day->format('m/d/Y') ?></td>
                        <td><input type="time" name="clockin[<?= $punch['id'] ?>]" value="<?= $clockIn ?>" step="60"></td>
                        <td>
                            <?php if (!empty($punch['LatitudeIN']) && !empty($punch['LongitudeIN'])) : ?>
                                <a href="https://www.google.com/maps?q=<?= $punch['LatitudeIN'] ?>,<?= $punch['LongitudeIN'] ?>" target="_blank" class="btn-view">View</a>
                            <?php endif; ?>
                        </td>
                        <td><input type="time" name="lunchout[<?= $punch['id'] ?>]" value="<?= $lunchOut ?>" step="60"></td>
                        <td>
                            <?php if (!empty($punch['LatitudeLunchStart']) && !empty($punch['LongitudeLunchStart'])) : ?>
                                <a href="https://www.google.com/maps?q=<?= $punch['LatitudeLunchStart'] ?>,<?= $punch['LongitudeLunchStart'] ?>" target="_blank" class="btn-view">View</a>
                            <?php endif; ?>
                        </td>
                        <td><input type="time" name="lunchin[<?= $punch['id'] ?>]" value="<?= $lunchIn ?>" step="60"></td>
                        <td>
                            <?php if (!empty($punch['LatitudeLunchEnd']) && !empty($punch['LongitudeLunchEnd'])) : ?>
                                <a href="https://www.google.com/maps?q=<?= $punch['LatitudeLunchEnd'] ?>,<?= $punch['LongitudeLunchEnd'] ?>" target="_blank" class="btn-view">View</a>
                            <?php endif; ?>
                        </td>
                        <td><input type="time" name="clockout[<?= $punch['id'] ?>]" value="<?= $clockOut ?>" step="60"></td>
                        <td>
                            <?php if (!empty($punch['LatitudeOut']) && !empty($punch['LongitudeOut'])) : ?>
                                <a href="https://www.google.com/maps?q=<?= $punch['LatitudeOut'] ?>,<?= $punch['LongitudeOut'] ?>" target="_blank" class="btn-view">View</a>
                            <?php endif; ?>
                        </td>
                        <td class="total-cell" id="total-<?= $punch['id'] ?>"><?= htmlspecialchars($punch['TotalHours'] ?? '0.00') ?></td>
                        <td>
                            <select name="reason[<?= $punch['id'] ?>]" class="reason-dropdown">
                                <option value="">Select reason...</option>
                                <option value="Forgot to punch">Forgot to punch</option>
                                <option value="Shift change">Shift change</option>
                                <option value="System error">System error</option>
                                <option value="Time correction">Time correction</option>
                                <option value="Late arrival">Late arrival</option>
                                <option value="Early departure">Early departure</option>
                                <option value="Manual update">Manual update</option>
                            </select>
                        </td>
                        <td><button type="submit" name="confirm[]" value="<?= $punch['id'] ?>" class="confirm-btn">✔</button></td>
                    </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                    <tr>
                        <td><?= $day->format('m/d/Y') ?></td>
                        <td colspan="11">No punches for this day.</td>
                    </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals">
                <strong>Total Week:</strong> <span id="weekly-total">0.00h</span> |
                <strong>Overtime:</strong> <span id="weekly-overtime">0.00h</span>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<script src="../js/view_punches.js"></script>

</body>
</html>