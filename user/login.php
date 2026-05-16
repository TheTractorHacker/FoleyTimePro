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
            $error = "Please enter your name and PIN.";
        } else {
            [$first, $last] = parse_name($namePrefill);
            $user = null;

            if ($first !== '' && $last !== '') {
                $stmt = $conn->prepare("
                    SELECT ID, FirstName, LastName, Pass, UserPIN, TwoFAEnabled, LockOut
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
                    // PIN if set, otherwise fall back to password
                    if (!empty($user['UserPIN'])) {
                        $valid = hash_equals($user['UserPIN'], hash('sha256', $password));
                    } else {
                        $valid = is_string($user['Pass']) && $user['Pass'] !== '' && password_verify($password, $user['Pass']);
                    }

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Employee Login</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" type="image/webp" href="../images/FoleyTimePro-favicon.webp">
  <link rel="stylesheet" href="../css/user-portal.css">
</head>
<body style="padding-bottom:0">

<div class="u-login-wrap">
  <div class="u-login-card">

    <div class="u-login-logo">
      <img src="../images/FoleyTimePro-logo.png" alt="Logo"
           onerror="this.onerror=null;this.src='../images/FoleyTimePro-favicon.webp'">
      <div>
        <div class="u-login-logo-name">FoleyTimePro</div>
        <div class="u-login-logo-sub">Employee Portal</div>
      </div>
    </div>

    <h1>Sign In</h1>
    <p>Enter your name and PIN to continue.</p>

    <?php if (!empty($error)): ?>
      <div class="u-alert u-alert-error" role="alert">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

      <div class="u-form-group u-field-wrap" id="nameField">
        <label for="nameInput">Full Name</label>
        <input
          type="text"
          name="name"
          id="nameInput"
          placeholder="First Last  or  Last, First"
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

      <div class="u-form-group">
        <label for="passInput">PIN or Password</label>
        <input
          type="password"
          name="password"
          id="passInput"
          placeholder="Enter your PIN or password"
          required
          autocomplete="current-password"
          inputmode="numeric">
      </div>

      <button type="submit" class="u-login-submit">Sign In</button>
    </form>

    <div class="u-login-footer">
      <a href="/index.php">Back to Home</a>
      <a href="/admin/login.php">Admin Login</a>
      <a href="/docs/privacy.php">Privacy</a>
    </div>

  </div>
</div>

<script>
const nameInput = document.getElementById("nameInput");
const suggestionsBox = document.getElementById("suggestions");
const nameField = document.getElementById("nameField");
let acAbort = null, debounceTimer = null, activeIndex = -1;

function clearSuggestions() {
  suggestionsBox.innerHTML = '';
  nameInput.setAttribute('aria-expanded', 'false');
  activeIndex = -1;
}
function showSuggestions(items) {
  clearSuggestions();
  if (!items || !items.length) return;
  nameInput.setAttribute('aria-expanded', 'true');
  items.forEach((name, idx) => {
    const li = document.createElement('li');
    li.textContent = name;
    li.role = 'option';
    li.tabIndex = -1;
    li.addEventListener('mousedown', e => { e.preventDefault(); nameInput.value = name; clearSuggestions(); });
    suggestionsBox.appendChild(li);
  });
}
function highlight(i) {
  Array.from(suggestionsBox.children).forEach((li, j) => {
    li[i===j ? 'setAttribute' : 'removeAttribute']('aria-selected', 'true');
    if (i===j) li.scrollIntoView({ block: 'nearest' });
  });
}
nameInput.addEventListener('input', () => {
  const q = nameInput.value.trim();
  if (q.length < 2) { clearSuggestions(); return; }
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    if (acAbort) acAbort.abort();
    acAbort = new AbortController();
    fetch(`autocomplete.php?search=${encodeURIComponent(q)}`, { signal: acAbort.signal })
      .then(r => r.ok ? r.json() : [])
      .then(d => showSuggestions(Array.isArray(d) ? d : []))
      .catch(() => {});
  }, 180);
});
nameInput.addEventListener('keydown', e => {
  const items = Array.from(suggestionsBox.children);
  if (!items.length) return;
  if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = (activeIndex+1) % items.length; highlight(activeIndex); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = (activeIndex-1+items.length) % items.length; highlight(activeIndex); }
  else if (e.key === 'Enter' && activeIndex >= 0) { e.preventDefault(); nameInput.value = items[activeIndex].textContent; clearSuggestions(); }
  else if (e.key === 'Escape') clearSuggestions();
});
document.addEventListener('click', e => { if (!nameField.contains(e.target)) clearSuggestions(); });
</script>
</body>
</html>
