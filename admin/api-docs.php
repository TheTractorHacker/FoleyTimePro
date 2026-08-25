<?php
/**
 * Admin → API Reference
 *
 * Renders the OpenAPI spec as a full documentation site inside the admin chrome.
 *
 * WHY SERVER-RENDERED RATHER THAN REDOC/SWAGGER-UI
 * Those load a ~1MB bundle from a CDN, which is exactly the dependency a self-hosted
 * timeclock on a shop-floor LAN cannot rely on. Rendering here means the reference
 * works with no network, no client-side spec parsing, and no flash of empty page —
 * and it can show installation-specific truth (this host's base URL, the live key
 * count, which scopes actually exist) that a generic spec viewer has no access to.
 *
 * DATA SOURCE
 * Reads api/docs/openapi.json, generated from openapi.yaml by
 * scripts/openapi-to-json.php. PHP here has no YAML parser, so the JSON artifact is
 * the runtime format; the YAML stays the editable source of truth.
 */
require_once '../auth/db.php';
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/../api/lib/ApiScopes.php';
require_once __DIR__ . '/includes/csrf.php';

/**
 * POST action=generate_demo_key — mints a real, working API key so the examples
 * on this page are copy-paste ready with no manual "go create a key elsewhere and
 * paste it in" step.
 *
 * DELIBERATELY NOT auto-minted on every page view: that would insert a new
 * api_keys row (and a matching audit-log trail) on every GET, including plain
 * refreshes. Requiring one click keeps key creation an explicit, attributable
 * action — matching how admin/api_keys.php's own create form behaves — while
 * still needing zero trips to a different page.
 *
 * The plaintext is returned ONLY in this response body and is never written to
 * $_SESSION — same rule admin/api_keys.php follows, and for the same reason: a
 * plaintext key sitting in session data would widen what a stolen session cookie
 * is worth. That rule is also why this endpoint cannot be made to "auto-fill" a
 * key silently on load; a human click is what stands in for the missing
 * session-cache.
 *
 * Scoped to every currently-available READ scope (pulled from ApiScopes, not
 * hardcoded) so whatever example the admin copies next just works — this key is
 * for trying the docs, not for a real integration, so it deliberately does not
 * let the admin pick a narrower set the way the real create-key form does.
 * Expires in 24h so a demo key pasted into a scratch terminal and forgotten
 * doesn't quietly become a permanent credential.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_demo_key') {
    header('Content-Type: application/json');

    if (!csrf_valid()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token. Reload the page and try again.']);
        exit;
    }

    $pepper = $_ENV['API_KEY_PEPPER'] ?? '';
    if ($pepper === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'API_KEY_PEPPER is not set in .env.']);
        exit;
    }

    $readScopes = array_keys(array_filter(
        ApiScopes::grouped()['read'],
        static fn($m) => !empty($m['available'])
    ));
    $admin = $_SESSION['admin'] ?? 'admin';

    try {
        // Keep at most one live demo key per admin — otherwise every click over
        // time leaves another forgotten row behind. Revoking (not deleting)
        // preserves the audit trail of prior demo keys, same as a normal revoke.
        $rev = $conn->prepare(
            "UPDATE api_keys SET RevokedAt = NOW()
             WHERE CreatedBy = ? AND Name LIKE 'Docs example (%'
               AND RevokedAt IS NULL"
        );
        $rev->bind_param('s', $admin);
        $rev->execute();
        $rev->close();

        $plaintext = bin2hex(random_bytes(32));
        $prefix    = substr($plaintext, 0, 8);
        $hash      = hash('sha256', $pepper . $plaintext);
        $scopesCsv = implode(',', $readScopes);
        $name      = 'Docs example (' . date('Y-m-d H:i') . ')';

        $ins = $conn->prepare(
            "INSERT INTO api_keys (Name, KeyPrefix, KeyHash, Scopes, CreatedBy, ExpiresAt)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))"
        );
        $ins->bind_param('sssss', $name, $prefix, $hash, $scopesCsv, $admin);
        $ins->execute();
        $ins->close();

        echo json_encode([
            'success'    => true,
            'key'        => $plaintext,
            'name'       => $name,
            'scopes'     => $readScopes,
            'expires_in' => '24 hours',
        ]);
    } catch (Throwable $e) {
        error_log('[admin/api-docs] generate_demo_key failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create key. See server logs.']);
    }
    exit;
}

$specJsonPath = __DIR__ . '/../api/docs/openapi.json';
$specYamlPath = __DIR__ . '/../api/docs/openapi.yaml';
$specYamlUrl  = '../api/docs/openapi.yaml';
$specJsonUrl  = '../api/docs/openapi.json';

$spec      = null;
$specError = null;

if (is_readable($specJsonPath)) {
    $decoded = json_decode((string)file_get_contents($specJsonPath), true);
    if (!is_array($decoded)) {
        $specError = 'openapi.json exists but is not valid JSON. Regenerate it.';
    } else {
        $spec = $decoded;
    }
} elseif (is_readable($specYamlPath)) {
    $specError = 'openapi.yaml is present but has not been converted to JSON yet.';
} else {
    $specError = 'No OpenAPI spec found at api/docs/openapi.yaml.';
}

/* ── Installation context ─────────────────────────────────────── */
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host
         . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x'))), '/');

$activeKeys = (int)($conn->query(
    "SELECT COUNT(*) c FROM api_keys
     WHERE RevokedAt IS NULL AND (ExpiresAt IS NULL OR ExpiresAt > NOW())"
)->fetch_assoc()['c'] ?? 0);

/* ── Spec helpers ─────────────────────────────────────────────── */

/** Resolve a local $ref ("#/components/schemas/Foo") against the spec. */
function odResolve(array $spec, $node, int $depth = 0)
{
    if (!is_array($node) || $depth > 12) return $node;
    if (!isset($node['$ref']) || !is_string($node['$ref'])) return $node;

    $ref = $node['$ref'];
    if (strncmp($ref, '#/', 2) !== 0) return $node;   // external refs are not followed

    $cursor = $spec;
    foreach (explode('/', substr($ref, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) return $node;
        $cursor = $cursor[$segment];
    }
    return odResolve($spec, $cursor, $depth + 1);
}

/**
 * Build a representative value for a schema, so every operation shows a concrete
 * response even when the spec author did not hand-write an example. Honors
 * example/default/enum first, then falls back to a type-appropriate placeholder.
 */
function odExample(array $spec, $schema, int $depth = 0)
{
    $schema = odResolve($spec, $schema, 0);
    if (!is_array($schema) || $depth > 7) return null;

    if (array_key_exists('example', $schema))  return $schema['example'];
    if (array_key_exists('default', $schema))  return $schema['default'];
    if (!empty($schema['enum']) && is_array($schema['enum'])) return $schema['enum'][0];

    foreach (['allOf', 'oneOf', 'anyOf'] as $combinator) {
        if (!empty($schema[$combinator]) && is_array($schema[$combinator])) {
            if ($combinator === 'allOf') {
                $merged = [];
                foreach ($schema[$combinator] as $part) {
                    $value = odExample($spec, $part, $depth + 1);
                    if (is_array($value)) $merged = array_merge($merged, $value);
                }
                return $merged ?: null;
            }
            return odExample($spec, $schema[$combinator][0], $depth + 1);
        }
    }

    // OpenAPI 3.1 allows a type array (e.g. ["string","null"]); take the first
    // non-null entry so nullable fields still render something meaningful.
    $type = $schema['type'] ?? null;
    if (is_array($type)) {
        $type = current(array_filter($type, static fn($t) => $t !== 'null')) ?: 'string';
    }
    if ($type === null && isset($schema['properties'])) $type = 'object';

    switch ($type) {
        case 'object':
            $out = [];
            foreach (($schema['properties'] ?? []) as $name => $prop) {
                $out[$name] = odExample($spec, $prop, $depth + 1);
                if (count($out) >= 14) break;   // keep the sample readable
            }
            return $out;

        case 'array':
            $item = odExample($spec, $schema['items'] ?? [], $depth + 1);
            return $item === null ? [] : [$item];

        case 'integer': return 0;
        case 'number':  return 0.0;
        case 'boolean': return false;

        case 'string':
            switch ($schema['format'] ?? '') {
                case 'date':      return date('Y-m-d');
                case 'date-time': return date('c');
                default:          return 'string';
            }
    }
    return null;
}

/** Human-readable type label for a parameter/property schema. */
function odType(array $spec, $schema): string
{
    $schema = odResolve($spec, $schema, 0);
    if (!is_array($schema)) return 'any';

    $type = $schema['type'] ?? null;
    if (is_array($type)) {
        $nullable = in_array('null', $type, true);
        $base = current(array_filter($type, static fn($t) => $t !== 'null')) ?: 'any';
        return $base . ($nullable ? '?' : '');
    }
    if ($type === 'array') {
        $items = odResolve($spec, $schema['items'] ?? [], 0);
        $inner = is_array($items) ? ($items['type'] ?? 'any') : 'any';
        return (is_array($inner) ? 'any' : $inner) . '[]';
    }
    if ($type === null) return isset($schema['properties']) ? 'object' : 'any';

    $format = $schema['format'] ?? '';
    return $format !== '' ? $type . '<' . $format . '>' : $type;
}

/** Syntax-tint JSON. Escapes every token; only structural punctuation passes through raw. */
function odTintJson(string $json): string
{
    $pattern = '/("(?:\\\\.|[^"\\\\])*")(\s*:)|("(?:\\\\.|[^"\\\\])*")|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|\b(true|false)\b|\b(null)\b/';

    return (string)preg_replace_callback($pattern, static function (array $m): string {
        if (($m[1] ?? '') !== '') {
            return '<span class="od-t-key">' . htmlspecialchars($m[1]) . '</span>'
                 . htmlspecialchars($m[2] ?? '');
        }
        if (($m[3] ?? '') !== '') return '<span class="od-t-str">'  . htmlspecialchars($m[3]) . '</span>';
        if (($m[4] ?? '') !== '') return '<span class="od-t-num">'  . htmlspecialchars($m[4]) . '</span>';
        if (($m[5] ?? '') !== '') return '<span class="od-t-bool">' . htmlspecialchars($m[5]) . '</span>';
        if (($m[6] ?? '') !== '') return '<span class="od-t-null">' . htmlspecialchars($m[6]) . '</span>';
        return htmlspecialchars($m[0]);
    }, $json);
}

/** Tint a shell snippet. Input is escaped first, so only flag/comment shapes are matched. */
function odTintShell(string $cmd): string
{
    $safe = htmlspecialchars($cmd);
    $safe = (string)preg_replace('/(^|\s)(#[^\n]*)/m', '$1<span class="od-t-cmt">$2</span>', $safe);
    $safe = (string)preg_replace('/(^|\s)(--?[A-Za-z][\w-]*)/m', '$1<span class="od-t-flag">$2</span>', $safe);
    return $safe;
}

/** Tint a Power Query M snippet: comments, keywords, and quoted strings. */
function odTintM(string $code): string
{
    $safe = htmlspecialchars($code);
    $safe = (string)preg_replace('/(\/\/[^\n]*)/', '<span class="od-t-cmt">$1</span>', $safe);
    $safe = (string)preg_replace('/&quot;(.*?)&quot;/', '<span class="od-t-str">&quot;$1&quot;</span>', $safe);
    $safe = (string)preg_replace('/\b(let|in|each|type|true|false|null)\b/', '<span class="od-t-flag">$1</span>', $safe);
    return $safe;
}

/** The scope an operation requires, read from its security requirement. */
function odScopeFor(array $op): ?string
{
    foreach (($op['security'] ?? []) as $requirement) {
        foreach ($requirement as $scopes) {
            if (is_array($scopes) && $scopes) return (string)$scopes[0];
        }
    }
    // Spec-declared scopes are optional; fall back to the x-scope vendor extension.
    return isset($op['x-scope']) ? (string)$op['x-scope'] : null;
}

/** Example query string from an operation's parameters, for the curl/M snippets. */
function odExampleQuery(array $spec, array $op): array
{
    $query = [];
    foreach (($op['parameters'] ?? []) as $parameter) {
        $parameter = odResolve($spec, $parameter, 0);
        if (!is_array($parameter) || ($parameter['in'] ?? '') !== 'query') continue;

        $required = !empty($parameter['required']);
        $example  = $parameter['example'] ?? odExample($spec, $parameter['schema'] ?? [], 6);

        // Only required params and the date window go into the sample call —
        // a snippet carrying all 16 optional filters teaches nothing.
        $name = (string)($parameter['name'] ?? '');
        if ($required || in_array($name, ['start', 'end'], true)) {
            if ($example === null || $example === '') continue;
            if (is_bool($example)) $example = $example ? 'true' : 'false';
            if (is_array($example)) continue;
            $query[$name] = (string)$example;
        }
    }
    return $query;
}

/* ── Group operations by tag ──────────────────────────────────── */
$methods   = ['get', 'post', 'put', 'patch', 'delete'];
$groups    = [];   // tag => [ops]
$tagInfo   = [];   // tag => description
$opCount   = 0;

if ($spec !== null) {
    foreach (($spec['tags'] ?? []) as $tag) {
        if (isset($tag['name'])) $tagInfo[$tag['name']] = $tag['description'] ?? '';
    }

    foreach (($spec['paths'] ?? []) as $path => $operations) {
        if (!is_array($operations)) continue;
        foreach ($operations as $method => $op) {
            if (!in_array(strtolower($method), $methods, true) || !is_array($op)) continue;
            $tag = $op['tags'][0] ?? 'Other';
            $groups[$tag][] = ['path' => $path, 'method' => strtolower($method), 'op' => $op];
            $opCount++;
        }
    }

    // Honor the spec's tag order; anything untagged sorts to the end.
    if (!empty($tagInfo)) {
        $ordered = [];
        foreach (array_keys($tagInfo) as $name) {
            if (isset($groups[$name])) $ordered[$name] = $groups[$name];
        }
        foreach ($groups as $name => $ops) {
            if (!isset($ordered[$name])) $ordered[$name] = $ops;
        }
        $groups = $ordered;
    }
}

$schemaCount = count($spec['components']['schemas'] ?? []);
$specVersion = $spec['info']['version'] ?? null;
$generatedAt = $spec['x-generated']['at'] ?? null;

$pageTitle     = 'API Reference';
$headerTitle   = 'API Reference';
$additionalCSS = [
    'https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap',
    '../css/api-docs.css',
];
$additionalJS  = ['../js/api-docs.js'];
include 'includes/header.php';

$slug = static fn(string $s): string => preg_replace('/[^a-z0-9]+/', '-', strtolower($s));
?>

<div class="od">

  <!-- ── Masthead ──────────────────────────────────────────── -->
  <div class="od-hero">
    <div class="od-hero-kicker">
      <span class="od-dot"></span>
      <span class="od-label">FoleyTimePro · REST API<?= $specVersion ? ' · v' . htmlspecialchars($specVersion) : '' ?></span>
    </div>
    <h1>Pull your time data <em>anywhere</em>.</h1>
    <p class="od-hero-sub">
      A read-only HTTP interface over hours, overtime, utilization, schedules and time off —
      built for Power BI, Excel, and any system that speaks JSON or CSV.
    </p>

    <div class="od-readout">
      <div class="od-readout-cell">
        <div class="od-readout-v signal"><?= $opCount ?: '—' ?></div>
        <div class="od-readout-l od-label">Endpoints</div>
      </div>
      <div class="od-readout-cell">
        <div class="od-readout-v"><?= $schemaCount ?: '—' ?></div>
        <div class="od-readout-l od-label">Schemas</div>
      </div>
      <div class="od-readout-cell">
        <div class="od-readout-v"><?= count(ApiScopes::all()) ?></div>
        <div class="od-readout-l od-label">Scopes</div>
      </div>
      <div class="od-readout-cell">
        <div class="od-readout-v<?= $activeKeys === 0 ? '' : ' signal' ?>"><?= $activeKeys ?></div>
        <div class="od-readout-l od-label">Active keys</div>
      </div>
      <div class="od-readout-cell">
        <div class="od-readout-v">60<span style="font-size:12px;color:var(--od-muted)">/min</span></div>
        <div class="od-readout-l od-label">Rate limit</div>
      </div>
    </div>
  </div>

  <div class="od-shell">

    <!-- ── Sidebar ─────────────────────────────────────────── -->
    <aside class="od-nav">
      <div class="od-search-wrap">
        <span class="od-search-icon">⌕</span>
        <input type="search" id="odSearch" class="od-search" placeholder="Filter endpoints  /" autocomplete="off" aria-label="Filter endpoints">
      </div>

      <div class="od-nav-group">
        <span class="od-label">Start</span>
        <a href="#getting-started" data-search="getting started authentication keys">
          <span class="t">Getting started</span>
        </a>
        <a href="#scopes" data-search="scopes permissions">
          <span class="t">Scopes</span>
        </a>
      </div>

      <?php foreach ($groups as $tag => $ops): ?>
        <div class="od-nav-group">
          <span class="od-label"><?= htmlspecialchars($tag) ?></span>
          <?php foreach ($ops as $entry):
            $op  = $entry['op'];
            $oid = $op['operationId'] ?? ($entry['method'] . $slug($entry['path']));
            $needle = strtolower(($op['summary'] ?? '') . ' ' . $entry['path'] . ' ' . $entry['method'] . ' ' . $tag);
          ?>
            <a href="#<?= htmlspecialchars($oid) ?>" data-search="<?= htmlspecialchars($needle) ?>">
              <span class="od-m od-m-<?= htmlspecialchars($entry['method']) ?>"><?= htmlspecialchars($entry['method']) ?></span>
              <span class="t"><?= htmlspecialchars($op['summary'] ?? $entry['path']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <div class="od-nav-empty">No endpoint matches that filter.</div>
    </aside>

    <!-- ── Main ────────────────────────────────────────────── -->
    <main class="od-main">

      <!-- Getting started -->
      <section class="od-section od-anim" id="getting-started">
        <div class="od-start">
          <div class="od-start-doc">
            <span class="od-label">Authentication</span>
            <h3>Three steps to your first request</h3>
            <p>
              Every external endpoint authenticates with an API key sent as a bearer token.
              Keys are scoped, rate limited to 60 requests per minute, and every call is written
              to an audit log.
            </p>
            <ol class="od-start-steps">
              <li>Create a key on the <a href="api_keys.php">API Keys</a> page and grant it the scopes
                  the endpoints below require. The key is shown once — store it in a vault.</li>
              <li>Send it as <code>Authorization: Bearer &lt;key&gt;</code> on every request.</li>
              <li>Add <code>&amp;format=csv</code> to any report for a spreadsheet-ready download.</li>
            </ol>
            <div class="od-callout od-key-panel" id="odKeyPanel">
              <div id="odKeyIdle">
                <?php if ($activeKeys === 0): ?>
                  <strong>No active keys yet.</strong> The examples here will return
                  <code>401 Invalid API key</code> until you create one —
                <?php else: ?>
                  <strong>Want these examples to actually run?</strong>
                <?php endif; ?>
                generate a temporary key and every command on this page updates to use it, so
                Copy really is paste-and-run.
                <div style="margin-top:10px">
                  <button type="button" class="btn btn-primary btn-sm" id="odGenKeyBtn">Generate a live key</button>
                  <span id="odGenKeySpinner" style="display:none;font-size:12px;color:var(--od-muted);margin-left:8px">Creating…</span>
                </div>
              </div>
              <div id="odKeyResult" style="display:none"></div>
            </div>
          </div>

          <div class="od-code">
            <div class="od-code-tabs" role="tablist">
              <button class="od-tab active" data-pane="s-curl" role="tab" aria-selected="true">cURL</button>
              <button class="od-tab" data-pane="s-pq" role="tab" aria-selected="false">Power BI</button>
            </div>
            <?php
              $startCurl = 'curl -H "Authorization: Bearer $FTP_KEY" \\' . "\n"
                         . '  "' . $baseUrl . '/api/external/v1/reports/utilization?start='
                         . date('Y-m-01') . '&end=' . date('Y-m-d') . '"';
              $startM = "let\n"
                      . "    Key  = \"YOUR_API_KEY\",\n"
                      . "    Base = \"" . $baseUrl . "\",\n"
                      . "    Resp = Json.Document(\n"
                      . "        Web.Contents(Base, [\n"
                      . "            RelativePath = \"api/external/v1/reports/utilization\",\n"
                      . "            Query        = [ start = \"" . date('Y-m-01') . "\", end = \"" . date('Y-m-d') . "\" ],\n"
                      . "            Headers      = [ Authorization = \"Bearer \" & Key ]\n"
                      . "        ])\n"
                      . "    ),\n"
                      . "    Rows = Table.FromRecords(Resp[data][rows])\n"
                      . "in\n"
                      . "    Rows";
            ?>
            <div class="od-pane active" data-pane="s-curl">
              <button class="od-copy" data-label="Copy">Copy</button>
              <pre><code><?= odTintShell($startCurl) ?></code></pre>
            </div>
            <div class="od-pane" data-pane="s-pq">
              <button class="od-copy" data-label="Copy">Copy</button>
              <pre><code><?= odTintM($startM) ?></code></pre>
            </div>
          </div>
        </div>
      </section>

      <!-- Scopes -->
      <section class="od-section od-anim" id="scopes">
        <div class="od-section-head">
          <span class="od-label">Permissions</span>
          <h2>Scopes</h2>
          <p>A key only reaches what you grant it. An endpoint called with a key missing its
             scope returns <code>403</code>, never partial data.</p>
        </div>
        <?php $odScopeGroups = ApiScopes::grouped(); ?>
        <table class="od-scopes">
          <thead><tr><th style="width:30%">Scope</th><th>Grants</th></tr></thead>
          <tbody>
            <?php foreach ($odScopeGroups['read'] as $name => $meta): ?>
              <tr>
                <td><span class="od-scope"><?= htmlspecialchars($name) ?></span></td>
                <td><?= htmlspecialchars($meta['description']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if (!empty($odScopeGroups['write'])): ?>
          <!--
            Reserved write scopes are listed separately, never interleaved with the
            live ones above. This page is what an integrator reads to decide what
            they can build against — showing punches:write next to punches:read
            with no distinction would tell them a capability exists when calling it
            returns 403 today. The create-key form (admin/api_keys.php) makes the
            same distinction for the same reason.
          -->
          <div class="od-callout" style="margin-top:14px">
            <strong>Reserved, not yet available:</strong> the external API is read-only today.
            These scopes can be granted to a key now so it is ready when write endpoints ship,
            but calling any endpoint with one currently returns <code>403</code> — there is
            nothing behind them yet.
          </div>
          <table class="od-scopes" style="margin-top:10px;opacity:.75">
            <tbody>
              <?php foreach ($odScopeGroups['write'] as $name => $meta): ?>
                <tr>
                  <td style="width:30%"><span class="od-scope" style="border-style:dashed"><?= htmlspecialchars($name) ?></span></td>
                  <td><?= htmlspecialchars($meta['description']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>

      <?php if ($spec === null): ?>
        <div class="od-empty">
          <h3>Spec not loaded</h3>
          <p><?= htmlspecialchars((string)$specError) ?></p>
          <p>The reference above still applies — only the generated endpoint listing is missing.</p>
          <code>php scripts/openapi-to-json.php</code>
        </div>
      <?php else: ?>

        <?php foreach ($groups as $tag => $ops): ?>
          <section class="od-section" id="<?= htmlspecialchars($slug($tag)) ?>">
            <div class="od-section-head">
              <span class="od-label"><?= count($ops) ?> endpoint<?= count($ops) === 1 ? '' : 's' ?></span>
              <h2><?= htmlspecialchars($tag) ?></h2>
              <?php if (!empty($tagInfo[$tag])): ?>
                <p><?= htmlspecialchars($tagInfo[$tag]) ?></p>
              <?php endif; ?>
            </div>

            <?php foreach ($ops as $entry):
              $op     = $entry['op'];
              $path   = $entry['path'];
              $method = $entry['method'];
              $oid    = $op['operationId'] ?? ($method . $slug($path));
              $scope  = odScopeFor($op);

              // Highlight the last path segment — the part that distinguishes
              // one report from another when a dozen share a prefix.
              $segments = explode('/', trim($path, '/'));
              $last     = array_pop($segments);
              $prefix   = $segments ? '/' . implode('/', $segments) . '/' : '/';

              $params = [];
              foreach (($op['parameters'] ?? []) as $p) {
                  $p = odResolve($spec, $p, 0);
                  if (is_array($p) && ($p['in'] ?? '') === 'query') $params[] = $p;
              }

              $query    = odExampleQuery($spec, $op);
              $queryStr = $query ? '?' . http_build_query($query) : '';

              $curl = 'curl -H "Authorization: Bearer $FTP_KEY" \\' . "\n"
                    . '  "' . $baseUrl . $path . $queryStr . '"';

              $mQuery = [];
              foreach ($query as $k => $v) $mQuery[] = $k . ' = "' . $v . '"';
              $m = "let\n"
                 . "    Resp = Json.Document(\n"
                 . "        Web.Contents(BaseUrl, [\n"
                 . "            RelativePath = \"" . ltrim($path, '/') . "\",\n"
                 . ($mQuery ? "            Query        = [ " . implode(', ', $mQuery) . " ],\n" : '')
                 . "            Headers      = [ Authorization = \"Bearer \" & ApiKey ]\n"
                 . "        ])\n"
                 . "    )\n"
                 . "in\n"
                 . "    Table.FromRecords(Resp[data][rows])";

              // Prefer a hand-written example; synthesize from the schema otherwise.
              $okResponse = null;
              foreach (['200', 200, 'default'] as $code) {
                  if (isset($op['responses'][$code])) { $okResponse = odResolve($spec, $op['responses'][$code], 0); break; }
              }
              $jsonContent = $okResponse['content']['application/json'] ?? null;
              $sample = null;
              if (is_array($jsonContent)) {
                  if (isset($jsonContent['example']))              $sample = $jsonContent['example'];
                  elseif (isset($jsonContent['examples']) && is_array($jsonContent['examples'])) {
                      $first  = reset($jsonContent['examples']);
                      $first  = odResolve($spec, is_array($first) ? $first : [], 0);
                      $sample = $first['value'] ?? null;
                  }
                  if ($sample === null && isset($jsonContent['schema'])) {
                      $sample = odExample($spec, $jsonContent['schema'], 0);
                  }
              }
              $sampleJson = $sample === null
                  ? null
                  : json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            ?>
            <article class="od-op" id="<?= htmlspecialchars($oid) ?>">
              <header class="od-op-head">
                <div class="od-op-route">
                  <span class="od-m od-m-<?= htmlspecialchars($method) ?>"><?= htmlspecialchars($method) ?></span>
                  <span class="od-op-path"><?= htmlspecialchars($prefix) ?><b><?= htmlspecialchars($last) ?></b></span>
                  <?php if ($scope): ?>
                    <span class="od-scope"><?= htmlspecialchars($scope) ?></span>
                  <?php endif; ?>
                </div>
                <div class="od-op-summary"><?= htmlspecialchars($op['summary'] ?? $path) ?></div>
                <?php if (!empty($op['description'])): ?>
                  <div class="od-op-desc"><?= nl2br(htmlspecialchars($op['description'])) ?></div>
                <?php endif; ?>
              </header>

              <div class="od-op-body">
                <div class="od-doc">
                  <?php if ($params): ?>
                    <div class="od-doc-block">
                      <span class="od-label">Query parameters</span>
                      <table class="od-params">
                        <?php foreach ($params as $p): ?>
                          <tr>
                            <td>
                              <div class="od-p-name"><?= htmlspecialchars((string)($p['name'] ?? '')) ?></div>
                              <div class="od-p-meta">
                                <span><?= htmlspecialchars(odType($spec, $p['schema'] ?? [])) ?></span>
                                <?php if (!empty($p['required'])): ?><span class="od-req">REQUIRED</span><?php endif; ?>
                                <?php
                                  $schema  = odResolve($spec, $p['schema'] ?? [], 0);
                                  $default = is_array($schema) ? ($schema['default'] ?? null) : null;
                                  if ($default !== null && $default !== ''):
                                ?>
                                  <span class="od-p-default">= <?= htmlspecialchars(is_bool($default) ? ($default ? 'true' : 'false') : (string)$default) ?></span>
                                <?php endif; ?>
                              </div>
                            </td>
                            <td>
                              <div class="od-p-desc"><?= htmlspecialchars((string)($p['description'] ?? '')) ?></div>
                              <?php
                                $enum = is_array($schema ?? null) ? ($schema['enum'] ?? null) : null;
                                if (is_array($enum) && $enum):
                              ?>
                                <div class="od-p-enum">
                                  <?php foreach (array_slice($enum, 0, 10) as $value): ?>
                                    <code><?= htmlspecialchars(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value) ?></code>
                                  <?php endforeach; ?>
                                </div>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </table>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($op['responses'])): ?>
                    <div class="od-doc-block">
                      <span class="od-label">Responses</span>
                      <?php foreach ($op['responses'] as $code => $response):
                        $response = odResolve($spec, $response, 0);
                        $cls = 'od-s' . substr((string)$code, 0, 1);
                      ?>
                        <div class="od-resp">
                          <span class="od-status <?= htmlspecialchars($cls) ?>"><?= htmlspecialchars((string)$code) ?></span>
                          <span class="od-resp-d"><?= htmlspecialchars((string)($response['description'] ?? '')) ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="od-code">
                  <div class="od-code-tabs" role="tablist">
                    <button class="od-tab active" data-pane="<?= htmlspecialchars($oid) ?>-curl" role="tab" aria-selected="true">cURL</button>
                    <button class="od-tab" data-pane="<?= htmlspecialchars($oid) ?>-pq" role="tab" aria-selected="false">Power BI</button>
                    <?php if ($sampleJson !== null): ?>
                      <button class="od-tab" data-pane="<?= htmlspecialchars($oid) ?>-res" role="tab" aria-selected="false">Response</button>
                    <?php endif; ?>
                  </div>

                  <div class="od-pane active" data-pane="<?= htmlspecialchars($oid) ?>-curl">
                    <button class="od-copy" data-label="Copy">Copy</button>
                    <pre><code><?= odTintShell($curl) ?></code></pre>
                  </div>
                  <div class="od-pane" data-pane="<?= htmlspecialchars($oid) ?>-pq">
                    <button class="od-copy" data-label="Copy">Copy</button>
                    <pre><code><?= odTintM($m) ?></code></pre>
                  </div>
                  <?php if ($sampleJson !== null): ?>
                    <div class="od-pane" data-pane="<?= htmlspecialchars($oid) ?>-res">
                      <button class="od-copy" data-label="Copy">Copy</button>
                      <pre><code><?= odTintJson($sampleJson) ?></code></pre>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </article>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>

      <?php endif; ?>

      <div style="margin-top:36px;padding-top:18px;border-top:1px solid var(--od-line);display:flex;gap:18px;flex-wrap:wrap;align-items:center">
        <span class="od-label">Machine readable</span>
        <?php if (is_readable($specYamlPath)): ?>
          <a href="<?= htmlspecialchars($specYamlUrl) ?>" download style="color:var(--od-teal);font-size:12.5px;text-decoration:none;border-bottom:1px solid currentColor">openapi.yaml</a>
        <?php endif; ?>
        <?php if (is_readable($specJsonPath)): ?>
          <a href="<?= htmlspecialchars($specJsonUrl) ?>" download style="color:var(--od-teal);font-size:12.5px;text-decoration:none;border-bottom:1px solid currentColor">openapi.json</a>
        <?php endif; ?>
        <?php if ($generatedAt): ?>
          <span style="font-family:var(--od-mono);font-size:11px;color:var(--od-muted)">
            generated <?= htmlspecialchars(date('Y-m-d H:i', strtotime($generatedAt))) ?>
          </span>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
