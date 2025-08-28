<?php require './auth/db.php'; 
date_default_timezone_set('America/Chicago'); 


// Check EnforceGPS from settings table
$gpsRequired = false;
$gpsQuery = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = 'EnforceGPS' LIMIT 1");
$gpsQuery->execute();
$gpsQuery->bind_result($value);
if ($gpsQuery->fetch()) {
    $gpsRequired = ($value === '1');
}
$gpsQuery->close();
?>


<!DOCTYPE html>
<html>
<head>
    <title>D-Best TimeClock</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="icon" type="image/png" href="images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#126ab3">
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
    <a href="index.php">🏠 Home</a>
    <a href="./user/login.php">🔐 User Login</a>
    <div class="dropdown">
      <button class="dropbtn">⏱ Settings ▾</button>
      <div class="dropdown-content">
        <a href="./admin/login.php">Admin Login</a>
        <a href="./admin/reports.php">Timeclock Reports</a>
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
  <a href="index.php">🏠 Home</a>
  <a href="./user/login.php">🔐 User Login</a>
  <button class="menu-toggle" id="menuBtn">☰</button>
</nav>

<!-- 📱 Modal Mobile Menu -->
<div class="mobile-menu-overlay" id="mobileMenuOverlay">
  <div class="mobile-menu-content">
    <button class="close-mobile-menu" id="closeMenu">&times;</button>
    <a href="./admin/admin.php">🛠️ Administration</a>
    <a href="reports.php">📊 Reports</a>
    <a href="clock.php">⏱️ Export Hours</a>
  </div>
</div>




<!-- 🔹 Page Content -->
<div class="wrapper">
    <div class="main">
        <h2>Employee Status</h2>
        <table>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <th>Time</th>
                <th>Date</th>
                <th>Notes</th>
            </tr>
            <?php
            // Optimized, secure query to get all users and their latest punch data in one go.
            $sql = "
                WITH LatestPunches AS (
                    SELECT
                        tp.EmployeeID,
                        tp.Date,
                        tp.TimeIN,
                        tp.LunchStart,
                        tp.LunchEnd,
                        tp.TimeOUT,
                        tp.Note,
                        ROW_NUMBER() OVER(PARTITION BY tp.EmployeeID ORDER BY tp.Date DESC, tp.TimeIN DESC) as rn
                    FROM timepunches tp
                )
                SELECT
                    u.ID,
                    u.FirstName,
                    u.LastName,
                    u.ClockStatus,
                    lp.Date AS PunchDate,
                    lp.TimeIN,
                    lp.LunchStart,
                    lp.LunchEnd,
                    lp.TimeOUT,
                    lp.Note AS PunchNote
                FROM users u
                LEFT JOIN LatestPunches lp ON u.ID = lp.EmployeeID AND lp.rn = 1
                ORDER BY u.LastName, u.FirstName;
            ";

            $result = $conn->query($sql);

            function formatTime12h($time) {
                return $time ? date("g:i A", strtotime($time)) : 'N/A';
            }

            while ($row = $result->fetch_assoc()):
                $fullName = $row['FirstName'] . ' ' . $row['LastName'];
                $status = $row['ClockStatus'];
                
                // Determine the most recent time from the single record
                $rawTime = $row['TimeOUT'] ?? $row['LunchEnd'] ?? $row['LunchStart'] ?? $row['TimeIN'];
                $lastTime = formatTime12h($rawTime);
                $lastDate = $row['PunchDate'] ? date("m/d/Y", strtotime($row['PunchDate'])) : 'N/A';
                $note = $row['PunchNote'] ?? '-';
            ?>
            <tr>
                <td><a href="#" onclick="openModal(<?= $row['ID'] ?>, '<?= htmlspecialchars($fullName) ?>')"><?= htmlspecialchars($fullName) ?></a></td>
                <td><span class="status <?= strtolower($status ?: 'out') ?>"><?= htmlspecialchars($status ?: 'Out') ?></span></td>
                <td><?= $lastTime ?></td>
                <td><?= $lastDate ?></td>
                <td><?= htmlspecialchars($note) ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<?php include 'functions/modal.html'; ?>

<script src="js/index.js"></script>






<script src="js/script.js"></script>
<footer>
  <p>D-BEST TimeSmart &copy; <?= date('Y') ?>. All rights reserved.</p>
  <p style="margin-top: 0.3rem;">
    <a href="/docs/privacy.php" style="color:#e0e0e0; text-decoration:none; margin-right:15px;">Privacy Policy</a>
    <a href="/docs/terms.php" style="color:#e0e0e0; text-decoration:none;">Terms of Use</a>
    <a href="/docs/report.php" style="color:#e0e0e0; text-decoration:none;">Report Issues</a>
  </p>
</footer>

</body>
</html>