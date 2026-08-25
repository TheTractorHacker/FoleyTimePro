<?php
require_once '../auth/db.php';
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

require_once 'includes/csrf.php';
require_once 'includes/ApiKeyVault.php';
require_once __DIR__ . '/../api/lib/ApiScopes.php';

/**
 * POST action=reveal — shows a key's value again after creation.
 *
 * Gated behind re-entering the admin's OWN login password (not just an
 * already-open session) via the exact same password_verify() call
 * admin/login.php uses. A live API key is as sensitive as the password that
 * gates it; requiring that password again means a session left open at an
 * unlocked desk can't be used to walk the key table and read every secret out
 * of it — the single biggest reason this needed a step beyond "you're already
 * logged in as admin."
 *
 * Handled here, before any HTML output, and responds with JSON — same shape
 * as admin/api-docs.php's generate_demo_key handler.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reveal') {
    header('Content-Type: application/json');

    if (!csrf_valid()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token. Reload the page and try again.']);
        exit;
    }

    $keyId   = (int)($_POST['id'] ?? 0);
    $confirm = (string)($_POST['password'] ?? '');

    if ($keyId <= 0 || $confirm === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Enter your password to view this key.']);
        exit;
    }

    $adminUsername = $_SESSION['admin'] ?? '';
    $stmt = $conn->prepare('SELECT password FROM admins WHERE username = ?');
    $stmt->bind_param('s', $adminUsername);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !is_string($row['password']) || !password_verify($confirm, $row['password'])) {
        // Same generic message either way — do not reveal whether the admin
        // account itself was found, matching admin/login.php's own posture.
        error_log("[admin/api_keys] reveal denied: bad re-auth for admin={$adminUsername} keyId={$keyId}");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Incorrect password.']);
        exit;
    }

    $stmt = $conn->prepare('SELECT Name, EncryptedKey, RevokedAt FROM api_keys WHERE ID = ?');
    $stmt->bind_param('i', $keyId);
    $stmt->execute();
    $key = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$key) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Key not found.']);
        exit;
    }

    $plaintext = ApiKeyVault::decrypt($key['EncryptedKey']);
    if ($plaintext === null) {
        // Never distinguishes "unset encryption key" from "pre-migration row"
        // from "tampered data" to the client — ApiKeyVault already logged
        // which one server-side. All three look identical to the admin: this
        // particular key just isn't viewable.
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'This key was created before viewing was enabled (or it was created while '
                       . 'API_KEY_ENCRYPTION_KEY was unset) and cannot be recovered. Revoke it and create '
                       . 'a new one to get a viewable key.',
        ]);
        exit;
    }

    error_log("[admin/api_keys] key revealed: admin={$adminUsername} keyId={$keyId} name=" . $key['Name']);
    echo json_encode([
        'success' => true,
        'key'     => $plaintext,
        'name'    => $key['Name'],
        'revoked' => $key['RevokedAt'] !== null,
    ]);
    exit;
}

// Grantable scopes come from api/lib/ApiScopes.php, which is also what
// admin/api-docs.php renders and what the OpenAPI spec documents. A local
// whitelist here would drift from those the first time an endpoint is added,
// producing keys that 403 on the endpoint they were created for.

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
            $name = trim($_POST['name'] ?? '');
            // Scopes arrive as a checkbox array. Unchecked boxes submit nothing,
            // so an absent key means "none selected", not a malformed request.
            [$wantScopes, $badScopes] = ApiScopes::partitionArray($_POST['scopes'] ?? []);

            if ($name === '') {
                $msg = 'Name is required.';
                $msgType = 'error';
            } elseif (empty($wantScopes)) {
                $msg = 'Select at least one permission for this key.';
                $msgType = 'error';
            } elseif (!empty($badScopes)) {
                // Only reachable by a tampered form post — the UI cannot produce this.
                $msg = 'Unknown scope(s): ' . implode(', ', $badScopes);
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
                    // null when API_KEY_ENCRYPTION_KEY is unset — creation still
                    // succeeds either way, this key just won't be viewable later.
                    $encrypted = ApiKeyVault::encrypt($plaintext);

                    $stmt = $conn->prepare(
                        "INSERT INTO api_keys (Name, KeyPrefix, KeyHash, Scopes, CreatedBy, EncryptedKey)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('ssssss', $name, $prefix, $hash, $scopes, $createdBy, $encrypted);
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
        } elseif ($action === 'delete') {
            // Permanently removes the row. Safe by design: api_key_audit_log's FK
            // is ON DELETE SET NULL (not CASCADE) — its rows for this key survive,
            // just with KeyID blanked, exactly how a failed-auth row already looks.
            // api_key_requests has no FK at all and self-expires within the hour
            // regardless. Nothing else references api_keys(ID) (verified against
            // every migration file), so deleting cannot violate a constraint.
            //
            // Unlike revoke, this works on a key in ANY state — the confirm text
            // in the page JS is what actually distinguishes "delete an already
            // revoked/expired key" (routine cleanup) from "delete a live key"
            // (immediately breaks anything still using it, no undo) — deleting
            // a live key is not blocked outright since that's a real, if rarer,
            // legitimate need, but it never happens without an explicit warning.
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $stmt = $conn->prepare("DELETE FROM api_keys WHERE ID = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $deleted = $stmt->affected_rows > 0;
                    $stmt->close();
                    if ($deleted) {
                        error_log('[admin/api_keys] key deleted: admin=' . ($_SESSION['admin'] ?? '?') . " id={$id}");
                    }
                    header('Location: api_keys.php');
                    exit;
                } catch (Throwable $e) {
                    error_log('[admin/api_keys] delete failed: ' . $e->getMessage());
                    $msg = 'Failed to delete key. See server logs for details.';
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
            LastUsedAt, LastUsedIP, RevokedAt, ExpiresAt,
            (EncryptedKey IS NOT NULL) AS Viewable
     FROM api_keys
     ORDER BY (RevokedAt IS NULL) DESC, CreatedAt DESC"
)->fetch_all(MYSQLI_ASSOC);

$pageTitle   = 'API Keys';
$headerTitle = 'API Keys';
include 'includes/header.php';
?>

<div class="page-header">
  <h1>API Keys <span style="font-size:14px;font-weight:400;color:#6b7280">(<?= count($keys) ?>)</span></h1>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="api-docs.php" class="btn btn-secondary">API Docs</a>
    <button class="btn btn-primary" onclick="document.getElementById('createModal').style.display='block'">+ New Key</button>
  </div>
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
        <td style="white-space:nowrap">
          <?php if ($k['Viewable']): ?>
            <button type="button" class="btn btn-sm btn-secondary"
                    onclick="odOpenViewModal(<?= (int)$k['ID'] ?>, <?= json_encode($k['Name']) ?>)">View</button>
          <?php else: ?>
            <span title="Created before viewing was enabled, or while API_KEY_ENCRYPTION_KEY was unset — this key's value cannot be recovered."
                  style="color:#9ca3af;font-size:11px;cursor:help">not viewable</span>
          <?php endif; ?>
          <?php if ($isActive): ?>
          <form method="POST" style="display:inline"
                onsubmit="return confirm('Revoke this key? External systems using it will get 401 immediately.');">
<?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="id" value="<?= (int)$k['ID'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
          </form>
          <?php endif; ?>
          <form method="POST" style="display:inline"
                onsubmit="return confirm(<?= $isActive
                    ? "'This key is still ACTIVE. Deleting it immediately breaks anything using it, with no undo. Delete anyway?'"
                    : "'Permanently delete this key? This cannot be undone. Its past usage stays in the audit log, just no longer linked to a name.'"
                ?>);">
<?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$k['ID'] ?>">
            <button type="submit" class="btn btn-sm" style="background:none;color:#9ca3af;text-decoration:underline;padding:4px 6px" onmouseover="this.style.color='#991b1b'" onmouseout="this.style.color='#9ca3af'">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($keys)): ?>
      <tr><td colspan="7" style="text-align:center;padding:32px;color:#9ca3af">No API keys yet. Click "+ New Key" to create one.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<style>
/* Scope picker — replaces the old comma-separated text field. Typing scope
   names by hand invited typos that produced a key silently missing the one
   permission its integration needed. */
.scope-list { display: flex; flex-direction: column; gap: 6px; }
.scope-opt {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 10px 12px;
  border: 1px solid var(--border); border-radius: 8px;
  background: var(--surface);
  cursor: pointer;
  transition: border-color .12s, background .12s;
}
.scope-opt:hover { border-color: var(--nav-bg); background: #f8faff; }
.scope-opt:focus-within { border-color: var(--nav-bg); box-shadow: 0 0 0 3px rgba(7,56,81,.12); }
.scope-opt input[type="checkbox"] { margin-top: 2px; width: 15px; height: 15px; flex-shrink: 0; accent-color: var(--nav-bg); cursor: pointer; }
.scope-opt:has(input:checked) { border-color: var(--nav-bg); background: #f4f8fc; }
.scope-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.scope-title { font-size: 13px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.scope-title code { font-family: ui-monospace, monospace; font-size: 11px; font-weight: 500; color: var(--text-muted); background: #f0f2f5; padding: 1px 5px; border-radius: 3px; }
.scope-desc { font-size: 11.5px; color: var(--text-muted); line-height: 1.45; }

.scope-reserved-head {
  display: flex; align-items: center; gap: 8px;
  margin: 16px 0 4px;
  font-size: 12px; font-weight: 700; color: var(--text);
}
.scope-reserved-head span {
  font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
  color: #92400e; background: #fef3c7; border: 1px solid #fde68a;
  padding: 1px 6px; border-radius: 20px;
}
/* Reserved scopes stay selectable but must never read as equivalent to a live
   permission — an admin who thinks they just enabled writes is worse off than
   one who sees the option is inert. */
.scope-opt.is-reserved { border-style: dashed; }
.scope-opt.is-reserved .scope-title { color: var(--text-muted); }

[data-theme="dark"] .scope-opt { background: #1e293b; border-color: #334155; }
[data-theme="dark"] .scope-opt:hover { background: #243044; border-color: #475569; }
[data-theme="dark"] .scope-opt:has(input:checked) { background: #243044; border-color: #64748b; }
[data-theme="dark"] .scope-title { color: #f1f5f9; }
[data-theme="dark"] .scope-title code { background: #0f172a; color: #94a3b8; }
[data-theme="dark"] .scope-reserved-head { color: #f1f5f9; }
[data-theme="dark"] .scope-reserved-head span { background: #422006; border-color: #713f12; color: #fcd34d; }
</style>

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
        <label>Permissions</label>
        <div style="font-size:11px;color:#6b7280;margin:2px 0 10px">
          Grant only what the integration needs. A key without a permission gets
          <code>403</code> on those endpoints — never partial data.
        </div>

        <?php $scopeGroups = ApiScopes::grouped(); ?>

        <div class="scope-list">
          <?php foreach ($scopeGroups['read'] as $scopeName => $meta): ?>
            <label class="scope-opt">
              <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scopeName) ?>"
                     <?= $meta['default'] ? 'checked' : '' ?>>
              <span class="scope-body">
                <span class="scope-title">
                  <?= htmlspecialchars($meta['label']) ?>
                  <code><?= htmlspecialchars($scopeName) ?></code>
                </span>
                <span class="scope-desc"><?= htmlspecialchars($meta['description']) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($scopeGroups['write'])): ?>
          <div class="scope-reserved-head">
            Write access
            <span>not yet available</span>
          </div>
          <div style="font-size:11px;color:#6b7280;margin:0 0 8px">
            The external API is read-only today. You can grant these now so a key is ready
            when write endpoints ship — until then they permit nothing.
          </div>
          <div class="scope-list">
            <?php foreach ($scopeGroups['write'] as $scopeName => $meta): ?>
              <label class="scope-opt is-reserved">
                <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scopeName) ?>">
                <span class="scope-body">
                  <span class="scope-title">
                    <?= htmlspecialchars($meta['label']) ?>
                    <code><?= htmlspecialchars($scopeName) ?></code>
                  </span>
                  <span class="scope-desc"><?= htmlspecialchars($meta['description']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('createModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Key</button>
      </div>
    </form>
  </div>
</div>

<!-- View Key Modal -->
<div id="viewModal" class="modal">
  <div class="modal-content">
    <button class="close-btn" onclick="odCloseViewModal()">&times;</button>
    <h3 id="odViewTitle">View API Key</h3>

    <div id="odViewPasswordStep">
      <p style="font-size:13px;color:#6b7280;margin:8px 0 14px">
        Confirm it's you — enter your admin password to view <strong id="odViewKeyName"></strong>.
      </p>
      <form id="odViewForm" style="display:flex;flex-direction:column;gap:12px" onsubmit="return odSubmitReveal(event)">
        <div class="form-group">
          <label>Your password</label>
          <input type="password" name="password" id="odViewPassword" autocomplete="current-password" required autofocus>
        </div>
        <div id="odViewError" style="display:none;font-size:12.5px;color:#991b1b;background:#fee2e2;padding:8px 10px;border-radius:6px"></div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="odCloseViewModal()">Cancel</button>
          <button type="submit" class="btn btn-primary" id="odViewSubmitBtn">Confirm</button>
        </div>
      </form>
    </div>

    <div id="odViewResultStep" style="display:none">
      <p style="font-size:13px;color:#6b7280;margin:8px 0 10px">
        This is the same value you were given at creation — copy it now.
      </p>
      <code id="odViewKeyValue" style="display:block;padding:10px;background:#f3f4f6;border-radius:6px;font-family:ui-monospace,monospace;font-size:12.5px;word-break:break-all;color:#111"></code>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="odCloseViewModal()">Close</button>
        <button type="button" class="btn btn-primary" id="odViewCopyBtn" onclick="odCopyRevealedKey()">Copy</button>
      </div>
    </div>
  </div>
</div>

<script>
var odViewKeyId = null;

function odOpenViewModal(id, name) {
  odViewKeyId = id;
  document.getElementById('odViewKeyName').textContent = name;
  document.getElementById('odViewPasswordStep').style.display = '';
  document.getElementById('odViewResultStep').style.display = 'none';
  document.getElementById('odViewError').style.display = 'none';
  document.getElementById('odViewPassword').value = '';
  document.getElementById('odViewSubmitBtn').disabled = false;
  document.getElementById('viewModal').style.display = 'block';
  setTimeout(function () { document.getElementById('odViewPassword').focus(); }, 0);
}

function odCloseViewModal() {
  document.getElementById('viewModal').style.display = 'none';
  // Clear the revealed value from the DOM the moment the modal closes — it
  // should not linger on-screen (or in memory) longer than the admin is
  // actively looking at it.
  document.getElementById('odViewKeyValue').textContent = '';
  odViewKeyId = null;
}

function odSubmitReveal(evt) {
  evt.preventDefault();
  var btn = document.getElementById('odViewSubmitBtn');
  var errBox = document.getElementById('odViewError');
  var password = document.getElementById('odViewPassword').value;

  errBox.style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Checking…';

  var body = 'action=reveal&id=' + encodeURIComponent(odViewKeyId)
           + '&password=' + encodeURIComponent(password)
           + '&csrf=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content);

  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  })
    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
    .then(function (res) {
      if (!res.ok || !res.data || !res.data.success) {
        errBox.textContent = (res.data && res.data.error) || 'Could not view this key.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Confirm';
        return;
      }
      document.getElementById('odViewKeyValue').textContent = res.data.key;
      document.getElementById('odViewPasswordStep').style.display = 'none';
      document.getElementById('odViewResultStep').style.display = '';
    })
    .catch(function () {
      errBox.textContent = 'Request failed — check your connection and try again.';
      errBox.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Confirm';
    });

  return false;
}

function odCopyRevealedKey() {
  var value = document.getElementById('odViewKeyValue').textContent;
  var btn = document.getElementById('odViewCopyBtn');
  var done = function (ok) {
    btn.textContent = ok ? 'Copied' : 'Press ⌘C';
    setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(value).then(function () { done(true); }, function () { done(false); });
  } else {
    try {
      var ta = document.createElement('textarea');
      ta.value = value; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      done(document.execCommand('copy'));
      document.body.removeChild(ta);
    } catch (e) { done(false); }
  }
}
</script>

<?php include 'includes/footer.php'; ?>
