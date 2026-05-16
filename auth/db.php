<?php
// db.php in /var/www/timeclock/auth/
require_once __DIR__ . '/../vendor/autoload.php';
// Extract environment variables
$host = 'localhost';
$db   = 'timeclock';
$user = 'timeclock';
$pass = 'SecureNet25!';
$tz   = $_ENV['DB_TIMEZONE'] ?? 'America/Chicago';

// Connect to MySQL
$conn = @new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB connection failed: ' . $conn->connect_error]);
        exit;
    } else {
        die('❌ DB connection failed: ' . $conn->connect_error);
    }
}

// Configure connection
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '{$tz}'");
?>