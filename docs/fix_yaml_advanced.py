#!/usr/bin/env python3
"""
Advanced YAML fixer for OpenAPI specifications.
Handles complex syntax issues including nested arrays and flow sequences.
"""

import os
import re
import sys
import yaml
import json
from pathlib import Path


def fix_flow_sequences(content):
    """
    Fix flow sequences in YAML that are causing parsing issues.
    
    Args:
        content (str): YAML content
        
    Returns:
        str: Fixed YAML content
    """
    # Fix arrays with string values like: email: ["The email has already been taken."]
    pattern = r'(\s*)(\w+):\s*\[\s*"([^"]*)"(?:\s*,\s*"[^"]*")*\s*\]'
    
    def replace_string_array(match):
        indent = match.group(1)
        key = match.group(2)
        first_value = match.group(3)
        
        # Extract all values using a separate regex
        values = re.findall(r'"([^"]*)"', match.group(0))
        
        result = f"{indent}{key}:\n"
        for value in values:
            result += f"{indent}  - \"{value}\"\n"
        return result
    
    content = re.sub(pattern, replace_string_array, content)
    
    # Fix multi-line string arrays with complex formatting
    lines = content.split('\n')
    fixed_lines = []
    in_array = False
    array_key = ""
    array_indent = 0
    array_items = []
    
    i = 0
    while i < len(lines):
        line = lines[i]
        
        # Check for start of problematic array
        array_start = re.match(r'^(\s*)(\w+):\s*\[\s*"(.*)"\s*$', line)
        if array_start and not in_array:
            in_array = True
            array_indent = len(array_start.group(1))
            array_key = array_start.group(2)
            array_items = [array_start.group(3)]
            i += 1
            continue
            
        # If we're in an array, collect items until we find the closing bracket
        if in_array:
            # Check if this line contains the end bracket
            if ']' in line:
                # Extract any text before the closing bracket
                match = re.search(r'^(\s*)"([^"]*)".*\]', line)
                if match:
                    array_items.append(match.group(2))
                
                # Output the array in YAML format
                fixed_lines.append(f"{' ' * array_indent}{array_key}:")
                for item in array_items:
                    fixed_lines.append(f"{' ' * (array_indent+2)}- \"{item}\"")
                
                in_array = False
                array_items = []
                i += 1
                continue
            else:
                # Extract item from this line
                match = re.search(r'^(\s*)"([^"]*)"(?:,|$)', line)
                if match:
                    array_items.append(match.group(2))
                i += 1
                continue
        
        # Pass through other lines
        fixed_lines.append(line)
        i += 1
    
    return '\n'.join(fixed_lines)


def fix_yaml_content(yaml_content):
    """
    Apply all fixes to YAML content.
    
    Args:
        yaml_content (str): Raw YAML content
        
    Returns:
        str: Fixed YAML content
    """
    content = yaml_content
    
    # Fix 1: Convert JSON-style arrays to YAML format
    # data: [ {...}, {...} ] -> data: \n  - {...}\n  - {...}
    pattern = r'(\s*)(data|items):\s*\[\s*\n'
    content = re.sub(pattern, r'\1\2:\n', content)
    
    # Fix 2: Add dash prefix to array items
    pattern = r'(\s*)(\{[^}]*\}),?\s*\n'
    content = re.sub(pattern, r'\1- \2\n', content)
    
    # Fix 3: Remove closing brackets for arrays
    pattern = r'(\s*)\]\s*,?\n'
    content = re.sub(pattern, r'\1\n', content)
    
    # Fix 4: Fix JSON-style meta objects
    pattern = r'(\s*)meta:\s*\{\s*\n((?:\s*"[^"]*":\s*[^,\n]*,?\s*\n)+)(\s*)\}'
    content = re.sub(pattern, r'\1meta:\n\2', content)
    
    # Fix 5: Remove quotes from keys in meta objects
    pattern = r'(\s*)"([^"]*)"\s*:\s*([^,\n]*),?\s*\n'
    content = re.sub(pattern, r'\1\2: \3\n', content)
    
    # Fix 6: Convert simple inline arrays to YAML format
    def replace_inline_array(match):
        indent = match.group(1)
        key = match.group(2)
        items_str = match.group(3)
        items = [i.strip(' "\'') for i in items_str.split(',')]
        
        result = f"{indent}{key}:\n"
        for item in items:
            result += f"{indent}  - {item}\n"
        return result
        
    pattern = r'(\s*)(\w+):\s*\[(.*?)\]'
    content = re.sub(pattern, replace_inline_array, content)
    
    # Fix 7: Fix flow sequences (arrays with string values)
    content = fix_flow_sequences(content)
    
    return content


def fix_yaml_file(yaml_path):
    """
    Fix YAML syntax issues in a file.
    
    Args:
        yaml_path (str or Path): Path to the YAML file
        
    Returns:
        bool: True if successful
    """
    yaml_path = Path(yaml_path)
    print(f"Processing {yaml_path}...")
    
    try:
        # Read the YAML content
        with open(yaml_path, 'r', encoding='utf-8') as f:
            content = f.read()
            
        # First check if it's already valid
        try:
            yaml.safe_load(content)
            print(f"YAML is already valid. No changes needed.")
            return True
        except yaml.YAMLError as e:
            print(f"YAML has syntax issues: {e}")
            
            # Apply fixes
            fixed_content = fix_yaml_content(content)
            
            # Try parsing the fixed content
            try:
                yaml.safe_load(fixed_content)
                # If successful, write back the fixed content
                with open(yaml_path, 'w', encoding='utf-8') as f:
                    f.write(fixed_content)
                print(f"Successfully fixed YAML syntax issues.")
                return True
            except yaml.YAMLError as e:
                print(f"Failed to fully fix YAML: {e}")
                return False
    except Exception as e:
        print(f"Error: {e}")
        return False


def yaml_to_json(yaml_path, json_path):
    """
    Convert YAML to JSON.
    
    Args:
        yaml_path (str or Path): Path to the YAML file
        json_path (str or Path): Path to output JSON file
        
    Returns:
        bool: True if successful
    """
    yaml_path = Path(yaml_path)
    json_path = Path(json_path)
    
    try:
        # First fix the YAML file
        if not fix_yaml_file(yaml_path):
            print("Cannot convert to JSON: YAML file has syntax errors.")
            return False
            
        # Read the fixed YAML
        with open(yaml_path, 'r', encoding='utf-8') as f:
            yaml_content = f.read()
            
        # Parse YAML to Python object
        data = yaml.safe_load(yaml_content)
        
        # Write to JSON
        with open(json_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=2)
            
        print(f"Successfully converted {yaml_path} to {json_path}")
        return True
    except Exception as e:
        print(f"Error converting YAML to JSON: {e}")
        return False


def main():
    """Main function."""
    base_dir = Path(__file__).parent
    yaml_path = base_dir / "openapi.yaml"
    json_path = base_dir / "openapi.json"
    
    if yaml_to_json(yaml_path, json_path):
        return 0
    else:
        return 1


if __name__ == "__main__":
    sys.exit(main())
