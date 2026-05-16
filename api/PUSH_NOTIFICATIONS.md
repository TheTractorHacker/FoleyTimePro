# Push Notifications Documentation

## Overview

The TimeClock system includes a complete push notification infrastructure using Firebase Cloud Messaging (FCM). This allows the mobile app to receive real-time notifications for important events.

## Features

- Device token registration and management
- User notification preferences
- Automatic notification triggers for key events:
  - Time-off request approvals
  - Time-off request rejections
  - Shift changes
  - Clock reminders
  - General announcements

## Setup

### 1. Firebase Project Setup

1. Create a Firebase project at https://console.firebase.google.com
2. Add your iOS and/or Android app to the project
3. Download the configuration files:
   - `google-services.json` for Android
   - `GoogleService-Info.plist` for iOS
4. Get your FCM Server Key:
   - Go to Project Settings → Cloud Messaging
   - Copy the "Server key" (legacy)

### 2. Configure Server Key

Set the FCM server key in the admin panel or directly in the database:

```sql
UPDATE settings
SET SettingValue = 'YOUR_FCM_SERVER_KEY_HERE'
WHERE SettingKey = 'fcm_server_key';

-- Enable push notifications
UPDATE settings
SET SettingValue = '1'
WHERE SettingKey = 'push_notifications_enabled';
```

## API Endpoints

### Register Device

Register a device to receive push notifications.

**Endpoint:** `POST /api/v1/notifications/register`

**Headers:**
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "device_token": "firebase_device_token_here",
  "platform": "ios",
  "device_name": "John's iPhone"
}
```

**Parameters:**
- `device_token` (required): FCM device token
- `platform` (required): One of: `ios`, `android`, `web`
- `device_name` (optional): Human-readable device name

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Device registered successfully",
  "data": null
}
```

### Unregister Device

Unregister a device from push notifications.

**Endpoint:** `DELETE /api/v1/notifications/unregister`

**Headers:**
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "device_token": "firebase_device_token_here"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Device unregistered successfully",
  "data": null
}
```

### Get Notification Preferences

Get user's notification preferences.

**Endpoint:** `GET /api/v1/notifications/preferences`

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "preferences": {
      "clock_reminders": true,
      "timeoff_updates": true,
      "shift_changes": true,
      "general_announcements": true
    }
  }
}
```

### Update Notification Preferences

Update user's notification preferences.

**Endpoint:** `PUT /api/v1/notifications/preferences`

**Headers:**
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "clock_reminders": true,
  "timeoff_updates": true,
  "shift_changes": false,
  "general_announcements": true
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Notification preferences updated successfully",
  "data": null
}
```

## Notification Types

### Time-Off Notifications

#### Approval
```json
{
  "notification": {
    "title": "Time-Off Request Approved",
    "body": "Your time-off request from Jan 15 to Jan 20, 2025 has been approved."
  },
  "data": {
    "type": "timeoff_approved",
    "action": "open_timeoff"
  }
}
```

#### Rejection
```json
{
  "notification": {
    "title": "Time-Off Request Rejected",
    "body": "Your time-off request from Jan 15 to Jan 20, 2025 was rejected. Reason: Insufficient coverage during that period."
  },
  "data": {
    "type": "timeoff_rejected",
    "action": "open_timeoff"
  }
}
```

### Shift Change Notification
```json
{
  "notification": {
    "title": "Shift Updated",
    "body": "Your shift 'Morning Shift' has been updated: Start time changed to 8:00 AM"
  },
  "data": {
    "type": "shift_change",
    "action": "open_shifts"
  }
}
```

### Clock Reminder
```json
{
  "notification": {
    "title": "Time to Clock In",
    "body": "Don't forget to clock in for your shift!"
  },
  "data": {
    "type": "clock_reminder",
    "action": "open_clock",
    "reminder_type": "clock_in"
  }
}
```

### General Announcement
```json
{
  "notification": {
    "title": "Important Update",
    "body": "Office will be closed on Monday for maintenance."
  },
  "data": {
    "type": "announcement",
    "action": "open_dashboard"
  }
}
```

## Usage in PHP

### Sending Notifications Programmatically

```php
require_once __DIR__ . '/api/services/NotificationService.php';
require_once __DIR__ . '/auth/db.php';

$notificationService = new NotificationService($conn);

// Send to specific user
$notificationService->sendToUser(
    $userId,
    'Welcome!',
    'Thanks for joining our team!',
    ['type' => 'welcome', 'action' => 'open_dashboard']
);

// Send to multiple users
$userIds = [1, 2, 3, 4, 5];
$notificationService->sendToMultipleUsers(
    $userIds,
    'System Maintenance',
    'The system will be down for maintenance on Saturday from 2-4 AM.',
    ['type' => 'announcement']
);

// Helper methods for specific notification types
$notificationService->sendTimeOffApproval($userId, 'Jan 15', 'Jan 20, 2025');
$notificationService->sendTimeOffRejection($userId, 'Jan 15', 'Jan 20, 2025', 'Reason here');
$notificationService->sendShiftChange($userId, 'Morning Shift', 'Start time changed');
$notificationService->sendClockReminder($userId, 'clock_in');
$notificationService->sendAnnouncement($userIds, 'Title', 'Message');
```

## Mobile App Integration

### React Native Example

```javascript
import messaging from '@react-native-firebase/messaging';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Request permission (iOS)
async function requestUserPermission() {
  const authStatus = await messaging().requestPermission();
  const enabled =
    authStatus === messaging.AuthorizationStatus.AUTHORIZED ||
    authStatus === messaging.AuthorizationStatus.PROVISIONAL;

  if (enabled) {
    console.log('Authorization status:', authStatus);
    return true;
  }
  return false;
}

// Get FCM token
async function getFCMToken() {
  const token = await messaging().getToken();
  return token;
}

// Register device with backend
async function registerDevice() {
  const token = await getFCMToken();
  const platform = Platform.OS; // 'ios' or 'android'
  const deviceName = await DeviceInfo.getDeviceName();

  const accessToken = await AsyncStorage.getItem('access_token');

  const response = await fetch('https://your-domain.com/api/v1/notifications/register', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      device_token: token,
      platform: platform,
      device_name: deviceName
    })
  });

  return await response.json();
}

// Handle foreground notifications
messaging().onMessage(async remoteMessage => {
  console.log('Notification received in foreground:', remoteMessage);

  // Show local notification or update UI
  Alert.alert(
    remoteMessage.notification.title,
    remoteMessage.notification.body
  );
});

// Handle background/quit state notifications
messaging().setBackgroundMessageHandler(async remoteMessage => {
  console.log('Background notification:', remoteMessage);
});

// Handle notification tap
messaging().onNotificationOpenedApp(remoteMessage => {
  console.log('Notification opened:', remoteMessage);

  // Navigate based on action
  const action = remoteMessage.data?.action;
  if (action === 'open_timeoff') {
    navigation.navigate('TimeOff');
  } else if (action === 'open_clock') {
    navigation.navigate('Clock');
  }
  // ... handle other actions
});

// Check if app was opened from a quit state notification
messaging()
  .getInitialNotification()
  .then(remoteMessage => {
    if (remoteMessage) {
      console.log('App opened from quit state:', remoteMessage);
      // Handle navigation
    }
  });
```

## Database Schema

### device_tokens
```sql
CREATE TABLE device_tokens (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    DeviceToken VARCHAR(255) NOT NULL,
    Platform ENUM('ios', 'android', 'web') NOT NULL,
    DeviceName VARCHAR(100),
    IsActive TINYINT(1) DEFAULT 1,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    LastUsed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE CASCADE,
    INDEX idx_user_active (UserID, IsActive),
    INDEX idx_token (DeviceToken)
);
```

### notification_preferences
```sql
CREATE TABLE notification_preferences (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    ClockReminders TINYINT(1) DEFAULT 1,
    TimeOffUpdates TINYINT(1) DEFAULT 1,
    ShiftChanges TINYINT(1) DEFAULT 1,
    GeneralAnnouncements TINYINT(1) DEFAULT 1,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE CASCADE,
    UNIQUE KEY unique_user_prefs (UserID)
);
```

### notification_logs
```sql
CREATE TABLE notification_logs (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    DeviceCount INT NOT NULL,
    Title VARCHAR(255) NOT NULL,
    Body TEXT NOT NULL,
    Data JSON,
    Status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    SentAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sent_at (SentAt),
    INDEX idx_status (Status)
);
```

## Automatic Triggers

The system automatically sends push notifications for these events:

1. **Time-Off Approval** - When admin approves a time-off request
2. **Time-Off Rejection** - When admin rejects a time-off request

### Adding Custom Triggers

To add notifications for other events, use the NotificationService:

```php
// Example: Notify user when shift is assigned
require_once __DIR__ . '/api/services/NotificationService.php';

$notificationService = new NotificationService($conn);
$notificationService->sendShiftChange(
    $userId,
    $shiftName,
    'You have been assigned to this shift'
);
```

## Testing

### Test Notification via curl

```bash
# Register a test device
curl -X POST http://localhost/api/v1/notifications/register \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "device_token": "test_token_123",
    "platform": "android",
    "device_name": "Test Device"
  }'

# Update preferences
curl -X PUT http://localhost/api/v1/notifications/preferences \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "clock_reminders": true,
    "timeoff_updates": true,
    "shift_changes": false,
    "general_announcements": true
  }'

# Get preferences
curl -X GET http://localhost/api/v1/notifications/preferences \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

## Troubleshooting

### Notifications not being sent

1. **Check FCM Server Key**: Ensure it's correctly set in the settings table
2. **Verify Device Token**: Make sure the device token is valid and active
3. **Check Logs**: Review `notification_logs` table for errors
4. **User Preferences**: Verify user hasn't disabled notifications
5. **FCM Console**: Check Firebase Console for delivery statistics

### Invalid Device Tokens

The system automatically deactivates invalid tokens when FCM returns errors like:
- `NotRegistered`
- `InvalidRegistration`

These tokens are marked as inactive in the database.

## Best Practices

1. **Register on Login**: Register device token immediately after successful authentication
2. **Update on Token Refresh**: FCM tokens can refresh; listen for token updates and re-register
3. **Unregister on Logout**: Unregister device when user logs out
4. **Handle Permissions**: Request notification permissions at an appropriate time
5. **Respect Preferences**: Always check user preferences before sending notifications
6. **Test Thoroughly**: Test notifications in foreground, background, and quit states

## Security

- Device tokens are user-specific and protected by authentication
- Only authenticated users can register/unregister devices
- Admin-only operations require admin authentication
- FCM Server Key is stored securely in the database
- All notification data is logged for audit purposes
