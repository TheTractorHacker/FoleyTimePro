<?php
/*************************************************
 * Admin Login (hardened)
 * - CSRF protection
 * - Session cookie hardening
 * - Simple cooldown lockout on failures
 * - Neutral errors (no user enumeration)
 * - Optional password rehash on login
 * - Security headers
 *************************************************/

// ---------- Security Headers (adjust CSP if you load externals) ----------
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'");

// ---------- Error Handling (prod defaults) ----------
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ---------- Session Cookie Hardening ----------
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',        // set your domain if needed, e.g. 'admin.example.com'
    'secure'   => true,      // true in production (requires HTTPS)
    'httponly' => true,
    'samesite' => 'Strict',  // 'Lax' if you need cross-site GET flows
]);
session_start();

// ---------- Constants ----------
const MAX_LOGIN_ATTEMPTS           = 5;
const LOCKOUT_SECONDS              = 15 * 60;   // 15 minutes
const FAILURE_DELAY_MICROSECONDS   = 350000;    // ~350ms artificial delay on failures

// ---------- DB ----------
require '../auth/db.php'; // must set $conn (mysqli)
require '../functions/get_branding.php';
$branding      = getBranding($conn);
$brandLogo     = !empty($branding['LogoPath']) ? htmlspecialchars($branding['LogoPath']) : '/images/FoleyTimePro-logo.png';
$brandCompany  = htmlspecialchars($branding['CompanyName'] ?? 'FoleyTimePro');

// ---------- Helpers ----------
function current_username_from_post(): string {
    return trim($_POST['username'] ?? '');
}
function is_locked_out(): bool {
    $until = $_SESSION['admin_lock_until'] ?? 0;
    return is_int($until) && time() < $until;
}
function record_failure_and_maybe_lock(): void {
    $_SESSION['admin_login_attempts'] = (int)($_SESSION['admin_login_attempts'] ?? 0) + 1;
    if ($_SESSION['admin_login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $_SESSION['admin_lock_until'] = time() + LOCKOUT_SECONDS;
    }
}
function clear_failures(): void {
    unset($_SESSION['admin_login_attempts'], $_SESSION['admin_lock_until']);
}
function ensure_csrf_token(): void {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
function csrf_valid(): bool {
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

// ---------- Initialize state ----------
$error = '';
ensure_csrf_token();

// ---------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!csrf_valid()) {
        $error = "Invalid request.";
    } elseif (is_locked_out()) {
        $error = "Too many failed login attempts. Please try again later.";
    } else {
        $username = current_username_from_post();
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = "Please enter both username and password.";
        } else {
            // Lookup user
            $stmt = $conn->prepare("SELECT username, password, TwoFAEnabled FROM admins WHERE username = ?");
            if ($stmt === false) {
                // do not leak DB errors to user
                $error = "Something went wrong. Please try again.";
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                $admin = $result ? $result->fetch_assoc() : null;

                // Default to invalid; only flip to success after verify
                $valid = false;

                if ($admin && is_string($admin['password']) && password_verify($password, $admin['password'])) {
                    $valid = true;

                    // Optional: upgrade hash if algorithm/cost changed
                    if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE admins SET password = ? WHERE username = ?");
                        if ($upd) {
                            $upd->bind_param("ss", $newHash, $admin['username']);
                            $upd->execute();
                            $upd->close();
                        }
                    }
                }

                // Tidy up statement/result
                if ($result) { $result->free(); }
                $stmt->close();

                if ($valid) {
                    clear_failures();
                    session_regenerate_id(true); // prevent fixation

                    if (!empty($admin['TwoFAEnabled'])) {
                        // 2FA flow
                        $_SESSION['2fa_admin_username'] = $admin['username'];
                        $_SESSION['2fa_pending'] = true;
                        header("Location: verify_2fa.php");
                        exit;
                    } else {
                        // Normal login
                        $_SESSION['admin'] = $admin['username'];
                        header("Location: dashboard.php");
                        exit;
                    }
                } else {
                    // Slow down brute-force slightly (uniform delay on any failure)
                    usleep(FAILURE_DELAY_MICROSECONDS);
                    record_failure_and_maybe_lock();
                    $error = "Invalid credentials";
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
  <title>Admin Login — FoleyTimePro</title>
  <link rel="icon" type="image/webp" href="../images/FoleyTimePro-favicon.webp">
  <meta name="robots" content="noindex,nofollow">
  <style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    background: linear-gradient(135deg, #040d14 0%, #073851 50%, #0a2a3d 100%);
  }
  .al-card {
    background: #fff;
    border-radius: 18px;
    padding: 44px 40px 36px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 32px 80px rgba(0,0,0,.45);
  }
  .al-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 32px;
  }
  .al-logo img {
    width: 44px; height: 44px;
    object-fit: contain;
    border-radius: 10px;
    background: #f0f2f5;
    padding: 4px;
  }
  .al-brand { font-size: 20px; font-weight: 800; color: #073851; }
  .al-sub   { font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }
  .al-card h1 { font-size: 22px; font-weight: 700; color: #111827; margin-bottom: 6px; }
  .al-card > p { font-size: 13px; color: #6b7280; margin-bottom: 28px; }
  .al-alert {
    background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;
    padding: 11px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
    margin-bottom: 18px;
  }
  .al-field { margin-bottom: 16px; }
  .al-field label {
    display: block; font-size: 12px; font-weight: 600;
    color: #374151; margin-bottom: 5px;
  }
  .al-field input {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid #e1e4e8; border-radius: 9px;
    font-size: 14px; font-family: inherit; color: #111827;
    background: #fff; outline: none;
    transition: border-color .15s, box-shadow .15s;
  }
  .al-field input:focus {
    border-color: #073851;
    box-shadow: 0 0 0 3px rgba(7,56,81,.1);
  }
  .al-btn {
    width: 100%; padding: 13px;
    background: #073851; color: #fff;
    border: none; border-radius: 9px;
    font-size: 15px; font-weight: 700;
    cursor: pointer; font-family: inherit;
    transition: background .15s; margin-top: 6px;
  }
  .al-btn:hover { background: #042a3d; }
  .al-footer {
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid #e1e4e8;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #9ca3af;
    flex-wrap: wrap;
    gap: 8px;
  }
  .al-footer a { color: #073851; text-decoration: none; font-weight: 600; }
  .al-footer a:hover { text-decoration: underline; }
  .al-lock {
    font-size: 13px; color: #d97706; font-weight: 600;
    background: #fef3c7; padding: 10px 14px;
    border-radius: 8px; margin-top: 14px;
    border: 1px solid #fde68a;
  }
  .al-badge {
    display: inline-block;
    background: #073851; color: #fff;
    font-size: 10px; font-weight: 700;
    padding: 2px 8px; border-radius: 10px;
    text-transform: uppercase; letter-spacing: .06em;
    margin-left: 6px; vertical-align: middle;
  }
  </style>
</head>
<body>

<div class="al-card">
  <div class="al-logo">
    <img src="<?= $brandLogo ?>" alt="Logo"
         onerror="this.onerror=null;this.src='/images/FoleyTimePro-logo.png'">
    <div>
      <div class="al-brand"><?= $brandCompany ?></div>
      <div class="al-sub">Admin Portal</div>
    </div>
  </div>

  <h1>Administrator Sign In <span class="al-badge">Admin</span></h1>
  <p>Restricted access. Authorized personnel only.</p>

  <?php if (!empty($error)): ?>
  <div class="al-alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div class="al-field">
      <label for="username">Username</label>
      <input id="username" name="username" type="text"
             placeholder="Admin username"
             required autocomplete="username"
             value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="al-field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password"
             placeholder="Password"
             required autocomplete="current-password">
    </div>

    <button type="submit" class="al-btn">Sign In</button>

    <?php if (isset($_SESSION['admin_lock_until']) && time() < $_SESSION['admin_lock_until']):
      $remaining = $_SESSION['admin_lock_until'] - time();
      $mins = floor($remaining / 60);
      $secs = $remaining % 60;
    ?>
    <div class="al-lock">
      &#128274; Too many failed attempts. Try again in <?= (int)$mins ?>m <?= (int)$secs ?>s.
    </div>
    <?php endif; ?>
  </form>

  <div class="al-footer">
    <a href="../user/login.php">&#8592; Employee Portal</a>
    <span>2FA supported</span>
  </div>
</div>

</body>
</html>
