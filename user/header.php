<?php
date_default_timezone_set('America/Chicago');
session_start();
require '../auth/db.php';

if (!isset($_SESSION['EmployeeID'])) {
    header("Location: login.php");
    exit;
}

$empID = $_SESSION['EmployeeID'];

// Fetch user data including ThemePref
$stmt = $conn->prepare("SELECT FirstName, LastName, ThemePref FROM users WHERE ID = ?");
$stmt->bind_param("i", $empID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$name = $user['FirstName'];
$fullName = $user['FirstName'] . ' ' . $user['LastName'];
$theme = $user['ThemePref'] ?? 'light'; // Default to light theme

// Avatar logic
$avatarPath = "../images/default_avatar.png";
$extensions = ['png', 'jpg', 'jpeg', 'webp'];
foreach ($extensions as $ext) {
    $try = "../avatars/{$empID}_pro.$ext";
    if (file_exists($try)) {
        $avatarPath = $try;
        break;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Dashboard</title>
    <link rel="stylesheet" href="../css/user.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="../js/user_header.js"></script>
</head>
<body data-theme="<?= $theme ?>">

<header class="topnav desktop-only">
  <div class="topnav-left">
    <img src="/images/D-Best.png" class="nav-logo" alt="Logo">
    <?php include '../functions/modal.html'; ?>

<header class="topnav desktop-only">
  <div class="topnav-left">
    <img src="../images/D-Best-favicon.png" class="nav-logo" alt="Logo">
    <span class="nav-title">D-BEST TimeSmart</span>
  </div>
  <div class="topnav-right">
    <span class="nav-date"><?= date('F j, Y') ?></span>
    <div class="profile-dropdown">
      <div class="profile-trigger" onclick="toggleDropdown()">
        <img src="<?= $avatarPath ?>" alt="Avatar" class="profile-avatar">
        <span class="profile-name"><?= htmlspecialchars($name) ?></span>
      </div>
      <div id="profileMenu" class="dropdown-menu hidden">
        <a href="settings.php">👤 Settings</a>
        <a href="timesheet.php">📄 My Timesheet</a>
        <a href="dashboard.php">🏠 Dashboard</a>
        <a href="../logout.php">🚪 Logout</a>
      </div>
    </div>
  </div>
</header>

<nav class="mobile-nav mobile-only">
  <a href="timesheet.php">📄 Sheet</a>
  <a href="dashboard.php">🏠 Home</a>
  <a href="../logout.php">🚪 Logout</a>
  <a href="settings.php">⚙️</a>
</nav>

<div class="wrapper">
    <div class="main">