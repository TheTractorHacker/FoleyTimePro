# TimeClock REST API

## Overview
This is the REST API for the TimeClock system. It provides endpoints for mobile apps and third-party integrations.

## Base URL
```
https://your-domain.com/api/v1
```

## Authentication
The API uses JWT (JSON Web Tokens) for authentication. After logging in, you'll receive an access token and a refresh token.

### Token Usage
- **Access Token**: Short-lived (15 minutes), used for API requests
- **Refresh Token**: Long-lived (30 days), used to obtain new access tokens

Include the access token in the Authorization header:
```
Authorization: Bearer <access_token>
```

## Endpoints

### Authentication

#### POST `/auth/login`
Login and obtain tokens.

**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "password123",
  "twofa_code": "123456"  // Optional, required if 2FA is enabled
}
```

**Response:**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "role": "user",
      "two_fa_enabled": false
    }
  }
}
```

#### POST `/auth/refresh`
Refresh an expired access token.

**Request Body:**
```json
{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

**Response:**
```json
{
  "success": true,
  "message": "Token refreshed successfully",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

#### POST `/auth/logout`
Logout from current device (revoke refresh token).

**Request Body:**
```json
{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

**Response:**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

#### POST `/auth/logout-all`
Logout from all devices (revoke all user's refresh tokens).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "message": "Logged out from all devices successfully"
}
```

### Clock Operations

#### POST `/clock/in`
Clock in to work.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Request Body:**
```json
{
  "latitude": 40.7128,
  "longitude": -74.0060,
  "notes": "Starting my shift"  // Optional
}
```

**Response:**
```json
{
  "success": true,
  "message": "Clocked in successfully",
  "data": {
    "punch_id": 123,
    "clock_in": "2025-11-24 09:00:00",
    "location": {
      "latitude": 40.7128,
      "longitude": -74.0060
    }
  }
}
```

#### POST `/clock/out`
Clock out from work.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Request Body:**
```json
{
  "latitude": 40.7128,
  "longitude": -74.0060,
  "notes": "Ending my shift"  // Optional
}
```

**Response:**
```json
{
  "success": true,
  "message": "Clocked out successfully",
  "data": {
    "punch_id": 123,
    "clock_in": "2025-11-24 09:00:00",
    "clock_out": "2025-11-24 17:00:00",
    "total_hours": 8.0
  }
}
```

#### GET `/clock/status`
Get current clock status.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "clocked_in": true,
    "current_punch": {
      "id": 123,
      "clock_in": "2025-11-24 09:00:00",
      "elapsed_hours": 4.5
    }
  }
}
```

#### GET `/clock/history`
Get punch history.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Query Parameters:**
- `start_date` (optional): YYYY-MM-DD
- `end_date` (optional): YYYY-MM-DD
- `limit` (optional): Number of records (default: 50)

**Response:**
```json
{
  "success": true,
  "data": {
    "punches": [
      {
        "id": 123,
        "date": "2025-11-24",
        "clock_in": "09:00:00",
        "clock_out": "17:00:00",
        "total_hours": 8.0,
        "notes": ""
      }
    ],
    "summary": {
      "total_hours": 40.0,
      "regular_hours": 40.0,
      "overtime_hours": 0.0
    }
  }
}
```

### User Profile

#### GET `/user/profile`
Get user profile information.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "email": "user@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "phone": "+1234567890",
    "role": "user",
    "is_active": true,
    "created_at": "2025-01-01 00:00:00"
  }
}
```

#### PUT `/user/update`
Update user profile.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Request Body:**
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "phone": "+1234567890"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Profile updated successfully"
}
```

### Shifts

#### GET `/shifts`
Get all shifts (admin only).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "shifts": [
      {
        "id": 1,
        "name": "Morning Shift",
        "start_time": "09:00:00",
        "end_time": "17:00:00",
        "days": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
        "is_active": true
      }
    ]
  }
}
```

#### GET `/shifts/my`
Get user's assigned shifts.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "shifts": [
      {
        "id": 1,
        "name": "Morning Shift",
        "start_time": "09:00:00",
        "end_time": "17:00:00",
        "days": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
      }
    ]
  }
}
```

### Branding

#### GET `/branding`
Get company branding information.

**Response:**
```json
{
  "success": true,
  "data": {
    "company_name": "My Company",
    "logo_url": "https://your-domain.com/images/logo.png",
    "favicon_url": "https://your-domain.com/images/favicon.webp",
    "primary_color": "#073851",
    "secondary_color": "#042a3d",
    "accent_color": "#4CAF50"
  }
}
```

### Time-Off Requests

#### GET `/timeoff`
Get user's time-off requests.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Query Parameters:**
- `status` (optional): Filter by status (Pending, Approved, Rejected)
- `limit` (optional): Number of records (default: 50)

**Response:**
```json
{
  "success": true,
  "data": {
    "requests": [
      {
        "id": 1,
        "start_date": "2025-12-20",
        "end_date": "2025-12-24",
        "type": "Vacation",
        "reason": "Holiday trip",
        "status": "Pending",
        "requested_at": "2025-11-24 10:00:00",
        "reviewed_at": null,
        "review_notes": null
      }
    ]
  }
}
```

#### POST `/timeoff/request`
Submit new time-off request.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Request Body:**
```json
{
  "start_date": "2025-12-20",
  "end_date": "2025-12-24",
  "type": "Vacation",
  "reason": "Holiday trip"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Time-off request submitted successfully",
  "data": {
    "request_id": 1,
    "status": "Pending"
  }
}
```

#### PUT `/timeoff/{id}/cancel`
Cancel pending time-off request.

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "message": "Request cancelled successfully"
}
```

#### GET `/timeoff/all`
Get all time-off requests (admin only).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Query Parameters:**
- `status` (optional): Filter by status
- `user_id` (optional): Filter by user
- `limit` (optional): Number of records (default: 100)

**Response:**
```json
{
  "success": true,
  "data": {
    "requests": [
      {
        "id": 1,
        "employee": {
          "id": 5,
          "name": "John Doe",
          "email": "john@example.com"
        },
        "start_date": "2025-12-20",
        "end_date": "2025-12-24",
        "type": "Vacation",
        "reason": "Holiday trip",
        "status": "Pending",
        "requested_at": "2025-11-24 10:00:00",
        "reviewed_at": null,
        "reviewed_by": null,
        "review_notes": null
      }
    ]
  }
}
```

#### PUT `/timeoff/{id}/approve`
Approve time-off request (admin only).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Request Body:**
```json
{
  "notes": "Approved - enjoy your vacation!"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Request approved successfully"
}
```

#### PUT `/timeoff/{id}/reject`
Reject time-off request (admin only).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Request Body:**
```json
{
  "notes": "Cannot approve due to staffing requirements"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Request rejected successfully"
}
```

## Error Responses

All error responses follow this format:

```json
{
  "success": false,
  "error": "Error message here",
  "errors": {  // Optional, for validation errors
    "field": "Error description"
  }
}
```

### Push Notifications

#### POST `/notifications/register`
Register device for push notifications.

**Authentication Required**

**Request Body:**
```json
{
  "device_token": "firebase_device_token_here",
  "platform": "ios",
  "device_name": "John's iPhone"
}
```

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Device registered successfully",
  "data": null
}
```

#### DELETE `/notifications/unregister`
Unregister device from push notifications.

**Authentication Required**

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
  "message": "Device unregister successfully",
  "data": null
}
```

#### GET `/notifications/preferences`
Get notification preferences.

**Authentication Required**

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

#### PUT `/notifications/preferences`
Update notification preferences.

**Authentication Required**

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

**See [PUSH_NOTIFICATIONS.md](PUSH_NOTIFICATIONS.md) for complete push notification documentation.**

### Common HTTP Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized (invalid/expired token)
- `403` - Forbidden (insufficient permissions)
- `404` - Not Found
- `422` - Validation Error
- `500` - Internal Server Error

## Rate Limiting
*Coming soon*

## Versioning
The API is versioned via the URL path (`/api/v1`). Breaking changes will result in a new version.
