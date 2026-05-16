<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header("Location: manage_admins.php");
    exit;
}

$error = "";
$success = "";

// Fetch admin info
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

if (!$admin) {
    $error = "Admin not found.";
}

require_once __DIR__ . '/includes/csrf.php';

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username)) {
        $error = "Username cannot be empty.";
    } else {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $update = $conn->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
            $update->bind_param("ssi", $username, $hashed, $id);
        } else {
            $update = $conn->prepare("UPDATE admins SET username = ? WHERE id = ?");
            $update->bind_param("si", $username, $id);
        }

        if ($update->execute()) {
            $success = "Admin updated successfully.";
            // Refresh the admin data
            $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
        } else {
            $error = "Failed to update admin.";
        }
    }
}
?>

<?php
$pageTitle   = 'Edit Admin';
$headerTitle = 'Edit Admin';
include 'includes/header.php';
?>
    <?php if ($error): ?>
            <p style="color: red; margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></p>
        <?php elseif ($success): ?>
            <p style="color: green; margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <?php if ($admin): ?>
            <form method="POST" class="filter-bar">
<?= csrf_field() ?>
                <div class="row">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" name="username" id="username" value="<?= htmlspecialchars($admin['username']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="password">New Password <span style="font-weight: normal; color: #999;">(leave blank to keep current)</span></label>
                        <input type="text" name="password" id="password">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="manage_admins.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>

<?php include 'includes/footer.php'; ?>
