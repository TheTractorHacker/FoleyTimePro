<?php
/**
 * Admin CSRF protection helpers.
 * Included by header.php — session must already be started.
 */

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="'
         . htmlspecialchars($_SESSION['admin_csrf'] ?? '', ENT_QUOTES, 'UTF-8')
         . '">';
}

function csrf_valid(): bool {
    return isset($_POST['csrf'])
        && hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf']);
}
