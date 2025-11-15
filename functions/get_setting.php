<?php
// get_setting.php
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/settings_helper.php'; // Include the new helper

header('Content-Type: application/json');

$settingKey = $_GET['setting'] ?? '';

if (empty($settingKey)) {
    echo json_encode(['success' => false, 'message' => 'No setting key provided.']);
    exit;
}

$value = getSettingValue($settingKey, $conn); // Use the helper function

if ($value !== null) {
    echo json_encode(['success' => true, 'setting' => $settingKey, 'value' => $value]);
} else {
    echo json_encode(['success' => false, 'message' => "Setting '{$settingKey}' not found."]);
}

$conn->close();
?>