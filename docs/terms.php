<?php require '../auth/db.php'; 
date_default_timezone_set('America/Chicago'); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Terms of Use - D-Best TimeClock</title>
  <link rel="stylesheet" href="../css/style.css">
  <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
  <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">

    <link rel="manifest" href="/manifest.json">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#126ab3">
  <link rel="stylesheet" href="../css/terms.css">
</head>
<body>

<!-- 🌐 Desktop Nav -->
<header class="topnav desktop-only">
  <div class="topnav-left">
    <img src="/images/D-Best.png" class="nav-logo" alt="Logo">
    <span class="nav-title">D-BEST TimeSmart</span>
  </div>
  <div class="topnav-right">
    <span class="nav-date"><?= date('F j, Y') ?></span>
    <a href="../index.php">🏠 Home</a>
    <a href="../user/login.php">🔐 User Login</a>
    <div class="dropdown">
      <button class="dropbtn">⏱ Settings ▾</button>
      <div class="dropdown-content">
        <a href="../admin/login.php">Admin Login</a>
        <a href="../admin/reports.php">Timeclock Reports</a>
      </div>
    </div>
  </div>
</header>

<!-- 📱 Mobile Banner -->
<div class="mobile-banner mobile-only">
  <img src="/images/D-Best.png" alt="Logo" class="nav-logo">
  <span class="nav-title">D-BEST TimeSmart</span>
</div>

<!-- 📱 Mobile Menu Trigger -->
<nav class="mobile-nav mobile-only">
  <a href="../index.php">🏠 Home</a>
  <a href="../user/login.php">🔐 User Login</a>
  <button class="menu-toggle" id="menuBtn">☰</button>
</nav>

<!-- 📱 Modal Mobile Menu -->
<div class="mobile-menu-overlay" id="mobileMenuOverlay">
  <div class="mobile-menu-content">
    <button class="close-mobile-menu" id="closeMenu">&times;</button>
    <a href="../admin/admin.php">🛠️ Administration</a>
    <a href="../reports.php">📊 Reports</a>
    <a href="../clock.php">⏱️ Export Hours</a>
  </div>
</div>

<div class="wrapper">
  <main class="main card" style="max-width: 800px; margin: auto;">
    <h1>Terms of Use</h1>
    <p><strong>Effective Date:</strong> July 30, 2025</p>

    <h3>Acceptance of Terms</h3>
    <p>By using D-Best TimeClock, you agree to abide by these Terms of Use. If you do not agree, do not use this system.</p>

    <h3>Authorized Use</h3>
    <p>This application is for employee time tracking only. Unauthorized use, data tampering, or attempts to access administrative areas without permission are prohibited.</p>

    <h3>System Access</h3>
    <p>You are responsible for keeping your login credentials secure. All actions taken under your account will be logged and monitored.</p>

    <h3>Modifications</h3>
    <p>We may update these terms at any time. Continued use of the system constitutes acceptance of any changes.</p>
  </main>
</div>

<footer>
  <p>D-BEST TimeSmart &copy; <?= date('Y') ?>. All rights reserved.</p>
  <p style="margin-top: 0.3rem;">
    <a href="/docs/privacy.php" style="color:#e0e0e0; text-decoration:none; margin-right:15px;">Privacy Policy</a>
    <a href="/docs/terms.php" style="color:#e0e0e0; text-decoration:none;">Terms of Use</a>
    <a href="/docs/report.php" style="color:#e0e0e0; text-decoration:none;">Report Issues</a>
  </p>
</footer>

<script src="../js/script.js"></script>
</body>
</html>