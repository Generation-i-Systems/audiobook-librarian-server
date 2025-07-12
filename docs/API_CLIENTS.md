# API Client Generation

This document provides information on how to generate API clients for the Audiobook Librarian API using the OpenAPI specification.

## OpenAPI JSON URL

The OpenAPI JSON specification is publicly available at:

```
https://books.thelin.org/api-docs/openapi.json
```

This is the URL you should use with client generation tools.

## Client Generation Tools

You can generate API clients for various programming languages using tools that support the OpenAPI specification:

### OpenAPI Generator

[OpenAPI Generator](https://openapi-generator.tech/) is a powerful tool that can generate clients for over 40 programming languages.

Example command:

```bash
# Install OpenAPI Generator
npm install @openapitools/openapi-generator-cli -g

# Generate a JavaScript client
openapi-generator-cli generate -i https://books.thelin.org/api-docs/openapi.json -g javascript -o ./javascript-client

# Generate a TypeScript client
openapi-generator-cli generate -i https://books.thelin.org/api-docs/openapi.json -g typescript-axios -o ./typescript-client

# Generate a Python client
openapi-generator-cli generate -i https://books.thelin.org/api-docs/openapi.json -g python -o ./python-client
```

### Swagger Codegen

[Swagger Codegen](https://swagger.io/tools/swagger-codegen/) is another option for client generation.

```bash
# Install Swagger Codegen
npm install -g swagger-codegen-cli

# Generate a client
swagger-codegen-cli generate -i https://books.thelin.org/api-docs/openapi.json -l javascript -o ./javascript-client
```

### Language-Specific Tools

Many languages have their own tools for generating clients from OpenAPI specs:

- **JavaScript/TypeScript**: [openapi-typescript](https://github.com/drwpow/openapi-typescript)
- **Python**: [openapi-python-client](https://github.com/openapi-generators/openapi-python-client)
- **C#/.NET**: [NSwag](https://github.com/RicoSuter/NSwag)
- **Java**: [Feign](https://github.com/OpenFeign/feign) with [OpenFeign](https://github.com/OpenFeign/feign-openapi)
- **PHP**: [Jane](https://github.com/janephp/janephp)

## Integration Examples

### JavaScript/TypeScript Example

```javascript
// Using a generated client
import { ApiClient, BooksApi } from './javascript-client';

// Configure the client
const client = new ApiClient();
client.basePath = 'https://books.thelin.org/api/v1';
client.apiKey = 'YOUR_API_KEY';

// Use the client
const booksApi = new BooksApi(client);
booksApi.listBooks((error, data) => {
  if (error) {
    console.error(error);
  } else {
    console.log('Books:', data);
  }
});
```

### Python Example

```python
# Using a generated client
import openapi_client
from openapi_client.api import books_api
from openapi_client.configuration import Configuration

# Configure the client
configuration = Configuration()
configuration.api_key['ApiKey'] = 'YOUR_API_KEY'
configuration.host = 'https://books.thelin.org/api/v1'

# Use the client
api_instance = books_api.BooksApi(openapi_client.ApiClient(configuration))
try:
    api_response = api_instance.list_books()
    print("Books:", api_response)
except Exception as e:
    print("Exception when calling BooksApi->list_books:", e)
```

## Important Notes

### Series Field Name

When working with series data, remember that the series name is stored in the `seriesName` field, not in a `name` field. This is important when creating or updating series via the API.

Example series data:

```json
{
  "seriesName": "Super Powereds",
  "description": "College for Supers"
}
```

### Authentication

Most endpoints require authentication. Make sure your generated client is configured to send the appropriate authentication headers or tokens.

## Troubleshooting

If you encounter issues with the generated clients:

1. Verify the OpenAPI JSON URL is accessible
2. Check for any validation errors in the OpenAPI specification
3. Ensure you're using the correct generator for your target language
4. Look for language-specific issues in the generated code
