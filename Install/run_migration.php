<?php
/**
 * Run Database Migration Script
 * Executes SQL migration files safely
 */

require_once __DIR__ . '/../auth/db.php';

echo "===========================================\n";
echo "D-BEST TimeSmart Migration Runner\n";
echo "===========================================\n\n";

// Read the migration file
$migrationFile = __DIR__ . '/migrations/001_add_shifts_and_enhancements.sql';

if (!file_exists($migrationFile)) {
    die("❌ Migration file not found: $migrationFile\n");
}

echo "📄 Reading migration file: $migrationFile\n";
$sql = file_get_contents($migrationFile);

if (empty($sql)) {
    die("❌ Migration file is empty\n");
}

// Split SQL into individual statements
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function($stmt) {
        // Filter out comments and empty statements
        $stmt = trim($stmt);
        return !empty($stmt) &&
               !str_starts_with($stmt, '--') &&
               !str_starts_with($stmt, '/*');
    }
);

echo "📊 Found " . count($statements) . " SQL statements to execute\n\n";

// Use multi_query for better SQL file handling
echo "📡 Executing migration using multi_query...\n\n";

$successCount = 0;
$errorCount = 0;

// Execute all statements
if ($conn->multi_query($sql)) {
    do {
        // Store first result set
        if ($result = $conn->store_result()) {
            $result->free();
        }

        // Check for errors
        if ($conn->errno) {
            // Check if error is about table already existing
            if ($conn->errno == 1050) { // Table already exists
                echo "⏭️  Table already exists, skipping...\n";
                $successCount++;
            } elseif ($conn->errno == 1061) { // Duplicate key name
                echo "⏭️  Index already exists, skipping...\n";
                $successCount++;
            } elseif ($conn->errno == 1062) { // Duplicate entry
                echo "⏭️  Data already exists, skipping...\n";
                $successCount++;
            } else {
                echo "❌ Error: " . $conn->error . "\n";
                $errorCount++;
            }
        } else {
            $successCount++;
            echo "✅ ";
        }

    } while ($conn->more_results() && $conn->next_result());
}

// Clear any remaining results
while ($conn->more_results() && $conn->next_result()) {
    if ($result = $conn->store_result()) {
        $result->free();
    }
}

echo "\n";

echo "\n===========================================\n";
echo "Migration Complete!\n";
echo "===========================================\n";
echo "✅ Successful: $successCount\n";
echo "❌ Errors: $errorCount\n";
echo "===========================================\n\n";

if ($errorCount == 0) {
    echo "🎉 All migrations executed successfully!\n\n";

    // Verify tables were created
    echo "Verifying tables...\n";
    $tables = ['shifts', 'user_shifts', 'time_off_requests', 'auto_clockout_log',
               'branding', 'api_tokens', 'device_tokens', 'calendar_connections'];

    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            echo "  ✅ $table\n";
        } else {
            echo "  ❌ $table (not found)\n";
        }
    }
} else {
    echo "⚠️  Some migrations failed. Please review errors above.\n";
}

$conn->close();
?>
