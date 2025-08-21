document.addEventListener('DOMContentLoaded', function() {
    const tagIdInput = document.getElementById('tag_id');
    const proceedBtn = document.getElementById('proceedBtn');
    const userNameDiv = document.getElementById('userName');
    const kioskActionsDiv = document.getElementById('kioskActions');
    const messageDiv = document.getElementById('message');

    // Initially hide kiosk actions
    kioskActionsDiv.style.display = 'none';
    proceedBtn.style.display = 'none'; // Hide proceed button initially

    tagIdInput.addEventListener('input', function() {
        if (tagIdInput.value.length > 0) {
            proceedBtn.style.display = 'block'; // Show proceed button when tag_id is entered
        } else {
            proceedBtn.style.display = 'none';
            userNameDiv.textContent = ''; // Clear user name if tag_id is cleared
            kioskActionsDiv.style.display = 'none'; // Hide actions
            messageDiv.textContent = ''; // Clear messages
            messageDiv.classList.remove('error', 'success'); // Remove any previous classes
        }
    });

    proceedBtn.addEventListener('click', function() {
        const tagId = tagIdInput.value;
        if (tagId) {
            fetch(`get_user_name.php?tag_id=${tagId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        userNameDiv.textContent = `Welcome, ${data.name}!`;
                        kioskActionsDiv.style.display = 'flex'; // Show kiosk actions
                        messageDiv.textContent = ''; // Clear any previous messages
                        messageDiv.classList.remove('error');
                        messageDiv.classList.add('success');
                    } else {
                        userNameDiv.textContent = '';
                        kioskActionsDiv.style.display = 'none';
                        messageDiv.textContent = data.message || 'Error: User not found.';
                        messageDiv.classList.remove('success');
                        messageDiv.classList.add('error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    userNameDiv.textContent = '';
                    kioskActionsDiv.style.display = 'none';
                    messageDiv.textContent = 'An error occurred while fetching user data.';
                    messageDiv.classList.remove('success');
                    messageDiv.classList.add('error');
                });
        }
    });

    // Optional: Handle form submission for clock in/out actions
    // This part might be handled by kiosk.js, but if not, it would go here.
    // For now, we're focusing on the proceed button.
});
