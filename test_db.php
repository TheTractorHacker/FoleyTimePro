<?php
// test_db.php
// This file is for debugging ONLY.

// Force PHP to display all errors on the screen.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h3>Database Connection Test</h3>";
echo "Attempting to connect...<br><br>";

// Use the exact same credentials as your auth/db.php file
$host = 'localhost';
$db   = 'timeclock';
$user = 'timeclock';
$pass = 'SecureNet25!';

// Attempt the connection
$conn = new mysqli($host, $user, $pass, $db);

// Check for and report any connection error
if ($conn->connect_error) {
    die("<h4>❌ Connection Failed:</h4><p><strong>Error Message:</strong> " . $conn->connect_error . "</p><p>Please verify your host, username, password, and database name are correct in your db.php file.</p>");
}

echo "<h4>✅ Success!</h4><p>The database connection is working correctly.</p>";

$conn->close();
?>