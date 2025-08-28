<?php
// get_setting.php
require_once __DIR__ . '/../auth/db.php';

header('Content-Type: application/json');

$settingKey = $_GET['setting'] ?? '';

if (empty($settingKey)) {
    echo json_encode(['success' => false, 'message' => 'No setting key provided.']);
    exit;
}

$stmt = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database query failed.']);
    exit;
}

$stmt->bind_param("s", $settingKey);
$stmt->execute();
$stmt->bind_result($value);

if ($stmt->fetch()) {
    echo json_encode(['success' => true, 'setting' => $settingKey, 'value' => $value]);
} else {
    echo json_encode(['success' => false, 'message' => "Setting '{$settingKey}' not found."]);
}

$stmt->close();
$conn->close();
?>