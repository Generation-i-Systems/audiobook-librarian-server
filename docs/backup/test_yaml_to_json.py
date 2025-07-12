#!/usr/bin/env python3
"""
Tests for the yaml_to_json.py script.
"""

import os
import json
import tempfile
import unittest
from yaml_to_json import preprocess_yaml, convert_yaml_to_json

class TestYamlToJson(unittest.TestCase):
    """Test cases for yaml_to_json.py script."""

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
        
        # Create a temporary output file
        self.temp_json_file = tempfile.NamedTemporaryFile(delete=False, suffix='.json')
    
    def tearDown(self):
        """Tear down test fixtures."""
        # Remove temporary files
        os.unlink(self.temp_yaml_file.name)
        os.unlink(self.temp_json_file.name)
        
        # Remove temporary files created by the script
        temp_yaml = self.temp_yaml_file.name + '.temp'
        if os.path.exists(temp_yaml):
            os.unlink(temp_yaml)
    
    def test_preprocess_yaml(self):
        """Test preprocess_yaml function."""
        # Run the preprocess_yaml function
        processed_yaml = preprocess_yaml(self.test_yaml_content)
        
        # Check if the syntax issues were fixed
        self.assertNotIn('items: [', processed_yaml)
        self.assertNotIn('meta: {', processed_yaml)
        self.assertIn('items:', processed_yaml)
        self.assertIn('meta:', processed_yaml)
    
    def test_convert_yaml_to_json(self):
        """Test convert_yaml_to_json function."""
        # Run the convert_yaml_to_json function
        result = convert_yaml_to_json(self.temp_yaml_file.name, self.temp_json_file.name)
        
        # Check if the JSON file was created
        self.assertTrue(os.path.exists(self.temp_json_file.name))
        
        # Check if the temporary YAML file was created
        temp_yaml = self.temp_yaml_file.name + '.temp'
        self.assertTrue(os.path.exists(temp_yaml))
        
        # Check if the JSON file contains valid JSON
        try:
            with open(self.temp_json_file.name, 'r') as f:
                json_content = json.load(f)
            
            # Check if the JSON content has the expected structure
            self.assertIn('info', json_content)
            self.assertIn('title', json_content['info'])
            self.assertEqual(json_content['info']['title'], 'Test API')
            self.assertIn('paths', json_content)
            
        except json.JSONDecodeError:
            self.fail("Generated JSON is not valid")

if __name__ == '__main__':
    unittest.main()
