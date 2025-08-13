# API Authentication Guide

The Librarian API uses **Laravel Sanctum** for token-based authentication. This guide explains how to authenticate with the API and use the authentication tokens.

## Overview

- **Authentication Method**: Bearer Token Authentication
- **Token Provider**: Laravel Sanctum
- **Token Storage**: MySQL database (`personal_access_tokens` table)
- **Token Expiration**: 30 days from creation
- **Base URL**: `/api/v1`

## Quick Start

1. **Register** a new account or use existing credentials
2. **Login** to get an access token
3. **Include the token** in the `Authorization` header for protected endpoints
4. **Logout** when done to invalidate the token

## Authentication Endpoints

### Register New Account

**Endpoint**: `POST /api/v1/register`

Creates a new user account. New accounts are created with `unverified` status and require admin approval.

```http
POST /api/v1/register
Content-Type: application/json

{
  "name": "John Doe",
  "username": "johndoe",
  "email": "john@example.com",
  "password": "securepassword123"
}
```

**Success Response** (201):
```json
{
  "message": "Account created. Waiting for admin approval."
}
```

**Error Responses**:
- `400` - Validation errors or duplicate email/username
- See OpenAPI spec for detailed error formats

### Login

**Endpoint**: `POST /api/v1/login`

Authenticates a user and returns an access token. You can login with either email or username.

#### Login with Email
```http
POST /api/v1/login
Content-Type: application/json

{
  "email": "john@example.com",
  "password": "securepassword123"
}
```

#### Login with Username
```http
POST /api/v1/login
Content-Type: application/json

{
  "username": "johndoe",
  "password": "securepassword123"
}
```

**Success Response** (200):
```json
{
  "id": 1,
  "name": "John Doe",
  "username": "johndoe",
  "email": "john@example.com",
  "role": "user",
  "authToken": "1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz",
  "refreshToken": "1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz",
  "token": "1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz"
}
```

**Error Responses**:
- `400` - Validation error
- `401` - Invalid credentials
- `403` - Account pending admin approval

### Logout

**Endpoint**: `POST /api/v1/logout`

Invalidates the current access token.

```http
POST /api/v1/logout
Authorization: Bearer 1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz
```

**Success Response** (200):
```json
{
  "message": "Successfully logged out"
}
```

### Get Current User

There are two endpoints:

1) Full user object

**Endpoint**: `GET /api/v1/user`

Returns the full authenticated user object.

```http
GET /api/v1/user
Authorization: Bearer 1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz
```

**Success Response** (200):
```json
{
  "id": 1,
  "name": "John Doe",
  "username": "johndoe",
  "email": "john@example.com",
  "role": "user",
  "email_verified_at": null,
  "created_at": "2024-01-01T12:00:00Z",
  "updated_at": "2024-01-01T12:00:00Z"
}
```

2) Minimal profile

**Endpoint**: `GET /api/v1/me`

Returns only the authenticated user's `name` and `email`.

```http
GET /api/v1/me
Authorization: Bearer 1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz
```

**Success Response** (200):
```json
{
  "name": "John Doe",
  "email": "john@example.com"
}
```

## Using Authentication Tokens

### Including Tokens in Requests

For all protected endpoints, include the access token in the `Authorization` header:

```http
Authorization: Bearer {your-token-here}
```

### Example Protected Request

```http
GET /api/v1/books
Authorization: Bearer 1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz
Accept: application/json
```

### Token Format

Sanctum tokens follow this format:
```
{token_id}|{actual_token}
```

Example: `1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz`

## User Roles and Permissions

### Role Types
- `admin` - Full access to all features
- `user` - Standard user access (approved account)
- `unverified` - New account awaiting admin approval

### Role-Based Access
- Some endpoints require `admin` role
- Most endpoints require at least `user` role
- `unverified` users cannot access protected endpoints

## Error Handling

### Common Authentication Errors

**401 Unauthorized**
```json
{
  "error": "Unauthorized"
}
```
- Missing or invalid token
- Token has expired
- Token has been revoked

**403 Forbidden**
```json
{
  "error": "Account pending admin approval"
}
```
- User account is in `unverified` status

### Handling Token Expiration

When a token expires, you'll receive a `401` response. Handle this by:
1. Redirecting to login
2. Prompting user to re-authenticate
3. Clearing stored tokens

## Security Best Practices

### Token Storage
- **Frontend Apps**: Store in memory or secure storage
- **Mobile Apps**: Use secure keychain/keystore
- **Never**: Store tokens in localStorage in web browsers (XSS risk)

### Token Transmission
- Always use HTTPS in production
- Never log tokens or include in URLs
- Include tokens only in Authorization headers

### Token Management
- Logout when done to invalidate tokens
- Implement token refresh if needed
- Monitor for suspicious activity

## Code Examples

### JavaScript/Fetch
```javascript
// Login
const loginResponse = await fetch('/api/v1/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    email: 'john@example.com',
    password: 'securepassword123'
  })
});

const authData = await loginResponse.json();
const token = authData.authToken;

// Use token for protected requests
const booksResponse = await fetch('/api/v1/books', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json'
  }
});
```

### cURL
```bash
# Login
curl -X POST http://localhost:8000/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"john@example.com","password":"securepassword123"}'

# Use token for requests
curl -X GET http://localhost:8000/api/v1/books \
  -H "Authorization: Bearer 1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz" \
  -H "Accept: application/json"
```

### Python/Requests
```python
import requests

# Login
login_data = {
    "email": "john@example.com",
    "password": "securepassword123"
}

response = requests.post("http://localhost:8000/api/v1/login", json=login_data)
auth_data = response.json()
token = auth_data["authToken"]

# Use token
headers = {
    "Authorization": f"Bearer {token}",
    "Accept": "application/json"
}

books_response = requests.get("http://localhost:8000/api/v1/books", headers=headers)
```

## Troubleshooting

### Token Not Working
1. Verify token format is correct
2. Check token hasn't expired (30 days)
3. Ensure user account is approved (`role != 'unverified'`)
4. Verify token wasn't already used for logout

### Registration Issues
1. Check all required fields are provided
2. Verify email format is valid
3. Ensure username/email aren't already taken
4. Password must be at least 8 characters

### Login Issues
1. Verify credentials are correct
2. Check account status (may need admin approval)
3. Ensure using correct email/username format

## Database Schema

The authentication system uses these database tables:

### `users`
- `id` - Primary key
- `name` - Full name
- `username` - Unique username
- `email` - Unique email address
- `password` - Hashed password
- `role` - User role (admin/user/unverified)
- `email_verified_at` - Email verification timestamp
- `created_at` / `updated_at` - Timestamps

### `personal_access_tokens`
- `id` - Primary key
- `tokenable_type` - Model type (App\Models\User)
- `tokenable_id` - User ID
- `name` - Token name
- `token` - Hashed token value
- `abilities` - Token permissions (JSON)
- `last_used_at` - Last usage timestamp
- `expires_at` - Expiration timestamp
- `created_at` / `updated_at` - Timestamps

## Migration from Firebase

If you previously used Firebase authentication, note these changes:

1. **Token Format**: Now uses Sanctum tokens instead of Firebase JWT
2. **User Storage**: Users stored in MySQL instead of Firebase
3. **Registration**: Creates database records instead of Firebase users
4. **Middleware**: Uses `api.auth` instead of `firebase.auth`

Existing Firebase tokens will no longer work and users will need to re-authenticate with the new system.