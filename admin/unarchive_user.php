<?php
// admin/unarchive_user.php
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
            // Copy user from user-archive back to users table
            $conn->query("INSERT INTO users (ID, LastName, Email, FirstName, Pass, TagID, ProfilePhoto, ClockStatus, Office, TwoFASecret, TwoFAEnabled, RecoveryCodeHash, AdminOverride2FA, JobTitle, PhoneNumber, ThemePref, TwoFARecoveryCode, LockOut) SELECT ID, LastName, Email, FirstName, Pass, TagID, ProfilePhoto, ClockStatus, Office, TwoFASecret, TwoFAEnabled, RecoveryCodeHash, AdminOverride2FA, JobTitle, PhoneNumber, ThemePref, TwoFARecoveryCode, LockOut FROM `user-archive` WHERE ID = $id");

            // Delete user from user-archive table
            $conn->query("DELETE FROM `user-archive` WHERE ID = $id");

            // Commit transaction
            $conn->commit();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            // Optional: log error, show error message
        }
    }
}

header("Location: archived_users.php");
exit;
