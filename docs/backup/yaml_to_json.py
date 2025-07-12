#!/usr/bin/env python3
import json
import sys
import yaml
import re

def preprocess_yaml(yaml_content):
    """
    Preprocess YAML content to fix common syntax issues
    """
    # Fix JSON-style arrays with objects
    yaml_content = re.sub(r'(\s+)(\w+): \[\s*\n\s*{', r'\1\2:\n\1  - {', yaml_content)
    
    # Fix JSON-style objects
    yaml_content = re.sub(r'(\s+)(\w+): \{', r'\1\2:\n\1  {', yaml_content)
    
    # Fix commas in arrays
    yaml_content = re.sub(r'},\s*\n\s*{', r'}\n      - {', yaml_content)
    
    return yaml_content

def convert_yaml_to_json(input_yaml, output_json):
    """
    Convert YAML to JSON with preprocessing to fix syntax issues
    """
    try:
        # Read the YAML file
        with open(input_yaml, 'r') as f:
            yaml_content = f.read()
        
        # Preprocess the YAML content
        processed_yaml = preprocess_yaml(yaml_content)
        
        # Write the preprocessed YAML to a temporary file
        temp_yaml = input_yaml + '.temp'
        with open(temp_yaml, 'w') as f:
            f.write(processed_yaml)
        
        print(f"Preprocessed YAML saved to {temp_yaml}")
        
        # Parse the YAML
        try:
            with open(temp_yaml, 'r') as f:
                data = yaml.safe_load(f)
            
            # Convert to JSON
            with open(output_json, 'w') as f:
                json.dump(data, f, indent=2)
            
            print(f"Successfully converted YAML to JSON: {output_json}")
            return True
        except Exception as e:
            print(f"Error parsing YAML: {str(e)}")
            
            # If parsing fails, create a minimal JSON structure
            print("Creating a minimal JSON structure...")
            
            # Extract basic info from the YAML file
            info = {}
            servers = []
            
            # Try to extract info section
            info_match = re.search(r'info:\s*\n(\s+.*\n)+', yaml_content)
            if info_match:
                info_text = info_match.group(0)
                title_match = re.search(r'title:\s*(.*)', info_text)
                if title_match:
                    info["title"] = title_match.group(1).strip()
                
                version_match = re.search(r'version:\s*(.*)', info_text)
                if version_match:
                    info["version"] = version_match.group(1).strip()
            
            # Create minimal structure
            openapi_json = {
                "openapi": "3.0.3",
                "info": info if info else {
                    "title": "Audiobook Librarian API",
                    "version": "1.0.0"
                },
                "servers": servers if servers else [
                    {
                        "url": "/api/v1",
                        "description": "API v1"
                    }
                ],
                "paths": {},
                "components": {
                    "schemas": {},
                    "securitySchemes": {}
                }
            }
            
            # Write the JSON to file
            with open(output_json, 'w') as f:
                json.dump(openapi_json, f, indent=2)
            
            print(f"Minimal JSON structure saved to {output_json}")
            return False
    except Exception as e:
        print(f"Error: {str(e)}")
        return False

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python yaml_to_json.py <input_yaml> <output_json>")
        sys.exit(1)
    
    input_yaml = sys.argv[1]
    output_json = sys.argv[2]
    
    convert_yaml_to_json(input_yaml, output_json)
