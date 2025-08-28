<?php
/*************************************************
 * Employee Login with DB LockOut + Attempt Countdown
 *************************************************/

/* ---------- Security Headers ---------- */
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'");

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require '../auth/db.php';

/* ---------- Config ---------- */
const MAX_LOGIN_ATTEMPTS_USER = 5;     // lock after this many failures
const FAILURE_DELAY_US_LOGIN  = 350000; // ~0.35s per failure

/* ---------- CSRF ---------- */
function ensure_csrf_token(): void {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
function csrf_valid(): bool {
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

/* ---------- Attempt bookkeeping (session-based) ---------- */
/*
 * We track attempts in session in two maps:
 *  - login_attempts_by_id[<userId>]     => count (when the typed name matches a user)
 *  - login_attempts_by_name[<nameKey>]  => count (when no user match)
 */
if (!isset($_SESSION['login_attempts_by_id']))   $_SESSION['login_attempts_by_id']   = [];
if (!isset($_SESSION['login_attempts_by_name'])) $_SESSION['login_attempts_by_name'] = [];

function norm_name_key(string $name): string {
    $name = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    $name = str_replace(',', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}
function attempts_get_for_userId(int $userId): int {
    return (int)($_SESSION['login_attempts_by_id'][$userId] ?? 0);
}
function attempts_inc_for_userId(int $userId): int {
    $n = attempts_get_for_userId($userId) + 1;
    $_SESSION['login_attempts_by_id'][$userId] = $n;
    return $n;
}
function attempts_clear_for_userId(int $userId): void {
    unset($_SESSION['login_attempts_by_id'][$userId]);
}
function attempts_get_for_name(string $nameKey): int {
    return (int)($_SESSION['login_attempts_by_name'][$nameKey] ?? 0);
}
function attempts_inc_for_name(string $nameKey): int {
    $n = attempts_get_for_name($nameKey) + 1;
    $_SESSION['login_attempts_by_name'][$nameKey] = $n;
    return $n;
}
function attempts_clear_for_name(string $nameKey): void {
    unset($_SESSION['login_attempts_by_name'][$nameKey]);
}

/* ---------- Helpers ---------- */
function parse_name(string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if (strpos($name, ',') !== false) {
        [$last, $first] = array_map('trim', explode(',', $name, 2));
    } else {
        $parts = explode(' ', $name, 2);
        $first = trim($parts[0] ?? '');
        $last  = trim($parts[1] ?? '');
    }
    return [$first, $last];
}

/* ---------- Page state ---------- */
ensure_csrf_token();
$error = '';
$info  = '';     // for non-error notices (e.g., attempts left)
$namePrefill = '';  // keep last typed name across round-trips
$attemptsLeftForView = null; // int|null

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = "Invalid request.";
    } else {
        $namePrefill = trim((string)($_POST['name'] ?? ''));
        $password    = (string)($_POST['password'] ?? '');
        $nameKey     = norm_name_key($namePrefill);

        if ($namePrefill === '' || $password === '') {
            $error = "Please enter both name and password.";
        } else {
            [$first, $last] = parse_name($namePrefill);
            $user = null;

            if ($first !== '' && $last !== '') {
                $stmt = $conn->prepare("
                    SELECT ID, FirstName, LastName, Pass, TwoFAEnabled, LockOut
                    FROM users
                    WHERE FirstName = ? AND LastName = ?
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param("ss", $first, $last);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $user = $res ? $res->fetch_assoc() : null;
                    if ($res) $res->free();
                    $stmt->close();
                }
            }

            /* ---- If we matched a user, enforce DB LockOut & count by userId ---- */
            if ($user) {
                $uid = (int)$user['ID'];

                // If DB says locked, block immediately
                if (!empty($user['LockOut'])) {
                    $error = "Your account is locked. Please contact an administrator.";
                } else {
                    // Not locked: check password
                    $valid = is_string($user['Pass']) && $user['Pass'] !== '' && password_verify($password, $user['Pass']);

                    if ($valid) {
                        // Success: clear attempts for both maps
                        attempts_clear_for_userId($uid);
                        attempts_clear_for_name($nameKey);

                        session_regenerate_id(true);

                        // Log login
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        if ($log = $conn->prepare("INSERT INTO login_logs (EmployeeID, IP) VALUES (?, ?)")) {
                            $log->bind_param("is", $uid, $ip);
                            $log->execute();
                            $log->close();
                        }

                        // 2FA flow
                        if (!empty($user['TwoFAEnabled'])) {
                            $_SESSION['temp_user_id']     = $uid;
                            $_SESSION['temp_first_name']  = (string)$user['FirstName'];
                            $_SESSION['user_2fa_pending'] = true;
                            header("Location: verify_2fa.php");
                            exit;
                        }

                        // Normal login
                        $_SESSION['EmployeeID'] = $uid;
                        $_SESSION['FirstName']  = (string)$user['FirstName'];
                        header("Location: dashboard.php");
                        exit;
                    }

                    // Failure path for a real user
                    usleep(FAILURE_DELAY_US_LOGIN);
                    $count = attempts_inc_for_userId($uid);
                    $remaining = max(0, MAX_LOGIN_ATTEMPTS_USER - $count);

                    if ($count >= MAX_LOGIN_ATTEMPTS_USER) {
                        // Lock the user in DB
                        if ($lk = $conn->prepare("UPDATE users SET LockOut = 1 WHERE ID = ?")) {
                            $lk->bind_param("i", $uid);
                            $lk->execute();
                            $lk->close();
                        }
                        $error = "Too many failed attempts. Your account is now locked.";
                        $attemptsLeftForView = 0;
                    } else {
                        $error = "Invalid login. Attempts remaining: {$remaining}.";
                        $attemptsLeftForView = $remaining;
                    }
                }
            } else {
                /* ---- No matching user: count by nameKey only ---- */
                usleep(FAILURE_DELAY_US_LOGIN);
                $count = attempts_inc_for_name($nameKey);
                $remaining = max(0, MAX_LOGIN_ATTEMPTS_USER - $count);

                // We cannot lock a real account because there isn't one matched;
                // still provide UX countdown to slow guessing.
                if ($count >= MAX_LOGIN_ATTEMPTS_USER) {
                    $error = "Too many failed attempts for this name. Please wait or verify your name.";
                    $attemptsLeftForView = 0;
                } else {
                    $error = "Invalid login. Attempts remaining: {$remaining}.";
                    $attemptsLeftForView = $remaining;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">

    <link rel="stylesheet" href="../css/user.css">
    <link rel="stylesheet" href="../css/style.css">

    <link rel="stylesheet" href="../css/user_login.css">
</head>
<body>

<!-- 🌐 Desktop Nav -->
<header class="topnav desktop-only">
  <div class="topnav-left">
    <img src="/images/D-Best.png" class="nav-logo" alt="Logo">
    <span class="nav-title">D-BEST TimeSmart</span>
  </div>
  <div class="topnav-right">
    <span class="nav-date"><?= htmlspecialchars(date('F j, Y'), ENT_QUOTES, 'UTF-8') ?></span>
    <a href="../index.php">🏠 Home</a>
    <a href="./login.php">🔐 User Login</a>
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
  <a href="./login.php">🔐 User Login</a>
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
  <div class="main">
    <div class="login-container" role="main" aria-labelledby="login-title">
      <div class="login-logo-wrap">
        <img src="/images/D-Best.png" alt="Company Logo">
      </div>

      <h2 id="login-title">Employee Login</h2>

      <?php if (!empty($error)): ?>
        <div class="error" aria-live="assertive"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($attemptsLeftForView !== null && $attemptsLeftForView > 0): ?>
        <div class="info" aria-live="polite">
            Attempts remaining: <?= (int)$attemptsLeftForView ?> of <?= (int)MAX_LOGIN_ATTEMPTS_USER ?>.
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <div class="field" id="nameField">
          <label for="nameInput">Name</label>
          <input
            type="text"
            name="name"
            id="nameInput"
            placeholder="Start typing name..."
            value="<?= htmlspecialchars($namePrefill, ENT_QUOTES, 'UTF-8') ?>"
            required
            autocomplete="name"
            spellcheck="false"
            autocapitalize="words"
            aria-autocomplete="list"
            aria-controls="suggestions"
            aria-expanded="false">
          <ul id="suggestions" class="suggestion-box" role="listbox" aria-label="Name suggestions"></ul>
        </div>

        <div class="field">
          <label for="passInput">PIN / Password</label>
          <input
            type="password"
            name="password"
            id="passInput"
            placeholder="PIN / Password"
            required
            autocomplete="current-password">
        </div>

        <button type="submit">Login</button>

        <div class="help" style="margin-top:0.75rem; font-size:0.85rem; color:#9ca3af;" aria-live="polite">
            Tip: You can type “Last, First” or “First Last”.
        </div>
      </form>
    </div>
  </div>
</div>

<footer>
  <p>D-BEST TimeSmart &copy; <?= htmlspecialchars(date('Y'), ENT_QUOTES, 'UTF-8') ?>. All rights reserved.</p>
  <p style="margin-top: 0.3rem;">
    <a href="/docs/privacy.php">Privacy Policy</a>
    <a href="/docs/terms.php">Terms of Use</a>
    <a href="/docs/report.php">Report Issues</a>
  </p>
</footer>

<script>
// ===== Autocomplete (debounced) =====
const nameInput = document.getElementById("nameInput");
const suggestionsBox = document.getElementById("suggestions");
const nameField = document.getElementById("nameField");

let acAbort = null;
let debounceTimer = null;
let activeIndex = -1;

function clearSuggestions() {
  suggestionsBox.innerHTML = '';
  suggestionsBox.classList.remove('show');
  nameInput.setAttribute('aria-expanded', 'false');
  activeIndex = -1;
}
function showSuggestions(items) {
  clearSuggestions();
  if (!items || !items.length) return;

  suggestionsBox.classList.add('show');
  nameInput.setAttribute('aria-expanded', 'true');

  items.forEach((fullName, idx) => {
    const li = document.createElement("li");
    li.textContent = fullName;
    li.role = "option";
    li.id = `sug-${idx}`;
    li.tabIndex = -1;
    li.addEventListener("mousedown", (e) => {
      e.preventDefault();
      nameInput.value = fullName;
      clearSuggestions();
    });
    suggestionsBox.appendChild(li);
  });
}
function highlight(index) {
  Array.from(suggestionsBox.children).forEach((li, i) => {
    if (i === index) {
      li.setAttribute('aria-selected', 'true');
      li.scrollIntoView({ block: 'nearest' });
    } else {
      li.removeAttribute('aria-selected');
    }
  });
}
function fetchNames(q) {
  if (acAbort) acAbort.abort();
  acAbort = new AbortController();
  fetch(`autocomplete.php?search=${encodeURIComponent(q)}`, { signal: acAbort.signal })
    .then(res => res.ok ? res.json() : [])
    .then(data => showSuggestions(Array.isArray(data) ? data : []))
    .catch(() => {});
}
nameInput.addEventListener("input", () => {
  const q = nameInput.value.trim();
  if (q.length < 2) { clearSuggestions(); return; }
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchNames(q), 180);
});
nameInput.addEventListener("keydown", (e) => {
  const items = Array.from(suggestionsBox.children);
  if (!items.length) return;

  if (e.key === "ArrowDown") {
    e.preventDefault();
    activeIndex = (activeIndex + 1) % items.length;
    highlight(activeIndex);
  } else if (e.key === "ArrowUp") {
    e.preventDefault();
    activeIndex = (activeIndex - 1 + items.length) % items.length;
    highlight(activeIndex);
  } else if (e.key === "Enter") {
    if (activeIndex >= 0 && activeIndex < items.length) {
      e.preventDefault();
      nameInput.value = items[activeIndex].textContent;
      clearSuggestions();
    }
  } else if (e.key === "Escape") {
    clearSuggestions();
  }
});
document.addEventListener("click", (e) => {
  if (!nameField.contains(e.target)) clearSuggestions();
});
</script>
<script src="../js/script.js"></script>
</body>
</html>