<?php
/**
 * Dynamic Theme CSS Generator
 * Generates CSS based on branding settings
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set content type
header('Content-Type: text/css; charset=UTF-8');

// Enable caching (1 hour)
header('Cache-Control: public, max-age=3600');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

// Get branding settings
require_once __DIR__ . '/../functions/get_branding.php';
$branding = getBranding();

// Extract colors
$primaryColor = $branding['PrimaryColor'] ?? '#3498db';
$secondaryColor = $branding['SecondaryColor'] ?? '#2ecc71';
$accentColor = $branding['AccentColor'] ?? '#e74c3c';

// Calculate lighter/darker variants
function adjustBrightness($hex, $percent) {
    $hex = str_replace('#', '', $hex);
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $r = max(0, min(255, $r + ($r * $percent / 100)));
    $g = max(0, min(255, $g + ($g * $percent / 100)));
    $b = max(0, min(255, $b + ($b * $percent / 100)));

    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

$primaryLight = adjustBrightness($primaryColor, 20);
$primaryDark = adjustBrightness($primaryColor, -20);
$secondaryLight = adjustBrightness($secondaryColor, 20);
$secondaryDark = adjustBrightness($secondaryColor, -20);
$accentLight = adjustBrightness($accentColor, 20);
$accentDark = adjustBrightness($accentColor, -20);

?>
/**
 * Dynamic Theme CSS
 * Generated from branding settings
 * Company: <?= htmlspecialchars($branding['CompanyName']) ?>

 */

:root {
    /* Brand Colors */
    --brand-primary: <?= $primaryColor ?>;
    --brand-primary-light: <?= $primaryLight ?>;
    --brand-primary-dark: <?= $primaryDark ?>;

    --brand-secondary: <?= $secondaryColor ?>;
    --brand-secondary-light: <?= $secondaryLight ?>;
    --brand-secondary-dark: <?= $secondaryDark ?>;

    --brand-accent: <?= $accentColor ?>;
    --brand-accent-light: <?= $accentLight ?>;
    --brand-accent-dark: <?= $accentDark ?>;

    /* Semantic Colors */
    --color-success: <?= $secondaryColor ?>;
    --color-warning: #f39c12;
    --color-error: <?= $accentColor ?>;
    --color-info: <?= $primaryColor ?>;
}

/* Primary Buttons */
.btn-primary,
button.btn-primary,
input[type="submit"].btn-primary,
a.btn-primary {
    background-color: var(--brand-primary) !important;
    border-color: var(--brand-primary) !important;
    color: #ffffff !important;
}

.btn-primary:hover,
button.btn-primary:hover,
input[type="submit"].btn-primary:hover,
a.btn-primary:hover {
    background-color: var(--brand-primary-dark) !important;
    border-color: var(--brand-primary-dark) !important;
}

/* Secondary Buttons */
.btn-secondary,
button.btn-secondary,
a.btn-secondary {
    background-color: var(--brand-secondary) !important;
    border-color: var(--brand-secondary) !important;
    color: #ffffff !important;
}

.btn-secondary:hover,
button.btn-secondary:hover,
a.btn-secondary:hover {
    background-color: var(--brand-secondary-dark) !important;
    border-color: var(--brand-secondary-dark) !important;
}

/* Success Buttons */
.btn-success {
    background-color: var(--color-success) !important;
    border-color: var(--color-success) !important;
}

.btn-success:hover {
    background-color: var(--brand-secondary-dark) !important;
    border-color: var(--brand-secondary-dark) !important;
}

/* Danger/Delete Buttons */
.btn-danger {
    background-color: var(--brand-accent) !important;
    border-color: var(--brand-accent) !important;
}

.btn-danger:hover {
    background-color: var(--brand-accent-dark) !important;
    border-color: var(--brand-accent-dark) !important;
}

/* Links */
a {
    color: var(--brand-primary);
}

a:hover {
    color: var(--brand-primary-dark);
}

/* Navigation */
.navbar,
nav {
    background-color: var(--brand-primary) !important;
}

.navbar-brand {
    color: #ffffff !important;
}

/* Active states */
.active,
.nav-link.active,
a.active {
    background-color: var(--brand-accent) !important;
    color: #ffffff !important;
}

/* Badges */
.badge-primary {
    background-color: var(--brand-primary) !important;
}

.badge-success {
    background-color: var(--color-success) !important;
}

.badge-danger {
    background-color: var(--color-error) !important;
}

.badge-warning {
    background-color: var(--color-warning) !important;
}

.badge-info {
    background-color: var(--color-info) !important;
}

/* Status indicators */
.status-in,
.clock-status.in {
    color: var(--color-success);
    border-color: var(--color-success);
}

.status-out,
.clock-status.out {
    color: var(--color-error);
    border-color: var(--color-error);
}

.status-lunch,
.clock-status.lunch {
    color: var(--color-warning);
    border-color: var(--color-warning);
}

/* Clock buttons */
.clock-in-btn {
    background: linear-gradient(135deg, var(--brand-secondary) 0%, var(--brand-secondary-dark) 100%);
    border: none;
    color: white;
}

.clock-out-btn {
    background: linear-gradient(135deg, var(--brand-accent) 0%, var(--brand-accent-dark) 100%);
    border: none;
    color: white;
}

.lunch-btn {
    background: linear-gradient(135deg, var(--color-warning) 0%, #e67e22 100%);
    border: none;
    color: white;
}

/* Cards and panels */
.card-header {
    background-color: var(--brand-primary);
    color: white;
}

.panel-heading {
    background-color: var(--brand-primary);
    color: white;
}

/* Tables */
.table thead th {
    background-color: var(--brand-primary);
    color: white;
    border-color: var(--brand-primary-dark);
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: rgba(<?= hexdec(substr($primaryColor, 1, 2)) ?>, <?= hexdec(substr($primaryColor, 3, 2)) ?>, <?= hexdec(substr($primaryColor, 5, 2)) ?>, 0.05);
}

/* Progress bars */
.progress-bar {
    background-color: var(--brand-primary);
}

/* Forms */
input[type="submit"],
button[type="submit"] {
    background-color: var(--brand-primary);
    border-color: var(--brand-primary);
    color: white;
}

input[type="submit"]:hover,
button[type="submit"]:hover {
    background-color: var(--brand-primary-dark);
    border-color: var(--brand-primary-dark);
}

input:focus,
textarea:focus,
select:focus {
    border-color: var(--brand-primary);
    outline-color: var(--brand-primary);
    box-shadow: 0 0 0 0.2rem rgba(<?= hexdec(substr($primaryColor, 1, 2)) ?>, <?= hexdec(substr($primaryColor, 3, 2)) ?>, <?= hexdec(substr($primaryColor, 5, 2)) ?>, 0.25);
}

/* Alerts */
.alert-success {
    background-color: var(--brand-secondary-light);
    border-color: var(--brand-secondary);
    color: var(--brand-secondary-dark);
}

.alert-danger {
    background-color: var(--brand-accent-light);
    border-color: var(--brand-accent);
    color: var(--brand-accent-dark);
}

.alert-info {
    background-color: var(--brand-primary-light);
    border-color: var(--brand-primary);
    color: var(--brand-primary-dark);
}

/* Spinners and loaders */
.spinner {
    border-top-color: var(--brand-primary);
}

/* Custom checkboxes and radios */
input[type="checkbox"]:checked,
input[type="radio"]:checked {
    background-color: var(--brand-primary);
    border-color: var(--brand-primary);
}

/* Pagination */
.pagination .active a {
    background-color: var(--brand-primary);
    border-color: var(--brand-primary);
}

.pagination a:hover {
    background-color: var(--brand-primary-light);
    border-color: var(--brand-primary-light);
}

/* Tooltips */
.tooltip {
    background-color: var(--brand-primary);
}

/* Modals */
.modal-header {
    background-color: var(--brand-primary);
    color: white;
}

/* Sidebar (if exists) */
.sidebar {
    background-color: var(--brand-primary-dark);
}

.sidebar a {
    color: rgba(255, 255, 255, 0.8);
}

.sidebar a:hover,
.sidebar a.active {
    background-color: var(--brand-primary);
    color: white;
}

<?php if (!empty($branding['LoginBackground'])): ?>
/* Login page background */
.login-page,
.login-container,
body.login {
    background-image: url('<?= htmlspecialchars(getBrandingAssetUrl($branding['LoginBackground'])) ?>');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-attachment: fixed;
}
<?php endif; ?>

/* Utility classes */
.text-primary {
    color: var(--brand-primary) !important;
}

.text-secondary {
    color: var(--brand-secondary) !important;
}

.text-accent {
    color: var(--brand-accent) !important;
}

.bg-primary {
    background-color: var(--brand-primary) !important;
    color: white;
}

.bg-secondary {
    background-color: var(--brand-secondary) !important;
    color: white;
}

.bg-accent {
    background-color: var(--brand-accent) !important;
    color: white;
}

.border-primary {
    border-color: var(--brand-primary) !important;
}

.border-secondary {
    border-color: var(--brand-secondary) !important;
}

.border-accent {
    border-color: var(--brand-accent) !important;
}

/* Dashboard widgets */
.widget {
    border-top: 3px solid var(--brand-primary);
}

.widget-success {
    border-top-color: var(--brand-secondary);
}

.widget-danger {
    border-top-color: var(--brand-accent);
}

.widget-warning {
    border-top-color: var(--color-warning);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .navbar {
        background-color: var(--brand-primary-dark) !important;
    }
}
