<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Kiosk TimeSmart</title>
  <link rel="stylesheet" href="kiosk.css" />
  <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
  <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
</head>
<body>
  <div class="container">
    <div class="header-row">
  <img src="../images/D-Best.png" class="logo" alt="Logo">
  <h1>Kiosk TimeSmart</h1>
</div>


    <!-- SCAN INPUT -->
    <div id="scanBox">
      <input type="text" id="tagInput" placeholder="Scan your badge..." autofocus autocomplete="off" />
    </div>

    <!-- CLOCK PANEL -->
    <div id="clockPanel" class="hidden">
      <div class="info-row">
        <h2 id="welcomeName">Welcome</h2>
        <p id="clockStatus">Status: -</p>
      </div>

      <div id="currentTime">--:--:--</div>

      <div class="action-buttons">
        <button onclick="submitPunch('clockin')">Clock In</button>
        <button onclick="submitPunch('lunchstart')">Lunch Start</button>
        <button onclick="submitPunch('lunchend')">Lunch End</button>
        <button onclick="submitPunch('clockout')" class="clockout-button">Clock Out</button>
        <button onclick="resetKiosk()" class="cancel-button">Cancel</button>
      </div>

    </div>

    <!-- FEEDBACK OVERLAY -->
    <div id="overlay" class="hidden">
      <video id="feedbackVideo" autoplay muted playsinline></video>
      <p id="overlayMessage"></p>
    </div>

    <!-- AUDIO CUES -->
    <audio id="successBeep" src="success.mp3" preload="auto"></audio>
    <audio id="errorBeep" src="error.mp3" preload="auto"></audio>
  </div>

  <script src="kiosk.js"></script>
</body>
</html>
