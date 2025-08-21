<?php
session_start();
require '../auth/db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$admin = $_SESSION['admin'];
$now = date('Y-m-d H:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    foreach ($_POST['action'] as $editID => $fieldActions) {
        foreach ($fieldActions as $field => $action) {
            $editID = intval($editID);
            $decision = ($action === 'approve') ? 'Approved' : 'Rejected';

            // Get edit info
            $stmt = $conn->prepare("SELECT * FROM pending_edits WHERE ID = ?");
            $stmt->bind_param("i", $editID);
            $stmt->execute();
            $edit = $stmt->get_result()->fetch_assoc();

            if (!$edit) continue;

            if ($decision === 'Approved') {
                // Update timepunches table
                $employeeID = $edit['EmployeeID'];
                $date = $edit['Date'];

                // Sanitize column name
                $allowedFields = ['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOUT', 'Note'];
                if (in_array($field, $allowedFields)) {
                    $requested = $edit[$field];
                    $sql = "UPDATE timepunches SET `$field` = ? WHERE EmployeeID = ? AND Date = ?";
                    $update = $conn->prepare($sql);
                    $update->bind_param("sis", $requested, $employeeID, $date);
                    $update->execute();
                }
            }

            // Update pending_edits status. This marks the entire request as processed.
            $updateStatus = $conn->prepare("UPDATE pending_edits SET Status = ?, ReviewedAt = ?, ReviewedBy = ? WHERE ID = ?");
            $updateStatus->bind_param("sssi", $decision, $now, $admin, $editID);
            $updateStatus->execute();
        }
    }

    header("Location: edits_timesheet.php");
    exit;
} else {
    echo "Invalid access.";
}
