#!/usr/bin/env python3
"""
Tests for the fix_yaml_meta.py script.
"""

import os
import tempfile
import unittest
from fix_yaml_meta import fix_yaml_meta_syntax

class TestFixYamlMeta(unittest.TestCase):
    """Test cases for fix_yaml_meta.py script."""

    def setUp(self):
        """Set up test fixtures."""
        # Create a temporary file for testing
        self.temp_yaml_file = tempfile.NamedTemporaryFile(delete=False, suffix='.yaml')
        
        # Sample YAML content with JSON-style meta objects
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
              schema:
                type: object
                properties:
                  data:
                    type: array
                    items:
                      type: object
                  meta: {
                    type: object,
                    properties: {
                      current_page: {
                        type: integer,
                        description: Current page
                      },
                      total: {
                        type: integer,
                        description: Total items
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
    
    def test_fix_yaml_meta(self):
        """Test fix_yaml_meta function."""
        # Run the fix_yaml_meta_syntax function
        fix_yaml_meta_syntax(self.temp_yaml_file.name, self.temp_output_file.name)
        
        # Check if the output file was created
        self.assertTrue(os.path.exists(self.temp_output_file.name))
        
        # Read the output file
        with open(self.temp_output_file.name, 'r') as f:
            fixed_content = f.read()
        
        # Check if the JSON-style meta objects were fixed
        self.assertNotIn('meta: {', fixed_content)
        self.assertIn('meta:', fixed_content)
        self.assertIn('  type: object', fixed_content)
        self.assertIn('  properties:', fixed_content)
        
        # Check if the nested JSON-style objects were fixed
        self.assertNotIn('current_page: {', fixed_content)
        self.assertIn('current_page:', fixed_content)
        self.assertIn('    type: integer', fixed_content)
        self.assertIn('    description: Current page', fixed_content)

if __name__ == '__main__':
    unittest.main()
