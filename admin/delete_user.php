<?php
// admin/delete_user.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ID'])) {
    $id = (int)$_POST['ID'];
    if ($id > 0) {
        // Begin transaction
        $conn->begin_transaction();

        try {
            // Delete from related tables
            $conn->query("DELETE FROM timepunches WHERE EmployeeID = $id");
            $conn->query("DELETE FROM login_logs WHERE EmployeeID = $id");
            $conn->query("DELETE FROM pending_edits WHERE EmployeeID = $id");
            $conn->query("DELETE FROM punch_changelog WHERE EmployeeID = $id");

            // Delete user from users table
            $conn->query("DELETE FROM users WHERE ID = $id");

            // Commit transaction
            $conn->commit();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            // Optional: log error, show error message
        }
    }
}

header("Location: manage_users.php");
exit;
