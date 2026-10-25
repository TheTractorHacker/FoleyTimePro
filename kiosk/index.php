<?php
session_start();
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../functions/get_branding.php';
$branding = getBranding($conn);

$tz         = $branding['Timezone']   ?? 'America/Chicago';
$timeFormat = $branding['TimeFormat'] ?? '12h';
$dateFormat = $branding['DateFormat'] ?? 'MM/DD/YYYY';
$company    = $branding['CompanyName'] ?? 'FoleyTimePro';

/* Brand colors — drive the kiosk accent + primary button. Fallbacks match
   get_branding.php defaults so an install without customized colors still looks
   correct. */
$primaryColor   = $branding['PrimaryColor']   ?? '#3498db';
$secondaryColor = $branding['SecondaryColor'] ?? '#2ecc71';
$accentColor    = $branding['AccentColor']    ?? '#e74c3c';

function k_hex_to_rgb($hex) {
    $hex = ltrim($hex ?? '', '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) return [52, 152, 219]; // safe fallback
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}
function k_shade($hex, $percent) {
    [$r,$g,$b] = k_hex_to_rgb($hex);
    $adj = fn($c) => max(0, min(255, (int)round($c + ($c * $percent / 100))));
    return sprintf('#%02x%02x%02x', $adj($r), $adj($g), $adj($b));
}
[$pR,$pG,$pB] = k_hex_to_rgb($primaryColor);
$primaryDark = k_shade($primaryColor, -22);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#0a1220">
  <title><?= htmlspecialchars($company) ?> — Kiosk</title>
  <link rel="icon" type="image/webp" href="<?= htmlspecialchars($branding['FaviconPath']) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Instrument+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap">
  <link rel="stylesheet" href="kiosk.css">
  <style>
    :root {
      --brand-primary:      <?= htmlspecialchars($primaryColor) ?>;
      --brand-primary-dark: <?= htmlspecialchars($primaryDark) ?>;
      --brand-secondary:    <?= htmlspecialchars($secondaryColor) ?>;
      --brand-accent:       <?= htmlspecialchars($accentColor) ?>;
      --brand-primary-15:   rgba(<?= (int)$pR ?>, <?= (int)$pG ?>, <?= (int)$pB ?>, 0.15);
      --brand-primary-28:   rgba(<?= (int)$pR ?>, <?= (int)$pG ?>, <?= (int)$pB ?>, 0.28);
      --brand-primary-40:   rgba(<?= (int)$pR ?>, <?= (int)$pG ?>, <?= (int)$pB ?>, 0.40);
    }
  </style>
</head>
<body>

  <div class="k-grain" aria-hidden="true"></div>
  <div class="k-vignette" aria-hidden="true"></div>

  <div id="offlineBanner" class="k-offline-banner" role="status" aria-live="polite">
    <svg viewBox="0 0 24 24"><path d="M1 1l22 22"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
    <span>Offline — reconnecting…</span>
  </div>

  <main class="k-stage" role="main">

    <!-- ============ HERO ============ -->
    <section class="k-hero" aria-label="Time and date">
      <div class="k-brand">
        <img src="<?= htmlspecialchars($branding['LogoPath']) ?>" alt=""
             class="k-brand-mark"
             onerror="this.onerror=null;this.src='../images/FoleyTimePro-favicon.webp'">
        <span class="k-brand-name"><?= htmlspecialchars($company) ?></span>
      </div>

      <div class="k-hero-body">
        <div class="k-clock-wrap">
          <div class="k-clock" id="kClock" aria-live="off">
            <span id="kClockTime">--:--</span><span class="k-ampm" id="kClockAmpm"></span>
          </div>
          <div class="k-clock-progress" aria-hidden="true">
            <div class="k-clock-progress-bar" id="clockProgress"></div>
          </div>
        </div>

        <div class="k-date-block">
          <div class="k-date-day" id="dateDay">—</div>
          <div class="k-date-full" id="dateFull">—</div>
        </div>

        <div class="k-hero-status" id="heroStatus">Ready</div>
      </div>

      <div class="k-hero-footer">
        Powered by <?= htmlspecialchars($company) ?>
      </div>
    </section>

    <!-- ============ CARD ============ -->
    <section class="k-card" aria-label="Employee interaction">

      <!-- ── SCAN PANEL ── -->
      <div class="k-panel" id="scanPanel">
        <div class="k-tabs" role="tablist" aria-label="Login method">
          <button class="k-tab active" data-mode="badge" role="tab" aria-selected="true" aria-controls="pane-badge" id="tab-badge">Badge</button>
          <button class="k-tab"        data-mode="pin"   role="tab" aria-selected="false" aria-controls="pane-pin"   id="tab-pin">PIN</button>
        </div>

        <!-- Badge tab -->
        <div class="k-tabpane active" data-pane="badge" role="tabpanel" aria-labelledby="tab-badge" id="pane-badge">
          <div class="k-scan-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <rect x="2" y="5" width="20" height="14" rx="2.5"/>
              <line x1="6" y1="9"  x2="6"  y2="15"/>
              <line x1="9" y1="9"  x2="9"  y2="15"/>
              <line x1="12" y1="9" x2="12" y2="15"/>
              <line x1="15" y1="9" x2="15" y2="15"/>
              <line x1="18" y1="9" x2="18" y2="15"/>
            </svg>
          </div>
          <p class="k-scan-hint">Scan your badge or type your tag</p>
          <input type="text" id="tagInput" class="k-tag-input"
                 placeholder="Tag ID"
                 autocomplete="off" autocorrect="off"
                 spellcheck="false" inputmode="text"
                 aria-label="Tag ID">
        </div>

        <!-- PIN tab -->
        <div class="k-tabpane" data-pane="pin" role="tabpanel" aria-labelledby="tab-pin" id="pane-pin">
          <p class="k-scan-hint">Enter your PIN</p>
          <div class="k-pin-display" id="pinDisplay" role="status" aria-live="polite" aria-label="PIN entry">
            <!-- Dots injected by JS -->
          </div>
          <div class="k-pin-grid" role="group" aria-label="PIN keypad">
            <button type="button" class="k-pin-btn" data-digit="1" aria-label="1">1</button>
            <button type="button" class="k-pin-btn" data-digit="2" aria-label="2">2</button>
            <button type="button" class="k-pin-btn" data-digit="3" aria-label="3">3</button>
            <button type="button" class="k-pin-btn" data-digit="4" aria-label="4">4</button>
            <button type="button" class="k-pin-btn" data-digit="5" aria-label="5">5</button>
            <button type="button" class="k-pin-btn" data-digit="6" aria-label="6">6</button>
            <button type="button" class="k-pin-btn" data-digit="7" aria-label="7">7</button>
            <button type="button" class="k-pin-btn" data-digit="8" aria-label="8">8</button>
            <button type="button" class="k-pin-btn" data-digit="9" aria-label="9">9</button>
            <button type="button" class="k-pin-btn action" id="pinClear" aria-label="Clear">Clear</button>
            <button type="button" class="k-pin-btn" data-digit="0" aria-label="0">0</button>
            <button type="button" class="k-pin-btn action" id="pinBack" aria-label="Backspace">
              <svg viewBox="0 0 24 24"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="18" y1="9" x2="12" y2="15"/><line x1="12" y1="9" x2="18" y2="15"/></svg>
            </button>
            <button type="button" class="k-pin-btn action submit" id="pinSubmit" disabled style="grid-column: 1 / -1; aspect-ratio: auto; min-height: 56px;">Submit</button>
          </div>
        </div>
      </div>

      <!-- ── EMPLOYEE PANEL ── -->
      <div class="k-panel hidden" id="clockPanel">
        <div class="k-emp">
          <div class="k-emp-greeting">Hi,</div>
          <div class="k-emp-name" id="welcomeName" aria-live="polite">—</div>
          <div class="k-emp-status status-out" id="statusBadge" aria-live="polite">Clocked Out</div>
          <div class="k-emp-meta" id="statusMeta"></div>
        </div>

        <div class="k-actions" id="actionButtons" role="group" aria-label="Punch actions">
          <!-- Rendered by JS based on status -->
        </div>

        <button class="k-cancel" id="cancelBtn" type="button"><em>Not you?</em> &nbsp;— Cancel</button>
      </div>
    </section>
  </main>

  <!-- Feedback overlay -->
  <div id="overlay" class="k-overlay hidden" role="alertdialog" aria-live="assertive" aria-hidden="true">
    <video id="feedbackVideo" muted playsinline preload="auto"></video>
    <p id="overlayMessage" class="k-overlay-msg"></p>
  </div>

  <!-- Audio -->
  <audio id="successBeep" src="success.mp3" preload="auto"></audio>
  <audio id="errorBeep"   src="error.mp3"   preload="auto"></audio>

<script>
(() => {
  // ── Config injected from branding ─────────────────────────
  const CFG = {
    tz:         <?= json_encode($tz) ?>,
    timeFormat: <?= json_encode($timeFormat) ?>,
    dateFormat: <?= json_encode($dateFormat) ?>,
    company:    <?= json_encode($company) ?>,
    endpoint:   '../functions/clock_action.php',
    overlayMs:  2500,
    idleMs:     20000,
    scanDebounceMs: 350,
    pinMinLength:   4,
    pinMaxLength:   12,
    submitTimeoutMs: 8000,
  };

  // ── DOM shortcuts ─────────────────────────────────────────
  const $ = (id) => document.getElementById(id);
  const scanPanel   = $('scanPanel');
  const clockPanel  = $('clockPanel');
  const overlay     = $('overlay');
  const overlayMsg  = $('overlayMessage');
  const feedbackVid = $('feedbackVideo');
  const successBeep = $('successBeep');
  const errorBeep   = $('errorBeep');
  const tagInput    = $('tagInput');
  const pinDisplay  = $('pinDisplay');
  const pinSubmit   = $('pinSubmit');
  const cancelBtn   = $('cancelBtn');
  const heroStatus  = $('heroStatus');
  const clockProgress = $('clockProgress');
  const offlineBanner = $('offlineBanner');
  const actionBtns  = $('actionButtons');

  // ── State ─────────────────────────────────────────────────
  const state = {
    mode: 'badge',        // 'badge' | 'pin'
    pinBuffer: '',
    currentEmp: null,     // {firstName, lastName, status, sinceTime, lunchStartTime, credType, credValue}
    submitting: false,
    idleTimer: null,
    scanTimer: null,
    durationTimer: null,
    wakeLock: null,
  };

  // ── Formatting helpers ────────────────────────────────────
  const use24 = CFG.timeFormat === '24h';

  function fmtTime(date, opts = {}) {
    const showSec = opts.showSec !== false;
    return date.toLocaleTimeString('en-US', {
      timeZone: CFG.tz,
      hour: use24 ? '2-digit' : 'numeric',
      minute: '2-digit',
      second: showSec ? '2-digit' : undefined,
      hour12: !use24,
    });
  }

  function fmtDateFull(date) {
    // Respect branding DateFormat for the FULL date; day-of-week always first
    if (CFG.dateFormat === 'DD/MM/YYYY') {
      return date.toLocaleDateString('en-GB', { timeZone: CFG.tz, day: 'numeric', month: 'long', year: 'numeric' });
    }
    if (CFG.dateFormat === 'YYYY-MM-DD') {
      const y = date.toLocaleDateString('en-CA', { timeZone: CFG.tz, year: 'numeric' });
      const m = date.toLocaleDateString('en-US', { timeZone: CFG.tz, month: 'long' });
      const d = date.toLocaleDateString('en-US', { timeZone: CFG.tz, day: 'numeric' });
      return `${y} · ${m} ${d}`;
    }
    return date.toLocaleDateString('en-US', { timeZone: CFG.tz, month: 'long', day: 'numeric', year: 'numeric' });
  }

  function fmtDurationSince(iso) {
    if (!iso) return null;
    const then = new Date(iso);
    if (isNaN(then)) return null;
    const diff = Math.max(0, Date.now() - then.getTime());
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m === 0 ? `${h}h` : `${h}h ${m}m`;
  }

  // ── Clock tick ────────────────────────────────────────────
  function tick() {
    const now = new Date();
    const timeStr = fmtTime(now, { showSec: true });
    // Split trailing AM/PM into its own span for styling
    const parts = timeStr.match(/^(.*?)\s*(AM|PM)?$/i);
    $('kClockTime').textContent = (parts && parts[1] ? parts[1].trim() : timeStr);
    $('kClockAmpm').textContent = (parts && parts[2]) ? parts[2].toUpperCase() : '';

    $('dateDay').textContent  = now.toLocaleDateString('en-US', { timeZone: CFG.tz, weekday: 'long' }).toUpperCase();
    $('dateFull').textContent = fmtDateFull(now);

    // Seconds progress bar (0 → 1 across the minute)
    const secs = parseInt(now.toLocaleTimeString('en-US', { timeZone: CFG.tz, second: '2-digit', hour12: false }).slice(-2), 10);
    if (clockProgress) clockProgress.style.transform = `scaleX(${(secs || 0) / 60})`;
  }
  tick();
  setInterval(tick, 1000);

  // ── Tabs ──────────────────────────────────────────────────
  document.querySelectorAll('.k-tab').forEach(tab => {
    tab.addEventListener('click', () => switchMode(tab.dataset.mode));
  });

  function switchMode(mode) {
    if (mode === state.mode) return;
    state.mode = mode;
    document.querySelectorAll('.k-tab').forEach(t => {
      const active = t.dataset.mode === mode;
      t.classList.toggle('active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('.k-tabpane').forEach(p => {
      p.classList.toggle('active', p.dataset.pane === mode);
    });
    if (mode === 'badge') {
      tagInput.value = '';
      setTimeout(() => tagInput.focus(), 60);
    } else {
      clearPin();
      // Blur badge input so hardware keys don't leak into it
      tagInput.blur();
    }
  }

  // ── Badge input ───────────────────────────────────────────
  tagInput.addEventListener('input', () => {
    clearTimeout(state.scanTimer);
    state.scanTimer = setTimeout(() => {
      const tag = tagInput.value.trim();
      if (!tag) return;
      lookup({ credType: 'tag', credValue: tag });
    }, CFG.scanDebounceMs);
  });
  tagInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(state.scanTimer);
      const tag = tagInput.value.trim();
      if (tag) lookup({ credType: 'tag', credValue: tag });
    }
  });

  // ── PIN keypad ────────────────────────────────────────────
  function renderPinDots() {
    const n = state.pinBuffer.length;
    const total = Math.max(CFG.pinMinLength, Math.min(CFG.pinMaxLength, Math.max(n, CFG.pinMinLength)));
    let html = '';
    for (let i = 0; i < total; i++) {
      html += `<span class="k-pin-dot ${i < n ? 'filled' : ''}"></span>`;
    }
    pinDisplay.innerHTML = html;
    pinSubmit.disabled = n < CFG.pinMinLength;
  }
  function addDigit(d) {
    if (state.pinBuffer.length >= CFG.pinMaxLength) return;
    state.pinBuffer += String(d);
    renderPinDots();
    resetIdle();
  }
  function delDigit() {
    state.pinBuffer = state.pinBuffer.slice(0, -1);
    renderPinDots();
    resetIdle();
  }
  function clearPin() {
    state.pinBuffer = '';
    renderPinDots();
  }
  function submitPin() {
    if (state.pinBuffer.length < CFG.pinMinLength) return;
    lookup({ credType: 'pin', credValue: state.pinBuffer });
  }

  document.querySelectorAll('.k-pin-btn[data-digit]').forEach(btn => {
    btn.addEventListener('click', () => addDigit(btn.dataset.digit));
  });
  $('pinBack').addEventListener('click', delDigit);
  $('pinClear').addEventListener('click', clearPin);
  pinSubmit.addEventListener('click', submitPin);

  // Physical keyboard support for PIN mode — only when scan panel + PIN tab visible
  document.addEventListener('keydown', (e) => {
    if (state.mode !== 'pin') return;
    if (clockPanel && !clockPanel.classList.contains('hidden')) return; // employee panel active
    if (overlay.classList.contains('visible')) return;

    if (/^[0-9]$/.test(e.key)) {
      addDigit(e.key);
      e.preventDefault();
    } else if (e.key === 'Backspace') {
      delDigit();
      e.preventDefault();
    } else if (e.key === 'Enter') {
      submitPin();
      e.preventDefault();
    } else if (e.key === 'Escape') {
      clearPin();
      e.preventDefault();
    }
  });

  renderPinDots();

  // ── Lookup (badge or PIN → status) ───────────────────────
  async function lookup({ credType, credValue }) {
    if (state.submitting) return;
    if (!navigator.onLine) {
      showFeedback(false, 'Offline — check the network and try again.');
      return;
    }
    state.submitting = true;
    const body = credType === 'tag'
      ? { TagID: credValue, mode: 'kiosk_status' }
      : { PIN: credValue, mode: 'kiosk_pin_status' };
    try {
      const data = await postForm(body);
      if (!data || !data.success || !data.firstName) {
        showFeedback(false, (data && data.message) || 'Not recognized');
        if (credType === 'pin') clearPin();
        return;
      }
      state.currentEmp = {
        firstName:      data.firstName,
        lastName:       data.lastName || '',
        status:         data.status || 'Out',
        sinceTime:      data.sinceTime || null,
        lunchStartTime: data.lunchStartTime || null,
        credType,
        credValue,
      };
      showEmployeePanel();
    } catch (err) {
      showFeedback(false, 'Network error — try again');
      if (credType === 'pin') clearPin();
    } finally {
      state.submitting = false;
    }
  }

  // ── Submit punch action ──────────────────────────────────
  async function submitPunch(action) {
    if (state.submitting || !state.currentEmp) return;
    if (!navigator.onLine) {
      showFeedback(false, 'Offline — try again when reconnected.');
      return;
    }
    state.submitting = true;
    setActionsDisabled(true);
    const { credType, credValue } = state.currentEmp;
    const body = credType === 'tag'
      ? { TagID: credValue, mode: 'kiosk',     action }
      : { PIN:   credValue, mode: 'kiosk_pin', action };
    try {
      const data = await postForm(body);
      const name = (data && data.firstName) ? data.firstName + ': ' : '';
      showFeedback(!!(data && data.success), name + ((data && data.message) || 'Unknown error'));
    } catch (err) {
      showFeedback(false, 'Failed to submit — try again');
    } finally {
      state.submitting = false;
    }
  }

  function postForm(payload) {
    const ctl = new AbortController();
    const to = setTimeout(() => ctl.abort(), CFG.submitTimeoutMs);
    return fetch(CFG.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(payload),
      signal: ctl.signal,
      credentials: 'same-origin',
    })
      .then(r => r.json().catch(() => ({ success: false, message: 'Bad response' })))
      .finally(() => clearTimeout(to));
  }

  // ── Employee panel + smart buttons ───────────────────────
  function showEmployeePanel() {
    const emp = state.currentEmp;
    $('welcomeName').textContent = emp.firstName + (emp.lastName ? ' ' + emp.lastName : '');
    setStatusBadge(emp.status);
    updateStatusMeta();
    renderActions(emp.status);

    scanPanel.classList.add('hidden');
    clockPanel.classList.remove('hidden');
    clockPanel.classList.add('entering');
    requestAnimationFrame(() => clockPanel.classList.remove('entering'));

    heroStatus.textContent = emp.firstName + ' • ' + statusLabel(emp.status);
    setHeroStatusClass(emp.status);
    startDurationTicker();
    resetIdle();
  }

  function setHeroStatusClass(status) {
    heroStatus.classList.remove('status-in', 'status-lunch', 'status-out');
    if (status === 'In')    heroStatus.classList.add('status-in');
    else if (status === 'Lunch') heroStatus.classList.add('status-lunch');
    else                    heroStatus.classList.add('status-out');
  }

  function setStatusBadge(status) {
    const badge = $('statusBadge');
    badge.className = 'k-emp-status';
    if (status === 'In') {
      badge.classList.add('status-in');
      badge.textContent = 'Clocked In';
    } else if (status === 'Lunch') {
      badge.classList.add('status-lunch');
      badge.textContent = 'On Lunch';
    } else {
      badge.classList.add('status-out');
      badge.textContent = 'Clocked Out';
    }
  }

  function statusLabel(status) {
    if (status === 'In')    return 'clocked in';
    if (status === 'Lunch') return 'on lunch';
    return 'clocked out';
  }

  function updateStatusMeta() {
    const emp = state.currentEmp;
    if (!emp) return;
    const meta = $('statusMeta');
    meta.textContent = '';

    let prefix = '', iso = null;
    if (emp.status === 'In' && emp.sinceTime) {
      prefix = 'Since ';
      iso = emp.sinceTime;
    } else if (emp.status === 'Lunch' && emp.lunchStartTime) {
      prefix = 'Lunch started ';
      iso = emp.lunchStartTime;
    } else {
      return;
    }

    const then = new Date(iso);
    const dur = fmtDurationSince(iso);
    if (!dur || isNaN(then)) return;

    // Build DOM defensively (no innerHTML) — protects against unexpected server strings
    const timeStrong = document.createElement('strong');
    timeStrong.textContent = fmtTime(then, { showSec: false });
    const durStrong = document.createElement('strong');
    durStrong.textContent = dur;

    meta.appendChild(document.createTextNode(prefix));
    meta.appendChild(timeStrong);
    meta.appendChild(document.createTextNode(' · '));
    meta.appendChild(durStrong);
  }

  function startDurationTicker() {
    clearInterval(state.durationTimer);
    state.durationTimer = setInterval(updateStatusMeta, 30000);
  }
  function stopDurationTicker() {
    clearInterval(state.durationTimer);
    state.durationTimer = null;
  }

  // ── Contextual action buttons per status ─────────────────
  const ACTIONS = {
    clockin:    { label: 'Clock In',      icon: '<svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>' },
    lunchstart: { label: 'Start Lunch',   icon: '<svg viewBox="0 0 24 24"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>' },
    lunchend:   { label: 'End Lunch',     icon: '<svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>' },
    clockout:   { label: 'Clock Out',     icon: '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>' },
  };

  const PLAN = {
    Out:   [{ action: 'clockin',    kind: 'primary' }],
    In:    [{ action: 'lunchstart', kind: 'primary' }, { action: 'clockout', kind: 'secondary out' }],
    Lunch: [{ action: 'lunchend',   kind: 'primary' }, { action: 'clockout', kind: 'secondary out' }],
  };

  function renderActions(status) {
    const plan = PLAN[status] || PLAN.Out;
    actionBtns.innerHTML = plan.map(({ action, kind }) => {
      const a = ACTIONS[action];
      const cls = kind.includes('primary') ? 'k-btn k-btn-primary'
                : kind.includes('out')     ? 'k-btn k-btn-secondary k-btn-out'
                : 'k-btn k-btn-secondary';
      return `<button type="button" class="${cls}" data-action="${action}">${a.icon}<span>${a.label}</span></button>`;
    }).join('');
    actionBtns.querySelectorAll('button[data-action]').forEach(btn => {
      btn.addEventListener('click', () => submitPunch(btn.dataset.action));
    });
  }

  function setActionsDisabled(disabled) {
    actionBtns.querySelectorAll('button').forEach(b => b.disabled = disabled);
  }

  // ── Reset ────────────────────────────────────────────────
  function resetKiosk() {
    stopDurationTicker();
    clearIdle();
    state.currentEmp = null;
    clockPanel.classList.add('hidden');
    scanPanel.classList.remove('hidden');
    scanPanel.classList.add('entering');
    requestAnimationFrame(() => scanPanel.classList.remove('entering'));
    tagInput.value = '';
    clearPin();
    heroStatus.textContent = 'Ready';
    heroStatus.classList.remove('status-in', 'status-lunch', 'status-out');
    if (state.mode === 'badge') tagInput.focus();
  }
  cancelBtn.addEventListener('click', resetKiosk);

  // ── Feedback overlay ─────────────────────────────────────
  function showFeedback(success, msg) {
    stopDurationTicker();
    overlay.classList.remove('hidden', 'success', 'error');
    overlay.classList.add(success ? 'success' : 'error');
    overlayMsg.textContent = msg;
    overlay.setAttribute('aria-hidden', 'false');

    // Play video
    try {
      feedbackVid.src = success ? 'Success Check.webm' : 'Alert 3.webm';
      feedbackVid.load();
      feedbackVid.play().catch(() => {});
    } catch (_) {}
    try {
      (success ? successBeep : errorBeep).play().catch(() => {});
    } catch (_) {}

    requestAnimationFrame(() => overlay.classList.add('visible'));

    setTimeout(() => {
      overlay.classList.remove('visible');
      overlay.setAttribute('aria-hidden', 'true');
      setTimeout(() => {
        overlay.classList.add('hidden');
        try { feedbackVid.pause(); feedbackVid.currentTime = 0; } catch (_) {}
        resetKiosk();
      }, 250);
    }, CFG.overlayMs);
  }

  // ── Idle auto-reset ──────────────────────────────────────
  function resetIdle() {
    clearIdle();
    if (clockPanel.classList.contains('hidden')) return; // only on employee panel
    state.idleTimer = setTimeout(() => {
      if (!clockPanel.classList.contains('hidden')) resetKiosk();
    }, CFG.idleMs);
  }
  function clearIdle() {
    clearTimeout(state.idleTimer);
    state.idleTimer = null;
  }
  ['click', 'touchstart', 'keydown'].forEach(evt => {
    document.addEventListener(evt, () => {
      if (!clockPanel.classList.contains('hidden')) resetIdle();
    }, { passive: true });
  });

  // ── Offline banner ───────────────────────────────────────
  function updateOnline() {
    const on = navigator.onLine;
    offlineBanner.classList.toggle('visible', !on);
  }
  window.addEventListener('online',  updateOnline);
  window.addEventListener('offline', updateOnline);
  updateOnline();

  // ── Screen Wake Lock ─────────────────────────────────────
  async function requestWakeLock() {
    if (!('wakeLock' in navigator)) return;
    try {
      state.wakeLock = await navigator.wakeLock.request('screen');
      state.wakeLock.addEventListener('release', () => { state.wakeLock = null; });
    } catch (_) { /* silently ignore — non-HTTPS, unsupported, etc. */ }
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && !state.wakeLock) requestWakeLock();
  });
  requestWakeLock();

  // ── Refocus tag input on any keypress in badge mode ─────
  // Guard against firing during overlay or when clockPanel is active so scanners
  // don't steal focus mid-interaction.
  document.addEventListener('keydown', (e) => {
    if (state.mode !== 'badge') return;
    if (scanPanel.classList.contains('hidden')) return;
    if (overlay.classList.contains('visible')) return;
    if (document.activeElement === tagInput) return;
    // Only refocus for meta/nav keys — for printable keys, focusing NOW would
    // still lose this keypress, so let the user's next key land naturally.
    if (e.key.length === 1) {
      tagInput.focus();
      return;
    }
    tagInput.focus();
  });

  // Autofocus on load
  window.addEventListener('load', () => tagInput.focus());
})();
</script>
</body>
</html>
