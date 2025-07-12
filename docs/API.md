# API Documentation

This document provides information about the Audiobook Librarian API and its OpenAPI specification.

## OpenAPI Specification

The Audiobook Librarian API is documented using the [OpenAPI Specification](https://swagger.io/specification/) (formerly known as Swagger).

### Specification Files

- `openapi.yaml`: The complete OpenAPI specification in YAML format
- `openapi.json`: The OpenAPI specification in JSON format (same content as YAML, different format)

### Public API Documentation URL

The OpenAPI JSON specification is publicly available at:

```
https://books.thelin.org/api-docs/openapi.json
```

This URL can be used with OpenAPI client generators and documentation tools like Swagger UI, Redoc, Postman, etc.

## API Structure

The API includes the following main resource categories:

- **Series** - Endpoints related to book series management
- **Books** - Endpoints for book information and management
- **Authors** - Endpoints for author information
- **Users** - Endpoints for user management
- **Auth** - Authentication and authorization endpoints

### Data Models

#### Series

Important note: Series documents use `seriesName` (not `name`) for the series title field. All code, queries, and data operations must use `seriesName` when referring to the series title.

Example series document:
```json
{
  "_id": "507f1f77bcf86cd799439011",
  "seriesName": "Super Powereds",
  "description": "College for Supers",
  "books": [...]
}
```

## Using the API

### Authentication

Most API endpoints require authentication. The API supports the following authentication methods:

- API Key authentication
- OAuth2 with JWT tokens

For details on authentication, please refer to the OpenAPI specification security schemes section.

### Common Parameters

- Pagination: `page`, `per_page`
- Filtering: Endpoint-specific query parameters
- Sorting: `sort_by`, `order`

### Response Format

API responses follow a consistent format:

```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 15,
    "total": 150
  }
}
```

## Generating API Clients

You can generate API clients for various programming languages using the OpenAPI JSON URL and tools like:

- [OpenAPI Generator](https://openapi-generator.tech/)
- [Swagger Codegen](https://swagger.io/tools/swagger-codegen/)
- [NSwag](https://github.com/RicoSuter/NSwag)

Example using OpenAPI Generator:

```bash
openapi-generator generate -i https://books.thelin.org/api-docs/openapi.json -g javascript -o ./client
```
