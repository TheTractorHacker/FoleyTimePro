<?php
/**
 * PWA Icon Generator Script
 * Generates all required icon sizes from a source image
 *
 * Usage: php scripts/generate_icons.php [source_image]
 * Default source: /images/FoleyTimePro-logo.png
 */

// Configuration
$sourceImage = $argv[1] ?? __DIR__ . '/../images/FoleyTimePro-logo.png';
$outputDir = __DIR__ . '/../images/icons/';

// Required icon sizes for PWA
$sizes = [72, 96, 128, 144, 152, 167, 180, 192, 384, 512];

// Ensure output directory exists
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Check source file exists
if (!file_exists($sourceImage)) {
    die("Error: Source image not found: $sourceImage\n");
}

// Get source image info
$imageInfo = getimagesize($sourceImage);
if ($imageInfo === false) {
    die("Error: Could not read image info from: $sourceImage\n");
}

$sourceWidth = $imageInfo[0];
$sourceHeight = $imageInfo[1];
$imageType = $imageInfo[2];

echo "Source image: $sourceImage\n";
echo "Dimensions: {$sourceWidth}x{$sourceHeight}\n";
echo "Type: " . image_type_to_mime_type($imageType) . "\n";
echo "Output directory: $outputDir\n\n";

// Load source image based on type
switch ($imageType) {
    case IMAGETYPE_PNG:
        $srcImage = imagecreatefrompng($sourceImage);
        break;
    case IMAGETYPE_JPEG:
        $srcImage = imagecreatefromjpeg($sourceImage);
        break;
    case IMAGETYPE_WEBP:
        $srcImage = imagecreatefromwebp($sourceImage);
        break;
    case IMAGETYPE_GIF:
        $srcImage = imagecreatefromgif($sourceImage);
        break;
    default:
        die("Error: Unsupported image type\n");
}

if (!$srcImage) {
    die("Error: Could not load source image\n");
}

// Preserve transparency for PNG
imagealphablending($srcImage, true);
imagesavealpha($srcImage, true);

// Calculate crop to make square (center crop)
$cropSize = min($sourceWidth, $sourceHeight);
$cropX = ($sourceWidth - $cropSize) / 2;
$cropY = ($sourceHeight - $cropSize) / 2;

// Create cropped square version
$squareImage = imagecreatetruecolor($cropSize, $cropSize);
imagealphablending($squareImage, false);
imagesavealpha($squareImage, true);
$transparent = imagecolorallocatealpha($squareImage, 0, 0, 0, 127);
imagefill($squareImage, 0, 0, $transparent);

imagecopy($squareImage, $srcImage, 0, 0, (int)$cropX, (int)$cropY, $cropSize, $cropSize);

// Generate each size
$generatedCount = 0;
foreach ($sizes as $size) {
    $outputPath = $outputDir . "icon-{$size}x{$size}.png";

    // Create new image at target size
    $newImage = imagecreatetruecolor($size, $size);

    // Preserve transparency
    imagealphablending($newImage, false);
    imagesavealpha($newImage, true);
    $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
    imagefill($newImage, 0, 0, $transparent);

    // High quality resize
    imagecopyresampled(
        $newImage,
        $squareImage,
        0, 0, 0, 0,
        $size, $size,
        $cropSize, $cropSize
    );

    // Save as PNG
    if (imagepng($newImage, $outputPath, 9)) {
        echo "Generated: icon-{$size}x{$size}.png\n";
        $generatedCount++;
    } else {
        echo "Error: Failed to generate icon-{$size}x{$size}.png\n";
    }

    imagedestroy($newImage);
}

// Generate maskable icons (with safe zone padding)
// Maskable icons need 10% padding on each side (safe zone)
$maskableSizes = [192, 512];
foreach ($maskableSizes as $size) {
    $outputPath = $outputDir . "maskable-{$size}x{$size}.png";

    // Create new image at target size
    $newImage = imagecreatetruecolor($size, $size);

    // Fill with primary brand color background
    // Using a neutral background that works with most logos
    $bgColor = imagecolorallocate($newImage, 52, 152, 219); // #3498db
    imagefill($newImage, 0, 0, $bgColor);

    // Calculate inner size (80% of total for 10% padding each side)
    $innerSize = (int)($size * 0.8);
    $padding = (int)($size * 0.1);

    // Create temp image for the logo
    $tempImage = imagecreatetruecolor($innerSize, $innerSize);
    imagealphablending($tempImage, false);
    imagesavealpha($tempImage, true);
    $transparent = imagecolorallocatealpha($tempImage, 0, 0, 0, 127);
    imagefill($tempImage, 0, 0, $transparent);

    // Resize source to inner size
    imagecopyresampled(
        $tempImage,
        $squareImage,
        0, 0, 0, 0,
        $innerSize, $innerSize,
        $cropSize, $cropSize
    );

    // Copy onto final image with padding
    imagealphablending($newImage, true);
    imagecopy($newImage, $tempImage, $padding, $padding, 0, 0, $innerSize, $innerSize);

    if (imagepng($newImage, $outputPath, 9)) {
        echo "Generated: maskable-{$size}x{$size}.png\n";
        $generatedCount++;
    } else {
        echo "Error: Failed to generate maskable-{$size}x{$size}.png\n";
    }

    imagedestroy($tempImage);
    imagedestroy($newImage);
}

// Generate shortcut icons (simple colored squares with icons would require SVG or pre-made icons)
// For now, create placeholder icons using the main logo
$shortcutIcons = ['clock-in', 'timesheet', 'dashboard'];
foreach ($shortcutIcons as $name) {
    $outputPath = $outputDir . "{$name}-96.png";

    $newImage = imagecreatetruecolor(96, 96);
    imagealphablending($newImage, false);
    imagesavealpha($newImage, true);
    $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
    imagefill($newImage, 0, 0, $transparent);

    imagecopyresampled(
        $newImage,
        $squareImage,
        0, 0, 0, 0,
        96, 96,
        $cropSize, $cropSize
    );

    if (imagepng($newImage, $outputPath, 9)) {
        echo "Generated: {$name}-96.png\n";
        $generatedCount++;
    }

    imagedestroy($newImage);
}

// Cleanup
imagedestroy($srcImage);
imagedestroy($squareImage);

echo "\nDone! Generated $generatedCount icons.\n";
echo "Icons saved to: $outputDir\n";
