<?php
// db.php in /var/www/timeclock/auth/
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env from current directory
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad(); // Won't throw fatal if .env is missing

// Debug: check if .env variables are loading
if (!isset($_ENV['DB_HOST'])) {
    die('❌ Failed to load .env variables.');
}

// Extract environment variables
$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];
$tz   = $_ENV['DB_TIMEZONE'] ?? 'America/Chicago';

// Connect to MySQL
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('❌ DB connection failed: ' . $conn->connect_error);
}

// Configure connection
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '{$tz}'");
?>
