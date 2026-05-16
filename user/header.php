<?php
/**
 * User portal shared header
 * Starts session, authenticates, fetches employee data, outputs HTML head + nav.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
}

require_once __DIR__ . '/../auth/db.php';

if (!isset($_SESSION['EmployeeID'])) {
    header('Location: login.php');
    exit;
}

$empID    = (int)$_SESSION['EmployeeID'];
$empFirst = $_SESSION['FirstName'] ?? 'Employee';

// Fetch branding
require_once __DIR__ . '/../functions/get_branding.php';
$branding = getBranding($conn);

$curPage  = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? 'Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($branding['CompanyName']) ?></title>
  <link rel="icon" type="image/webp" href="<?= htmlspecialchars($branding['FaviconPath']) ?>">
  <link rel="manifest" href="/manifest.php">
  <meta name="theme-color" content="<?= htmlspecialchars($branding['PrimaryColor'] ?? '#073851') ?>">
  <link rel="stylesheet" href="../css/user-portal.css">
  <?php foreach ((array)($additionalCSS ?? []) as $css): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
  <?php endforeach; ?>
</head>
<body>

<header class="u-nav">
  <a href="dashboard.php" class="u-nav-brand">
    <img src="<?= htmlspecialchars($branding['LogoPath']) ?>" alt="" class="u-nav-logo">
    <span><?= htmlspecialchars($branding['CompanyName']) ?></span>
  </a>
  <nav class="u-nav-links">
    <a href="dashboard.php"  class="<?= $curPage==='dashboard.php'  ?'active':'' ?>">Dashboard</a>
    <a href="timesheet.php"  class="<?= $curPage==='timesheet.php'  ?'active':'' ?>">Timesheet</a>
    <a href="time_off.php"   class="<?= $curPage==='time_off.php'   ?'active':'' ?>">Time Off</a>
    <a href="settings.php"   class="<?= $curPage==='settings.php'   ?'active':'' ?>">Settings</a>
  </nav>
  <div class="u-nav-user">
    Hi, <?= htmlspecialchars($empFirst) ?>
    <a href="../logout.php" class="u-nav-logout">Logout</a>
  </div>
</header>

<!-- Mobile bottom nav -->
<nav class="u-bottom-nav">
  <div class="u-bottom-nav-inner">
    <a href="dashboard.php" class="<?= $curPage==='dashboard.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Home
    </a>
    <a href="timesheet.php" class="<?= $curPage==='timesheet.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Timesheet
    </a>
    <a href="time_off.php" class="<?= $curPage==='time_off.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Time Off
    </a>
    <a href="settings.php" class="<?= $curPage==='settings.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Settings
    </a>
    <a href="../logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</nav>

<div class="u-content">
