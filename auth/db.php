<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Load .env once — safeLoad() is a no-op if the file is missing
if (!isset($_ENV['DB_HOST'])) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$host = $_ENV['DB_HOST']     ?? 'localhost';
$db   = $_ENV['DB_NAME']     ?? 'timeclock';
$user = $_ENV['DB_USER']     ?? 'timeclock';
$pass = $_ENV['DB_PASS']     ?? '';
$rawTz = $_ENV['DB_TIMEZONE'] ?? 'America/Chicago';
$tz    = in_array($rawTz, timezone_identifiers_list(), true) ? $rawTz : 'America/Chicago';

$conn = @new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
              && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    } else {
        http_response_code(500);
        die('❌ Database connection failed. Please contact your administrator.');
    }
    exit;
}

$conn->set_charset('utf8mb4');
$tzStmt = $conn->prepare("SET time_zone = ?");
$tzStmt->bind_param("s", $tz);
$tzStmt->execute();
$tzStmt->close();
