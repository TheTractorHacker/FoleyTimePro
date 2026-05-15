<?php
session_start();
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../functions/get_branding.php';
$branding = getBranding($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($branding['CompanyName']) ?> — Kiosk</title>
  <link rel="icon" type="image/webp" href="<?= htmlspecialchars($branding['FaviconPath']) ?>">
  <link rel="stylesheet" href="kiosk.css">
</head>
<body>

  <!-- Top bar: brand + date -->
  <header class="k-header">
    <div class="k-brand">
      <img src="<?= htmlspecialchars($branding['LogoPath']) ?>" alt=""
           class="k-logo" onerror="this.onerror=null;this.src='../images/FoleyTimePro-favicon.webp'">
      <span class="k-brand-name"><?= htmlspecialchars($branding['CompanyName']) ?></span>
    </div>
    <div class="k-date-display">
      <div class="k-date-day" id="dateDay"></div>
      <div class="k-date-full" id="dateFull"></div>
    </div>
  </header>

  <!-- Main clock -->
  <div class="k-clock-wrap">
    <div class="k-clock" id="kClock">--:-- --</div>
  </div>

  <!-- ── Scan state ─────────────────────────────────── -->
  <div class="k-panel" id="scanPanel">
    <div class="k-scan-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <rect x="2" y="5" width="20" height="14" rx="2"/>
        <line x1="6" y1="12" x2="18" y2="12"/>
        <line x1="6" y1="8"  x2="6"  y2="16"/>
        <line x1="10" y1="8" x2="10" y2="16"/>
        <line x1="14" y1="8" x2="14" y2="16"/>
        <line x1="18" y1="8" x2="18" y2="16"/>
      </svg>
    </div>
    <p class="k-scan-hint">Scan your badge or enter your Tag ID</p>
    <input type="text" id="tagInput"
           placeholder="Scan badge or type ID…"
           autocomplete="off" autocorrect="off"
           spellcheck="false" autofocus>
  </div>

  <!-- ── Clock panel (after scan) ──────────────────── -->
  <div class="k-panel hidden" id="clockPanel">
    <div class="k-employee">
      <div class="k-emp-name" id="welcomeName">—</div>
      <div id="statusBadge" class="k-status-badge k-status-out">Clocked Out</div>
    </div>

    <div class="k-actions" id="actionButtons">
      <button class="k-btn k-btn-in"    onclick="submitPunch('clockin')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Clock In
      </button>
      <button class="k-btn k-btn-lunch" onclick="submitPunch('lunchstart')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
        Lunch Start
      </button>
      <button class="k-btn k-btn-return" onclick="submitPunch('lunchend')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Lunch End
      </button>
      <button class="k-btn k-btn-out"   onclick="submitPunch('clockout')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Clock Out
      </button>
      <button class="k-btn k-btn-cancel" onclick="resetKiosk()">
        Cancel
      </button>
    </div>
  </div>

  <!-- ── Feedback overlay ───────────────────────────── -->
  <div id="overlay" class="k-overlay hidden">
    <video id="feedbackVideo" autoplay muted playsinline></video>
    <p id="overlayMessage" class="k-overlay-msg"></p>
  </div>

  <!-- Audio -->
  <audio id="successBeep" src="success.mp3" preload="auto"></audio>
  <audio id="errorBeep"   src="error.mp3"   preload="auto"></audio>

  <footer class="k-footer">Powered by FoleyTimePro &mdash; Foley IT</footer>

<script>
// ── Clock & date ──────────────────────────────────────
const TZ = 'America/Chicago';
function pad(n){ return String(n).padStart(2,'0'); }

function tick() {
  const now = new Date();
  const t = now.toLocaleTimeString('en-US',{timeZone:TZ,hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
  document.getElementById('kClock').textContent = t;

  const day  = now.toLocaleDateString('en-US',{timeZone:TZ,weekday:'long'});
  const full = now.toLocaleDateString('en-US',{timeZone:TZ,month:'long',day:'numeric',year:'numeric'});
  document.getElementById('dateDay').textContent = day;
  document.getElementById('dateFull').textContent = full;
}
tick();
setInterval(tick, 1000);

// ── Kiosk logic ───────────────────────────────────────
const tagInput    = document.getElementById('tagInput');
const scanPanel   = document.getElementById('scanPanel');
const clockPanel  = document.getElementById('clockPanel');
const overlay     = document.getElementById('overlay');
const successBeep = document.getElementById('successBeep');
const errorBeep   = document.getElementById('errorBeep');

let scannedTag = '';
let scanTimer  = null;

tagInput.focus();

tagInput.addEventListener('input', () => {
  clearTimeout(scanTimer);
  scanTimer = setTimeout(() => {
    const tag = tagInput.value.trim();
    if (!tag) return;
    scannedTag = tag;

    fetch('../functions/clock_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ TagID: scannedTag, mode: 'kiosk_status' })
    })
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.firstName) {
        showFeedback(false, data.message || 'Badge not recognized');
        return;
      }
      document.getElementById('welcomeName').textContent = data.firstName + (data.lastName ? ' ' + data.lastName : '');
      setStatusBadge(data.status);
      scanPanel.classList.add('hidden');
      clockPanel.classList.remove('hidden');
    })
    .catch(() => showFeedback(false, 'Network error — try again'));
  }, 400);
});

function setStatusBadge(status) {
  const badge = document.getElementById('statusBadge');
  badge.className = 'k-status-badge';
  if (status === 'In') {
    badge.classList.add('k-status-in');
    badge.textContent = '● Clocked In';
  } else if (status === 'Lunch') {
    badge.classList.add('k-status-lunch');
    badge.textContent = '● On Lunch';
  } else {
    badge.classList.add('k-status-out');
    badge.textContent = '● Clocked Out';
  }
}

window.submitPunch = function(action) {
  if (!scannedTag || !action) return;
  fetch('../functions/clock_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ TagID: scannedTag, mode: 'kiosk', action })
  })
  .then(r => r.json())
  .then(data => {
    const msg = data.firstName ? data.firstName + ': ' + data.message : data.message;
    showFeedback(data.success, msg);
  })
  .catch(() => showFeedback(false, 'Failed to submit punch'));
};

window.resetKiosk = function() {
  clockPanel.classList.add('hidden');
  scanPanel.classList.remove('hidden');
  tagInput.value = '';
  scannedTag = '';
  tagInput.focus();
};

function showFeedback(success, msg) {
  const vid = document.getElementById('feedbackVideo');
  vid.src = success ? 'Success Check.webm' : 'Alert 3.webm';
  vid.load(); vid.play();
  (success ? successBeep : errorBeep).play().catch(()=>{});
  document.getElementById('overlayMessage').textContent = msg;
  overlay.classList.remove('hidden');

  setTimeout(() => {
    overlay.classList.add('hidden');
    vid.pause(); vid.currentTime = 0;
    resetKiosk();
  }, 3500);
}
</script>
</body>
</html>
