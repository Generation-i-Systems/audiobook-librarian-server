# Librarian API Documentation

Welcome to the Librarian API documentation. This API provides access to the audiobook management system with Bearer token authentication.

## 📚 Documentation Overview

This documentation suite provides everything you need to integrate with the Librarian API:

- **[OpenAPI Specification](../openapi.json)** - Complete API specification in OpenAPI 3.0 format
- **[Code Examples](examples.md)** - Examples in multiple programming languages

## 🚀 Quick Start

### 1. Base URL
```
http://localhost:8000/api/v1
```

### 2. Authentication Flow
```
Register → Login → Get Token → Use Token → Logout
```

### 3. Basic Example
```bash
# Login
curl -X POST http://localhost:8000/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Use returned token
curl -X GET http://localhost:8000/api/v1/books \
  -H "Authorization: Bearer {token}"
```

## 🔐 Authentication

The API uses **Bearer tokens** for authentication.

- **Token Type**: Bearer token in `Authorization` header
- **Login identifiers**: `email` or `username`
- **Storage**: MySQL `api_tokens` table (issued via the document store service)
- **Expiration**: No automatic expiration is currently enforced for `api_tokens` (tokens are revoked on logout)

### Authentication Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/register` | Create new account |
| POST | `/login` | Get access token |
| POST | `/forgot-password` | Request password reset email |
| POST | `/reset-password` | Reset password using token |
| POST | `/logout` | Invalidate token |
| GET | `/user` | Get current user |

### Required Headers
```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## 📖 API Endpoints

### Authentication
- `POST /api/v1/register` - Register new user
- `POST /api/v1/login` - Authenticate user
- `POST /api/v1/forgot-password` - Request password reset email
- `POST /api/v1/reset-password` - Reset password using token
- `POST /api/v1/logout` - Logout user
- `GET /api/v1/user` - Get current user object
- `GET /api/v1/me` - Get current user profile (name and email only)

### Books
- `GET /api/v1/books` - List books
- `GET /api/v1/books/{id}` - Get book details
- `GET /api/v1/books/search` - Search books
- `GET /api/v1/books/{id}/download` - Download book

Note: Public book listings automatically exclude titles flagged as `needs_review`. These records remain accessible to privileged workflows but are hidden from general API listings to ensure data quality.

### Authors & Series
- `GET /api/v1/authors/autocomplete` - Author suggestions
- `GET /api/v1/series/autocomplete` - Series suggestions
- `GET /api/v1/authors/{id}/books` - Books by author
- `GET /api/v1/series/{id}/books` - Books in series

### Badges
- `GET /api/v1/badges` - List all badges with user-earned metadata
- `GET /api/v1/badges/category/{category}` - List badges by category with user-earned metadata
- `GET /api/v1/user/badges` - List badges earned by the current user

Badge fields include:
- `icon` — single-character emoji for quick display
- `image_url` — URI to the exported SVG file for the badge (e.g., `/images/badges/{key}.svg`)
- `key`, `name`, `description`, `category`, `tier`, `points`
- User context fields where applicable: `earned`, `earned_at`, `times_earned`

*See [OpenAPI specification](../openapi.json) for complete endpoint documentation.*

## 🔧 User Roles

| Role | Description | Access Level |
|------|-------------|--------------|
| `superadmin` | Super Administrator | Full API access |
| `admin` | Administrator | Elevated API access |
| `standard` | Regular user | Standard API access |

## 📝 Request/Response Format

### Success Response Format
```json
{
  "data": { ... },
  "message": "Success",
  "status": 200
}
```

### Error Response Format
```json
{
  "error": "Error type",
  "message": "Detailed error message",
  "status": 400
}
```

### Validation Error Format
```json
{
  "field_name": [
    "Validation error message"
  ]
}
```

## 🚨 Error Codes

| Code | Meaning | Description |
|------|---------|-------------|
| 200 | OK | Request successful |
| 201 | Created | Resource created |
| 400 | Bad Request | Invalid request data |
| 401 | Unauthorized | Invalid or missing token |
| 403 | Forbidden | Insufficient permissions |
| 404 | Not Found | Resource not found |
| 422 | Unprocessable Entity | Validation failed |
| 500 | Internal Server Error | Server error |

## 🔒 Security

### Best Practices
- Always use HTTPS in production
- Store tokens securely (not in localStorage for web apps)
- Implement proper token refresh logic
- Handle authentication errors gracefully
- Never log or expose tokens

### Token Security
- Tokens are hashed in database
- 30-day automatic expiration
- Single-use logout invalidation
- Database-based validation

## 🛠️ Development Tools

### Testing with cURL
```bash
# Set base URL
BASE_URL="http://localhost:8000/api/v1"

# Login and save token
TOKEN=$(curl -s -X POST $BASE_URL/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' \
  | jq -r '.authToken')

# Use token for requests
curl -X GET $BASE_URL/books \
  -H "Authorization: Bearer $TOKEN"
```

### Postman
Import the [Postman collection](examples.md#postman-collection) for easy testing.

### OpenAPI Tools
Use the [OpenAPI specification](../openapi.json) with tools like:
- Swagger UI
- Postman
- Insomnia
- Code generators

## 📚 Language-Specific Examples

The documentation includes examples for:

- **JavaScript/Fetch** - Frontend and Node.js
- **Python/Requests** - Backend services
- **PHP/Guzzle** - Laravel/PHP applications
- **cURL** - Command line testing
- **React** - Frontend integration

See the [examples documentation](examples.md) for complete code samples.

## 🐛 Troubleshooting

### Common Issues

**"Unauthorized" Error**
- Check token format: `Bearer {token}`
- Verify the token is still valid (tokens are revoked on logout)
- Ensure user account is approved

**"Account pending admin approval"**
- New accounts need admin approval
- Contact administrator to approve account

**Token Not Working**
- Token may have been revoked
- Try logging in again
- Check for typos in Authorization header

**Validation Errors**
- Check required fields
- Verify data types and formats
- Review field length limits

### Debug Checklist
1. ✅ Correct base URL
2. ✅ Proper headers included
3. ✅ Valid JSON in request body
4. ✅ Token format correct
5. ✅ User account approved
6. ✅ Token not expired

## 📞 Support

For API support:
- Check this documentation first
- Review error messages carefully
- Test with simple cURL commands
- Verify account status with administrator

## 📋 Changelog

### Version 1.0.0
- ✅ Bearer token authentication
- ✅ Comprehensive API documentation
- ✅ OpenAPI specification
- ✅ Multi-language examples
- ✅ Implemented proper error handling

## 📄 License

This API documentation is part of the Librarian project. See the main project repository for license information.

---

**Need help?** Check the individual documentation files for detailed information:
- [Code Examples](examples.md) - Working examples in multiple languages
- [OpenAPI Spec](../openapi.json) - Machine-readable API specification
