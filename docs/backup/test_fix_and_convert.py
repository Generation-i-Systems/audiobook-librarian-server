#!/usr/bin/env python3
"""
Tests for the fix_and_convert.py script.
"""

import os
import json
import tempfile
import unittest
from fix_and_convert import fix_yaml_and_convert_to_json

class TestFixAndConvert(unittest.TestCase):
    """Test cases for fix_and_convert.py script."""

    def setUp(self):
        """Set up test fixtures."""
        # Create a temporary file for testing
        self.temp_yaml_file = tempfile.NamedTemporaryFile(delete=False, suffix='.yaml')
        
        # Sample YAML content with syntax issues
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
                    items: [
                      {
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
                    ]
                  meta: {
                    type: object,
                    properties: {
                      current_page: {
                        type: integer,
                        description: Current page
                      }
                    }
                  }
"""
        
        # Write the test content to the temporary file
        with open(self.temp_yaml_file.name, 'w') as f:
            f.write(self.test_yaml_content)
        
        # Create temporary output files
        self.temp_fixed_yaml_file = tempfile.NamedTemporaryFile(delete=False, suffix='.yaml')
        self.temp_json_file = tempfile.NamedTemporaryFile(delete=False, suffix='.json')
    
    def tearDown(self):
        """Tear down test fixtures."""
        # Remove temporary files
        os.unlink(self.temp_yaml_file.name)
        os.unlink(self.temp_fixed_yaml_file.name)
        os.unlink(self.temp_json_file.name)
    
    def test_fix_yaml_and_convert_to_json(self):
        """Test fix_yaml_and_convert_to_json function."""
        # Run the fix_yaml_and_convert_to_json function
        fix_yaml_and_convert_to_json(self.temp_yaml_file.name, self.temp_json_file.name)
        
        # Check if the JSON file was created
        self.assertTrue(os.path.exists(self.temp_json_file.name))
        
        # Check if the function created a fixed YAML file
        fixed_yaml_file = self.temp_yaml_file.name + '.fixed'
        self.assertTrue(os.path.exists(fixed_yaml_file))
        
        # Read the fixed YAML file
        with open(fixed_yaml_file, 'r') as f:
            fixed_content = f.read()
        
        # Check if the syntax issues were fixed in the YAML
        self.assertNotIn('items: [\n', fixed_content)
        self.assertNotIn('meta: {', fixed_content)
        
        # Check if the JSON file was created
        self.assertTrue(os.path.exists(self.temp_json_file.name))
        
        # Check if the JSON file contains valid JSON
        try:
            with open(self.temp_json_file.name, 'r') as f:
                json_content = json.load(f)
            
            # Check if the JSON content has the expected structure
            self.assertIn('openapi', json_content)
            self.assertIn('info', json_content)
            self.assertIn('paths', json_content)
            # The function creates a basic JSON structure with empty paths
            self.assertEqual({}, json_content['paths'])
            # Check for other expected sections
            self.assertIn('components', json_content)
            self.assertIn('schemas', json_content['components'])
            self.assertIn('securitySchemes', json_content['components'])
            
        except json.JSONDecodeError:
            self.fail("Generated JSON is not valid")

if __name__ == '__main__':
    unittest.main()
