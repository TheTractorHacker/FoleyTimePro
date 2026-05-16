<?php
// admin/remove_office.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ID'])) {
    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    $id = (int)$_POST['ID'];
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM Offices WHERE ID = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
}

header("Location: manage_offices.php");
exit;
