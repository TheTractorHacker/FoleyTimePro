document.addEventListener("DOMContentLoaded", () => {
  const tagInput = document.getElementById("tagInput");
  const scanBox = document.getElementById("scanBox");
  const clockPanel = document.getElementById("clockPanel");
  const welcomeName = document.getElementById("welcomeName");
  const clockStatus = document.getElementById("clockStatus");
  const currentTime = document.getElementById("currentTime");
  const overlay = document.getElementById("overlay");
  const overlayMessage = document.getElementById("overlayMessage");
  const feedbackVideo = document.getElementById("feedbackVideo");
  const successBeep = document.getElementById("successBeep");
  const errorBeep = document.getElementById("errorBeep");

  let scannedTag = '';
  let scanTimeout = null;

  function updateClock() {
    currentTime.textContent = new Date().toLocaleTimeString('en-US', {
      timeZone: 'America/Chicago'
    });
  }
  updateClock();
  setInterval(updateClock, 1000);

  overlay.classList.add("hidden");
  overlay.style.display = "none";
  tagInput.focus();

  tagInput.addEventListener("input", () => {
    clearTimeout(scanTimeout);
    scanTimeout = setTimeout(() => {
      const tag = tagInput.value.trim();
      if (!tag) return;
      scannedTag = tag;

      fetch("../functions/clock_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ TagID: scannedTag, mode: "kiosk_status" })
      })
      .then(res => res.json())
      .then(data => {
        if (!data.success || !data.firstName) {
          showOverlay(false, data.message || "Tag not recognized");
          return;
        }

        welcomeName.textContent = `Hi, ${data.firstName}`;
        clockStatus.textContent = `Status: ${data.status}`;
        clockPanel.classList.remove("hidden");
        scanBox.classList.add("hidden");

        renderActionButtons();
      })
      .catch(() => showOverlay(false, "❌ Network error"));
    }, 500);
  });

  window.submitPunch = function (action) {
    if (!scannedTag || !action) return;

    fetch("../functions/clock_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ TagID: scannedTag, mode: "kiosk", action })
    })
    .then(res => res.json())
    .then(data => {
      const success = data.success;
      const msg = data.firstName ? `${data.firstName}: ${data.message}` : data.message;
      showOverlay(success, msg);
    })
    .catch(() => showOverlay(false, "❌ Failed to punch"));
  }

  function showOverlay(success, msg) {
    feedbackVideo.src = success ? "Success Check.webm" : "Alert 3.webm";
    feedbackVideo.load();
    feedbackVideo.play();

    (success ? successBeep : errorBeep).play();

    overlayMessage.textContent = msg;
    overlay.classList.remove("hidden");
    overlay.style.display = "flex";

    setTimeout(() => {
      overlay.classList.add("hidden");
      overlay.style.display = "none";
      feedbackVideo.pause();
      feedbackVideo.currentTime = 0;

      clockPanel.classList.add("hidden");
      scanBox.classList.remove("hidden");
      tagInput.value = '';
      tagInput.focus();
    }, 3500);
  }

  window.resetKiosk = function () {
  document.getElementById("clockPanel").classList.add("hidden");
  document.getElementById("scanBox").classList.remove("hidden");
  document.getElementById("tagInput").value = '';
  document.getElementById("tagInput").focus();
}

function renderActionButtons() {
  const actions = [
    { key: "clockin", label: "Clock In" },
    { key: "lunchstart", label: "Lunch Start" },
    { key: "lunchend", label: "Lunch End" },
    { key: "clockout", label: "Clock Out" }
  ];

  const container = document.querySelector(".action-buttons");
  container.innerHTML = '';

  actions.forEach(({ key, label }) => {
    const btn = document.createElement("button");
    btn.textContent = label;
    btn.onclick = () => submitPunch(key);
    // No animation classes here
    btn.classList.add("action-btn");
    container.appendChild(btn);
  });

  const cancelBtn = document.createElement("button");
  cancelBtn.textContent = "Cancel";
  cancelBtn.onclick = resetKiosk;
  cancelBtn.classList.add("cancel-button");
  container.appendChild(cancelBtn);
}



});