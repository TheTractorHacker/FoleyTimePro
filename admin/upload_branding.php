<?php
/**
 * Branding Asset Upload Handler
 * Handles logo, favicon, and background image uploads
 */

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin'])) {
    die('Unauthorized');
}

require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../functions/get_branding.php';
require_once __DIR__ . '/includes/csrf.php';

$uploadType = $_GET['type'] ?? 'logo';
$message = '';
$messageType = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    // CSRF gate — the form emits csrf_field(); reject any POST that doesn't carry a valid token.
    if (!csrf_valid()) {
        http_response_code(403);
        die('Invalid CSRF token. Reload the page and try again.');
    }
    $file = $_FILES['file'];

    // Validate file upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Upload failed with error code: ' . $file['error'];
        $messageType = 'danger';
    } else {
        // Check file size (2MB max)
        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxSize) {
            $message = 'File too large. Maximum size is 2MB.';
            $messageType = 'danger';
        } else {
            // Validate file type based on upload type
            $allowedTypes = [];
            $targetDir = __DIR__ . '/../uploads/branding/';

            switch ($uploadType) {
                case 'logo':
                    $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/webp'];
                    $dbColumn = 'LogoPath';
                    $prefix = 'logo_';
                    break;
                case 'favicon':
                    $allowedTypes = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/webp', 'image/jpeg', 'image/jpg'];
                    $dbColumn = 'FaviconPath';
                    $prefix = 'favicon_';
                    break;
                case 'background':
                    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                    $dbColumn = 'LoginBackground';
                    $prefix = 'background_';
                    break;
                default:
                    $message = 'Invalid upload type';
                    $messageType = 'danger';
            }

            if (empty($message)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedTypes)) {
                    $message = 'Invalid file type. Allowed types: ' . implode(', ', $allowedTypes);
                    $messageType = 'danger';
                } else {
                    // Never trust the client filename's extension — derive it from the
                    // MIME reported by finfo (magic-byte read). This is what stops
                    // uploading "shell.jpg.php" and getting a .php file in /uploads/.
                    $mimeToExt = [
                        'image/jpeg'                => 'jpg',
                        'image/jpg'                 => 'jpg',
                        'image/png'                 => 'png',
                        'image/webp'                => 'webp',
                        'image/svg+xml'             => 'svg',
                        'image/x-icon'              => 'ico',
                        'image/vnd.microsoft.icon'  => 'ico',
                    ];
                    // Logos/favicons are always re-encoded to WebP by optimizeImage;
                    // backgrounds keep their original raster format (whitelisted here).
                    $safeBgExt = $mimeToExt[$mimeType] ?? null;
                    $extension = match ($uploadType) {
                        'logo', 'favicon' => 'webp',
                        'background'      => in_array($safeBgExt, ['jpg', 'png', 'webp'], true) ? $safeBgExt : null,
                        default           => null,
                    };
                    if ($extension === null) {
                        $message = 'Unsupported image format for this upload type.';
                        $messageType = 'danger';
                    } else {
                    $filename  = $prefix . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                    $targetPath = $targetDir . $filename;

                    // Create directory if it doesn't exist
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }

                    // Move uploaded file then auto-resize/recompress
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        if (extension_loaded('gd')) {
                            optimizeImage($targetPath, $mimeType, $extension, $uploadType);
                        }

                        // Get old file path from database
                        $result = $conn->query("SELECT $dbColumn FROM branding WHERE ID = 1");
                        if ($result && $row = $result->fetch_assoc()) {
                            $oldPath = $row[$dbColumn];
                            // Delete old file if it exists and is not default
                            if ($oldPath && !str_contains($oldPath, 'FoleyTimePro')) {
                                $oldFullPath = __DIR__ . '/..' . $oldPath;
                                if (file_exists($oldFullPath)) {
                                    @unlink($oldFullPath);
                                }
                            }
                        }

                        // Update database
                        $relativePath = '/uploads/branding/' . $filename;
                        $stmt = $conn->prepare("UPDATE branding SET $dbColumn = ?, UpdatedAt = NOW() WHERE ID = 1");
                        $stmt->bind_param("s", $relativePath);

                        if ($stmt->execute()) {
                            clearBrandingCache();
                            $message = ucfirst($uploadType) . ' uploaded successfully!';
                            $messageType = 'success';
                        } else {
                            $message = 'Database error: ' . $conn->error;
                            $messageType = 'danger';
                            // Delete uploaded file on DB error
                            @unlink($targetPath);
                        }

                        $stmt->close();
                    } else {
                        $message = 'Failed to move uploaded file';
                        $messageType = 'danger';
                    }
                    } // end of $extension !== null branch
                }
            }
        }
    }
}

/**
 * Auto-resize and recompress an uploaded branding image.
 * Always re-encodes so the stored file is compact regardless of original size.
 * Logos/favicons are saved as WebP; backgrounds keep their original format.
 */
function optimizeImage(string $path, string $srcMime, string $outExt, string $uploadType): void {
    // Max dimensions and quality per asset type
    $cfg = match($uploadType) {
        'favicon'    => ['w' => 256,  'h' => 256,  'q' => 90],
        'background' => ['w' => 1920, 'h' => 1080, 'q' => 82],
        default      => ['w' => 600,  'h' => 300,  'q' => 90],  // logo
    };

    // Decode source image
    $src = match($srcMime) {
        'image/jpeg', 'image/jpg' => imagecreatefromjpeg($path),
        'image/png'               => imagecreatefrompng($path),
        'image/webp'              => imagecreatefromwebp($path),
        default                   => null,
    };
    if (!$src) return;

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Scale to fit within target box, never upscale
    $scale = min(1.0, $cfg['w'] / $srcW, $cfg['h'] / $srcH);
    $dstW  = max(1, (int)round($srcW * $scale));
    $dstH  = max(1, (int)round($srcH * $scale));

    // Create canvas with transparency support
    $canvas = imagecreatetruecolor($dstW, $dstH);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

    imagecopyresampled($canvas, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($src);

    // Encode to final format
    match($outExt) {
        'webp'       => imagewebp($canvas, $path, $cfg['q']),
        'jpg', 'jpeg'=> imagejpeg($canvas, $path, $cfg['q']),
        'png'        => imagepng($canvas, $path, (int)round((100 - $cfg['q']) / 11)),
        default      => null,
    };

    imagedestroy($canvas);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload <?= ucfirst($uploadType) ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        .upload-container {
            max-width: 500px;
            margin: 0 auto;
        }
        .drop-zone {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            background: #f9f9f9;
            cursor: pointer;
            transition: all 0.3s;
        }
        .drop-zone.dragover {
            border-color: #3498db;
            background: #e8f4f8;
        }
        .drop-zone input[type="file"] {
            display: none;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
        .btn-primary {
            background-color: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background-color: #2980b9;
        }
        .preview {
            margin-top: 20px;
            max-width: 100%;
        }
        .preview img {
            max-width: 100%;
            max-height: 300px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="upload-container">
        <h2>Upload <?= ucfirst($uploadType) ?></h2>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
<?= csrf_field() ?>
            <div class="drop-zone" id="dropZone">
                <p>📁 Drag & drop your file here or click to browse</p>
                <input type="file" name="file" id="fileInput" accept="<?php
                    switch ($uploadType) {
                        case 'logo':
                            echo 'image/png,image/jpeg,image/svg+xml,image/webp';
                            break;
                        case 'favicon':
                            echo 'image/x-icon,image/png';
                            break;
                        case 'background':
                            echo 'image/jpeg,image/png,image/webp';
                            break;
                    }
                ?>" required>
                <br>
                <small>
                    <?php
                    switch ($uploadType) {
                        case 'logo':
                            echo 'PNG, JPG, SVG, or WebP • Max 2MB';
                            break;
                        case 'favicon':
                            echo 'ICO or PNG • 32x32px recommended';
                            break;
                        case 'background':
                            echo 'JPG, PNG, or WebP • 1920x1080px recommended • Max 2MB';
                            break;
                    }
                    ?>
                </small>
            </div>

            <div class="preview" id="preview" style="display: none;">
                <h3>Preview:</h3>
                <img id="previewImage" src="" alt="Preview">
            </div>

            <button type="submit" class="btn btn-primary" id="uploadBtn" style="display: none;">
                Upload <?= ucfirst($uploadType) ?>
            </button>
        </form>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const preview = document.getElementById('preview');
        const previewImage = document.getElementById('previewImage');
        const uploadBtn = document.getElementById('uploadBtn');
        const uploadForm = document.getElementById('uploadForm');

        // Click to browse
        dropZone.addEventListener('click', () => {
            fileInput.click();
        });

        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');

            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                showPreview(files[0]);
            }
        });

        // File selected
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                showPreview(e.target.files[0]);
            }
        });

        // Show preview
        function showPreview(file) {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    previewImage.src = e.target.result;
                    preview.style.display = 'block';
                    uploadBtn.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
                uploadBtn.style.display = 'block';
            }
        }

        // Auto-close on success
        <?php if ($messageType === 'success'): ?>
        setTimeout(() => {
            if (window.parent) {
                window.parent.postMessage('upload_success', '*');
            }
        }, 2000);
        <?php endif; ?>
    </script>
</body>
</html>
