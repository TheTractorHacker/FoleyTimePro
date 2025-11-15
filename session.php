<?php
session_start();
if (!isset($_SESSION['EmployeeID'])) {
    header("Location: index.php");
    exit;
}
?>
