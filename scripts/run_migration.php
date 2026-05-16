<?php
/**
 * Migration Runner
 * Run SQL migrations against the database
 *
 * Usage: php scripts/run_migration.php [migration_file]
 */

require_once __DIR__ . '/../auth/db.php';

$migrationFile = $argv[1] ?? __DIR__ . '/../Install/migrations/002_add_pwa_and_calendar_settings.sql';

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

echo "Running migration: $migrationFile\n";

$sql = file_get_contents($migrationFile);

// Split by semicolons but be careful with procedures/functions
$statements = array_filter(
    array_map('trim', preg_split('/;\s*$/m', $sql)),
    function($stmt) {
        // Filter out comments-only statements
        $stripped = preg_replace('/--.*$/m', '', $stmt);
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $stripped);
        return !empty(trim($stripped));
    }
);

$success = 0;
$errors = 0;

foreach ($statements as $statement) {
    $statement = trim($statement);
    if (empty($statement)) continue;

    // Skip comment-only statements
    $stripped = preg_replace('/--.*$/m', '', $statement);
    $stripped = preg_replace('/\/\*.*?\*\//s', '', $stripped);
    if (empty(trim($stripped))) continue;

    echo "Executing: " . substr($statement, 0, 60) . "...\n";

    if ($conn->query($statement)) {
        $success++;
        echo "  OK\n";
    } else {
        $errors++;
        echo "  ERROR: " . $conn->error . "\n";
    }
}

echo "\nMigration complete!\n";
echo "Successful: $success\n";
echo "Errors: $errors\n";

$conn->close();
