<?php
$code = isset($_GET['code']) ? (int) $_GET['code'] : 500;
http_response_code($code);
$message = $_GET['message'] ?? 'Something went wrong';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title><?= htmlspecialchars($code) ?> | Something went wrong</title>
  <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
  <link rel="icon" type="image/png" href="images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
  <link rel="icon" type="image/webp" href="images/D-Best-favicon.webp">
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <style>
    :root {
      --primary: #0078d7;
      --background: #f9f9f9;
      --text: #2e2e2e;
      --card-bg: #ffffff;
      --error: #e63946;
      --shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --background: #1b1c1d;
        --text: #eeeeee;
        --card-bg: #262728;
        --error: #ff6b6b;
        --shadow: 0 12px 24px rgba(255, 255, 255, 0.05);
      }
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: "Segoe UI", "Helvetica Neue", sans-serif;
      background-color: var(--background);
      color: var(--text);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 2rem;
    }

    .error-box {
      background-color: var(--card-bg);
      padding: 3rem 2rem;
      border-radius: 16px;
      box-shadow: var(--shadow);
      text-align: center;
      max-width: 480px;
      width: 100%;
      animation: fadeIn 0.6s ease;
    }

    .icon {
      font-size: 4.5rem;
      color: var(--error);
      margin-bottom: 1rem;
    }

    .error-code {
      font-size: 3rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
    }

    .error-message {
      font-size: 1.2rem;
      margin-bottom: 2rem;
      color: #666;
    }

    .actions a {
      display: inline-block;
      margin: 0 0.5rem;
      padding: 0.6rem 1.2rem;
      background-color: var(--primary);
      color: #fff;
      text-decoration: none;
      border-radius: 6px;
      transition: background-color 0.3s ease;
    }

    .actions a:hover {
      background-color: #005fa3;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to   { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body>
  <div class="error-box">
    <div class="icon">🚧</div>
    <div class="error-code"><?= htmlspecialchars($code) ?></div>
    <div class="error-message"><?= htmlspecialchars($message) ?></div>
    <div class="actions">
      <a href="javascript:history.back()">Go Back</a>
      <a href="/">Go Home</a>
    </div>
  </div>
</body>
</html>
