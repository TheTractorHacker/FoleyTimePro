# TimeClock API - Quick Start Guide

## 🚀 Getting Started

### 1. Test the API Setup
```bash
cd /var/www/timeclock/api
php test_api.php
```

This will verify:
- ✅ Database connection
- ✅ JWT library installation
- ✅ Token generation and validation
- ✅ File permissions

### 2. Test Endpoints
```bash
cd /var/www/timeclock/api
./test_endpoints.sh
```

**Note:** Edit `test_endpoints.sh` first and set the correct email and password.

## 📋 Quick Reference

### Authentication Flow

1. **Login** → Get access token + refresh token
2. **Use access token** for API requests (15 min validity)
3. **Refresh** when access token expires (using refresh token)
4. **Logout** to revoke refresh token

### Common Requests

#### 1. Login
```bash
curl -X POST http://your-domain.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password123"}'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "access_token": "eyJ0eXAi...",
    "refresh_token": "eyJ0eXAi...",
    "user": { ... }
  }
}
```

#### 2. Clock In
```bash
curl -X POST http://your-domain.com/api/v1/clock/in \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"latitude":40.7128,"longitude":-74.0060}'
```

#### 3. Clock Out
```bash
curl -X POST http://your-domain.com/api/v1/clock/out \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"latitude":40.7128,"longitude":-74.0060}'
```

#### 4. Get Clock Status
```bash
curl -X GET http://your-domain.com/api/v1/clock/status \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

#### 5. Get User Profile
```bash
curl -X GET http://your-domain.com/api/v1/user/profile \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

#### 6. Get Punch History
```bash
curl -X GET "http://your-domain.com/api/v1/clock/history?limit=10" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

#### 7. Get My Shifts
```bash
curl -X GET http://your-domain.com/api/v1/shifts/my \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

#### 8. Get Branding (No Auth)
```bash
curl -X GET http://your-domain.com/api/v1/branding
```

#### 9. Refresh Token
```bash
curl -X POST http://your-domain.com/api/v1/auth/refresh \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"YOUR_REFRESH_TOKEN"}'
```

#### 10. Logout
```bash
curl -X POST http://your-domain.com/api/v1/auth/logout \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"YOUR_REFRESH_TOKEN"}'
```

## 🔧 Testing with Postman

1. Create a new collection "TimeClock API"
2. Add environment variables:
   - `base_url`: `http://your-domain.com/api/v1`
   - `access_token`: (will be set after login)
   - `refresh_token`: (will be set after login)

3. Create a login request and add a test script to save tokens:
```javascript
var response = pm.response.json();
pm.environment.set("access_token", response.data.access_token);
pm.environment.set("refresh_token", response.data.refresh_token);
```

4. For authenticated requests, use:
   - Authorization Type: Bearer Token
   - Token: `{{access_token}}`

## 📁 API Structure

```
/var/www/timeclock/api/
├── v1/
│   ├── auth.php          # Authentication endpoints
│   ├── clock.php         # Clock in/out endpoints
│   ├── user.php          # User profile endpoints
│   ├── shifts.php        # Shifts endpoints
│   └── branding.php      # Branding endpoint
├── auth/
│   └── JWTHandler.php    # JWT token management
├── middleware/
│   ├── AuthMiddleware.php    # Authentication middleware
│   └── ApiResponse.php       # Response helpers
├── .htaccess             # URL routing
├── README.md             # Full documentation
├── QUICKSTART.md         # This file
├── test_api.php          # API setup test
└── test_endpoints.sh     # Endpoint test script
```

## 🔐 Security Notes

- Access tokens expire after 15 minutes
- Refresh tokens expire after 30 days
- Refresh tokens are stored in the database and can be revoked
- All endpoints except `/branding` and `/auth/login` require authentication
- CORS is enabled for all origins (configure `.htaccess` for production)

## 🐛 Troubleshooting

### "Invalid token" error
- Access token may have expired - use refresh endpoint
- Make sure to include "Bearer " prefix in Authorization header

### "Method not allowed" error
- Check HTTP method (GET, POST, PUT, etc.)
- Some endpoints only accept specific methods

### "GPS location required" error
- Check if GPS enforcement is enabled in settings
- Include `latitude` and `longitude` in clock in/out requests

### Database errors
- Verify database connection in `/var/www/timeclock/auth/db.php`
- Check that all required tables exist (run migrations)

## 📞 Support

For full API documentation, see [README.md](README.md)
