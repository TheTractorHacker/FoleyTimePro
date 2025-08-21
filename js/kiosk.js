document.addEventListener('DOMContentLoaded', function() {
    const kioskForm = document.getElementById('kioskForm');
    if (kioskForm) {
        const tagIdInput = document.getElementById('tag_id');
        const userNameDiv = document.getElementById('userName');
        const kioskActionsDiv = document.getElementById('kioskActions');
        const messageDiv = document.getElementById('message');
        const proceedBtn = document.getElementById('proceedBtn');

        proceedBtn.addEventListener('click', function() {
            if (tagIdInput.value.length >= 4) { // Assuming tag IDs are at least 4 chars long
                fetch(`get_user_name.php?tag_id=${tagIdInput.value}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            userNameDiv.textContent = data.name;
                            userNameDiv.style.display = 'block';
                            kioskActionsDiv.style.display = 'grid';
                            messageDiv.textContent = '';
                            messageDiv.className = '';
                            tagIdInput.disabled = true;
                            proceedBtn.style.display = 'none';
                        } else {
                            userNameDiv.textContent = '';
                            userNameDiv.style.display = 'none';
                            kioskActionsDiv.style.display = 'none';
                            messageDiv.textContent = data.message;
                            messageDiv.className = 'error';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        userNameDiv.textContent = '';
                        userNameDiv.style.display = 'none';
                        kioskActionsDiv.style.display = 'none';
                        messageDiv.textContent = 'Error fetching user name.';
                        messageDiv.className = 'error';
                    });
            }
        });

        kioskForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const tagID = tagIdInput.value;
            const action = e.submitter.value;

            fetch('kiosk_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ tagID, action })
            })
            .then(response => response.json())
            .then(data => {
                messageDiv.className = data.success ? 'success' : 'error';
                messageDiv.textContent = data.message;
                if (data.success) {
                    tagIdInput.value = '';
                    userNameDiv.style.display = 'none';
                    kioskActionsDiv.style.display = 'none';
                    tagIdInput.disabled = false;
                    proceedBtn.style.display = 'block';
                }
            })
            .catch(error => {
                messageDiv.className = 'error';
                messageDiv.textContent = 'An error occurred. Please try again.';
                console.error('Error:', error);
            });
        });
    }
});
