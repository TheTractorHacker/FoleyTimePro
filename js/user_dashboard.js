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
    if (action === 'clockout' && !confirm('Are you sure you want to clock out?')) {
        return; // User cancelled
    }
    const note = document.getElementById('note').value;
    fetch('../functions/clock_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ EmployeeID: empID, action: action, note: note })
    })
    .then(res => res.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    })
    .catch(() => {
        alert('Error communicating with server.');
    });
}

