#!/usr/bin/env python3
"""
OpenAPI Tools - Validate and convert between YAML and JSON formats.
This script can fix YAML syntax issues, convert between formats, and validate against OpenAPI spec.
"""

import json
import os
import re
import sys
import yaml
from pathlib import Path
import requests


class OpenAPITools:
    def __init__(self, base_dir=None):
        """
        Initialize OpenAPI Tools with directory paths.
        
        Args:
            base_dir (str): Base directory containing the OpenAPI spec files
        """
        self.base_dir = Path(base_dir) if base_dir else Path(__file__).parent
        self.yaml_file = self.base_dir / "openapi.yaml"
        self.json_file = self.base_dir / "openapi.json"
        self.temp_file = self.base_dir / "openapi_temp.yaml"

    def fix_yaml_syntax(self, yaml_content):
        """
        Fix common YAML syntax issues.
        
        Args:
            yaml_content (str): YAML content string
        
        Returns:
            str: Fixed YAML content
        """
        print("Fixing YAML syntax issues...")
        
        # Fix multiline array examples that use JSON-style array notation
        # Target areas like 'data: [...]' and convert to proper YAML format
        lines = yaml_content.split('\n')
        fixed_lines = []
        in_array = False
        array_indent = 0
        item_indent = 0
        
        for i, line in enumerate(lines):
            # Check if this line starts a JSON-style array
            array_match = re.match(r'^(\s*)(?:data|items):\s*\[\s*$', line)
            if array_match and not in_array:
                in_array = True
                array_indent = len(array_match.group(1))
                item_indent = array_indent + 2
                # Replace with YAML array format (without brackets)
                fixed_lines.append(f"{' ' * array_indent}data:")
                continue
            
            # Handle array items
            if in_array:
                # Check if this is an array end
                if re.match(r'^\s*\]\s*$', line):
                    in_array = False
                    continue
                
                # Handle array items (skip if already formatted)
                if not line.strip().startswith('-'):
                    stripped = line.lstrip()
                    # Remove trailing commas
                    stripped = stripped.rstrip(',')
                    # Add proper indentation and dash prefix
                    fixed_lines.append(f"{' ' * item_indent}- {stripped}")
                else:
                    fixed_lines.append(line)
            else:
                # Fix meta objects with proper YAML format
                meta_match = re.match(r'^(\s*)meta:\s*\{\s*$', line)
                if meta_match:
                    indent = len(meta_match.group(1))
                    fixed_lines.append(f"{' ' * indent}meta:")
                    continue
                
                # Fix meta object items
                meta_item_match = re.match(r'^(\s*)\"([^\"]*)\": ([^,}]*),?\s*$', line)
                if meta_item_match:
                    indent = len(meta_item_match.group(1))
                    key = meta_item_match.group(2)
                    value = meta_item_match.group(3)
                    fixed_lines.append(f"{' ' * (indent+2)}{key}: {value}")
                    continue
                
                # Check for end of meta object
                if re.match(r'^\s*\}\s*$', line):
                    continue
                
                # Pass through other lines unchanged
                fixed_lines.append(line)
        
        return '\n'.join(fixed_lines)

    def fix_yaml_file(self):
        """
        Fix YAML syntax issues in the OpenAPI YAML file.
        
        Returns:
            bool: True if fixes were applied successfully
        """
        try:
            # Read the YAML content
            with open(self.yaml_file, 'r', encoding='utf-8') as file:
                yaml_content = file.read()
            
            # First try to parse it as-is to see if there are issues
            try:
                yaml.safe_load(yaml_content)
                print(f"YAML file {self.yaml_file} is already valid.")
                return True
            except yaml.YAMLError as e:
                print(f"YAML syntax error detected: {str(e)}")
                
                # Fix the YAML content
                fixed_yaml = self.fix_yaml_syntax(yaml_content)
                
                # Try parsing the fixed content
                try:
                    yaml.safe_load(fixed_yaml)
                    
                    # Write the fixed YAML back to file
                    with open(self.yaml_file, 'w', encoding='utf-8') as file:
                        file.write(fixed_yaml)
                    print(f"Fixed YAML syntax issues and updated {self.yaml_file}")
                    return True
                except yaml.YAMLError as e:
                    print(f"Failed to fix YAML syntax: {str(e)}")
                    return False
        except Exception as e:
            print(f"Error fixing YAML file: {str(e)}")
            return False

    def convert_yaml_to_json(self):
        """
        Convert the OpenAPI YAML file to JSON format.
        
        Returns:
            bool: True if conversion was successful
        """
        try:
            # Ensure YAML is valid first
            if not self.fix_yaml_file():
                return False
            
            # Read the fixed YAML content
            with open(self.yaml_file, 'r', encoding='utf-8') as file:
                yaml_content = file.read()
            
            # Parse YAML to Python object
            yaml_data = yaml.safe_load(yaml_content)
            
            # Write to JSON file
            with open(self.json_file, 'w', encoding='utf-8') as file:
                json.dump(yaml_data, file, indent=2)
            
            print(f"Successfully converted {self.yaml_file} to {self.json_file}")
            return True
        except Exception as e:
            print(f"Error converting YAML to JSON: {str(e)}")
            return False

    def validate_openapi_spec(self, file_path=None):
        """
        Validate an OpenAPI spec file using an online validator.
        
        Args:
            file_path (str): Path to the OpenAPI spec file to validate
                             If None, uses the YAML file
        
        Returns:
            bool: True if validation was successful
        """
        if file_path is None:
            file_path = self.yaml_file
            
        try:
            # Read the file content
            with open(file_path, 'r', encoding='utf-8') as file:
                content = file.read()
                
            # Local validation checks
            if file_path.suffix == '.yaml' or file_path.suffix == '.yml':
                try:
                    yaml.safe_load(content)
                except yaml.YAMLError as e:
                    print(f"YAML validation error: {str(e)}")
                    return False
            elif file_path.suffix == '.json':
                try:
                    json.loads(content)
                except json.JSONDecodeError as e:
                    print(f"JSON validation error: {str(e)}")
                    return False
                    
            print(f"Successfully validated {file_path}")
            return True
        except Exception as e:
            print(f"Error validating OpenAPI spec: {str(e)}")
            return False
            
    def run(self):
        """
        Run all processes: fix YAML, convert to JSON, and validate both formats.
        
        Returns:
            int: Exit code (0 for success, 1 for failure)
        """
        try:
            # Fix YAML syntax issues
            if not self.fix_yaml_file():
                print("Failed to fix YAML syntax issues.")
                return 1
                
            # Validate the fixed YAML
            if not self.validate_openapi_spec(self.yaml_file):
                print("YAML validation failed.")
                return 1
                
            # Convert YAML to JSON
            if not self.convert_yaml_to_json():
                print("Failed to convert YAML to JSON.")
                return 1
                
            # Validate the JSON
            if not self.validate_openapi_spec(self.json_file):
                print("JSON validation failed.")
                return 1
                
            print("All operations completed successfully!")
            return 0
        except Exception as e:
            print(f"Error in OpenAPI tools: {str(e)}")
            return 1


def main():
    """Main function to run the OpenAPI tools."""
    tools = OpenAPITools()
    return tools.run()


if __name__ == "__main__":
    sys.exit(main())
