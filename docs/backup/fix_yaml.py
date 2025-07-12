#!/usr/bin/env python3
import re
import sys

def fix_yaml_syntax(input_file, output_file):
    with open(input_file, 'r') as f:
        content = f.read()
    
    # Fix JSON-style object notation in YAML
    # Replace "key: {" with "key:\n  {"
    fixed_content = re.sub(r'(\s+)(\w+): \{', r'\1\2:\n\1  {', content)
    
    with open(output_file, 'w') as f:
        f.write(fixed_content)

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python fix_yaml.py input_file output_file")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_file = sys.argv[2]
    fix_yaml_syntax(input_file, output_file)
    print(f"Fixed YAML syntax in {input_file} and saved to {output_file}")
