# Authentication Middleware Documentation

This document describes the authentication middleware used in the Librarian API.

## Overview

The Librarian API uses a custom authentication middleware (`ApiAuth`) built on top of Laravel Sanctum for token-based authentication. This middleware handles token validation, user authentication, and access control.

## Middleware Components

### ApiAuth Middleware

**Location**: `app/Http/Middleware/ApiAuth.php`

**Purpose**: Validates Bearer tokens and authenticates users for API requests.

#### How It Works

1. **Token Extraction**: Extracts Bearer token from `Authorization` header
2. **Token Validation**: Validates token using Laravel Sanctum's `PersonalAccessToken`
3. **Expiration Check**: Verifies token hasn't expired
4. **User Resolution**: Retrieves associated user from database
5. **Role Verification**: Ensures user is approved (not `unverified`)
6. **Request Setup**: Sets authenticated user in request context

#### Code Flow

```php
public function handle(Request $request, Closure $next)
{
    // 1. Extract Authorization header
    $authHeader = $request->header('Authorization');
    if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    // 2. Get token from header
    $token = $matches[1];
    
    // 3. Find token in database
    $accessToken = PersonalAccessToken::findToken($token);
    
    // 4. Validate token and check expiration
    if (!$accessToken || $accessToken->expires_at && $accessToken->expires_at->isPast()) {
        return response()->json(['error' => 'Invalid or expired token'], 401);
    }

    // 5. Get associated user
    $user = $accessToken->tokenable;
    
    if (!$user) {
        return response()->json(['error' => 'User not found'], 401);
    }

    // 6. Check user approval status
    if ($user->role === 'unverified') {
        return response()->json(['error' => 'Account pending admin approval'], 403);
    }

    // 7. Set user in request
    Auth::setUser($user);
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    return $next($request);
}
```

### Standard Role Middleware

**Location**: `app/Http/Middleware/RequireStandardRole.php`

**Purpose**: Ensures authenticated users have appropriate role permissions.

**Usage**: Combined with `ApiAuth` middleware in route groups.

## Middleware Registration

### Bootstrap Configuration

**Location**: `bootstrap/app.php`

```php
$middleware->alias([
    // ... other middleware
    'api.auth' => \App\Http\Middleware\ApiAuth::class,
    'standard' => \App\Http\Middleware\RequireStandardRole::class,
]);
```

### Route Application

**Location**: `routes/api.php`

```php
Route::prefix('v1')->group(function () {
    // Public routes (no auth required)
    Route::get('/books/{book}/cover', [BookApiController::class, 'cover']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Protected routes (auth required)
    Route::middleware(['api.auth', 'standard'])->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });
        Route::get('/books', [BookApiController::class, 'index']);
        // ... other protected routes
    });
});
```

## Token Management

### Token Creation

Tokens are created during login in `AuthController`:

```php
// Create a Sanctum token with 30-day expiration
$token = $user->createToken('api-token', ['*'], now()->addDays(30))->plainTextToken;
```

### Token Format

```
{token_id}|{actual_token_hash}
```

Example: `1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yz`

### Token Storage

Tokens are stored in the `personal_access_tokens` table:

```sql
CREATE TABLE personal_access_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

### Token Lifecycle

1. **Creation**: Token created during login
2. **Usage**: Token validated on each protected request
3. **Expiration**: Token expires after 30 days
4. **Revocation**: Token deleted during logout

## Error Responses

### 401 Unauthorized

Returned when:
- No Authorization header provided
- Invalid token format
- Token not found in database
- Token has expired
- Associated user not found

```json
{
  "error": "Unauthorized"
}
```

Or:

```json
{
  "error": "Invalid or expired token"
}
```

### 403 Forbidden

Returned when:
- User account is unverified (pending approval)

```json
{
  "error": "Account pending admin approval"
}
```

## Security Features

### Token Security

1. **Hashed Storage**: Only token hashes stored in database
2. **Expiration**: Tokens expire after 30 days
3. **Single Use Logout**: Logout invalidates specific token
4. **Database Validation**: Each request validates against database

### User Security

1. **Role-Based Access**: Users must be approved (not `unverified`)
2. **Password Hashing**: Passwords stored with Laravel's bcrypt
3. **User Resolution**: Authenticated user available in all controllers

### Request Security

1. **Bearer Token Only**: Only accepts Bearer token format
2. **Header Validation**: Strict Authorization header parsing
3. **Database Lookups**: All tokens validated against database

## Usage in Controllers

### Accessing Authenticated User

```php
class BookApiController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user(); // Get authenticated user
        
        // Use user for business logic
        $books = Book::visibleTo($user)->get();
        
        return response()->json($books);
    }
}
```

### User Properties

```php
$user = $request->user();

$userId = $user->id;
$userName = $user->name;
$userRole = $user->role; // 'admin', 'user', etc.
$userEmail = $user->email;
```

## Middleware Testing

### Unit Testing

```php
public function test_middleware_allows_valid_token()
{
    $user = User::factory()->create(['role' => 'user']);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->get('/api/v1/user');

    $response->assertStatus(200);
}

public function test_middleware_rejects_invalid_token()
{
    $response = $this->withHeaders([
        'Authorization' => 'Bearer invalid-token',
    ])->get('/api/v1/user');

    $response->assertStatus(401);
}
```

### Integration Testing

```php
public function test_protected_endpoint_requires_auth()
{
    // No token
    $response = $this->get('/api/v1/books');
    $response->assertStatus(401);

    // Valid token
    $user = User::factory()->create(['role' => 'user']);
    $token = $user->createToken('test')->plainTextToken;
    
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->get('/api/v1/books');
    
    $response->assertStatus(200);
}
```

## Migration Notes

### From Firebase Auth

When migrating from Firebase authentication:

1. **Remove Firebase Middleware**: 
   - Remove `firebase.auth` from route groups
   - Replace with `api.auth`

2. **Update Token Handling**:
   - Firebase JWT → Sanctum tokens
   - Different token validation logic
   - Database-based instead of Firebase-based

3. **User Model Changes**:
   - Ensure User model uses `HasApiTokens` trait
   - Update user creation/management logic

### Backward Compatibility

The new middleware provides these compatibility features:

1. **Multiple Token Fields**: Response includes `authToken`, `refreshToken`, and `token`
2. **User Data Format**: Maintains similar user data structure
3. **Error Handling**: Similar error response formats

## Configuration

### Token Expiration

Default: 30 days. Modify in `AuthController`:

```php
$token = $user->createToken('api-token', ['*'], now()->addDays(30))->plainTextToken;
```

### Sanctum Configuration

**Location**: `config/sanctum.php`

Key settings:
- `expiration`: Global token expiration (null = uses per-token expiration)
- `guard`: Authentication guard to use (['web'])
- `stateful`: Domains for stateful authentication

## Performance Considerations

### Database Queries

Each authenticated request performs:
1. Token lookup in `personal_access_tokens` table
2. User lookup via token relationship

### Optimization Tips

1. **Index**: Ensure `personal_access_tokens.token` is indexed
2. **Cleanup**: Regularly clean expired tokens
3. **Caching**: Consider caching frequently accessed user data

### Token Cleanup Command

```php
// Clean expired tokens
DB::table('personal_access_tokens')
    ->where('expires_at', '<', now())
    ->delete();
```

## Troubleshooting

### Common Issues

1. **"Unauthorized" on valid requests**
   - Check token format (Bearer {token})
   - Verify token hasn't expired
   - Ensure user role is not 'unverified'

2. **Token not found**
   - Token may have been revoked
   - Check database for token existence
   - Verify token format is correct

3. **User not found**
   - Token exists but user was deleted
   - Database integrity issue

### Debug Steps

1. **Check Headers**: Verify Authorization header format
2. **Database Check**: Look up token in `personal_access_tokens`
3. **User Status**: Verify user role and account status
4. **Logs**: Check Laravel logs for detailed errors