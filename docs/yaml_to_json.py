#!/usr/bin/env python3
"""
Convert OpenAPI YAML to JSON format.
This script reads the openapi.yaml file and converts it to openapi.json.
"""

import json
import sys
import yaml
from pathlib import Path


def convert_yaml_to_json(yaml_file, json_file):
    """
    Convert a YAML file to JSON format.
    
    Args:
        yaml_file (str): Path to the YAML file
        json_file (str): Path to output the JSON file
    
    Returns:
        bool: True if conversion was successful, False otherwise
    """
    try:
        # Read YAML content
        with open(yaml_file, 'r', encoding='utf-8') as file:
            yaml_content = file.read()
        
        # Parse YAML to Python object
        yaml_data = yaml.safe_load(yaml_content)
        
        # Write to JSON file
        with open(json_file, 'w', encoding='utf-8') as file:
            json.dump(yaml_data, file, indent=2)
        
        print(f"Successfully converted {yaml_file} to {json_file}")
        return True
    
    except Exception as e:
        print(f"Error converting YAML to JSON: {str(e)}")
        return False


def main():
    """Main function to run the conversion."""
    # Define file paths
    base_dir = Path(__file__).parent
    yaml_file = base_dir / "openapi.yaml"
    json_file = base_dir / "openapi.json"
    
    # Convert YAML to JSON
    success = convert_yaml_to_json(yaml_file, json_file)
    
    # Return appropriate exit code
    return 0 if success else 1


if __name__ == "__main__":
    sys.exit(main())
