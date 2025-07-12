#!/usr/bin/env python3
"""
Tests for the fix_yaml.py script.
"""

import os
import tempfile
import unittest
from fix_yaml import fix_yaml_syntax

class TestFixYaml(unittest.TestCase):
    """Test cases for fix_yaml.py script."""

    def setUp(self):
        """Set up test fixtures."""
        # Create a temporary file for testing
        self.temp_yaml_file = tempfile.NamedTemporaryFile(delete=False, suffix='.yaml')
        
        # Sample YAML content with JSON-style object notation
        self.test_yaml_content = """
openapi: 3.0.3
info:
  title: Test API
  version: 1.0.0
paths:
  /test:
    get:
      responses:
        '200':
          description: Success
          content:
            application/json:
              schema: {
                type: object,
                properties: {
                  id: {
                    type: string,
                    description: ID
                  },
                  name: {
                    type: string,
                    description: Name
                  }
                }
              }
"""
        
        # Write the test content to the temporary file
        with open(self.temp_yaml_file.name, 'w') as f:
            f.write(self.test_yaml_content)
        
        # Create a temporary output file
        self.temp_output_file = tempfile.NamedTemporaryFile(delete=False, suffix='.yaml')
    
    def tearDown(self):
        """Tear down test fixtures."""
        # Remove temporary files
        os.unlink(self.temp_yaml_file.name)
        os.unlink(self.temp_output_file.name)
    
    def test_fix_yaml_syntax(self):
        """Test fix_yaml_syntax function."""
        # Run the fix_yaml_syntax function
        fix_yaml_syntax(self.temp_yaml_file.name, self.temp_output_file.name)
        
        # Check if the output file was created
        self.assertTrue(os.path.exists(self.temp_output_file.name))
        
        # Read the output file
        with open(self.temp_output_file.name, 'r') as f:
            fixed_content = f.read()
        
        # Check if the JSON-style object notation was fixed
        self.assertNotIn('schema: {', fixed_content)
        self.assertIn('schema:', fixed_content)
        self.assertNotIn('properties: {', fixed_content)
        self.assertIn('properties:', fixed_content)
        self.assertNotIn('id: {', fixed_content)
        self.assertIn('id:', fixed_content)

if __name__ == '__main__':
    unittest.main()
