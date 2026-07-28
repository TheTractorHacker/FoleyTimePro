<?php
require_once '../auth/db.php';
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

require_once 'includes/csrf.php';

// Whitelist of grantable scopes. Adding a new endpoint that needs a scope
// means adding it here so the create form can't accept typos or invented
// values (which future scope-checked endpoints might inadvertently honor).
const API_KEY_ALLOWED_SCOPES = ['punches:read', 'employees:read'];

$pepper = $_ENV['API_KEY_PEPPER'] ?? '';

$msg = '';
$msgType = 'info';
// Plaintext key we minted THIS request — rendered inline in the response
// and never persisted to $_SESSION (avoids a session-hijack window between
// "create key" and "admin visits page next").
$newKeyPlaintext = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $msg = 'Invalid CSRF token. Reload the page and try again.';
        $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name       = trim($_POST['name'] ?? '');
            $rawScopes  = trim($_POST['scopes'] ?? 'punches:read,employees:read');
            $wantScopes = array_values(array_filter(array_map('trim', explode(',', $rawScopes))));
            $badScopes  = array_diff($wantScopes, API_KEY_ALLOWED_SCOPES);

            if ($name === '') {
                $msg = 'Name is required.';
                $msgType = 'error';
            } elseif (empty($wantScopes)) {
                $msg = 'At least one scope is required.';
                $msgType = 'error';
            } elseif (!empty($badScopes)) {
                $msg = 'Unknown scope(s): ' . htmlspecialchars(implode(', ', $badScopes))
                     . '. Allowed: ' . implode(', ', API_KEY_ALLOWED_SCOPES);
                $msgType = 'error';
            } elseif ($pepper === '') {
                $msg = 'API_KEY_PEPPER is not set in .env. Add it (openssl rand -hex 32) before creating keys.';
                $msgType = 'error';
            } else {
                try {
                    $plaintext = bin2hex(random_bytes(32));     // 64 hex chars
                    $prefix    = substr($plaintext, 0, 8);
                    $hash      = hash('sha256', $pepper . $plaintext);
                    $createdBy = $_SESSION['admin'] ?? null;
                    $scopes    = implode(',', $wantScopes);

                    $stmt = $conn->prepare(
                        "INSERT INTO api_keys (Name, KeyPrefix, KeyHash, Scopes, CreatedBy)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('sssss', $name, $prefix, $hash, $scopes, $createdBy);
                    $stmt->execute();
                    $stmt->close();
                    // Render inline in THIS response — do not persist to session.
                    // Admin cannot bookmark or share this URL to expose the key.
                    $newKeyPlaintext = $plaintext;
                    $msg = 'Key created. Copy it below — it will not be shown again.';
                    $msgType = 'success';
                } catch (Throwable $e) {
                    error_log('[admin/api_keys] create failed: ' . $e->getMessage());
                    $msg = 'Failed to create key. See server logs for details.';
                    $msgType = 'error';
                }
            }
        } elseif ($action === 'revoke') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $stmt = $conn->prepare(
                        "UPDATE api_keys SET RevokedAt = NOW()
                         WHERE ID = ? AND RevokedAt IS NULL"
                    );
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                    header('Location: api_keys.php');
                    exit;
                } catch (Throwable $e) {
                    error_log('[admin/api_keys] revoke failed: ' . $e->getMessage());
                    $msg = 'Failed to revoke key. See server logs for details.';
                    $msgType = 'error';
                }
            }
        }
    }
}

// Clean up any stale key left in $_SESSION by an older version of this
// file — new creates render inline in the POST response (see above).
if (isset($_SESSION['new_api_key'])) {
    unset($_SESSION['new_api_key']);
}

$keys = $conn->query(
    "SELECT ID, Name, KeyPrefix, Scopes, CreatedBy, CreatedAt,
            LastUsedAt, LastUsedIP, RevokedAt, ExpiresAt
     FROM api_keys
     ORDER BY (RevokedAt IS NULL) DESC, CreatedAt DESC"
)->fetch_all(MYSQLI_ASSOC);

$pageTitle   = 'API Keys';
$headerTitle = 'API Keys';
include 'includes/header.php';
?>

<div class="page-header">
  <h1>API Keys <span style="font-size:14px;font-weight:400;color:#6b7280">(<?= count($keys) ?>)</span></h1>
  <button class="btn btn-primary" onclick="document.getElementById('createModal').style.display='block'">+ New Key</button>
</div>

<?php if ($newKeyPlaintext): ?>
<div class="card" style="padding:16px 20px;margin-bottom:18px;background:#fef3c7;border-left:4px solid #d97706">
  <div style="font-weight:600;margin-bottom:8px;color:#78350f">Copy this key now — it will not be shown again</div>
  <code style="display:block;padding:10px;background:#fff;border-radius:6px;font-family:ui-monospace,monospace;word-break:break-all;color:#111"><?= htmlspecialchars($newKeyPlaintext) ?></code>
  <div style="font-size:12px;color:#78350f;margin-top:8px">Store it in the external system's credentials vault. If you lose it, revoke and create a new one.</div>
</div>
<?php endif; ?>

<?php if ($msg): ?>
<div class="card" style="padding:12px 16px;margin-bottom:18px;background:<?= $msgType === 'error' ? '#fee2e2' : '#dbeafe' ?>;color:<?= $msgType === 'error' ? '#991b1b' : '#1e3a8a' ?>">
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="card" style="padding:16px 20px;margin-bottom:18px;background:#f3f4f6">
  <div style="font-size:13px;color:#374151;line-height:1.6">
    <strong>External API endpoints:</strong><br>
    <code>GET /api/external/v1/punches?since=&lt;ISO8601&gt;[&amp;employee_email=…][&amp;limit=…]</code><br>
    <code>GET /api/external/v1/employees</code><br>
    Send <code>Authorization: Bearer &lt;key&gt;</code>. Rate limit: <strong>60 requests/min per key</strong>.
  </div>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Prefix</th>
        <th>Scopes</th>
        <th>Created</th>
        <th>Last Used</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($keys as $k):
      $isRevoked = $k['RevokedAt'] !== null;
      $isExpired = $k['ExpiresAt'] !== null && strtotime($k['ExpiresAt']) < time();
      $isActive  = !$isRevoked && !$isExpired;
    ?>
      <tr>
        <td>
          <strong><?= htmlspecialchars($k['Name']) ?></strong>
          <?php if ($k['CreatedBy']): ?>
            <div style="font-size:11px;color:#6b7280">by <?= htmlspecialchars($k['CreatedBy']) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-family:ui-monospace,monospace;font-size:12px;color:#6b7280"><?= htmlspecialchars($k['KeyPrefix']) ?>…</td>
        <td style="font-size:12px;color:#6b7280"><?= htmlspecialchars($k['Scopes']) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($k['CreatedAt']) ?></td>
        <td style="font-size:12px">
          <?php if ($k['LastUsedAt']): ?>
            <?= htmlspecialchars($k['LastUsedAt']) ?>
            <?php if ($k['LastUsedIP']): ?>
              <div style="font-size:11px;color:#6b7280"><?= htmlspecialchars($k['LastUsedIP']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <span style="color:#9ca3af">never</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($isActive): ?>
            <span class="badge badge-approved">Active</span>
          <?php elseif ($isRevoked): ?>
            <span class="badge badge-out">Revoked</span>
          <?php else: ?>
            <span class="badge badge-inactive">Expired</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($isActive): ?>
          <form method="POST" style="display:inline"
                onsubmit="return confirm('Revoke this key? External systems using it will get 401 immediately.');">
<?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="id" value="<?= (int)$k['ID'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
          </form>
          <?php else: ?>
            <span style="color:#9ca3af;font-size:12px">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($keys)): ?>
      <tr><td colspan="7" style="text-align:center;padding:32px;color:#9ca3af">No API keys yet. Click "+ New Key" to create one.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Create Modal -->
<div id="createModal" class="modal">
  <div class="modal-content">
    <button class="close-btn" onclick="document.getElementById('createModal').style.display='none'">&times;</button>
    <h3>New API Key</h3>
    <form method="POST" style="display:flex;flex-direction:column;gap:12px;margin-top:16px">
<?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label>Name *</label>
        <input type="text" name="name" placeholder="e.g. ITFlow production" required maxlength="100">
      </div>
      <div class="form-group">
        <label>Scopes</label>
        <input type="text" name="scopes" value="punches:read,employees:read" maxlength="255">
        <div style="font-size:11px;color:#6b7280;margin-top:4px">Comma-separated. Available: <code>punches:read</code>, <code>employees:read</code></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('createModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Key</button>
      </div>
    </form>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
