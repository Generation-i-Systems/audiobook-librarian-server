#!/usr/bin/env python3
"""
Fix YAML arrays in OpenAPI specification.
This script converts JSON-style array notation to YAML-compliant format.
"""

import os
import re
import sys
import yaml


def fix_yaml_arrays(file_path):
    """
    Fix JSON-style arrays in YAML files by converting them to proper YAML format.
    
    Args:
        file_path (str): Path to the YAML file
        
    Returns:
        bool: True if successful
    """
    print(f"Processing {file_path}...")
    
    try:
        # Read file content
        with open(file_path, 'r', encoding='utf-8') as file:
            content = file.read()
            
        # First handle arrays with object items
        # Convert array notation like:
        # data: [
        #   { ... },
        #   { ... }
        # ]
        # To proper YAML:
        # data:
        #   - ...
        #   - ...
        
        # Process the content
        new_content = content
        
        # Step 1: Fix arrays that start with data: [
        pattern = r'(\s*)(data|items):\s*\[\s*\n'
        new_content = re.sub(pattern, r'\1\2:\n', new_content)
        
        # Step 2: Convert array items to YAML format with dashes
        pattern = r'(\s*)(\{\s*.*?\s*\}),?\s*\n'
        new_content = re.sub(pattern, r'\1- \2\n', new_content)
        
        # Step 3: Remove array closing brackets
        pattern = r'(\s*)\]\s*,?\n'
        new_content = re.sub(pattern, r'\1\n', new_content)
        
        # Step 4: Fix meta objects using JSON format
        pattern = r'(\s*)meta:\s*\{\s*\n((?:\s*"[^"]*":\s*[^,\n]*,?\s*\n)+)(\s*)\}'
        new_content = re.sub(pattern, r'\1meta:\n\2', new_content)
        
        # Step 5: Remove quotes around keys and fix JSON-style meta properties
        pattern = r'(\s*)"([^"]*)"\s*:\s*([^,\n]*),?'
        new_content = re.sub(pattern, r'\1\2: \3', new_content)
        
        # Step 6: Fix simple arrays
        pattern = r'(\s*)(\w+):\s*\[\s*"([^"]*)"\s*(?:,\s*"([^"]*)"\s*)*\]'
        
        def replace_simple_array(match):
            indent = match.group(1)
            key = match.group(2)
            first_item = match.group(3)
            rest = match.string[match.end(3):match.end(0)]
            
            # Extract remaining items
            items = [first_item]
            for item in re.findall(r',\s*"([^"]*)"', rest):
                items.append(item)
                
            result = f"{indent}{key}:\n"
            for item in items:
                result += f"{indent}  - {item}\n"
            return result.rstrip('\n')
            
        new_content = re.sub(pattern, replace_simple_array, new_content)
        
        # Write back the fixed content if changed
        if new_content != content:
            with open(file_path, 'w', encoding='utf-8') as file:
                file.write(new_content)
            print(f"Fixed YAML array syntax in {file_path}")
            return True
        else:
            print(f"No changes needed for {file_path}")
            return True
    
    except Exception as e:
        print(f"Error fixing YAML arrays: {str(e)}")
        return False


def convert_to_json(yaml_file, json_file):
    """
    Convert YAML to JSON after fixing arrays.
    
    Args:
        yaml_file (str): Path to the YAML file
        json_file (str): Path to output the JSON file
        
    Returns:
        bool: True if successful
    """
    try:
        # First fix the YAML
        if not fix_yaml_arrays(yaml_file):
            return False
            
        # Then parse and convert to JSON
        with open(yaml_file, 'r', encoding='utf-8') as file:
            yaml_content = file.read()
            
        # Parse YAML
        yaml_data = yaml.safe_load(yaml_content)
        
        # Write to JSON
        import json
        with open(json_file, 'w', encoding='utf-8') as file:
            json.dump(yaml_data, file, indent=2)
            
        print(f"Successfully converted {yaml_file} to {json_file}")
        return True
        
    except Exception as e:
        print(f"Error converting to JSON: {str(e)}")
        return False


def main():
    """Main function."""
    # Define paths
    yaml_file = os.path.join(os.path.dirname(__file__), "openapi.yaml")
    json_file = os.path.join(os.path.dirname(__file__), "openapi.json")
    
    # Fix YAML arrays and convert to JSON
    if convert_to_json(yaml_file, json_file):
        return 0
    else:
        return 1


if __name__ == "__main__":
    sys.exit(main())
