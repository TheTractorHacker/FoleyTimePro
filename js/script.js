// 🟦 Open the modal and prepare it for PIN input
function openModal(empID, fullName) {
    document.getElementById("modal").classList.remove("hidden");
    document.getElementById("employeeName").innerText = fullName;
    document.getElementById("modalEmployeeID").value = empID;
    document.getElementById("modalPIN").value = "";
    document.getElementById("note").value = "";
    document.getElementById("statusArea").classList.add("hidden");
    document.getElementById("pinForm").classList.remove("hidden");
    document.getElementById("modalError").innerText = "";
    document.getElementById("adjustPopup").classList.add("hidden");
    document.getElementById("confirmPopup").classList.add("hidden");
}

// ❌ Close popups on click outside
window.addEventListener("click", (e) => {
    if (e.target.id === "modal") document.getElementById("modal").classList.add("hidden");
    if (e.target.id === "adjustPopup") document.getElementById("adjustPopup").classList.add("hidden");
    if (e.target.id === "customPopup") document.getElementById("customPopup").classList.add("hidden");
    if (e.target.id === "confirmPopup") document.getElementById("confirmPopup").classList.add("hidden");
});

// ❌ Close modal
document.getElementById("modalClose").onclick = () => {
    document.getElementById("modal").classList.add("hidden");
};

// ✅ PIN Form Submission & Verification
document.getElementById("pinForm").addEventListener("submit", function (e) {
    e.preventDefault();
    const empID = document.getElementById("modalEmployeeID").value;
    const pin = document.getElementById("modalPIN").value;

    fetch("functions/verify_pin.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `EmployeeID=${encodeURIComponent(empID)}&PIN=${encodeURIComponent(pin)}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById("statusArea").classList.remove("hidden");
            document.getElementById("pinForm").classList.add("hidden");
            document.getElementById("currentStatus").innerText = `Status: ${data.status}, Time: ${data.time}`;
            document.getElementById("modalError").innerText = "";
        } else {
            document.getElementById("modalError").innerText = data.message;
        }
    })
    .catch(() => {
        document.getElementById("modalError").innerText = "Error verifying PIN.";
    });
});

// 🔁 Global state
let selectedTime = "";

// ⏱ Trigger punch action and gather GPS
async function submitAction(action) {
    if (action === 'clockout') {
        const popup = document.getElementById('confirmationPopup');
        const message = document.getElementById('confirmationMessage');
        const yesBtn = document.getElementById('confirmYes');
        const noBtn = document.getElementById('confirmNo');

        message.textContent = 'Are you sure you want to clock out?';
        popup.classList.remove('hidden');

        yesBtn.onclick = () => {
            popup.classList.add('hidden');
            proceedWithAction(action);
        };

        noBtn.onclick = () => {
            popup.classList.add('hidden');
        };
    } else {
        proceedWithAction(action);
    }
}

async function proceedWithAction(action) {
    // Reset hidden location values
    document.getElementById('latitude').value = '';
    document.getElementById('longitude').value = '';
    document.getElementById('accuracy').value = '';

    try {
        const response = await fetch('../functions/get_setting.php?setting=EnforceGPS');
        const data = await response.json();

        if (data.success && data.value === '1') {
            // GPS is required
            if (!navigator.geolocation) {
                showPopup("📍 Geolocation is required but not supported by your browser.");
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => { // Success
                    const { latitude, longitude, accuracy } = position.coords;
                    document.getElementById('latitude').value = latitude;
                    document.getElementById('longitude').value = longitude;
                    document.getElementById('accuracy').value = accuracy;
                    showConfirmPopup(action);
                },
                (error) => { // Error
                    console.warn("📍 High-accuracy GPS failed:", error.message);
                    // Fallback to low-accuracy
                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            const { latitude, longitude, accuracy } = position.coords;
                            document.getElementById('latitude').value = latitude;
                            document.getElementById('longitude').value = longitude;
                            document.getElementById('accuracy').value = accuracy;
                            showPopup("📍 Using approximate location. Enable precise location for better accuracy.");
                            setTimeout(() => showConfirmPopup(action), 1500);
                        },
                        (fallbackError) => {
                            console.warn("📍 Low-accuracy GPS also failed:", fallbackError.message);
                            // Proceed without GPS. The server will decide if this is acceptable.
                            showPopup("🛰️ GPS failed. Your location will be based on your network. This may be rejected if GPS is required.");
                            setTimeout(() => showConfirmPopup(action), 2500); // Give user time to read the message
                        },
                        { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 }
                    );
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        } else {
            // GPS is not required or setting not found
            showConfirmPopup(action);
        }
    } catch (error) {
        console.error('Error fetching GPS setting:', error);
        showPopup('Error checking GPS setting. Please try again.');
    }
}

// ⏱ Show time confirmation popup
function showConfirmPopup(action) {
    const now = new Date().toLocaleTimeString("en-US", {
        timeZone: "America/Chicago",
        hour: "2-digit",
        minute: "2-digit",
        hour12: true
    });

    selectedTime = now;
    document.getElementById("confirmAction").value = action;
    document.getElementById("confirmTimeText").textContent = now;
    document.getElementById("confirmPopup").classList.remove("hidden");
}


// ✅ Use current time and submit
function confirmSubmit() {
    const empID = document.getElementById("modalEmployeeID").value;
    const note = document.getElementById("note").value;
    const lat = document.getElementById("latitude").value;
    const lon = document.getElementById("longitude").value;
    const acc = document.getElementById("accuracy").value;
    const action = document.getElementById("confirmAction").value;

    // No adjustment: pass empty time so server uses actual clock time
    sendPunch(empID, action, note, '', lat, lon, acc);
}

/**
 * Sends the punch data to the server and handles responses robustly.
 */
function sendPunch(empID, action, note, time, lat = '', lon = '', accuracy = '') {
    const clientTime = new Date().toISOString();
    
    const data = {
        EmployeeID: empID,
        action: action,
        note: note,
        time: time,
        latitude: lat,
        longitude: lon,
        accuracy: accuracy,
        clientTime: clientTime
    };

    fetch("../functions/clock_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data)
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                throw new Error(`Server Error: ${response.status} ${response.statusText}. Response: ${text.substring(0, 200)}`);
            });
        }
        return response.json();
    })
    .then(data => {
        document.getElementById("confirmPopup").classList.add("hidden");
        document.getElementById("adjustPopup").classList.add("hidden");
        document.getElementById("modal").classList.add("hidden");
        showPopup(data.message);
        if (data.success) {
            setTimeout(() => location.reload(), 2000);
        }
    })
    .catch((error) => {
        console.error('Punch Error:', error);
        showPopup(`❌ Error submitting punch. ${error.message}`);
    });
}

// ✏️ Show time adjuster with prefilled time
function openAdjuster() {
    document.getElementById("adjustTimeInput").value = selectedTime;
    document.getElementById("confirmPopup").classList.add("hidden");
    document.getElementById("adjustPopup").classList.remove("hidden");
}

// 🕒 Submit adjusted time
function submitWithAdjustment() {
    const empID = document.getElementById("modalEmployeeID").value;
    const note = document.getElementById("note").value;
    const customTime = document.getElementById("adjustTimeInput").value;
    const lat = document.getElementById("latitude").value;
    const lon = document.getElementById("longitude").value;
    const acc = document.getElementById("accuracy").value;
    const action = document.getElementById("confirmAction").value;

    if (!customTime) {
        showPopup("⏱ Please enter a valid time.");
        return;
    }

    sendPunch(empID, action, note, customTime, lat, lon, acc);
}


// ✅ Popup Feedback Box
function showPopup(message) {
    document.getElementById("popupMessage").textContent = message;
    document.getElementById("customPopup").classList.remove("hidden");
}

document.getElementById("popupClose").addEventListener("click", () => {
    document.getElementById("customPopup").classList.add("hidden");
});

// ✏️ Show time adjuster with prefilled time
function openAdjuster() {
    document.getElementById("adjustTimeInput").value = selectedTime;
    document.getElementById("confirmPopup").classList.add("hidden");
    document.getElementById("adjustPopup").classList.remove("hidden");
}

// 🕒 Submit adjusted time
function submitWithAdjustment() {
    const empID = document.getElementById("modalEmployeeID").value;
    const note = document.getElementById("note").value;
    const customTime = document.getElementById("adjustTimeInput").value;
    const lat = document.getElementById("latitude").value;
    const lon = document.getElementById("longitude").value;
    const acc = document.getElementById("accuracy").value;
    const action = document.getElementById("confirmAction").value;

    if (!customTime) {
        showPopup("⏱ Please enter a valid time.");
        return;
    }

    sendPunch(empID, action, note, customTime, lat, lon, acc);
}


// ✅ Popup Feedback Box
function showPopup(message) {
    document.getElementById("popupMessage").textContent = message;
    document.getElementById("customPopup").classList.remove("hidden");
}

document.getElementById("popupClose").addEventListener("click", () => {
    document.getElementById("customPopup").classList.add("hidden");
});