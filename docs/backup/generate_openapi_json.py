#!/usr/bin/env python3
import json
import re
import sys
import os

def extract_openapi_info(yaml_file):
    """Extract basic OpenAPI info from YAML file"""
    info = {}
    with open(yaml_file, 'r') as f:
        content = f.read()
    
    # Extract OpenAPI version
    version_match = re.search(r'^openapi:\s*([\d\.]+)', content)
    if version_match:
        info['openapi'] = version_match.group(1)
    
    # Extract info section
    info_section = {}
    info_match = re.search(r'info:\s*\n((?:\s+.*\n)+?)(?=\w+:|$)', content)
    if info_match:
        info_text = info_match.group(1)
        
        # Extract title
        title_match = re.search(r'\s+title:\s*(.*)', info_text)
        if title_match:
            info_section['title'] = title_match.group(1).strip()
        
        # Extract version
        version_match = re.search(r'\s+version:\s*(.*)', info_text)
        if version_match:
            info_section['version'] = version_match.group(1).strip()
        
        # Extract description
        desc_match = re.search(r'\s+description:\s*\|\s*\n((?:\s+.*\n)+?)(?=\s+\w+:|$)', info_text)
        if desc_match:
            desc_lines = desc_match.group(1).strip().split('\n')
            desc = '\n'.join([line.strip() for line in desc_lines])
            info_section['description'] = desc
    
    info['info'] = info_section
    
    # Extract servers
    servers = []
    servers_match = re.search(r'servers:\s*\n((?:\s+.*\n)+?)(?=\w+:|$)', content)
    if servers_match:
        servers_text = servers_match.group(1)
        server_entries = re.findall(r'\s+-\s+url:\s*(.*)\s*\n\s+description:\s*(.*)', servers_text)
        
        for url, desc in server_entries:
            servers.append({
                'url': url.strip(),
                'description': desc.strip()
            })
    
    info['servers'] = servers
    
    return info

def extract_paths(yaml_file):
    """Extract paths from YAML file"""
    paths = {}
    with open(yaml_file, 'r') as f:
        content = f.read()
    
    # Extract paths section
    paths_match = re.search(r'paths:\s*\n((?:\s+.*\n)+?)(?=components:|$)', content)
    if paths_match:
        paths_text = paths_match.group(1)
        
        # Extract individual paths
        path_entries = re.findall(r'\s+(\/[^:\n]+):\s*\n((?:\s+.*\n)+?)(?=\s+\/|$)', paths_text)
        
        for path, path_content in path_entries:
            path_obj = {}
            
            # Extract HTTP methods
            method_entries = re.findall(r'\s+(get|post|put|delete|patch):\s*\n((?:\s+.*\n)+?)(?=\s+(?:get|post|put|delete|patch):|$)', path_content)
            
            for method, method_content in method_entries:
                method_obj = {}
                
                # Extract tags
                tags_match = re.search(r'\s+tags:\s*\n((?:\s+-\s+.*\n)+)', method_content)
                if tags_match:
                    tags_text = tags_match.group(1)
                    tags = re.findall(r'\s+-\s+(.*)', tags_text)
                    method_obj['tags'] = [tag.strip() for tag in tags]
                
                # Extract summary
                summary_match = re.search(r'\s+summary:\s*(.*)', method_content)
                if summary_match:
                    method_obj['summary'] = summary_match.group(1).strip()
                
                # Extract description
                desc_match = re.search(r'\s+description:\s*(.*)', method_content)
                if desc_match:
                    method_obj['description'] = desc_match.group(1).strip()
                
                # Extract operationId
                op_id_match = re.search(r'\s+operationId:\s*(.*)', method_content)
                if op_id_match:
                    method_obj['operationId'] = op_id_match.group(1).strip()
                
                # Add method to path
                path_obj[method] = method_obj
            
            # Add path to paths
            paths[path] = path_obj
    
    return paths

def extract_components(yaml_file):
    """Extract components from YAML file"""
    components = {
        'schemas': {},
        'securitySchemes': {}
    }
    
    with open(yaml_file, 'r') as f:
        content = f.read()
    
    # Extract components section directly
    components_section = re.search(r'components:\s*\n((?:\s+.*\n)+)', content)
    if not components_section:
        print("No components section found in YAML")
        return components
        
    components_text = components_section.group(0)
    
    # Extract security schemes
    security_schemes = {}
    security_match = re.search(r'\s+securitySchemes:\s*\n((?:\s+.*\n)+?)(?=\s+\w+:|$)', components_text)
    if security_match:
        security_text = security_match.group(1)
        
        # Extract individual security schemes
        scheme_entries = re.findall(r'\s+(\w+):\s*\n((?:\s+.*\n)+?)(?=\s+\w+:|$)', security_text)
        
        for scheme_name, scheme_content in scheme_entries:
            scheme_obj = {}
            
            # Extract type
            type_match = re.search(r'\s+type:\s*(.*)', scheme_content)
            if type_match:
                scheme_obj['type'] = type_match.group(1).strip()
            
            # Extract scheme
            scheme_match = re.search(r'\s+scheme:\s*(.*)', scheme_content)
            if scheme_match:
                scheme_obj['scheme'] = scheme_match.group(1).strip()
            
            # Extract bearerFormat
            bearer_match = re.search(r'\s+bearerFormat:\s*(.*)', scheme_content)
            if bearer_match:
                scheme_obj['bearerFormat'] = bearer_match.group(1).strip()
            
            # Extract description
            desc_match = re.search(r'\s+description:\s*(.*)', scheme_content)
            if desc_match:
                scheme_obj['description'] = desc_match.group(1).strip()
            
            # Add scheme to securitySchemes
            security_schemes[scheme_name] = scheme_obj
    
    components['securitySchemes'] = security_schemes
    
    # Define a function to extract schemas with proper indentation handling
    def extract_schemas_from_yaml(yaml_content):
        schemas = {}
        
        # Find the schemas section
        schemas_match = re.search(r'components:\s*\n(?:\s+.*\n)*?\s+schemas:\s*\n((?:\s+.*\n)+?)(?=\s+\w+:|$)', yaml_content)
        if not schemas_match:
            print("No schemas section found")
            return schemas
        
        schemas_text = schemas_match.group(1)
        
        # Find all top-level schema definitions
        schema_pattern = r'(\s+)(\w+):\s*\n((?:\1\s+.*\n)+)'
        schema_matches = re.finditer(schema_pattern, schemas_text)
        
        for match in schema_matches:
            indent = match.group(1)  # Capture the indentation level
            schema_name = match.group(2)
            schema_content = match.group(3)
            
            # Skip if this isn't actually a schema (could be a property of another schema)
            if len(indent) != 4:  # Assuming 2-space indentation in YAML
                continue
                
            # Create schema object
            schema = {}
            
            # Extract type
            type_match = re.search(r'\s+type:\s*(.*)', schema_content)
            if type_match:
                schema['type'] = type_match.group(1).strip()
            
            # Extract description
            desc_match = re.search(r'\s+description:\s*(.*)', schema_content)
            if desc_match:
                schema['description'] = desc_match.group(1).strip()
            
            # Extract properties
            props_match = re.search(r'\s+properties:\s*\n((?:\s+.*\n)+?)(?=\s+\w+:|$)', schema_content)
            if props_match:
                props_text = props_match.group(1)
                properties = {}
                
                # Find all property definitions with proper indentation handling
                prop_pattern = r'(\s+)(\w+):\s*\n((?:\1\s+.*\n)+)'
                prop_matches = re.finditer(prop_pattern, props_text)
                
                for prop_match in prop_matches:
                    prop_indent = prop_match.group(1)
                    prop_name = prop_match.group(2)
                    prop_content = prop_match.group(3)
                    
                    # Skip if this isn't actually a property
                    if len(prop_indent) != 8:  # Assuming 2-space indentation in YAML
                        continue
                    
                    prop = {}
                    
                    # Extract property type
                    prop_type_match = re.search(r'\s+type:\s*(.*)', prop_content)
                    if prop_type_match:
                        prop['type'] = prop_type_match.group(1).strip()
                    
                    # Extract property format
                    prop_format_match = re.search(r'\s+format:\s*(.*)', prop_content)
                    if prop_format_match:
                        prop['format'] = prop_format_match.group(1).strip()
                    
                    # Extract property description
                    prop_desc_match = re.search(r'\s+description:\s*(.*)', prop_content)
                    if prop_desc_match:
                        prop['description'] = prop_desc_match.group(1).strip()
                    
                    # Extract property example
                    prop_example_match = re.search(r'\s+example:\s*(.*)', prop_content)
                    if prop_example_match:
                        example_value = prop_example_match.group(1).strip()
                        # Remove quotes if present
                        if example_value.startswith('"') and example_value.endswith('"'):
                            example_value = example_value[1:-1]
                        prop['example'] = example_value
                    
                    # Add property to properties
                    properties[prop_name] = prop
                
                if properties:
                    schema['properties'] = properties
            
            # Extract required fields
            required_match = re.search(r'\s+required:\s*\n((?:\s+-\s+.*\n)+)', schema_content)
            if required_match:
                required_text = required_match.group(1)
                required_fields = re.findall(r'\s+-\s+(.*)', required_text)
                schema['required'] = [field.strip() for field in required_fields]
            
            # Extract example
            example_match = re.search(r'\s+example:\s*\n((?:\s+.*\n)+?)(?=\s+\w+:|$)', schema_content)
            if example_match:
                example_text = example_match.group(1)
                example_obj = {}
                example_lines = example_text.strip().split('\n')
                for line in example_lines:
                    if ':' in line:
                        key, value = line.strip().split(':', 1)
                        example_obj[key.strip()] = value.strip()
                schema['example'] = example_obj
            else:
                # Check for inline example
                inline_example = re.search(r'\s+example:\s*(.*)', schema_content)
                if inline_example:
                    schema['example'] = inline_example.group(1).strip()
            
            # Handle $ref references
            ref_match = re.search(r'\s+\$ref:\s*(.*)', schema_content)
            if ref_match:
                schema = {'$ref': ref_match.group(1).strip()}
            
            # Add schema to schemas
            schemas[schema_name] = schema
        
        return schemas
    
    # Extract schemas using the function
    components['schemas'] = extract_schemas_from_yaml(content)
    
    # Add common security schemes if they're not already defined
    if 'bearerAuth' not in components['securitySchemes']:
        components['securitySchemes']['bearerAuth'] = {
            'type': 'http',
            'scheme': 'bearer',
            'bearerFormat': 'JWT',
            'description': 'JWT token authentication.'
        }
    
    if 'adminAuth' not in components['securitySchemes']:
        components['securitySchemes']['adminAuth'] = {
            'type': 'http',
            'scheme': 'bearer',
            'bearerFormat': 'JWT',
            'description': 'Admin authentication required.'
        }
    
    # Add some common schemas if they're not already defined
    if not components['schemas']:
        # Add basic error schema
        components['schemas']['Error'] = {
            'type': 'object',
            'properties': {
                'message': {
                    'type': 'string',
                    'description': 'Error message'
                },
                'errors': {
                    'type': 'object',
                    'description': 'Validation errors'
                }
            }
        }
        
        # Add pagination meta schema
        components['schemas']['PaginationMeta'] = {
            'type': 'object',
            'properties': {
                'current_page': {
                    'type': 'integer',
                    'description': 'Current page number'
                },
                'last_page': {
                    'type': 'integer',
                    'description': 'Last page number'
                },
                'per_page': {
                    'type': 'integer',
                    'description': 'Items per page'
                },
                'total': {
                    'type': 'integer',
                    'description': 'Total number of items'
                }
            }
        }
    
    return components

def generate_openapi_json(yaml_file, json_file):
    """Generate OpenAPI JSON from YAML file"""
    try:
        # Extract basic info
        openapi_json = extract_openapi_info(yaml_file)
        
        # Extract paths
        paths = extract_paths(yaml_file)
        openapi_json['paths'] = paths
        
        # Extract components
        components = extract_components(yaml_file)
        openapi_json['components'] = components
        
        # Write JSON to file
        with open(json_file, 'w') as f:
            json.dump(openapi_json, f, indent=2)
        
        print(f"Generated OpenAPI JSON saved to {json_file}")
        
        # Return success
        return True
    except Exception as e:
        print(f"Error generating OpenAPI JSON: {str(e)}")
        return False

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python generate_openapi_json.py <yaml_file> <json_file>")
        sys.exit(1)
    
    yaml_file = sys.argv[1]
    json_file = sys.argv[2]
    
    if not os.path.exists(yaml_file):
        print(f"Error: YAML file {yaml_file} does not exist")
        sys.exit(1)
    
    generate_openapi_json(yaml_file, json_file)
