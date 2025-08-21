<?php
// Simulate errors for testing
$code = $_GET['code'] ?? null;

if ($code) {
    // Map code to a default message
    $messages = [
        '400' => 'Bad Request',
        '401' => 'Unauthorized',
        '403' => 'Forbidden',
        '404' => 'Page Not Found',
        '500' => 'Internal Server Error',
        '502' => 'Bad Gateway',
        '503' => 'Service Unavailable'
    ];

    $message = $messages[$code] ?? 'Unexpected Error';
    header("Location: error.php?code=$code&message=" . urlencode($message));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test Error Redirect</title>
    <link rel="stylesheet" href="css/test.css">
</head>
<body>
    <h1>Test Redirect to Error Page</h1>
    <p>Click any button to simulate an error:</p>

    <a class="btn" href="?code=400">400 Bad Request</a>
    <a class="btn" href="?code=401">401 Unauthorized</a>
    <a class="btn" href="?code=403">403 Forbidden</a>
    <a class="btn" href="?code=404">404 Not Found</a>
    <a class="btn" href="?code=500">500 Internal Server Error</a>
    <a class="btn" href="?code=502">502 Bad Gateway</a>
    <a class="btn" href="?code=503">503 Service Unavailable</a>
</body>
</html>
