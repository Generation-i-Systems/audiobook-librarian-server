#!/usr/bin/env python3
import re
import sys

def fix_yaml_meta_syntax(input_file, output_file):
    """Fix YAML syntax issues related to meta objects and arrays"""
    try:
        with open(input_file, 'r') as f:
            content = f.read()
        
        # Process the file line by line to handle indentation correctly
        lines = content.split('\n')
        result_lines = []
        indent_stack = [0]  # Keep track of indentation levels
        
        for i, line in enumerate(lines):
            # Skip empty lines
            if not line.strip():
                result_lines.append(line)
                continue
            
            # Calculate current indentation
            current_indent = len(line) - len(line.lstrip())
            
            # Handle JSON-style object notation
            if re.search(r': \{', line):
                # Extract property name and indentation
                match = re.match(r'(\s*)(\w+): \{', line)
                if match:
                    indent, prop = match.groups()
                    # Add property with correct indentation
                    result_lines.append(f"{indent}{prop}:")
                    # Set new indentation level for nested properties
                    indent_stack.append(current_indent + 2)
                else:
                    result_lines.append(line)
            # Handle property with comma at the end
            elif re.search(r',\s*$', line):
                # Remove the comma and add the line
                line = re.sub(r',\s*$', '', line)
                result_lines.append(line)
            # Handle closing brace
            elif re.search(r'\}\s*$', line):
                # Skip closing braces as they're not needed in YAML
                if indent_stack and indent_stack[-1] <= current_indent:
                    indent_stack.pop()
            else:
                result_lines.append(line)
        
        # Join the lines back together
        fixed_content = '\n'.join(result_lines)
        
        with open(output_file, 'w') as f:
            f.write(fixed_content)
        
        print(f"Fixed YAML syntax in {input_file} and saved to {output_file}")
        return True
    except Exception as e:
        print(f"Error: {str(e)}")
        return False

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python fix_yaml_meta.py input_file output_file")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_file = sys.argv[2]
    fix_yaml_meta_syntax(input_file, output_file)
