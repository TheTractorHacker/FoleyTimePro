# Push Notification Infrastructure - Setup Complete ✅

## Summary

The push notification infrastructure has been successfully implemented for the TimeClock system. This enables real-time notifications to mobile devices for important events like time-off request approvals/rejections, shift changes, and clock reminders.

## What Was Implemented

### 1. Database Tables Created ✅

- **notification_preferences** - Stores user notification preferences
  - Clock reminders (enabled/disabled)
  - Time-off updates (enabled/disabled)
  - Shift changes (enabled/disabled)
  - General announcements (enabled/disabled)

- **notification_logs** - Tracks all sent notifications for auditing
  - Device count
  - Title and body
  - Custom data (JSON)
  - Status (sent/failed/pending)
  - Timestamp

- **Settings** - Added FCM configuration
  - `fcm_server_key` - Firebase Cloud Messaging server key
  - `push_notifications_enabled` - Enable/disable push notifications globally

### 2. API Endpoints Created ✅

**Base URL:** `/api/v1/notifications/`

- **POST `/register`** - Register device for push notifications
  - Accepts device token, platform (ios/android/web), device name
  - Updates existing tokens or creates new ones
  - Marks device as active

- **DELETE `/unregister`** - Unregister device
  - Marks device token as inactive
  - Prevents future notifications to that device

- **GET `/preferences`** - Get notification preferences
  - Returns user's current notification settings
  - Defaults to all enabled if not set

- **PUT `/preferences`** - Update notification preferences
  - Allows users to customize which notifications they receive
  - Granular control over notification types

### 3. NotificationService Class ✅

**Location:** `/var/www/timeclock/api/services/NotificationService.php`

**Key Methods:**
- `sendToUser($userId, $title, $body, $data)` - Send to specific user
- `sendToMultipleUsers($userIds, $title, $body, $data)` - Broadcast to multiple users
- `sendTimeOffApproval($userId, $startDate, $endDate)` - Time-off approval notification
- `sendTimeOffRejection($userId, $startDate, $endDate, $reason)` - Time-off rejection notification
- `sendShiftChange($userId, $shiftName, $change)` - Shift update notification
- `sendClockReminder($userId, $type)` - Clock in/out reminders
- `sendAnnouncement($userIds, $title, $message)` - General announcements
- `shouldSendNotification($userId, $type)` - Check user preferences

**Features:**
- Uses Firebase Cloud Messaging (FCM) for delivery
- Automatically deactivates invalid device tokens
- Logs all notifications for tracking
- Respects user notification preferences
- Handles multiple devices per user

### 4. Automatic Notification Triggers ✅

Notifications are now automatically sent for:

1. **Time-Off Approvals**
   - Triggered when admin approves a time-off request (web or API)
   - Includes date range in notification
   - Deep link to time-off screen in app

2. **Time-Off Rejections**
   - Triggered when admin rejects a time-off request (web or API)
   - Includes rejection reason
   - Deep link to time-off screen in app

### 5. Integration Points ✅

Push notifications integrated into:

- **API Endpoint:** `/var/www/timeclock/api/v1/timeoff.php`
  - Lines 403-408: Send notification on approval
  - Lines 468-474: Send notification on rejection

- **Admin Web Portal:** `/var/www/timeclock/admin/time_off_requests.php`
  - Lines 29-37: Send notification on web approval
  - Lines 59-68: Send notification on web rejection

### 6. Documentation ✅

Complete documentation created:

1. **PUSH_NOTIFICATIONS.md** - Comprehensive guide including:
   - Firebase setup instructions
   - API endpoint documentation
   - Notification type specifications
   - Mobile app integration examples (React Native)
   - Database schema
   - Testing instructions
   - Troubleshooting guide
   - Best practices

2. **README.md** - Updated with notification endpoints

3. **API_SUMMARY.md** - Updated with push notification section

### 7. URL Routing ✅

**.htaccess** updated with notification routes:
```apache
RewriteRule ^v1/notifications/register$ v1/notifications.php?action=register [L,QSA]
RewriteRule ^v1/notifications/unregister$ v1/notifications.php?action=unregister [L,QSA]
RewriteRule ^v1/notifications/preferences$ v1/notifications.php?action=preferences [L,QSA]
```

## Files Created/Modified

### New Files
- `/var/www/timeclock/api/v1/notifications.php` - Notification API endpoints
- `/var/www/timeclock/api/services/NotificationService.php` - Notification service class
- `/var/www/timeclock/api/PUSH_NOTIFICATIONS.md` - Complete documentation
- `/var/www/timeclock/migrations/008_add_notification_tables.sql` - Database migration
- `/var/www/timeclock/migrations/create_notification_tables.php` - Migration runner

### Modified Files
- `/var/www/timeclock/api/.htaccess` - Added notification routes
- `/var/www/timeclock/api/v1/timeoff.php` - Added notification triggers
- `/var/www/timeclock/admin/time_off_requests.php` - Added notification triggers
- `/var/www/timeclock/api/README.md` - Added notification endpoints
- `/var/www/timeclock/api/API_SUMMARY.md` - Updated feature list

## Configuration Required

### Admin Setup Steps

1. **Get Firebase Credentials**
   - Create Firebase project at https://console.firebase.google.com
   - Add iOS/Android app to project
   - Get FCM Server Key from Project Settings → Cloud Messaging

2. **Configure Server Key**
   ```sql
   UPDATE settings
   SET SettingValue = 'YOUR_FCM_SERVER_KEY_HERE'
   WHERE SettingKey = 'fcm_server_key';
   ```

3. **Enable Push Notifications**
   ```sql
   UPDATE settings
   SET SettingValue = '1'
   WHERE SettingKey = 'push_notifications_enabled';
   ```

### Mobile App Setup

1. **Install Firebase SDK**
   ```bash
   npm install @react-native-firebase/app @react-native-firebase/messaging
   ```

2. **Add Configuration Files**
   - Android: Place `google-services.json` in `android/app/`
   - iOS: Place `GoogleService-Info.plist` in `ios/`

3. **Request Permissions**
   - iOS: Request notification permissions
   - Android: Automatic since Android 13+

4. **Register Device Token**
   - Get FCM token on app launch
   - Send to `/api/v1/notifications/register` with user's access token

See `/var/www/timeclock/api/PUSH_NOTIFICATIONS.md` for complete mobile integration code.

## Testing

### Test Device Registration

```bash
curl -X POST http://localhost/api/v1/notifications/register \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "device_token": "test_token_123",
    "platform": "android",
    "device_name": "Test Device"
  }'
```

### Test Notification Flow

1. Register a device token
2. Submit a time-off request (as employee)
3. Approve/reject the request (as admin)
4. Verify notification is sent to device
5. Check `notification_logs` table for delivery status

### Verify Database Tables

```bash
php -r "
require 'auth/db.php';
\$tables = ['notification_preferences', 'notification_logs'];
foreach (\$tables as \$table) {
    \$result = \$conn->query(\"SELECT COUNT(*) as count FROM \$table\");
    \$count = \$result->fetch_assoc()['count'];
    echo \"✓ \$table: \$count rows\\n\";
}
"
```

## Notification Flow Diagram

```
┌─────────────────┐
│  Admin Action   │ (Approve/Reject Time-Off)
└────────┬────────┘
         │
         v
┌─────────────────┐
│ Database Update │ (Update time_off_requests)
└────────┬────────┘
         │
         v
┌─────────────────┐
│ NotificationSvc │ (Send notification)
└────────┬────────┘
         │
         v
┌─────────────────┐
│  Get Devices    │ (Query device_tokens for user)
└────────┬────────┘
         │
         v
┌─────────────────┐
│ Check Prefs     │ (Query notification_preferences)
└────────┬────────┘
         │
         v
┌─────────────────┐
│   Send to FCM   │ (POST to Firebase)
└────────┬────────┘
         │
         ├──> Success ──> Log (sent)
         │
         └──> Failure ──> Log (failed)
                         Deactivate invalid tokens
```

## Next Steps

The push notification infrastructure is now complete and ready for mobile app integration. The next pending tasks are:

1. **Update Docker configuration** - Add any necessary dependencies
2. **Create React Native mobile app** - Build the mobile app structure
3. **Implement mobile authentication** - Integrate with API
4. **Build mobile screens** - Dashboard, Clock, Shifts, Time Off, Profile
5. **Test end-to-end** - Full notification flow from approval to device

## Support & Documentation

- **Full Documentation:** `/var/www/timeclock/api/PUSH_NOTIFICATIONS.md`
- **API Reference:** `/var/www/timeclock/api/README.md`
- **Quick Start:** `/var/www/timeclock/api/QUICKSTART.md`
- **Summary:** `/var/www/timeclock/api/API_SUMMARY.md`

---

**Implementation Date:** 2025-11-24
**Status:** ✅ Complete and Ready for Mobile Integration
