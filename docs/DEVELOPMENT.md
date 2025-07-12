# Development Guidelines

This document contains guidelines and information for developers working on the Audiobook Librarian API documentation.

## OpenAPI Development

### Structure

The OpenAPI specification is organized as follows:

- **Info**: Basic information about the API
- **Servers**: Server configurations for different environments
- **Paths**: API endpoints organized by resource and HTTP method
- **Components**: Reusable schemas, parameters, responses, and security schemes

### Modifying the OpenAPI Specification

1. Make changes to the `openapi.yaml` file directly
2. After updating the YAML, generate the JSON version (see below)
3. Ensure the JSON file is also copied to the public directory for external access

### Generating JSON from YAML

To maintain synchronization between the YAML and JSON formats, follow these steps after updating the YAML specification:

1. First, make your changes to `openapi.yaml`
2. Then, copy the updated JSON to the public directory:

```bash
cp /home/eric-22/PhpstormProjects/ab5/docs/openapi.json /home/eric-22/PhpstormProjects/ab5/public/api-docs/
```

### Best Practices

- Use consistent naming conventions for paths, parameters, and schemas
- Include clear descriptions for all components
- Provide examples where possible
- Follow OpenAPI 3.0.3 specification guidelines
- Keep descriptions concise but informative
- Use schema references (`$ref`) for reusable components
- Validate your OpenAPI specification with a validator tool before committing

## Testing

To ensure the API documentation remains accurate and functional:

1. Validate the OpenAPI specification after changes using tools like:
   - [Swagger Editor](https://editor.swagger.io/)
   - [OpenAPI Validator](https://github.com/openapi-contrib/openapi-validator)
   - [Spectral](https://github.com/stoplightio/spectral)

2. Test the API endpoints against the specification to ensure they conform to the documented behavior

## Tooling

Useful tools for working with OpenAPI specifications:

- **[Swagger UI](https://swagger.io/tools/swagger-ui/)**: Interactive API documentation
- **[Redoc](https://github.com/Redocly/redoc)**: Documentation generation
- **[Postman](https://www.postman.com/)**: API testing and collection creation
- **[OpenAPI Generator](https://openapi-generator.tech/)**: Generate clients, server stubs, and documentation

## Future Improvements

- Consider implementing a YAML linter in the CI/CD pipeline
- Automate the generation of OpenAPI JSON from YAML in the build process
- Add integration tests that validate API responses against the OpenAPI schema definitions
