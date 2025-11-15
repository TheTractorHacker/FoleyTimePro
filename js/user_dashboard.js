// Toggle visibility of the punch area
function togglePunch() {
    const area = document.getElementById('punchArea');
    const btn = document.querySelector('.toggle-punch');
    if (area.style.display === 'none') {
        area.style.display = 'block';
        btn.textContent = '⏱ Hide Punch In / Out';
    } else {
        area.style.display = 'none';
        btn.textContent = '⏱ Show Punch In / Out';
    }
}

// Submit clock actions to the server
function submitAction(action) {
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

function proceedWithAction(action) {
    const note = document.getElementById('note').value;
    fetch('../functions/clock_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ EmployeeID: empID, action: action, note: note })
    })
    .then(res => res.json())
    .then(data => {
        const popup = document.getElementById('customPopup');
        const message = document.getElementById('popupMessage');
        const closeBtn = document.getElementById('popupClose');

        let displayMessage = data.message;
        if (data.hoursWorked) {
            displayMessage += `<br>Hours Worked: ${data.hoursWorked}`;
        }

        message.innerHTML = displayMessage;
        popup.classList.remove('hidden');

        closeBtn.onclick = () => {
            popup.classList.add('hidden');
            if (data.success) {
                location.reload();
            }
        };
    })
    .catch(() => {
        const popup = document.getElementById('customPopup');
        const message = document.getElementById('popupMessage');
        const closeBtn = document.getElementById('popupClose');
        message.textContent = 'Error communicating with server.';
        popup.classList.remove('hidden');
        closeBtn.onclick = () => {
            popup.classList.add('hidden');
        };
    });
}

