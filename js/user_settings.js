    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tabs button').forEach(btn => btn.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        document.getElementById(tabId + '-btn').classList.add('active');
    }

    function togglePassword(fieldId) {
        const input = document.getElementById(fieldId);
        input.type = input.type === 'password' ? 'text' : 'password';
    }

    window.onload = function () {
        switchTab('profile');
        setTimeout(() => {
            const messages = document.querySelectorAll('.message, .error');
            messages.forEach(m => m.style.display = 'none');
        }, 5000);

        const disable2faForm = document.getElementById('disable-2fa-form');
        const show2faPassButton = document.getElementById('show-2fa-pass');

        if (disable2faForm && show2faPassButton) {
            const passwordContainer = disable2faForm.querySelector('.password-box');
            const submitButton = disable2faForm.querySelector('button[type="submit"]');

            // Initially hide the password field and the final submit button
            passwordContainer.style.display = 'none';
            submitButton.style.display = 'none';

            show2faPassButton.addEventListener('click', function() {
                passwordContainer.style.display = 'block';
                submitButton.style.display = 'block';
                this.style.display = 'none'; // Hide the initial button
            });
        }
    }
