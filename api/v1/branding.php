<?php
/**
 * Branding API Endpoint
 * GET /api/v1/branding - Get company branding information
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../auth/db.php';
require_once __DIR__ . '/../middleware/ApiResponse.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method not allowed', 405);
}

// No authentication required for branding - it's public information

global $conn;

// Get branding from database
$result = $conn->query("SELECT * FROM branding LIMIT 1");

if (!$result || $result->num_rows === 0) {
    // Return default branding if not configured
    ApiResponse::success([
        'company_name' => 'TimeClock',
        'logo_url' => '/images/FoleyTimePro.png',
        'favicon_url' => '/images/FoleyTimePro-favicon.webp',
        'primary_color' => '#073851',
        'secondary_color' => '#042a3d',
        'accent_color' => '#4CAF50',
        'timezone' => 'America/New_York'
    ]);
}

$branding = $result->fetch_assoc();

// Build full URLs for logo and favicon
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = "$protocol://$host";

$logoPath = $branding['LogoPath'] ?? '/images/FoleyTimePro.png';
$faviconPath = $branding['FaviconPath'] ?? '/images/FoleyTimePro-favicon.webp';

// Make sure paths start with /
if ($logoPath && $logoPath[0] !== '/') {
    $logoPath = '/' . $logoPath;
}
if ($faviconPath && $faviconPath[0] !== '/') {
    $faviconPath = '/' . $faviconPath;
}

ApiResponse::success([
    'company_name' => $branding['CompanyName'] ?? 'TimeClock',
    'logo_url' => $baseUrl . $logoPath,
    'favicon_url' => $baseUrl . $faviconPath,
    'primary_color' => $branding['PrimaryColor'] ?? '#073851',
    'secondary_color' => $branding['SecondaryColor'] ?? '#042a3d',
    'accent_color' => $branding['AccentColor'] ?? '#4CAF50',
    'timezone' => $branding['Timezone'] ?? 'America/New_York'
]);
