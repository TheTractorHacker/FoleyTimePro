<?php
// admin/permanently_delete_user.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ID'])) {
    $id = (int)$_POST['ID'];
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM `user-archive` WHERE ID = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
}

header("Location: archived_users.php");
exit;
