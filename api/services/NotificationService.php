<?php
/**
 * Push Notification Service
 * Handles sending push notifications via Firebase Cloud Messaging (FCM)
 */

class NotificationService
{
    private $conn;
    private $fcmServerKey;

    public function __construct($dbConnection)
    {
        $this->conn = $dbConnection;
        $this->fcmServerKey = $this->getOrCreateFCMServerKey();
    }

    /**
     * Get or create FCM server key from settings
     */
    private function getOrCreateFCMServerKey()
    {
        $stmt = $this->conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = 'fcm_server_key'");
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            return $result->fetch_assoc()['SettingValue'];
        }

        return null; // Will be set by admin in settings
    }

    /**
     * Send notification to specific user
     */
    public function sendToUser($userId, $title, $body, $data = [])
    {
        // Get user's active device tokens
        $stmt = $this->conn->prepare("
            SELECT DeviceToken, Platform FROM device_tokens
            WHERE UserID = ? AND IsActive = 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $deviceTokens = [];
        while ($row = $result->fetch_assoc()) {
            $deviceTokens[] = $row['DeviceToken'];
        }

        if (empty($deviceTokens)) {
            return false; // No devices to send to
        }

        return $this->sendNotification($deviceTokens, $title, $body, $data);
    }

    /**
     * Send notification to multiple users
     */
    public function sendToMultipleUsers($userIds, $title, $body, $data = [])
    {
        if (empty($userIds)) {
            return false;
        }

        // Get all active device tokens for these users
        $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
        $stmt = $this->conn->prepare("
            SELECT DeviceToken FROM device_tokens
            WHERE UserID IN ($placeholders) AND IsActive = 1
        ");
        $stmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);
        $stmt->execute();
        $result = $stmt->get_result();

        $deviceTokens = [];
        while ($row = $result->fetch_assoc()) {
            $deviceTokens[] = $row['DeviceToken'];
        }

        if (empty($deviceTokens)) {
            return false;
        }

        return $this->sendNotification($deviceTokens, $title, $body, $data);
    }

    /**
     * Send notification via FCM
     */
    private function sendNotification($deviceTokens, $title, $body, $data = [])
    {
        if (!$this->fcmServerKey) {
            error_log('FCM Server Key not configured');
            return false;
        }

        $notification = [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'badge' => '1'
        ];

        $fields = [
            'registration_ids' => $deviceTokens,
            'notification' => $notification,
            'data' => $data,
            'priority' => 'high'
        ];

        $headers = [
            'Authorization: key=' . $this->fcmServerKey,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $response = json_decode($result, true);

            // Log notification
            $this->logNotification($deviceTokens, $title, $body, $data, 'sent');

            // Handle invalid tokens
            if (isset($response['results'])) {
                foreach ($response['results'] as $index => $res) {
                    if (isset($res['error']) && in_array($res['error'], ['NotRegistered', 'InvalidRegistration'])) {
                        $this->deactivateToken($deviceTokens[$index]);
                    }
                }
            }

            return true;
        } else {
            error_log("FCM Error: HTTP $httpCode - $result");
            $this->logNotification($deviceTokens, $title, $body, $data, 'failed');
            return false;
        }
    }

    /**
     * Deactivate invalid device token
     */
    private function deactivateToken($deviceToken)
    {
        $stmt = $this->conn->prepare("UPDATE device_tokens SET IsActive = 0 WHERE DeviceToken = ?");
        $stmt->bind_param("s", $deviceToken);
        $stmt->execute();
    }

    /**
     * Log notification for debugging and tracking
     */
    private function logNotification($deviceTokens, $title, $body, $data, $status)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO notification_logs (DeviceCount, Title, Body, Data, Status, SentAt)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $deviceCount = count($deviceTokens);
        $dataJson = json_encode($data);
        $stmt->bind_param("issss", $deviceCount, $title, $body, $dataJson, $status);
        $stmt->execute();
    }

    /**
     * Send time-off approval notification
     */
    public function sendTimeOffApproval($userId, $startDate, $endDate)
    {
        return $this->sendToUser(
            $userId,
            'Time-Off Request Approved',
            "Your time-off request from $startDate to $endDate has been approved.",
            ['type' => 'timeoff_approved', 'action' => 'open_timeoff']
        );
    }

    /**
     * Send time-off rejection notification
     */
    public function sendTimeOffRejection($userId, $startDate, $endDate, $reason)
    {
        return $this->sendToUser(
            $userId,
            'Time-Off Request Rejected',
            "Your time-off request from $startDate to $endDate was rejected. Reason: $reason",
            ['type' => 'timeoff_rejected', 'action' => 'open_timeoff']
        );
    }

    /**
     * Send shift change notification
     */
    public function sendShiftChange($userId, $shiftName, $changeDescription)
    {
        return $this->sendToUser(
            $userId,
            'Shift Updated',
            "Your shift '$shiftName' has been updated: $changeDescription",
            ['type' => 'shift_change', 'action' => 'open_shifts']
        );
    }

    /**
     * Send clock reminder notification
     */
    public function sendClockReminder($userId, $reminderType)
    {
        $messages = [
            'clock_in' => 'Don\'t forget to clock in for your shift!',
            'clock_out' => 'Don\'t forget to clock out before leaving!'
        ];

        $title = $reminderType === 'clock_in' ? 'Time to Clock In' : 'Time to Clock Out';
        $body = $messages[$reminderType] ?? 'Time clock reminder';

        return $this->sendToUser(
            $userId,
            $title,
            $body,
            ['type' => 'clock_reminder', 'action' => 'open_clock', 'reminder_type' => $reminderType]
        );
    }

    /**
     * Send general announcement
     */
    public function sendAnnouncement($userIds, $title, $message)
    {
        return $this->sendToMultipleUsers(
            $userIds,
            $title,
            $message,
            ['type' => 'announcement', 'action' => 'open_dashboard']
        );
    }

    /**
     * Check user notification preferences
     */
    public function shouldSendNotification($userId, $notificationType)
    {
        $stmt = $this->conn->prepare("
            SELECT ClockReminders, TimeOffUpdates, ShiftChanges, GeneralAnnouncements
            FROM notification_preferences
            WHERE UserID = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return true; // Default to all enabled
        }

        $prefs = $result->fetch_assoc();

        $typeMap = [
            'clock_reminder' => 'ClockReminders',
            'timeoff' => 'TimeOffUpdates',
            'shift' => 'ShiftChanges',
            'announcement' => 'GeneralAnnouncements'
        ];

        $column = $typeMap[$notificationType] ?? null;
        return $column ? (bool)$prefs[$column] : true;
    }
}
