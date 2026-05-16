<?php
session_start();
if (php_sapi_name() !== 'cli' && !isset($_SESSION['admin'])) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/../auth/db.php';

// Check if the column already exists
$result = $conn->query("SHOW COLUMNS FROM `admins` LIKE 'TwoFASecret'");
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE `admins` 
            ADD COLUMN `TwoFASecret` VARCHAR(255) NULL AFTER `password`,
            ADD COLUMN `TwoFAEnabled` BOOLEAN NOT NULL DEFAULT FALSE AFTER `TwoFASecret`,
            ADD COLUMN `BackupCodes` TEXT NULL AFTER `TwoFAEnabled`";

    if ($conn->query($sql) === TRUE) {
        echo "Table 'admins' updated successfully.";
    } else {
        echo "Error updating table: " . $conn->error;
    }
} else {
    echo "Columns already exist.";
}

$conn->close();
?>