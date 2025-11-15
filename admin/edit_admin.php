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

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Admin</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <header>
        <img src="/images/D-Best.png" alt="Logo" class="logo">
        <h1>Edit Admin</h1>
        <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="view_punches.php">Timesheets</a>
        <a href="summary.php">Summary</a>
        <a href="reports.php" class="active">Reports</a>
        <a href="manage_users.php">Users</a>
        <a href="manage_offices.php">Offices</a>
        <a href="attendance.php">Attendance</a>
        <a href="manage_admins.php">Admins</a>
        <a href="../logout.php">Logout</a>
    </nav>
    </header>

    <div class="container">
        <?php if ($error): ?>
            <p style="color: red; margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></p>
        <?php elseif ($success): ?>
            <p style="color: green; margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <?php if ($admin): ?>
            <form method="POST" class="summary-filter">
                <div class="row">
                    <div class="field">
                        <label for="username">Username</label>
                        <input type="text" name="username" id="username" value="<?= htmlspecialchars($admin['username']) ?>" required>
                    </div>

                    <div class="field">
                        <label for="password">New Password <span style="font-weight: normal; color: #999;">(leave blank to keep current)</span></label>
                        <input type="text" name="password" id="password">
                    </div>
                </div>

                <div class="buttons">
                    <button type="submit">Save Changes</button>
                    <a href="manage_admins.php" class="btn-reset">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
