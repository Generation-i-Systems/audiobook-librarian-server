# OpenAPI Documentation

This directory contains the OpenAPI specification for the Audiobook Librarian API.

## Files

- `openapi.yaml`: The complete OpenAPI specification in YAML format
- `openapi-full.yaml`: The full API specification (merged into openapi.yaml)
- `json/openapi.json`: The OpenAPI specification in JSON format

## Scripts

### generate_openapi_json.py

This script generates a JSON version of the OpenAPI specification from the YAML file.

```bash
python3 generate_openapi_json.py <input_yaml_file> <output_json_file>
```

Example:
```bash
python3 generate_openapi_json.py openapi.yaml json/openapi.json
```

### fix_yaml_meta.py

This script fixes common YAML syntax issues related to JSON-style object notation in YAML files.

```bash
python3 fix_yaml_meta.py <input_yaml_file> <output_yaml_file>
```

### fix_and_convert.py

This script attempts to fix YAML syntax issues and convert YAML to JSON.

```bash
python3 fix_and_convert.py <input_yaml_file> <output_json_file>
```

## Usage

To update the JSON version of the OpenAPI specification after making changes to the YAML file:

```bash
python3 generate_openapi_json.py openapi.yaml json/openapi.json
```

This will extract all components from the YAML file, including:
- Basic OpenAPI info (version, title, description)
- Servers section
- Paths with HTTP methods, tags, summaries, descriptions, and operationIds
- Components section including schemas and security schemes
