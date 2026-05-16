<?php
/**
 * Dynamic PWA Manifest Generator
 * Generates manifest.json dynamically from branding table for white-label support
 */

// No session needed for manifest
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

require_once __DIR__ . '/auth/db.php';
require_once __DIR__ . '/functions/get_branding.php';

$branding = getBranding($conn);

// Build app name
$companyName = $branding['CompanyName'] ?? 'TimeClock';
$appName = $companyName . ' TimeClock';
$shortName = strlen($companyName) > 12 ? 'TimeClock' : $companyName;

// Get colors
$primaryColor = $branding['PrimaryColor'] ?? '#3498db';
$secondaryColor = $branding['SecondaryColor'] ?? '#2ecc71';

// Build icons array with all required sizes
$iconSizes = [72, 96, 128, 144, 152, 167, 180, 192, 384, 512];
$icons = [];

// Check if custom icons exist in /images/icons/
foreach ($iconSizes as $size) {
    $iconPath = "/images/icons/icon-{$size}x{$size}.png";
    $fullPath = __DIR__ . $iconPath;

    if (file_exists($fullPath)) {
        $icons[] = [
            'src' => $iconPath,
            'sizes' => "{$size}x{$size}",
            'type' => 'image/png',
            'purpose' => 'any'
        ];
    }
}

// Add maskable icons if they exist
foreach ([192, 512] as $size) {
    $maskablePath = "/images/icons/maskable-{$size}x{$size}.png";
    $fullPath = __DIR__ . $maskablePath;

    if (file_exists($fullPath)) {
        $icons[] = [
            'src' => $maskablePath,
            'sizes' => "{$size}x{$size}",
            'type' => 'image/png',
            'purpose' => 'maskable'
        ];
    }
}

// Fallback to default icon if no custom icons exist
if (empty($icons)) {
    // Check for company logo or use default
    $logoPath = $branding['LogoPath'] ?? '/images/FoleyTimePro.png';
    if (!file_exists(__DIR__ . $logoPath)) {
        $logoPath = '/images/FoleyTimePro.png';
    }

    $icons[] = [
        'src' => $logoPath,
        'sizes' => '192x192',
        'type' => 'image/png',
        'purpose' => 'any'
    ];
    $icons[] = [
        'src' => $logoPath,
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'any maskable'
    ];
}

// Build shortcuts for quick actions
$shortcuts = [
    [
        'name' => 'Clock In',
        'short_name' => 'Clock In',
        'description' => 'Quick clock in action',
        'url' => '/index.php?action=clockin',
        'icons' => [
            [
                'src' => '/images/icons/clock-in-96.png',
                'sizes' => '96x96',
                'type' => 'image/png'
            ]
        ]
    ],
    [
        'name' => 'My Timesheet',
        'short_name' => 'Timesheet',
        'description' => 'View your timesheet',
        'url' => '/user/timesheet.php',
        'icons' => [
            [
                'src' => '/images/icons/timesheet-96.png',
                'sizes' => '96x96',
                'type' => 'image/png'
            ]
        ]
    ],
    [
        'name' => 'Dashboard',
        'short_name' => 'Dashboard',
        'description' => 'Go to dashboard',
        'url' => '/user/dashboard.php',
        'icons' => [
            [
                'src' => '/images/icons/dashboard-96.png',
                'sizes' => '96x96',
                'type' => 'image/png'
            ]
        ]
    ]
];

// Remove shortcuts with missing icons
$shortcuts = array_filter($shortcuts, function($shortcut) {
    if (!empty($shortcut['icons'])) {
        foreach ($shortcut['icons'] as $icon) {
            if (!file_exists(__DIR__ . $icon['src'])) {
                return false;
            }
        }
    }
    return true;
});
$shortcuts = array_values($shortcuts); // Re-index array

// Build the manifest
$manifest = [
    'name' => $appName,
    'short_name' => $shortName,
    'description' => "Employee time tracking for {$companyName}",
    'start_url' => '/index.php',
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => $primaryColor,
    'theme_color' => $primaryColor,
    'icons' => $icons,
    'categories' => ['business', 'productivity'],
    'lang' => 'en-US',
    'dir' => 'ltr',
    'prefer_related_applications' => false,
    'related_applications' => []
];

// Add shortcuts if any exist
if (!empty($shortcuts)) {
    $manifest['shortcuts'] = $shortcuts;
}

// Check for related Android app in settings
$result = $conn->query("SELECT SettingValue FROM settings WHERE SettingKey = 'twa_package_name'");
if ($result && $row = $result->fetch_assoc()) {
    $packageName = $row['SettingValue'];
    if (!empty($packageName)) {
        $manifest['related_applications'][] = [
            'platform' => 'play',
            'url' => "https://play.google.com/store/apps/details?id={$packageName}",
            'id' => $packageName
        ];
    }
}

// Output JSON
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
