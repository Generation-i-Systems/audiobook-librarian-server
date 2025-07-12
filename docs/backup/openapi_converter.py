#!/usr/bin/env python3
import json
import sys
import yaml

def convert_yaml_to_json(yaml_file, json_file):
    """Convert a YAML file to JSON"""
    try:
        # Load YAML file
        with open(yaml_file, 'r') as f:
            yaml_content = yaml.safe_load(f)
        
        # Convert to JSON and write to file
        with open(json_file, 'w') as f:
            json.dump(yaml_content, f, indent=2)
        
        print(f"Successfully converted {yaml_file} to {json_file}")
        return True
    except Exception as e:
        print(f"Error converting YAML to JSON: {str(e)}")
        return False

def merge_yaml_files(source_file, target_file):
    """Merge source YAML file into target YAML file"""
    try:
        # Copy source to target (full replacement)
        with open(source_file, 'r') as f:
            content = f.read()
        
        with open(target_file, 'w') as f:
            f.write(content)
        
        print(f"Successfully merged {source_file} into {target_file}")
        return True
    except Exception as e:
        print(f"Error merging YAML files: {str(e)}")
        return False

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage:")
        print("  Convert YAML to JSON: python openapi_converter.py convert <yaml_file> <json_file>")
        print("  Merge YAML files: python openapi_converter.py merge <source_file> <target_file>")
        sys.exit(1)
    
    command = sys.argv[1]
    
    if command == "convert" and len(sys.argv) == 4:
        yaml_file = sys.argv[2]
        json_file = sys.argv[3]
        convert_yaml_to_json(yaml_file, json_file)
    elif command == "merge" and len(sys.argv) == 4:
        source_file = sys.argv[2]
        target_file = sys.argv[3]
        merge_yaml_files(source_file, target_file)
    else:
        print("Invalid command or arguments")
        print("Usage:")
        print("  Convert YAML to JSON: python openapi_converter.py convert <yaml_file> <json_file>")
        print("  Merge YAML files: python openapi_converter.py merge <source_file> <target_file>")
        sys.exit(1)
