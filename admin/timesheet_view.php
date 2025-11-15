<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include necessary files (e.g., database connection)
include_once '../auth/db.php'; 

// Get parameters from URL
$employeeID = isset($_GET['emp']) ? $_GET['emp'] : null;
$from_raw = isset($_GET['from']) ? $_GET['from'] : null;
$to_raw = isset($_GET['to']) ? $_GET['to'] : null;

// Convert MM/DD/YYYY to YYYY-MM-DD for database queries
$from = null;
$to = null;

if ($from_raw) {
    $date_obj = DateTime::createFromFormat('m/d/Y', $from_raw);
    if ($date_obj) {
        $from = $date_obj->format('Y-m-d');
    }
}

if ($to_raw) {
    $date_obj = DateTime::createFromFormat('m/d/Y', $to_raw);
    if ($date_obj) {
        $to = $date_obj->format('Y-m-d');
    }
}

// If parameters are still missing after parsing, handle the error
if (!$employeeID || !$from || !$to) {
    echo "<div style='padding: 20px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;'>";
    echo "<strong>Error:</strong> Missing or invalid required parameters (emp, from, to).<br>";
    echo "Please ensure you are accessing this page with the correct employee ID and date range in MM/DD/YYYY format.<br>";
    echo "Example: <code>view_punches.php?from=09/01/2025&to=09/07/2025&emp=1</code>";
    echo "</div>";
    exit();
}

function calculatePunchTotalHours($timeIn, $lunchStart, $lunchEnd, $timeOut) {
    $totalMinutes = 0;

    $in = $timeIn ? strtotime($timeIn) : null;
    $out = $timeOut ? strtotime($timeOut) : null;
    $ls = $lunchStart ? strtotime($lunchStart) : null;
    $le = $lunchEnd ? strtotime($lunchEnd) : null;

    if ($in && $out) {
        $totalMinutes = ($out - $in) / 60; // Difference in minutes
        if ($ls && $le) {
            $lunchDuration = ($le - $ls) / 60;
            $totalMinutes -= $lunchDuration;
        }
    }
    $hours = $totalMinutes / 60;
    return number_format($hours, 2); // Convert to hours and format
}

        $totalWeeklyHours = 0;
        $overtimeHours = 0;
        $standardWorkWeek = 40; // Assuming a 40-hour work week

        // Fetch all punches for the given date range to calculate total hours and overtime
        $stmtTotal = $conn->prepare("SELECT TotalHours FROM timepunches WHERE EmployeeID = ? AND Date BETWEEN ? AND ?");
        $stmtTotal->bind_param("iss", $employeeID, $from, $to);
        $stmtTotal->execute();
        $resultTotal = $stmtTotal->get_result();

        while ($punchTotal = $resultTotal->fetch_assoc()) {
            $totalWeeklyHours += (float)($punchTotal['TotalHours'] ?? 0);
        }

        if ($totalWeeklyHours > $standardWorkWeek) {
            $overtimeHours = $totalWeeklyHours - $standardWorkWeek;
        }
    ?>

    

    <div class="timesheet-summary">
        <p><strong>Total Week Hours:</strong> <span id="weekly-total"><?= number_format($totalWeeklyHours, 2) ?></span></p>
        <p><strong>Overtime Hours:</strong> <span id="weekly-overtime"><?= number_format($overtimeHours, 2) ?></span></p>
    </div>

<div id="timesheet-content-container">
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
                    $clockIn   = !empty($punch['TimeIN'])      ? date('h:i A', strtotime($punch['TimeIN']))     : '';
                    $clockOut  = !empty($punch['TimeOut'])     ? date('h:i A', strtotime($punch['TimeOut']))    : '';
                    $lunchOut  = !empty($punch['LunchStart'])  ? date('h:i A', strtotime($punch['LunchStart'])) : '';
                    $lunchIn   = !empty($punch['LunchEnd'])    ? date('h:i A', strtotime($punch['LunchEnd']))   : '';
        ?>
        <tr>
            <td><?= $day->format('m/d/Y') ?></td>
            <td><?= $clockIn ?></td>
            <td>
                <?php if (!empty($punch['LatitudeIN']) && !empty($punch['LongitudeIN'])) : ?>
                    <a href="https://www.google.com/maps?q=<?= $punch['LatitudeIN'] ?>,<?= $punch['LongitudeIN'] ?>" target="_blank" class="btn-view">View</a>
                <?php endif; ?>
            </td>
            <td><?= $lunchOut ?></td>
            <td>
                <?php if (!empty($punch['LatitudeLunchStart']) && !empty($punch['LongitudeLunchStart'])) : ?>
                    <a href="https://www.google.com/maps?q=<?= $punch['LatitudeLunchStart'] ?>,<?= $punch['LongitudeLunchStart'] ?>" target="_blank" class="btn-view">View</a>
                <?php endif; ?>
            </td>
            <td><?= $lunchIn ?></td>
            <td>
                <?php if (!empty($punch['LatitudeLunchEnd']) && !empty($punch['LongitudeLunchEnd'])) : ?>
                    <a href="https://www.google.com/maps?q=<?= $punch['LatitudeLunchEnd'] ?>,<?= $punch['LongitudeLunchEnd'] ?>" target="_blank" class="btn-view">View</a>
                <?php endif; ?>
            </td>
            <td><?= $clockOut ?></td>
            <td>
                <?php if (!empty($punch['LatitudeOut']) && !empty($punch['LongitudeOut'])) : ?>
                    <a href="https://www.google.com/maps?q=<?= $punch['LatitudeOut'] ?>,<?= $punch['LongitudeOut'] ?>" target="_blank" class="btn-view">View</a>
                <?php endif; ?>
            </td>
            <td class="total-cell" id="total-<?= $punch['id'] ?>"><?= calculatePunchTotalHours($punch['TimeIN'], $punch['LunchStart'], $punch['LunchEnd'], $punch['TimeOut']) ?></td>
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
</div>

<script src="../js/admin_timesheet.js"></script>
<script>updateTotals();</script>
