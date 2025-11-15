<?php
// functions/settings_helper.php

/**
 * Retrieves a setting value from the database.
 *
 * @param string $settingKey The key of the setting to retrieve.
 * @param mysqli $conn The database connection object.
 * @return string|null The setting value if found, otherwise null.
 */
function getSettingValue($settingKey, $conn) {
    $stmt = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = ? LIMIT 1");
    if (!$stmt) {
        error_log("Database query preparation failed for setting: " . $conn->error);
        return null;
    }

    $stmt->bind_param("s", $settingKey);
    $stmt->execute();
    $stmt->bind_result($value);

    if ($stmt->fetch()) {
        $stmt->close();
        return $value;
    } else {
        $stmt->close();
        return null;
    }
}
