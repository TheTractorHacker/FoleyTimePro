let resetUserId = null;

function showResetModal(id) {
    resetUserId = id;
    document.getElementById('resetModal').style.display = 'block';
}
function closeResetModal() {
    resetUserId = null;
    document.getElementById('resetModal').style.display = 'none';
}
document.getElementById('confirmResetBtn').addEventListener('click', () => {
    if (!resetUserId) return;
    fetch('reset_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(resetUserId)
    }).then(() => {
        closeResetModal();
        window.location.reload();
    });
});

// 2FA Modal Logic
function open2FAModal(userId) {
    document.getElementById('2faUserId').value = userId;
    document.getElementById('modal2FA').style.display = 'block';
}
function close2FAModal() {
    document.getElementById('modal2FA').style.display = 'none';
}
function confirm2FA(action) {
    const labels = {
        enable: 'Enable 2FA for this user?',
        disable: 'Disable 2FA and remove all secrets for this user?',
        lock: 'Lock user from managing 2FA?',
        unlock: 'Allow user to manage their own 2FA?'
    };
    if (confirm(labels[action])) {
        const form = document.getElementById('form2FA');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'action';
        input.value = action;
        form.appendChild(input);
        form.submit();
    }
}
