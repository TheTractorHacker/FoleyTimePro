<?php
/**
 * Branding Helper Functions
 * Provides global branding configuration for multi-company support
 */

/**
 * Get branding configuration
 * Uses caching for performance
 *
 * @param mysqli|null $conn Database connection (optional, will create if not provided)
 * @param bool $forceRefresh Force refresh from database
 * @return array Associative array with branding settings
 */
function getBranding($conn = null, $forceRefresh = false) {
    // Check session cache first
    if (!$forceRefresh && isset($_SESSION['branding_cache']) &&
        isset($_SESSION['branding_cache_time']) &&
        (time() - $_SESSION['branding_cache_time']) < 3600) { // 1 hour cache
        return $_SESSION['branding_cache'];
    }

    // Check file cache
    $cacheFile = __DIR__ . '/../cache/branding.json';
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cacheTime = filemtime($cacheFile);
        if ((time() - $cacheTime) < 3600) { // 1 hour cache
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached) {
                $_SESSION['branding_cache'] = $cached;
                $_SESSION['branding_cache_time'] = time();
                return $cached;
            }
        }
    }

    // Create connection if not provided
    $closeConn = false;
    if ($conn === null) {
        require_once __DIR__ . '/../auth/db.php';
        $closeConn = true;
    }

    // Default branding (fallback)
    $branding = [
        'CompanyName' => 'FoleyTimePro',
        'LogoPath' => '/images/FoleyTimePro-logo.png',
        'FaviconPath' => '/images/FoleyTimePro-favicon.webp',
        'PrimaryColor' => '#3498db',
        'SecondaryColor' => '#2ecc71',
        'AccentColor' => '#e74c3c',
        'LoginBackground' => null,
        'EmailSignature' => null,
        'ContactEmail' => null,
        'ContactPhone' => null,
        'Address' => null,
        'Timezone' => 'America/Chicago',
        'DateFormat' => 'MM/DD/YYYY',
        'TimeFormat' => '12h'
    ];

    // Fetch from database
    $result = $conn->query("SELECT * FROM branding LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $branding = $result->fetch_assoc();
    }

    // Close connection if we created it
    if ($closeConn && isset($conn)) {
        $conn->close();
    }

    // Cache in session
    $_SESSION['branding_cache'] = $branding;
    $_SESSION['branding_cache_time'] = time();

    // Cache in file
    if (!is_dir(dirname($cacheFile))) {
        mkdir(dirname($cacheFile), 0755, true);
    }
    file_put_contents($cacheFile, json_encode($branding, JSON_PRETTY_PRINT));

    return $branding;
}

/**
 * Clear branding cache
 * Call this after updating branding settings
 */
function clearBrandingCache() {
    // Clear session cache
    if (isset($_SESSION['branding_cache'])) {
        unset($_SESSION['branding_cache']);
    }
    if (isset($_SESSION['branding_cache_time'])) {
        unset($_SESSION['branding_cache_time']);
    }

    // Clear file cache
    $cacheFile = __DIR__ . '/../cache/branding.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * Format date according to branding preferences
 *
 * @param string|int $date Date string or timestamp
 * @param array|null $branding Branding settings (optional)
 * @return string Formatted date
 */
function formatDate($date, $branding = null) {
    if ($branding === null) {
        $branding = getBranding();
    }

    $timestamp = is_numeric($date) ? $date : strtotime($date);

    switch ($branding['DateFormat']) {
        case 'DD/MM/YYYY':
            return date('d/m/Y', $timestamp);
        case 'YYYY-MM-DD':
            return date('Y-m-d', $timestamp);
        case 'MM/DD/YYYY':
        default:
            return date('m/d/Y', $timestamp);
    }
}

/**
 * Format time according to branding preferences
 *
 * @param string|int $time Time string or timestamp
 * @param array|null $branding Branding settings (optional)
 * @return string Formatted time
 */
function formatTime($time, $branding = null) {
    if ($branding === null) {
        $branding = getBranding();
    }

    $timestamp = is_numeric($time) ? $time : strtotime($time);

    if ($branding['TimeFormat'] === '24h') {
        return date('H:i', $timestamp);
    } else {
        return date('g:i A', $timestamp);
    }
}

/**
 * Format datetime according to branding preferences
 *
 * @param string|int $datetime Datetime string or timestamp
 * @param array|null $branding Branding settings (optional)
 * @return string Formatted datetime
 */
function formatDateTime($datetime, $branding = null) {
    if ($branding === null) {
        $branding = getBranding();
    }

    return formatDate($datetime, $branding) . ' ' . formatTime($datetime, $branding);
}

/**
 * Get timezone for date/time operations
 *
 * @param array|null $branding Branding settings (optional)
 * @return DateTimeZone
 */
function getBrandingTimezone($branding = null) {
    if ($branding === null) {
        $branding = getBranding();
    }

    try {
        return new DateTimeZone($branding['Timezone']);
    } catch (Exception $e) {
        return new DateTimeZone('America/Chicago'); // Fallback
    }
}

/**
 * Get email signature HTML
 * Replaces variables with actual values
 *
 * @param array|null $branding Branding settings (optional)
 * @return string HTML email signature
 */
function getEmailSignature($branding = null) {
    if ($branding === null) {
        $branding = getBranding();
    }

    $signature = $branding['EmailSignature'] ?? '';

    // Replace variables
    $signature = str_replace('{{company_name}}', $branding['CompanyName'], $signature);
    $signature = str_replace('{{contact_email}}', $branding['ContactEmail'] ?? '', $signature);
    $signature = str_replace('{{contact_phone}}', $branding['ContactPhone'] ?? '', $signature);
    $signature = str_replace('{{address}}', $branding['Address'] ?? '', $signature);

    // Default signature if none set
    if (empty($signature)) {
        $signature = "<p><strong>{$branding['CompanyName']}</strong></p>";
        if (!empty($branding['ContactEmail'])) {
            $signature .= "<p>Email: {$branding['ContactEmail']}</p>";
        }
        if (!empty($branding['ContactPhone'])) {
            $signature .= "<p>Phone: {$branding['ContactPhone']}</p>";
        }
    }

    return $signature;
}

/**
 * Get absolute URL for a branding asset
 *
 * @param string $path Relative path from branding table
 * @return string Full URL
 */
function getBrandingAssetUrl($path) {
    if (empty($path)) {
        return '';
    }

    // If already absolute URL, return as is
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    // Get protocol and host
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Ensure path starts with /
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    return "$protocol://$host$path";
}

?>
