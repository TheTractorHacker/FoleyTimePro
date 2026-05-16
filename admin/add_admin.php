<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$error = "";
$success = "";

require_once __DIR__ . '/includes/csrf.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Username and password are required.";
    } else {
        // Check if username exists
        $stmt = $conn->prepare("SELECT id FROM admins WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Username already exists.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
            $insert->bind_param("ss", $username, $hashed);
            $insert->execute();

            $success = "Admin added successfully.";
        }
    }
}
?>

<?php
$pageTitle   = 'Add Admin';
$headerTitle = 'Add Admin';
include 'includes/header.php';
?>
    <?php if ($error): ?>
            <p style="color: red; margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></p>
        <?php elseif ($success): ?>
            <p style="color: green; margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <form method="POST" class="filter-bar">
<?= csrf_field() ?>
            <div class="row">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="text" name="password" id="password" required>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Add Admin</button>
                <a href="manage_admins.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<?php include 'includes/footer.php'; ?>
