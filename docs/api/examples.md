# API Authentication Examples

This document provides comprehensive examples of using the Librarian API authentication in various programming languages and tools.

## Table of Contents

1. [Basic Authentication Flow](#basic-authentication-flow)
2. [cURL Examples](#curl-examples)
3. [JavaScript/Fetch Examples](#javascriptfetch-examples)
4. [Python Examples](#python-examples)
5. [PHP Examples](#php-examples)
6. [Postman Collection](#postman-collection)
7. [React/Frontend Examples](#reactfrontend-examples)
8. [Error Handling Examples](#error-handling-examples)

## Basic Authentication Flow

The typical flow for API authentication:

```
1. Register (optional) → Account created (unverified)
2. Login → Receive access token
3. Use token for API requests
4. Logout → Token invalidated
```

## cURL Examples

### Register New User

```bash
curl -X POST http://localhost:8000/api/v1/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "username": "johndoe",
    "email": "john@example.com",
    "password": "securepassword123"
  }'
```

**Response:**
```json
{
  "message": "Account created. Waiting for admin approval."
}
```

### Login with Email

```bash
curl -X POST http://localhost:8000/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "securepassword123"
  }'
```

### Login with Username

```bash
curl -X POST http://localhost:8000/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "johndoe",
    "password": "securepassword123"
  }'
```

**Response:**
```json
{
  "id": 1,
  "name": "John Doe",
  "username": "johndoe",
  "email": "john@example.com",
  "role": "user",
  "authToken": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "refreshToken": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "token": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
}
```

### Forgot Password

```bash
curl -X POST http://localhost:8000/api/v1/forgot-password \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com"
  }'
```

**Response:**
```json
{
  "message": "If an account exists for that email, a password reset link has been sent."
}
```

### Reset Password

```bash
curl -X POST http://localhost:8000/api/v1/reset-password \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "token": "token-from-email",
    "password": "new-password-123",
    "password_confirmation": "new-password-123"
  }'
```

**Response:**
```json
{
  "message": "Password has been reset successfully."
}
```

### Access Protected Endpoint

```bash
# Save token from login response
TOKEN="0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"

# Get current user info
curl -X GET http://localhost:8000/api/v1/user \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# Get books list
curl -X GET http://localhost:8000/api/v1/books \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

### Logout

```bash
curl -X POST http://localhost:8000/api/v1/logout \
  -H "Authorization: Bearer $TOKEN"
```

**Response:**
```json
{
  "message": "Successfully logged out"
}
```

## JavaScript/Fetch Examples

### Complete Authentication Class

```javascript
class LibrarianAPI {
  constructor(baseURL = 'http://localhost:8000/api/v1') {
    this.baseURL = baseURL;
    this.token = localStorage.getItem('librarian_token'); // Use secure storage in production
  }

  async register(userData) {
    const response = await fetch(`${this.baseURL}/register`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(userData)
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Registration failed');
    }

    return await response.json();
  }

  async login(credentials) {
    const response = await fetch(`${this.baseURL}/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(credentials)
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Login failed');
    }

    const data = await response.json();
    this.token = data.authToken;
    localStorage.setItem('librarian_token', this.token); // Use secure storage in production

    return data;
  }

  async logout() {
    if (!this.token) return;

    const response = await fetch(`${this.baseURL}/logout`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.token}`,
      }
    });

    this.token = null;
    localStorage.removeItem('librarian_token');

    return response.ok;
  }

  async getUser() {
    return this.authenticatedRequest('/user');
  }

  async getBooks() {
    return this.authenticatedRequest('/books');
  }

  async authenticatedRequest(endpoint, options = {}) {
    if (!this.token) {
      throw new Error('No authentication token available');
    }

    const response = await fetch(`${this.baseURL}${endpoint}`, {
      ...options,
      headers: {
        'Authorization': `Bearer ${this.token}`,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...options.headers,
      }
    });

    if (!response.ok) {
      if (response.status === 401) {
        // Token expired or invalid
        this.token = null;
        localStorage.removeItem('librarian_token');
        throw new Error('Authentication required');
      }

      const error = await response.json();
      throw new Error(error.message || 'Request failed');
    }

    return await response.json();
  }
}
```

### Usage Examples

```javascript
const api = new LibrarianAPI();

// Register
try {
  await api.register({
    name: 'John Doe',
    username: 'johndoe',
    email: 'john@example.com',
    password: 'securepassword123'
  });
  console.log('Registration successful');
} catch (error) {
  console.error('Registration failed:', error.message);
}

// Login
try {
  const user = await api.login({
    email: 'john@example.com',
    password: 'securepassword123'
  });
  console.log('Logged in as:', user.name);
} catch (error) {
  console.error('Login failed:', error.message);
}

// Get books
try {
  const books = await api.getBooks();
  console.log('Books:', books);
} catch (error) {
  console.error('Failed to get books:', error.message);
}

// Logout
await api.logout();
```

### Simple Fetch Examples

```javascript
// Register
const registerUser = async () => {
  const response = await fetch('/api/v1/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      name: 'John Doe',
      username: 'johndoe',
      email: 'john@example.com',
      password: 'securepassword123'
    })
  });

  return await response.json();
};

// Login
const loginUser = async () => {
  const response = await fetch('/api/v1/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'john@example.com',
      password: 'securepassword123'
    })
  });

  const data = await response.json();
  localStorage.setItem('token', data.authToken);
  return data;
};

// Make authenticated request
const getBooks = async () => {
  const token = localStorage.getItem('token');
  const response = await fetch('/api/v1/books', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });

  return await response.json();
};
```

## Python Examples

### Using Requests Library

```python
import requests
import json
from typing import Optional, Dict, Any

class LibrarianAPI:
    def __init__(self, base_url: str = "http://localhost:8000/api/v1"):
        self.base_url = base_url
        self.token: Optional[str] = None
        self.session = requests.Session()

    def register(self, user_data: Dict[str, str]) -> Dict[str, Any]:
        """Register a new user account."""
        response = self.session.post(
            f"{self.base_url}/register",
            json=user_data
        )
        response.raise_for_status()
        return response.json()

    def login(self, credentials: Dict[str, str]) -> Dict[str, Any]:
        """Login and store authentication token."""
        response = self.session.post(
            f"{self.base_url}/login",
            json=credentials
        )
        response.raise_for_status()

        data = response.json()
        self.token = data["authToken"]

        # Update session headers
        self.session.headers.update({
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json"
        })

        return data

    def logout(self) -> bool:
        """Logout and clear authentication token."""
        if not self.token:
            return True

        try:
            response = self.session.post(f"{self.base_url}/logout")
            response.raise_for_status()
        except requests.RequestException:
            pass  # Logout even if request fails
        finally:
            self.token = None
            self.session.headers.pop("Authorization", None)

        return True

    def get_user(self) -> Dict[str, Any]:
        """Get current user information."""
        return self._authenticated_request("GET", "/user")

    def get_books(self) -> Dict[str, Any]:
        """Get books list."""
        return self._authenticated_request("GET", "/books")

    def _authenticated_request(self, method: str, endpoint: str, **kwargs) -> Dict[str, Any]:
        """Make an authenticated request."""
        if not self.token:
            raise ValueError("No authentication token available")

        response = self.session.request(
            method,
            f"{self.base_url}{endpoint}",
            **kwargs
        )

        if response.status_code == 401:
            self.token = None
            self.session.headers.pop("Authorization", None)
            raise ValueError("Authentication required")

        response.raise_for_status()
        return response.json()

# Usage examples
def main():
    api = LibrarianAPI()

    try:
        # Register
        register_result = api.register({
            "name": "John Doe",
            "username": "johndoe",
            "email": "john@example.com",
            "password": "securepassword123"
        })
        print("Registration:", register_result)

        # Login
        user_data = api.login({
            "email": "john@example.com",
            "password": "securepassword123"
        })
        print(f"Logged in as: {user_data['name']}")

        # Get user info
        user_info = api.get_user()
        print("User info:", user_info)

        # Get books
        books = api.get_books()
        print(f"Found {len(books)} books")

        # Logout
        api.logout()
        print("Logged out successfully")

    except requests.RequestException as e:
        print(f"Request error: {e}")
    except ValueError as e:
        print(f"Auth error: {e}")

if __name__ == "__main__":
    main()
```

### Simple Python Example

```python
import requests

# Login
login_response = requests.post("http://localhost:8000/api/v1/login", json={
    "email": "john@example.com",
    "password": "securepassword123"
})

if login_response.status_code == 200:
    auth_data = login_response.json()
    token = auth_data["authToken"]

    # Use token for authenticated requests
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json"
    }

    # Get books
    books_response = requests.get(
        "http://localhost:8000/api/v1/books",
        headers=headers
    )

    if books_response.status_code == 200:
        books = books_response.json()
        print(f"Found {len(books)} books")
    else:
        print("Failed to get books")

    # Logout
    logout_response = requests.post(
        "http://localhost:8000/api/v1/logout",
        headers=headers
    )
else:
    print("Login failed")
```

## PHP Examples

### Using Guzzle HTTP Client

```php
<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class LibrarianAPI
{
    private $client;
    private $baseUrl;
    private $token;

    public function __construct($baseUrl = 'http://localhost:8000/api/v1')
    {
        $this->baseUrl = $baseUrl;
        $this->client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 30,
        ]);
    }

    public function register(array $userData): array
    {
        try {
            $response = $this->client->post('/register', [
                'json' => $userData
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new Exception('Registration failed: ' . $e->getMessage());
        }
    }

    public function login(array $credentials): array
    {
        try {
            $response = $this->client->post('/login', [
                'json' => $credentials
            ]);

            $data = json_decode($response->getBody(), true);
            $this->token = $data['authToken'];

            return $data;
        } catch (RequestException $e) {
            throw new Exception('Login failed: ' . $e->getMessage());
        }
    }

    public function logout(): bool
    {
        if (!$this->token) {
            return true;
        }

        try {
            $this->client->post('/logout', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token
                ]
            ]);
        } catch (RequestException $e) {
            // Continue with logout even if request fails
        } finally {
            $this->token = null;
        }

        return true;
    }

    public function getUser(): array
    {
        return $this->authenticatedRequest('GET', '/user');
    }

    public function getBooks(): array
    {
        return $this->authenticatedRequest('GET', '/books');
    }

    private function authenticatedRequest(string $method, string $endpoint, array $options = []): array
    {
        if (!$this->token) {
            throw new Exception('No authentication token available');
        }

        $options['headers']['Authorization'] = 'Bearer ' . $this->token;
        $options['headers']['Accept'] = 'application/json';

        try {
            $response = $this->client->request($method, $endpoint, $options);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 401) {
                $this->token = null;
                throw new Exception('Authentication required');
            }
            throw new Exception('Request failed: ' . $e->getMessage());
        }
    }
}

// Usage
try {
    $api = new LibrarianAPI();

    // Register
    $registerResult = $api->register([
        'name' => 'John Doe',
        'username' => 'johndoe',
        'email' => 'john@example.com',
        'password' => 'securepassword123'
    ]);
    echo "Registration: " . json_encode($registerResult) . "\n";

    // Login
    $userData = $api->login([
        'email' => 'john@example.com',
        'password' => 'securepassword123'
    ]);
    echo "Logged in as: " . $userData['name'] . "\n";

    // Get books
    $books = $api->getBooks();
    echo "Found " . count($books) . " books\n";

    // Logout
    $api->logout();
    echo "Logged out successfully\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## Postman Collection

### Collection JSON

```json
{
  "info": {
    "name": "Librarian API",
    "description": "Authentication examples for Librarian API",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "auth": {
    "type": "bearer",
    "bearer": [
      {
        "key": "token",
        "value": "{{authToken}}",
        "type": "string"
      }
    ]
  },
  "variable": [
    {
      "key": "baseUrl",
      "value": "http://localhost:8000/api/v1"
    },
    {
      "key": "authToken",
      "value": ""
    }
  ],
  "item": [
    {
      "name": "Authentication",
      "item": [
        {
          "name": "Register",
          "request": {
            "method": "POST",
            "header": [
              {
                "key": "Content-Type",
                "value": "application/json"
              }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"name\": \"John Doe\",\n  \"username\": \"johndoe\",\n  \"email\": \"john@example.com\",\n  \"password\": \"securepassword123\"\n}"
            },
            "url": {
              "raw": "{{baseUrl}}/register",
              "host": ["{{baseUrl}}"],
              "path": ["register"]
            }
          }
        },
        {
          "name": "Login",
          "event": [
            {
              "listen": "test",
              "script": {
                "exec": [
                  "if (pm.response.code === 200) {",
                  "    const response = pm.response.json();",
                  "    pm.environment.set('authToken', response.authToken);",
                  "    pm.collectionVariables.set('authToken', response.authToken);",
                  "}"
                ]
              }
            }
          ],
          "request": {
            "method": "POST",
            "header": [
              {
                "key": "Content-Type",
                "value": "application/json"
              }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"email\": \"john@example.com\",\n  \"password\": \"securepassword123\"\n}"
            },
            "url": {
              "raw": "{{baseUrl}}/login",
              "host": ["{{baseUrl}}"],
              "path": ["login"]
            }
          }
        },
        {
          "name": "Get Current User",
          "request": {
            "method": "GET",
            "header": [
              {
                "key": "Accept",
                "value": "application/json"
              }
            ],
            "url": {
              "raw": "{{baseUrl}}/user",
              "host": ["{{baseUrl}}"],
              "path": ["user"]
            }
          }
        },
        {
          "name": "Logout",
          "event": [
            {
              "listen": "test",
              "script": {
                "exec": [
                  "pm.environment.unset('authToken');",
                  "pm.collectionVariables.set('authToken', '');"
                ]
              }
            }
          ],
          "request": {
            "method": "POST",
            "url": {
              "raw": "{{baseUrl}}/logout",
              "host": ["{{baseUrl}}"],
              "path": ["logout"]
            }
          }
        }
      ]
    },
    {
      "name": "Books",
      "item": [
        {
          "name": "Get Books",
          "request": {
            "method": "GET",
            "header": [
              {
                "key": "Accept",
                "value": "application/json"
              }
            ],
            "url": {
              "raw": "{{baseUrl}}/books",
              "host": ["{{baseUrl}}"],
              "path": ["books"]
            }
          }
        }
      ]
    }
  ]
}
```

## React/Frontend Examples

### React Hook for Authentication

```javascript
import { useState, useEffect, createContext, useContext } from 'react';

const AuthContext = createContext();

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(localStorage.getItem('authToken'));
  const [loading, setLoading] = useState(true);

  const baseURL = 'http://localhost:8000/api/v1';

  useEffect(() => {
    if (token) {
      fetchUser();
    } else {
      setLoading(false);
    }
  }, [token]);

  const fetchUser = async () => {
    try {
      const response = await fetch(`${baseURL}/user`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json'
        }
      });

      if (response.ok) {
        const userData = await response.json();
        setUser(userData);
      } else {
        // Token is invalid
        logout();
      }
    } catch (error) {
      console.error('Failed to fetch user:', error);
      logout();
    } finally {
      setLoading(false);
    }
  };

  const login = async (credentials) => {
    try {
      const response = await fetch(`${baseURL}/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(credentials)
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Login failed');
      }

      const data = await response.json();
      const newToken = data.authToken;

      setToken(newToken);
      setUser(data);
      localStorage.setItem('authToken', newToken);

      return data;
    } catch (error) {
      throw error;
    }
  };

  const register = async (userData) => {
    try {
      const response = await fetch(`${baseURL}/register`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(userData)
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Registration failed');
      }

      return await response.json();
    } catch (error) {
      throw error;
    }
  };

  const logout = async () => {
    if (token) {
      try {
        await fetch(`${baseURL}/logout`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });
      } catch (error) {
        console.error('Logout request failed:', error);
      }
    }

    setToken(null);
    setUser(null);
    localStorage.removeItem('authToken');
  };

  const authenticatedFetch = async (url, options = {}) => {
    if (!token) {
      throw new Error('No authentication token');
    }

    const response = await fetch(url, {
      ...options,
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...options.headers
      }
    });

    if (response.status === 401) {
      logout();
      throw new Error('Authentication required');
    }

    return response;
  };

  const value = {
    user,
    token,
    loading,
    login,
    register,
    logout,
    authenticatedFetch
  };

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
};
```

### Login Component

```javascript
import { useState } from 'react';
import { useAuth } from './AuthProvider';

const LoginForm = () => {
  const [credentials, setCredentials] = useState({
    email: '',
    password: ''
  });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const { login } = useAuth();

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    try {
      await login(credentials);
      // Redirect or update UI on success
    } catch (error) {
      setError(error.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <div>
        <label>Email:</label>
        <input
          type="email"
          value={credentials.email}
          onChange={(e) => setCredentials({
            ...credentials,
            email: e.target.value
          })}
          required
        />
      </div>

      <div>
        <label>Password:</label>
        <input
          type="password"
          value={credentials.password}
          onChange={(e) => setCredentials({
            ...credentials,
            password: e.target.value
          })}
          required
        />
      </div>

      {error && <div style={{color: 'red'}}>{error}</div>}

      <button type="submit" disabled={loading}>
        {loading ? 'Logging in...' : 'Login'}
      </button>
    </form>
  );
};
```

## Error Handling Examples

### JavaScript Error Handling

```javascript
const handleApiError = (error, response) => {
  if (response.status === 401) {
    // Token expired or invalid
    localStorage.removeItem('authToken');
    window.location.href = '/login';
    return;
  }

  if (response.status === 403) {
    // Account pending approval
    alert('Your account is pending admin approval');
    return;
  }

  if (response.status === 400) {
    // Validation errors
    const errorData = await response.json();
    displayValidationErrors(errorData);
    return;
  }

  // Generic error
  console.error('API Error:', error);
  alert('An error occurred. Please try again.');
};

const displayValidationErrors = (errors) => {
  Object.keys(errors).forEach(field => {
    const fieldErrors = errors[field];
    fieldErrors.forEach(error => {
      console.error(`${field}: ${error}`);
      // Display error in UI
    });
  });
};
```

### Python Error Handling

```python
import requests
from typing import Dict, Any

def handle_api_error(response: requests.Response) -> None:
    """Handle API errors based on status code."""
    if response.status_code == 401:
        raise AuthenticationError("Authentication required")
    elif response.status_code == 403:
        raise PermissionError("Account pending approval")
    elif response.status_code == 400:
        try:
            error_data = response.json()
            raise ValidationError(error_data)
        except ValueError:
            raise APIError("Bad request")
    else:
        raise APIError(f"API error: {response.status_code}")

class AuthenticationError(Exception):
    pass

class ValidationError(Exception):
    def __init__(self, errors: Dict[str, Any]):
        self.errors = errors
        super().__init__(f"Validation errors: {errors}")

class APIError(Exception):
    pass
```

### Complete Example with Error Handling

```javascript
class APIClient {
  constructor(baseURL) {
    this.baseURL = baseURL;
    this.token = localStorage.getItem('authToken');
  }

  async request(endpoint, options = {}) {
    const url = `${this.baseURL}${endpoint}`;
    const config = {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...options.headers
      },
      ...options
    };

    if (this.token) {
      config.headers.Authorization = `Bearer ${this.token}`;
    }

    try {
      const response = await fetch(url, config);

      if (!response.ok) {
        await this.handleError(response);
      }

      return await response.json();
    } catch (error) {
      if (error instanceof NetworkError) {
        throw error;
      }
      throw new APIError('Network request failed', error);
    }
  }

  async handleError(response) {
    const status = response.status;

    try {
      const errorData = await response.json();

      switch (status) {
        case 400:
          throw new ValidationError(errorData);
        case 401:
          this.clearAuth();
          throw new AuthenticationError('Authentication required');
        case 403:
          throw new PermissionError('Account pending approval');
        case 404:
          throw new NotFoundError('Resource not found');
        case 422:
          throw new ValidationError(errorData);
        default:
          throw new APIError(`HTTP ${status}: ${errorData.message || 'Unknown error'}`);
      }
    } catch (parseError) {
      throw new APIError(`HTTP ${status}: Unable to parse error response`);
    }
  }

  clearAuth() {
    this.token = null;
    localStorage.removeItem('authToken');
    // Trigger logout in your app
  }
}

// Custom error classes
class APIError extends Error {
  constructor(message, originalError = null) {
    super(message);
    this.name = 'APIError';
    this.originalError = originalError;
  }
}

class ValidationError extends APIError {
  constructor(errors) {
    super('Validation failed');
    this.name = 'ValidationError';
    this.errors = errors;
  }
}

class AuthenticationError extends APIError {
  constructor(message) {
    super(message);
    this.name = 'AuthenticationError';
  }
}

class PermissionError extends APIError {
  constructor(message) {
    super(message);
    this.name = 'PermissionError';
  }
}

class NotFoundError extends APIError {
  constructor(message) {
    super(message);
    this.name = 'NotFoundError';
  }
}
```

These examples provide comprehensive coverage of authentication usage across different programming languages and scenarios. Each example includes proper error handling and follows best practices for API integration.
