<?php
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
    echo "Missing or invalid parameters for timesheet edit.";
    exit();
}

// Fetch employee name
$employeeName = "Unknown Employee"; // Default
$stmtEmployee = $conn->prepare("SELECT FirstName, LastName FROM users WHERE ID = ?");
$stmtEmployee->bind_param("i", $employeeID);
$stmtEmployee->execute();
$resultEmployee = $stmtEmployee->get_result();
if ($resultEmployee->num_rows > 0) {
    $employeeData = $resultEmployee->fetch_assoc();
    $employeeName = htmlspecialchars($employeeData['FirstName'] . " " . $employeeData['LastName']);
}
$stmtEmployee->close();

// Fetch time punches for the given employee and date range
$stmt = $conn->prepare("SELECT * FROM timepunches WHERE EmployeeID = ? AND Date BETWEEN ? AND ? ORDER BY Date, TimeIN");
$stmt->bind_param("iss", $employeeID, $from, $to);
$stmt->execute();
$result = $stmt->get_result();

?>

<div class="timesheet-edit-container">
    <h2>Edit Timesheet for <?= $employeeName ?> (<?= htmlspecialchars($from_raw) ?> to <?= htmlspecialchars($to_raw) ?>)</h2>

    <table class="timesheet-edit-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Clock In</th>
                <th>Lunch Out</th>
                <th>Lunch In</th>
                <th>Clock Out</th>
                <th>Total</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php while ($punch = $result->fetch_assoc()): ?>
                    <tr data-punch-id="<?= $punch['id'] ?>">
                        <td><input type="date" name="date" value="<?= htmlspecialchars($punch['Date']) ?>"></td>
                        <td><input type="time" name="time_in" value="<?= !empty($punch['TimeIN']) ? date('H:i', strtotime($punch['TimeIN'])) : '' ?>"></td>
                        <td><input type="time" name="lunch_out" value="<?= !empty($punch['LunchStart']) ? date('H:i', strtotime($punch['LunchStart'])) : '' ?>"></td>
                        <td><input type="time" name="lunch_in" value="<?= !empty($punch['LunchEnd']) ? date('H:i', strtotime($punch['LunchEnd'])) : '' ?>"></td>
                        <td><input type="time" name="time_out" value="<?= !empty($punch['TimeOut']) ? date('H:i', strtotime($punch['TimeOut'])) : '' ?>"></td>
                        <td><span class="total-hours"><?= htmlspecialchars($punch['TotalHours'] ?? '0.00') ?></span></td>
                        <td>
                            <button class="btn-delete-punch" data-punch-id="<?= $punch['id'] ?>">Delete</button>
                            <button class="btn-delete-specific" data-punch-id="<?= $punch['id'] ?>">Delete Specific</button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">No punches found for this period.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <button id="btn-add-new-punch">Add New Punch</button>
    <button id="btn-save-changes">Save Changes</button>
</div>

<style>
.modal { display: none; position: fixed; z-index: 1000; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); }
.modal-content { background: white; padding: 2rem; border-radius: 10px; width: 400px; max-width: 90%; margin: 10% auto; position: relative; }
.modal-content h3 { margin-top: 0; }
.close-btn { position: absolute; top: 10px; right: 15px; font-size: 20px; cursor: pointer; }
#punch-info {
    background-color: #f4f6f9;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 10px;
    margin-bottom: 15px;
}
#punch-info p {
    margin: 5px 0;
}
#punch-types-checkboxes {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 15px;
}
#punch-types-checkboxes label {
    display: flex;
    align-items: center;
    cursor: pointer;
}
#punch-types-checkboxes input[type="checkbox"] {
    margin-right: 10px;
    width: 18px;
    height: 18px;
}
#confirmSpecificDelete {
    background-color: #dc3545;
    color: white;
    border: none;
    padding: 10px 15px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 16px;
    width: 100%;
}
#confirmSpecificDelete:hover {
    background-color: #c82333;
}
</style>
<!-- Specific Punch Deletion Modal -->
<div id="specificPunchDeleteModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close-btn">&times;</span>
        <h3>Delete Specific Punch Data</h3>
        <div id="punch-info"></div> <!-- Placeholder for punch info -->
        <p>Select the punch types to delete for the selected day:</p>
        <div id="punch-types-checkboxes">
            <label><input type="checkbox" value="TimeIN"> Clock In</label>
            <label><input type="checkbox" value="LunchStart"> Lunch Out</label>
            <label><input type="checkbox" value="LunchEnd"> Lunch In</label>
            <label><input type="checkbox" value="TimeOut"> Clock Out</label>
        </div>
        <button id="confirmSpecificDelete">Confirm Delete</button>
    </div>
</div>

<script>
    const currentEmployeeID = <?= json_encode($employeeID) ?>;
</script>
<script src="../js/admin_timesheet_edit.js"></script>
<!-- Custom Alert Modal -->
<div id="customAlertModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close-btn">&times;</span>
        <p id="customAlertMessage"></p>
        <div id="customAlertActions" style="display:none;">
            <button id="customAlertConfirm">Yes</button>
            <button id="customAlertCancel">No</button>
        </div>
    </div>
</div>