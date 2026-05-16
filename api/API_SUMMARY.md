# TimeClock REST API - Implementation Summary

## ✅ Completed Features

### 1. JWT Authentication System
- **Access Tokens**: 15-minute expiry for API requests
- **Refresh Tokens**: 30-day expiry for obtaining new access tokens
- **Token Storage**: Refresh tokens stored in database with revocation support
- **2FA Support**: Integrated with existing 2FA and backup codes
- **Multi-device**: Support for logout from current device or all devices

### 2. Authentication Endpoints (`/api/v1/auth/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/auth/login` | POST | Login with email/password (optional 2FA code) |
| `/auth/refresh` | POST | Refresh expired access token |
| `/auth/logout` | POST | Logout from current device |
| `/auth/logout-all` | POST | Logout from all devices |

### 3. Clock Operations (`/api/v1/clock/`)

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|---------------|
| `/clock/in` | POST | Clock in to work | ✅ |
| `/clock/out` | POST | Clock out from work | ✅ |
| `/clock/status` | GET | Get current clock status | ✅ |
| `/clock/history` | GET | Get punch history with filters | ✅ |

**Features:**
- GPS location tracking (optional/required based on settings)
- Notes support for clock in/out
- Automatic hours calculation
- Regular vs overtime hours breakdown

### 4. User Profile (`/api/v1/user/`)

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|---------------|
| `/user/profile` | GET | Get user profile with shifts | ✅ |
| `/user/update` | PUT | Update user profile | ✅ |

**Profile Includes:**
- Basic info (name, email, phone, job title)
- Office information
- Assigned shifts
- 2FA status
- Theme preference

### 5. Shifts Management (`/api/v1/shifts/`)

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|---------------|
| `/shifts` | GET | Get all shifts (admin only) | ✅ Admin |
| `/shifts/my` | GET | Get user's assigned shifts | ✅ |

### 6. Branding (`/api/v1/branding`)

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|---------------|
| `/branding` | GET | Get company branding | ❌ Public |

**Returns:**
- Company name
- Logo and favicon URLs
- Color scheme (primary, secondary, accent)
- Timezone

### 7. Push Notifications (`/api/v1/notifications/`)

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|---------------|
| `/notifications/register` | POST | Register device for push notifications | ✅ |
| `/notifications/unregister` | DELETE | Unregister device | ✅ |
| `/notifications/preferences` | GET | Get notification preferences | ✅ |
| `/notifications/preferences` | PUT | Update notification preferences | ✅ |

**Features:**
- FCM (Firebase Cloud Messaging) integration
- User-specific notification preferences
- Automatic notifications for time-off approvals/rejections
- Device token management
- Support for iOS, Android, and Web platforms

## 📦 Technical Stack

- **Language**: PHP 8.3
- **Authentication**: JWT (Firebase PHP-JWT library)
- **Database**: MySQL/MariaDB with MySQLi
- **Architecture**: RESTful API with middleware pattern
- **Security**: Bearer token authentication, SQL injection prevention

## 🏗️ Architecture

```
API Request Flow:
1. Client sends request with JWT token
2. .htaccess routes to appropriate endpoint
3. Endpoint calls AuthMiddleware to validate token
4. Business logic executes
5. ApiResponse formats and returns JSON response
```

## 📂 File Structure

```
/var/www/timeclock/api/
├── v1/                         # API version 1
│   ├── auth.php                # Authentication endpoints
│   ├── clock.php               # Clock operations
│   ├── user.php                # User profile
│   ├── shifts.php              # Shifts management
│   ├── branding.php            # Branding info
│   ├── timeoff.php             # Time-off requests
│   └── notifications.php       # Push notifications
├── auth/
│   └── JWTHandler.php          # JWT token management class
├── middleware/
│   ├── AuthMiddleware.php      # Request authentication
│   └── ApiResponse.php         # Response formatting
├── services/
│   └── NotificationService.php # Push notification service
├── .htaccess                   # URL routing & CORS
├── .env                        # JWT secret keys (auto-generated)
├── README.md                   # Full API documentation
├── QUICKSTART.md               # Quick start guide
├── API_SUMMARY.md              # This file
├── PUSH_NOTIFICATIONS.md       # Push notification guide
├── test_api.php                # Setup verification script
└── test_endpoints.sh           # Endpoint testing script
```

## 🔒 Security Features

1. **JWT-based Authentication**
   - Secure token generation with 256-bit keys
   - Short-lived access tokens (15 min)
   - Long-lived refresh tokens with database tracking

2. **Token Management**
   - Refresh token revocation support
   - Logout from single device or all devices
   - Automatic cleanup of expired tokens

3. **SQL Injection Prevention**
   - All queries use prepared statements with parameter binding

4. **CORS Configuration**
   - Configurable cross-origin resource sharing
   - Preflight request handling

5. **Account Security**
   - Checks for locked/inactive accounts
   - 2FA integration with backup codes
   - Password verification using PHP's password_verify

## 🧪 Testing

### Setup Test
```bash
cd /var/www/timeclock/api
php test_api.php
```

Verifies:
- Database connection
- JWT library installation
- Token generation and validation
- File permissions
- User accounts

### Endpoint Test
```bash
cd /var/www/timeclock/api
./test_endpoints.sh
```

Tests all endpoints in sequence:
1. Get branding (public)
2. Login
3. Get profile
4. Get shifts
5. Clock in/out (if not clocked in)
6. Get history
7. Update profile
8. Refresh token
9. Logout

## 📊 Database Schema Updates

Added columns to `api_tokens` table:
- `Type` ENUM('access', 'refresh', 'device') - Token type
- `IsRevoked` TINYINT(1) - Revocation flag

## 🚀 Usage Examples

### JavaScript/Fetch
```javascript
// Login
const response = await fetch('http://your-domain.com/api/v1/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    email: 'user@example.com',
    password: 'password123'
  })
});
const data = await response.json();
const accessToken = data.data.access_token;

// Clock In
await fetch('http://your-domain.com/api/v1/clock/in', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${accessToken}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    latitude: 40.7128,
    longitude: -74.0060
  })
});
```

### React Native Example
```javascript
import AsyncStorage from '@react-native-async-storage/async-storage';

// Login and store tokens
const login = async (email, password) => {
  const response = await fetch('http://your-domain.com/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });

  const data = await response.json();

  if (data.success) {
    await AsyncStorage.setItem('access_token', data.data.access_token);
    await AsyncStorage.setItem('refresh_token', data.data.refresh_token);
    return data.data.user;
  }

  throw new Error(data.error);
};

// Get clock status
const getClockStatus = async () => {
  const token = await AsyncStorage.getItem('access_token');

  const response = await fetch('http://your-domain.com/api/v1/clock/status', {
    headers: { 'Authorization': `Bearer ${token}` }
  });

  return await response.json();
};
```

## 🎯 Next Steps

### Complete (Ready for Mobile App)
- ✅ Authentication flow with JWT
- ✅ Clock operations with GPS tracking
- ✅ User profile management
- ✅ Shifts information
- ✅ Branding customization
- ✅ Time-off request system
- ✅ Push notification infrastructure

### Future Enhancements
- [ ] Calendar sync endpoints (Google & Outlook)
- [ ] Rate limiting
- [ ] API versioning strategy
- [ ] Webhook support
- [ ] File upload for profile photos
- [ ] Geofencing validation
- [ ] Admin user management endpoints
- [ ] Reports and analytics endpoints
- [ ] Shift swap/trade functionality
- [ ] Availability/scheduling endpoints

## 📝 Notes

- All endpoints return consistent JSON structure
- Error responses include descriptive messages
- HTTP status codes follow REST conventions
- API is ready for mobile app integration
- Documentation includes curl examples for all endpoints

## 🔗 Related Documentation

- [Full API Documentation](README.md) - Complete endpoint reference
- [Quick Start Guide](QUICKSTART.md) - Getting started guide
- JWT Handler: `/var/www/timeclock/api/auth/JWTHandler.php`
- Auth Middleware: `/var/www/timeclock/api/middleware/AuthMiddleware.php`
