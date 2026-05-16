<?php
/**
 * API Test Script
 * Run this to test the API infrastructure
 */

require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/auth/JWTHandler.php';

echo "=== TimeClock API Test ===\n\n";

// Test 1: Database connection
echo "1. Testing database connection...\n";
if ($conn->ping()) {
    echo "   ✓ Database connected\n\n";
} else {
    echo "   ✗ Database connection failed\n\n";
    exit(1);
}

// Test 2: Check if api_tokens table exists
echo "2. Checking api_tokens table...\n";
$result = $conn->query("SHOW TABLES LIKE 'api_tokens'");
if ($result->num_rows > 0) {
    echo "   ✓ api_tokens table exists\n\n";
} else {
    echo "   ✗ api_tokens table not found\n";
    echo "   Run the database migration to create it\n\n";
}

// Test 3: Check if JWT library is installed
echo "3. Checking JWT library...\n";
if (class_exists('Firebase\JWT\JWT')) {
    echo "   ✓ Firebase JWT library loaded\n\n";
} else {
    echo "   ✗ Firebase JWT library not found\n";
    echo "   Run: composer install\n\n";
}

// Test 4: Generate and validate test token
echo "4. Testing JWT token generation...\n";
try {
    $testUserId = 1;
    $testEmail = "test@example.com";
    $testRole = "user";

    $accessToken = JWTHandler::generateAccessToken($testUserId, $testEmail, $testRole);
    echo "   ✓ Access token generated\n";

    $refreshToken = JWTHandler::generateRefreshToken($testUserId, $testEmail, $testRole);
    echo "   ✓ Refresh token generated\n";

    $validation = JWTHandler::validateAccessToken($accessToken);
    if ($validation['valid']) {
        echo "   ✓ Access token validated\n";
        echo "   Token contains: UserID={$validation['data']->sub}, Email={$validation['data']->email}\n\n";
    } else {
        echo "   ✗ Access token validation failed: {$validation['error']}\n\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 5: Check .env file
echo "5. Checking .env file...\n";
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    echo "   ✓ .env file exists\n";
    $envContent = file_get_contents($envFile);
    if (strpos($envContent, 'JWT_SECRET_KEY') !== false) {
        echo "   ✓ JWT_SECRET_KEY found in .env\n\n";
    } else {
        echo "   ! JWT_SECRET_KEY not found in .env (will be generated on first use)\n\n";
    }
} else {
    echo "   ! .env file not found (will be created on first use)\n\n";
}

// Test 6: Check API directory permissions
echo "6. Checking API directory permissions...\n";
$apiDir = __DIR__;
$perms = fileperms($apiDir);
$octalPerms = substr(sprintf('%o', $perms), -4);
if ($octalPerms >= '0755') {
    echo "   ✓ API directory permissions: $octalPerms\n\n";
} else {
    echo "   ! Warning: API directory permissions: $octalPerms (should be 0755 or higher)\n\n";
}

// Test 7: Check if a test user exists
echo "7. Checking for test users...\n";
$stmt = $conn->prepare("SELECT ID, Email, FirstName, LastName, LockOut FROM users LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "   ✓ Found user: {$user['FirstName']} {$user['LastName']} ({$user['Email']})\n";
    echo "   User ID: {$user['ID']}, Locked: " . ($user['LockOut'] ? 'Yes' : 'No') . "\n\n";
} else {
    echo "   ! No users found in database\n\n";
}

echo "=== Test Complete ===\n\n";
echo "To test the API endpoints, use a tool like Postman or curl:\n\n";
echo "curl -X POST http://your-domain.com/api/v1/auth/login \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -d '{\"email\":\"user@example.com\",\"password\":\"password\"}'\n\n";
