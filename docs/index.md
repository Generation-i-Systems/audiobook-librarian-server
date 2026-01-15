# Audiobook Librarian Documentation

Welcome to the comprehensive documentation for the Audiobook Librarian API and system.

## 📚 API Documentation

### OpenAPI Specification

- **[OpenAPI JSON Specification](openapi.json)** - Machine-readable API specification (authoritative)

### Authentication Documentation

- **[Code Examples](api/examples.md)** - Working examples in multiple programming languages (JavaScript, Python, PHP, cURL, React)

### API Overview

- **[API README](api/README.md)** - Central hub with quick start guide and overview
- **[Recommendations & Tracking](api/recommendations-and-tracking.md)** - Documentation for book sharing and status features

## 🔗 Quick Links

### For Developers

- **Base URL**: `https://books.thelin.org/api/v1`
- **Local URL**: `http://localhost:8000/api/v1`
- **Authentication**: Bearer Token
- **Format**: JSON

### Key Endpoints

- `POST /api/v1/login` - Authenticate and get token
- `POST /api/v1/register` - Create new account
- `GET /api/v1/user` - Get current user info
- `GET /api/v1/books` - List audiobooks
- `POST /api/v1/logout` - Invalidate token

## 🚀 Getting Started

1. **Register** a new account or use existing credentials
2. **Login** to receive an authentication token
3. **Include the token** in Authorization header: `Bearer {token}`
4. **Make API requests** to access audiobook data

### Quick Example

```bash
# Login
curl -X POST https://books.thelin.org/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Use returned token
curl -X GET https://books.thelin.org/api/v1/books \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

## 📖 Documentation Categories

### API Reference

- [Code Examples](api/examples.md) - Copy-paste examples for integration

### Specifications

- [OpenAPI JSON](openapi.json) - Complete API specification with examples

## 🔧 Tools & Integration

### API Testing

- **Swagger UI**: Import the [OpenAPI specification](openapi.json)
- **Postman**: Use the [collection examples](api/examples.md#postman-collection)
- **cURL**: Copy examples from the [code examples](api/examples.md)

### Code Generation

The [OpenAPI specification](openapi.json) can be used with code generators like:

- OpenAPI Generator
- Swagger Codegen
- Postman Code Generation

## 🔐 Authentication System

**Type**: Bearer token authentication
**Token Format**: Opaque token string returned by `POST /api/v1/login` (use as `Authorization: Bearer {token}`)
**Expiration**: No automatic expiration is currently enforced (tokens are revoked on logout)
**Supported Logins**: Email or username + password

### User Roles

- **admin** - Full API access
- **user** - Standard access (approved account)
- **unverified** - No API access (pending approval)

## 📱 Response Format

### Success Response

```json
{
  "data": { ... },
  "meta": { ... }
}
```

### Error Response

```json
{
    "error": "Error type",
    "message": "Detailed error message"
}
```

## 🆘 Support & Troubleshooting

### Common Issues

- **401 Unauthorized**: Check token format and expiration
- **403 Forbidden**: Account may need admin approval
- **400 Bad Request**: Review request format and required fields

### Debug Steps

1. ✅ Verify correct base URL
2. ✅ Check Authorization header format
3. ✅ Confirm account is approved
4. ✅ Validate request JSON format

## 📝 Additional Resources

- [OpenAPI 3.0 Specification](https://swagger.io/specification/)
- [JSON API Standard](https://jsonapi.org/)

---

**Need Help?** Check the specific documentation sections above or review the [code examples](api/examples.md) for detailed implementation instructions.

**API Version**: 1.0.0
**Last Updated**: August 2025
**Base URL**: `https://books.thelin.org/api/v1`
