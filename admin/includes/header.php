<?php
/**
 * Admin Header — shared across all admin pages
 */
if (!function_exists('getBranding')) {
    require_once __DIR__ . '/../../functions/get_branding.php';
}
if (!isset($conn)) { require_once __DIR__ . '/../../auth/db.php'; }
if (!function_exists('csrf_field')) { require_once __DIR__ . '/csrf.php'; }

$branding    = getBranding($conn);
$pageTitle   = $pageTitle ?? 'Admin';
$headerTitle = $headerTitle ?? $pageTitle;
$adminTheme  = $_COOKIE['admin_theme'] ?? 'light';
$cur         = basename($_SERVER['PHP_SELF']);

$pendingEdits = (int)($conn->query("SELECT COUNT(*) c FROM pending_edits WHERE Status='Pending'")->fetch_assoc()['c'] ?? 0);
$pendingTO    = (int)($conn->query("SELECT COUNT(*) c FROM time_off_requests WHERE Status='Pending'")->fetch_assoc()['c'] ?? 0);

function navLink(string $href, string $label, array $matches, int $badge = 0): string {
    global $cur;
    $active = in_array($cur, $matches) ? ' active' : '';
    $b = $badge > 0 ? '<span class="anb">'.$badge.'</span>' : '';
    return '<a href="'.$href.'" class="'.$active.'">'.$label.$b.'</a>';
}

/**
 * Append ?v=<mtime> to a local asset URL so an edited CSS/JS file is fetched
 * immediately instead of possibly sitting in a browser's cache — nginx serves
 * these with only ETag/Last-Modified (no Cache-Control), which some browsers
 * treat as heuristically cacheable and can skip revalidating on an ordinary
 * reload. Changing the URL itself, not just the file, makes a stale copy
 * impossible: a browser has never seen the new querystring before.
 * Absolute URLs (Google Fonts, any future CDN link) pass through untouched —
 * there is no local file to stat, and cache-busting someone else's CDN asset
 * is not this function's job.
 */
function versionedAsset(string $href): string {
    if (preg_match('#^(https?:)?//#', $href)) return $href;
    $disk = __DIR__ . '/../' . ltrim($href, '/');
    // realpath() also rejects a path that has been rewritten/escaped oddly;
    // fall back to the bare href rather than emit a broken query string.
    $real = realpath($disk);
    if ($real === false) return $href;
    $mtime = @filemtime($real);
    if ($mtime === false) return $href;
    return $href . (str_contains($href, '?') ? '&' : '?') . 'v=' . $mtime;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($adminTheme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($branding['CompanyName']) ?></title>
<link rel="icon" type="image/webp" href="<?= htmlspecialchars($branding['FaviconPath']) ?>">
<meta name="theme-color" content="<?= htmlspecialchars($branding['PrimaryColor'] ?? '#073851') ?>">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['admin_csrf'] ?? '') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(versionedAsset('../css/admin-layout.css')) ?>">
<?php foreach ((array)($additionalCSS ?? []) as $css): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(versionedAsset($css)) ?>">
<?php endforeach; ?>
</head>
<body>

<div class="a-wrap">

  <!-- Top navbar -->
  <header class="a-nav">
    <div class="a-nav-left">
      <button class="a-burger" id="burger" aria-label="Menu">&#9776;</button>
      <a href="dashboard.php" class="a-brand">
        <img src="<?= htmlspecialchars($branding['LogoPath']) ?>" alt="" class="a-brand-logo">
        <span><?= htmlspecialchars($branding['CompanyName']) ?></span>
      </a>
    </div>

    <nav class="a-nav-links" id="navLinks">
      <?= navLink('dashboard.php',       'Dashboard',    ['dashboard.php']) ?>

      <div class="a-dropdown">
        <button class="a-dropbtn<?= in_array($cur,['view_punches.php','summary.php','attendance.php']) ? ' active' : '' ?>">Timesheets</button>
        <div class="a-dropmenu">
          <?= navLink('view_punches.php', 'View Punches', ['view_punches.php']) ?>
          <?= navLink('summary.php',      'Summary',      ['summary.php']) ?>
          <?= navLink('attendance.php',   'Attendance',   ['attendance.php']) ?>
        </div>
      </div>

      <div class="a-dropdown">
        <button class="a-dropbtn<?= in_array($cur,['manage_users.php','add_user.php','edit_user.php','archived_users.php','manage_shifts.php','add_shift.php','edit_shift.php','manage_offices.php']) ? ' active' : '' ?>">People</button>
        <div class="a-dropmenu">
          <?= navLink('manage_users.php',  'Users',         ['manage_users.php','add_user.php','edit_user.php','archived_users.php']) ?>
          <?= navLink('manage_shifts.php', 'Shifts',        ['manage_shifts.php','add_shift.php','edit_shift.php']) ?>
          <?= navLink('manage_offices.php','Offices',       ['manage_offices.php']) ?>
        </div>
      </div>

      <div class="a-dropdown">
        <button class="a-dropbtn<?= in_array($cur,['time_off_requests.php','edits_timesheet.php']) ? ' active' : '' ?>">
          Requests<?php if ($pendingTO + $pendingEdits > 0): ?><span class="anb"><?= $pendingTO + $pendingEdits ?></span><?php endif; ?>
        </button>
        <div class="a-dropmenu">
          <?= navLink('time_off_requests.php','Time Off',      ['time_off_requests.php'], $pendingTO) ?>
          <?= navLink('edits_timesheet.php',  'Edit Requests', ['edits_timesheet.php'],   $pendingEdits) ?>
        </div>
      </div>

      <div class="a-dropdown">
        <button class="a-dropbtn<?= in_array($cur,['geofences.php','quickbooks.php']) ? ' active' : '' ?>">Integrations</button>
        <div class="a-dropmenu">
          <?= navLink('geofences.php', 'Geofences',  ['geofences.php']) ?>
          <?= navLink('quickbooks.php','QuickBooks', ['quickbooks.php']) ?>
        </div>
      </div>

      <div class="a-dropdown">
        <button class="a-dropbtn<?= in_array($cur,['reports.php','manage_admins.php','add_admin.php','edit_admin.php','settings.php','enable_2fa.php','api_keys.php','api-docs.php']) ? ' active' : '' ?>">Admin</button>
        <div class="a-dropmenu">
          <?= navLink('reports.php',         'Reports',         ['reports.php']) ?>
          <?= navLink('custom_reports.php',  'Custom Reports',  ['custom_reports.php']) ?>
          <?= navLink('export_history.php',  'Export History',  ['export_history.php']) ?>
          <?= navLink('manage_admins.php','Admins',   ['manage_admins.php','add_admin.php','edit_admin.php']) ?>
          <?= navLink('api_keys.php',     'API Keys', ['api_keys.php']) ?>
          <?= navLink('api-docs.php',     'API Docs', ['api-docs.php']) ?>
          <?= navLink('settings.php',     'Settings', ['settings.php']) ?>
        </div>
      </div>
    </nav>

    <div class="a-nav-right">
      <button class="a-theme-btn" id="themeBtn" title="Toggle dark mode">
        <span id="themeIcon"><?= $adminTheme === 'dark' ? '&#9728;' : '&#9790;' ?></span>
      </button>
      <a href="../logout.php" class="a-logout-btn">Logout</a>
    </div>
  </header>

  <!-- Page content -->
  <main class="a-main">
    <div class="a-content">

<script>
(function(){
  // Mobile burger
  var burger = document.getElementById('burger');
  var nav    = document.getElementById('navLinks');
  burger && burger.addEventListener('click', function(){ nav.classList.toggle('open'); });

  // Mobile dropdown toggles
  document.querySelectorAll('.a-dropbtn').forEach(function(btn){
    btn.addEventListener('click', function(e){
      if (window.innerWidth > 900) return;
      e.stopPropagation();
      var dd = btn.closest('.a-dropdown');
      dd.classList.toggle('open');
    });
  });
  document.addEventListener('click', function(){ document.querySelectorAll('.a-dropdown.open').forEach(function(d){ d.classList.remove('open'); }); });

  // Dark mode
  document.getElementById('themeBtn').addEventListener('click', function(){
    var html = document.documentElement;
    var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    document.getElementById('themeIcon').innerHTML = next === 'dark' ? '&#9728;' : '&#9790;';
    var e = new Date(); e.setFullYear(e.getFullYear()+1);
    document.cookie = 'admin_theme=' + next + '; path=/; expires=' + e.toUTCString() + '; SameSite=Lax';
  });
})();
</script>
