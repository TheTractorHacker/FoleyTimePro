<?php
/**
 * Admin Branding Portal
 * Customize company branding, colors, logo, and settings
 */

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../functions/get_branding.php';

// Get current branding
$branding = getBranding($conn);

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_branding'])) {
    // CSRF protection
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        $message = 'Invalid security token';
        $messageType = 'danger';
    } else {
        // Sanitize inputs
        $companyName = htmlspecialchars(trim($_POST['company_name']));
        $primaryColor = htmlspecialchars(trim($_POST['primary_color']));
        $secondaryColor = htmlspecialchars(trim($_POST['secondary_color']));
        $accentColor = htmlspecialchars(trim($_POST['accent_color']));
        $contactEmail = filter_var(trim($_POST['contact_email']), FILTER_SANITIZE_EMAIL);
        $contactPhone = htmlspecialchars(trim($_POST['contact_phone']));
        $address = htmlspecialchars(trim($_POST['address']));
        $timezone = htmlspecialchars(trim($_POST['timezone']));
        $dateFormat = htmlspecialchars(trim($_POST['date_format']));
        $timeFormat = htmlspecialchars(trim($_POST['time_format']));
        $emailSignature = $_POST['email_signature']; // Allow HTML

        // Update database
        $stmt = $conn->prepare("
            UPDATE branding SET
                CompanyName = ?,
                PrimaryColor = ?,
                SecondaryColor = ?,
                AccentColor = ?,
                ContactEmail = ?,
                ContactPhone = ?,
                Address = ?,
                Timezone = ?,
                DateFormat = ?,
                TimeFormat = ?,
                EmailSignature = ?,
                UpdatedAt = NOW()
            WHERE ID = 1
        ");

        $stmt->bind_param(
            "sssssssssss",
            $companyName,
            $primaryColor,
            $secondaryColor,
            $accentColor,
            $contactEmail,
            $contactPhone,
            $address,
            $timezone,
            $dateFormat,
            $timeFormat,
            $emailSignature
        );

        if ($stmt->execute()) {
            clearBrandingCache();
            $branding = getBranding($conn, true);
            $message = 'Branding settings updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Error updating branding: ' . $conn->error;
            $messageType = 'danger';
        }

        $stmt->close();
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Get list of timezones
$timezones = DateTimeZone::listIdentifiers();

// Setup for header
$pageTitle = 'Branding Settings';
$headerTitle = 'Branding & Customization';
$additionalCSS = [];
?>
<?php include 'includes/header.php'; ?>

<div class="page-header">
  <h1>Branding &amp; Customization</h1>
  <div style="display:flex;gap:8px">
    <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" form="brandingForm" class="btn btn-primary">Save Changes</button>
  </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="POST" id="brandingForm">
  <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
  <input type="hidden" name="update_branding" value="1">

  <!-- Company Info -->
  <div class="card" style="margin-bottom:16px">
    <h2>Company Information</h2>
    <div class="form-row">
      <div class="form-group">
        <label>Company Name <span style="color:#e74c3c">*</span></label>
        <input type="text" name="company_name" value="<?= htmlspecialchars($branding['CompanyName']) ?>" required>
        <small style="color:#6b7280;font-size:12px">Appears throughout the app and in emails</small>
      </div>
      <div class="form-group">
        <label>Contact Email</label>
        <input type="email" name="contact_email" value="<?= htmlspecialchars($branding['ContactEmail'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Contact Phone</label>
        <input type="tel" name="contact_phone" value="<?= htmlspecialchars($branding['ContactPhone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Company Address</label>
        <input type="text" name="address" value="<?= htmlspecialchars($branding['Address'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Theme Colors -->
  <div class="card" style="margin-bottom:16px">
    <h2>Theme Colors</h2>
    <div class="form-row">
      <?php foreach ([
        ['primary_color',   'Primary Color',   'Main nav &amp; buttons',    $branding['PrimaryColor']],
        ['secondary_color', 'Secondary Color', 'Success &amp; accents',     $branding['SecondaryColor']],
        ['accent_color',    'Accent Color',    'Errors &amp; warnings',     $branding['AccentColor']],
      ] as [$id, $label, $hint, $val]): ?>
      <div class="form-group">
        <label><?= $label ?></label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="<?= $id ?>_picker" value="<?= htmlspecialchars($val) ?>"
                 style="width:44px;height:38px;padding:2px;border:1.5px solid #d1d5db;border-radius:7px;cursor:pointer;flex-shrink:0">
          <input type="text" name="<?= $id ?>" id="<?= $id ?>"
                 value="<?= htmlspecialchars($val) ?>" pattern="^#[0-9A-Fa-f]{6}$" required>
        </div>
        <small style="color:#6b7280;font-size:12px"><?= $hint ?></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Logo & Images -->
  <div class="card" style="margin-bottom:16px">
    <h2>Logo &amp; Images</h2>
    <div class="form-row">
      <div class="form-group">
        <label>Company Logo</label>
        <?php if ($branding['LogoPath']): ?>
          <img src="<?= htmlspecialchars($branding['LogoPath']) ?>" alt="Logo" style="height:60px;margin-bottom:8px;display:block;border-radius:6px;border:1px solid #e1e4e8">
        <?php endif; ?>
        <div style="border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;background:#f9fafb">
          <a href="#" onclick="document.getElementById('uploadLogoModal').style.display='block'; return false;" class="btn btn-sm btn-secondary">Upload Logo</a>
          <p style="margin:8px 0 0;font-size:12px;color:#9ca3af">PNG or SVG, max 2MB</p>
        </div>
      </div>
      <div class="form-group">
        <label>Favicon</label>
        <?php if ($branding['FaviconPath']): ?>
          <img src="<?= htmlspecialchars($branding['FaviconPath']) ?>" alt="Favicon" style="width:32px;height:32px;margin-bottom:8px;display:block">
        <?php endif; ?>
        <div style="border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;background:#f9fafb">
          <a href="#" onclick="document.getElementById('uploadFaviconModal').style.display='block'; return false;" class="btn btn-sm btn-secondary">Upload Favicon</a>
          <p style="margin:8px 0 0;font-size:12px;color:#9ca3af">ICO or PNG, 32×32px</p>
        </div>
      </div>
      <div class="form-group">
        <label>Login Background</label>
        <?php if ($branding['LoginBackground']): ?>
          <img src="<?= htmlspecialchars($branding['LoginBackground']) ?>" alt="Background" style="height:60px;margin-bottom:8px;display:block;border-radius:6px;border:1px solid #e1e4e8;object-fit:cover;width:100%">
        <?php endif; ?>
        <div style="border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;background:#f9fafb">
          <a href="#" onclick="document.getElementById('uploadBackgroundModal').style.display='block'; return false;" class="btn btn-sm btn-secondary">Upload Background</a>
          <p style="margin:8px 0 0;font-size:12px;color:#9ca3af">JPG, 1920×1080px recommended</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Regional Settings -->
  <div class="card" style="margin-bottom:16px">
    <h2>Regional Settings</h2>
    <div class="form-row">
      <div class="form-group">
        <label>Timezone <span style="color:#e74c3c">*</span></label>
        <select name="timezone" required>
          <?php foreach ($timezones as $tz): ?>
            <option value="<?= htmlspecialchars($tz) ?>" <?= $tz === $branding['Timezone'] ? 'selected' : '' ?>><?= htmlspecialchars($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Date Format <span style="color:#e74c3c">*</span></label>
        <select name="date_format" required>
          <option value="MM/DD/YYYY" <?= $branding['DateFormat'] === 'MM/DD/YYYY' ? 'selected' : '' ?>>MM/DD/YYYY (US)</option>
          <option value="DD/MM/YYYY" <?= $branding['DateFormat'] === 'DD/MM/YYYY' ? 'selected' : '' ?>>DD/MM/YYYY (Europe)</option>
          <option value="YYYY-MM-DD" <?= $branding['DateFormat'] === 'YYYY-MM-DD' ? 'selected' : '' ?>>YYYY-MM-DD (ISO)</option>
        </select>
      </div>
      <div class="form-group">
        <label>Time Format <span style="color:#e74c3c">*</span></label>
        <select name="time_format" required>
          <option value="12h" <?= $branding['TimeFormat'] === '12h' ? 'selected' : '' ?>>12-hour (AM/PM)</option>
          <option value="24h" <?= $branding['TimeFormat'] === '24h' ? 'selected' : '' ?>>24-hour</option>
        </select>
      </div>
    </div>
  </div>

  <!-- Email Signature -->
  <div class="card" style="margin-bottom:16px">
    <h2>Email Signature</h2>
    <div class="form-group">
      <label>Signature HTML</label>
      <textarea name="email_signature" rows="6" style="font-family:monospace;font-size:13px"><?= htmlspecialchars($branding['EmailSignature'] ?? '') ?></textarea>
      <small style="color:#6b7280;font-size:12px">Variables: {{company_name}}, {{contact_email}}, {{contact_phone}}, {{address}}. Leave blank for default.</small>
    </div>
  </div>

</form>

<!-- Upload Modals -->
<?php foreach ([
  ['uploadLogoModal',       'Upload Company Logo',       'logo'],
  ['uploadFaviconModal',    'Upload Favicon',            'favicon'],
  ['uploadBackgroundModal', 'Upload Login Background',   'background'],
] as [$modalId, $title, $type]): ?>
<div id="<?= $modalId ?>" class="modal">
  <div class="modal-content">
    <button class="close-btn" onclick="document.getElementById('<?= $modalId ?>').style.display='none'">&times;</button>
    <h3><?= $title ?></h3>
    <iframe src="upload_branding.php?type=<?= $type ?>" style="width:100%;height:360px;border:none;margin-top:16px;border-radius:6px"></iframe>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="document.getElementById('<?= $modalId ?>').style.display='none'; location.reload()">Close &amp; Reload</button>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
(function(){
  function setupPicker(pickerId, inputId) {
    var picker = document.getElementById(pickerId);
    var input  = document.getElementById(inputId);
    picker.addEventListener('input', function(){ input.value = this.value; });
    input.addEventListener('input', function(){
      if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) picker.value = this.value;
    });
  }
  setupPicker('primary_color_picker',   'primary_color');
  setupPicker('secondary_color_picker', 'secondary_color');
  setupPicker('accent_color_picker',    'accent_color');
})();
</script>

<?php include 'includes/footer.php'; ?>
