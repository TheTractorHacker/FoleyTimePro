<?php
// db.php in /var/www/timeclock/auth/
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables from .env if present
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Extract environment variables (either from Docker or .env)
$host = getenv('DB_HOST');
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$tz   = getenv('DB_TIMEZONE') ?: 'America/Chicago';

if (!$host || !$db || !$user || !$pass) {
    die('❌ Missing database environment variables.');
}

// Connect to MySQL
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('❌ DB connection failed: ' . $conn->connect_error);
}

// Configure connection
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '{$tz}'");
?>
