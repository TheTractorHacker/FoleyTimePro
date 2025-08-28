<?php
// db.php in /var/www/timeclock/auth/
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env if present but rely primarily on environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Extract credentials from environment
$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
$db   = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
$user = $_ENV['DB_USER'] ?? getenv('DB_USER');
$pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS');
$tz   = $_ENV['DB_TIMEZONE'] ?? getenv('DB_TIMEZONE') ?? 'America/Chicago';

if (!$host || !$db || !$user || $pass === null) {
    header('Location: ../error.php?code=500&message=' . urlencode('Database credentials not set.'));
    exit;
}

// Connect to MySQL
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    header('Location: ../error.php?code=500&message=' . urlencode('DB connection failed: ' . $conn->connect_error));
    exit;
}

// Configure connection
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '{$tz}'");
?>
