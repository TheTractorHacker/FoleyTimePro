<?php require '../auth/db.php'; 
date_default_timezone_set('America/Chicago'); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Privacy Policy - D-Best TimeClock</title>
  <link rel="stylesheet" href="../css/style.css">
  <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
  <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#126ab3">
  <link rel="stylesheet" href="../css/privacy.css">
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
    <h1>Privacy Policy</h1>
    <p><strong>Effective Date:</strong> July 30, 2025</p>

    <p>D-Best TimeClock is committed to protecting your privacy. This policy outlines what data we collect, how it is used, and your rights regarding your personal information.</p>

    <h3>Information We Collect</h3>
    <ul>
      <li>Employee name and ID</li>
      <li>Clock-in/clock-out timestamps</li>
      <li>IP address and GPS location (if enabled)</li>
    </ul>

    <h3>How We Use Your Data</h3>
    <p>We use your data solely to manage time attendance records and generate reports for payroll and administrative purposes.</p>

    <h3>Data Sharing</h3>
    <p>Your data is not shared with third parties. Only authorized administrators have access to time logs and user information.</p>

    <h3>Security</h3>
    <p>We implement technical and administrative safeguards to protect your data from unauthorized access or misuse.</p>

    <h3>Your Rights</h3>
    <p>You may request a copy of your time records or request corrections by contacting your system administrator.</p>
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