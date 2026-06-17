<?php

namespace App\Console\Commands;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ApiServiceClient extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:client
                            {url : The API URL or URI to call}
                            {--user= : User ID to impersonate (defaults to first admin user)}
                            {--method=GET : HTTP method to use (GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS)}
                            {--data= : JSON data to send with the request}
                            {--header=* : Additional headers to send (format: "Header-Name: value")}
                            {--download= : Download response to file (provide filename)}
                            {--show-token : Display the auth token being used}
                            {--timeout=30 : Request timeout in seconds}
                            {--max-time=0 : Maximum time for the entire request (0 = unlimited)}
                            {--show-details : Show detailed request/response information}
                            {--curl : Generate and display equivalent curl command}
                            {--curl-only : Only show curl command without making the request}
                            {--no-color : Disable colored output}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Service client to make API calls as a specific user or admin with curl-like capabilities';

    protected DocumentStoreServiceInterface $documentStoreService;

    /**
     * ApiServiceClient constructor.
     */
    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        parent::__construct();
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $url = $this->argument('url');
        $userId = $this->option('user');
        $method = strtoupper($this->option('method'));
        $data = $this->option('data');
        $headers = $this->option('header');
        $downloadFile = $this->option('download');
        $showToken = $this->option('show-token');
        $timeout = (int) $this->option('timeout');
        $maxTime = (int) $this->option('max-time');
        $showDetails = $this->option('show-details');
        $generateCurl = $this->option('curl');
        $curlOnly = $this->option('curl-only');
        $noColor = $this->option('no-color');
        $verbose = $this->getOutput()->isVerbose();

        // If curl-only is specified, also enable curl generation
        if ($curlOnly) {
            $generateCurl = true;
        }

        // If verbose is enabled, also show token and details
        if ($verbose) {
            $showToken = true;
            $showDetails = true;
        }

        try {
            // Get the user to impersonate
            $user = $this->getUser($userId);
            if (!$user) {
                $this->error('Could not find user to impersonate');
                return 1;
            }

            $this->info("Making {$method} request as user: {$user->name} ({$user->getAuthIdentifier()})");

            // Parse the URL to get the URI and host
            [$uri, $host] = $this->parseUrl($url);
            $this->info("Request URI: {$uri}");
            if ($host) {
                $this->info("Request Host: {$host}");
            }

            // Generate token and show if requested
            $tempToken = $this->generateTempTokenForUser($user);
            if ($showToken) {
                $this->line('');
                $this->info('Auth Token: ' . $tempToken);
                $this->line('');
            }

            // Generate curl command if requested
            if ($generateCurl) {
                $curlCommand = $this->generateCurlCommand($uri, $method, $data, $headers, $tempToken, $host);
                $this->line('');
                $this->line('<comment>Equivalent curl command:</comment>');
                $this->line($curlCommand);
                $this->line('');

                // If curl-only is specified, exit here
                if ($curlOnly) {
                    $this->cleanupTempToken($tempToken);
                    return 0;
                }
            }

            // Make the API call
            $response = $this->makeApiCall($uri, $method, $data, $user, $host, $headers, $timeout, $maxTime, $showDetails, $tempToken);

            // Handle download or display response
            if ($downloadFile) {
                $this->handleDownload($response, $downloadFile);
            } else {
                $this->displayResponse($response, $noColor);
            }

            // Clean up token
            $this->cleanupTempToken($tempToken);

            return 0;
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Get the user to impersonate
     *
     * @param string|null $userId
     * @return DocumentstoreUser|null
     */
    protected function getUser(?string $userId): ?DocumentstoreUser
    {
        if ($userId) {
            // Get specific user
            $userData = $this->documentStoreService->getUserById($userId);
            if (!$userData) {
                $this->warn("User with ID '{$userId}' not found, falling back to first admin user");
                return $this->getFirstAdminUser();
            }
            return new DocumentstoreUser($userData);
        }

        // Get first admin user
        return $this->getFirstAdminUser();
    }

    /**
     * Get the first admin user
     *
     * @return DocumentstoreUser|null
     */
    protected function getFirstAdminUser(): ?DocumentstoreUser
    {
        $adminUsers = $this->documentStoreService->getAdminUsers();

        if (empty($adminUsers)) {
            return null;
        }

        return new DocumentstoreUser($adminUsers[0]);
    }

    /**
     * Parse URL to extract URI and host
     *
     * @param string $url
     * @return array [uri, host]
     */
    protected function parseUrl(string $url): array
    {
        // If it's already a URI (starts with /), return as is with no host
        if (str_starts_with($url, '/')) {
            return [$url, null];
        }

        // If it's a full URL, extract the path and query
        $parsed = parse_url($url);
        if (!$parsed) {
            throw new \InvalidArgumentException("Invalid URL format: {$url}");
        }

        // If we only have a path and no scheme, it's likely an invalid URL
        // unless it looks like a relative path starting with /
        if (!isset($parsed['scheme']) && isset($parsed['path']) && !str_starts_with($parsed['path'], '/')) {
            throw new \InvalidArgumentException("Invalid URL format: {$url}");
        }

        // For full URLs, we need a scheme and host
        if (isset($parsed['scheme']) && !isset($parsed['host'])) {
            throw new \InvalidArgumentException("Invalid URL format: {$url}");
        }

        $uri = $parsed['path'] ?? '/';
        if (isset($parsed['query'])) {
            $uri .= '?' . $parsed['query'];
        }

        // Extract host and scheme for constructing full URLs
        $host = null;
        if (isset($parsed['scheme'])) {
            $host = $parsed['scheme'] . '://' . $parsed['host'];
            if (isset($parsed['port'])) {
                $host .= ':' . $parsed['port'];
            }
        }

        return [$uri, $host];
    }

    /**
     * Make the API call
     *
     * @param string $uri
     * @param string $method
     * @param string|null $data
     * @param DocumentstoreUser $user
     * @param string|null $host
     * @param array $headers
     * @param int $timeout
     * @param int $maxTime
     * @param bool $verbose
     * @param string $tempToken
     * @return array
     */
    protected function makeApiCall(string $uri, string $method, ?string $data, DocumentstoreUser $user, ?string $host = null, array $headers = [], int $timeout = 30, int $maxTime = 0, bool $verbose = false, string $tempToken = null): array
    {
        // Use the provided temp token
        if (!$tempToken) {
            $userData = $user->getRawUser();
            $tempToken = $this->generateTempToken($userData);
        }

        $startTime = microtime(true);

        // Create a request object
        $request = Request::create($uri, $method);

        // Set the host in the server parameters if provided
        if ($host) {
            $parsedHost = parse_url($host);
            if (isset($parsedHost['host'])) {
                // Set HTTP_HOST and SERVER_NAME
                $request->server->set('HTTP_HOST', $parsedHost['host']);
                $request->server->set('SERVER_NAME', $parsedHost['host']);

                // Set the scheme (http/https)
                if (isset($parsedHost['scheme'])) {
                    $request->server->set('HTTPS', $parsedHost['scheme'] === 'https' ? 'on' : 'off');
                    // Also set REQUEST_SCHEME
                    $request->server->set('REQUEST_SCHEME', $parsedHost['scheme']);
                }

                // Set the port if specified
                if (isset($parsedHost['port'])) {
                    $request->server->set('SERVER_PORT', $parsedHost['port']);
                }

                // Override the request's getSchemeAndHttpHost method by setting the trusted host
                $request->setTrustedHosts([$parsedHost['host']]);

                // Set the full URL in the request
                $fullUrl = $host . $uri;
                $request->headers->set('HOST', $parsedHost['host']);
                $request->server->set('HTTP_REFERER', $fullUrl);
            }
        }

        // Set the Authorization header with Bearer token
        $request->headers->set('Authorization', 'Bearer ' . $tempToken);

        // Add custom headers
        foreach ($headers as $header) {
            if (strpos($header, ':') !== false) {
                [$name, $value] = explode(':', $header, 2);
                $request->headers->set(trim($name), trim($value));
            }
        }

        if ($verbose) {
            $this->line('');
            $this->info('> ' . $method . ' ' . $uri);
            $this->info('> Host: ' . ($host ?: 'localhost'));
            $this->info('> Authorization: Bearer ' . $tempToken);

            foreach ($headers as $header) {
                $this->info('> ' . $header);
            }
        }

        // Add JSON data if provided
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $jsonData = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Invalid JSON data: " . json_last_error_msg());
            }
            $request->merge($jsonData);
            $request->headers->set('Content-Type', 'application/json');
        }

        // Set timeout if specified
        if ($maxTime > 0) {
            set_time_limit($maxTime);
        }

        if ($verbose) {
            $this->info('> Sending request...');
        }

        // Dispatch the request through Laravel's router
        $response = app()->handle($request);

        // Get response content
        $content = $response->getContent();
        $statusCode = $response->getStatusCode();
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2); // milliseconds

        if ($verbose) {
            $this->info('< Response received in ' . $duration . 'ms');
            $this->info('< Status: ' . $statusCode);
            foreach ($response->headers->all() as $name => $values) {
                $this->info('< ' . $name . ': ' . implode(', ', $values));
            }
        }

        // Try to decode JSON response
        $jsonResponse = json_decode($content, true);

        return [
            'status_code' => $statusCode,
            'headers' => $response->headers->all(),
            'content' => $jsonResponse !== null ? $jsonResponse : $content,
            'raw_content' => $content,
            'is_json' => $jsonResponse !== null,
            'duration_ms' => $duration,
            'response' => $response,
        ];
    }

    /**
     * Display the API response
     *
     * @param array $response
     * @param bool $noColor
     * @return void
     */
    protected function displayResponse(array $response, bool $noColor = false): void
    {
        // Display status code and timing
        $statusColor = $response['status_code'] >= 200 && $response['status_code'] < 300 ? 'info' : 'error';
        $this->line('');
        $this->{$statusColor}("Status Code: {$response['status_code']}");

        if (isset($response['duration_ms'])) {
            $this->info("Response Time: {$response['duration_ms']}ms");
        }

        // Display headers (only important ones)
        $importantHeaders = ['content-type', 'content-length', 'cache-control', 'accept-ranges', 'content-disposition'];
        $this->line('');
        $this->line('<comment>Response Headers:</comment>');
        foreach ($importantHeaders as $header) {
            if (isset($response['headers'][$header])) {
                $value = is_array($response['headers'][$header]) ? implode(', ', $response['headers'][$header]) : $response['headers'][$header];
                $this->line("  {$header}: {$value}");
            }
        }

        // Show response size
        if (isset($response['raw_content'])) {
            $size = strlen($response['raw_content']);
            $this->info("Response Size: " . $this->formatBytes($size));
        }

        // Display response body
        $this->line('');
        $this->line('<comment>Response Body:</comment>');

        if ($response['is_json']) {
            $jsonOutput = json_encode($response['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($noColor) {
                $this->line($jsonOutput);
            } else {
                $this->displayColoredJson($jsonOutput);
            }
        } else {
            $this->line($response['content']);
        }
    }

    /**
     * Display JSON with syntax highlighting
     *
     * @param string $json
     * @return void
     */
    protected function displayColoredJson(string $json): void
    {
        $lines = explode("\n", $json);

        foreach ($lines as $line) {
            $coloredLine = $this->colorizeJsonLine($line);
            $this->line($coloredLine);
        }
    }

    /**
     * Colorize a JSON line
     *
     * @param string $line
     * @return string
     */
    protected function colorizeJsonLine(string $line): string
    {
        // Color keys (strings before colons)
        $line = preg_replace('/"([^"]+)"\s*:/', '<fg=cyan>"$1"</>', $line);

        // Color string values
        $line = preg_replace('/:\s*"([^"]*)"/', ': <fg=green>"$1"</>', $line);

        // Color numbers
        $line = preg_replace('/:\s*(\d+\.?\d*)/', ': <fg=yellow>$1</>', $line);

        // Color booleans
        $line = preg_replace('/:\s*(true|false)/', ': <fg=magenta>$1</>', $line);

        // Color null
        $line = preg_replace('/:\s*(null)/', ': <fg=red>$1</>', $line);

        // Color brackets and braces
        $line = preg_replace('/([{}\[\]])/', '<fg=white>$1</>', $line);

        return $line;
    }

    /**
     * Generate a temporary token for a user
     *
     * @param DocumentstoreUser $user
     * @return string
     */
    protected function generateTempTokenForUser(DocumentstoreUser $user): string
    {
        $userData = $user->getRawUser();
        return $this->generateTempToken($userData);
    }

    /**
     * Handle file download
     *
     * @param array $response
     * @param string $filename
     * @return void
     */
    protected function handleDownload(array $response, string $filename): void
    {
        if ($response['status_code'] >= 200 && $response['status_code'] < 300) {
            $content = $response['raw_content'];
            $size = strlen($content);

            if (file_put_contents($filename, $content)) {
                $this->info("Downloaded successfully: {$filename}");
                $this->info("File size: " . $this->formatBytes($size));
                $this->info("Duration: {$response['duration_ms']}ms");

                // Show content type if available
                if (isset($response['headers']['content-type'])) {
                    $contentType = is_array($response['headers']['content-type']) ? $response['headers']['content-type'][0] : $response['headers']['content-type'];
                    $this->info("Content-Type: {$contentType}");
                }
            } else {
                $this->error("Failed to save file: {$filename}");
            }
        } else {
            $this->error("Download failed with status code: {$response['status_code']}");
            $this->displayResponse($response, false);
        }
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @return string
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Generate a temporary Sanctum token for API authentication
     *
     * @param array $userData
     * @return string
     */
    protected function generateTempToken(array $userData): string
    {
        // Create or find a User model instance to generate the token
        $user = User::firstOrCreate(
            ['id' => $userData['id']],
            [
                'name' => $userData['name'] ?? 'API User',
                'email' => $userData['email'] ?? 'api@example.com',
                'role' => $userData['role'] ?? 'user',
                'password' => bcrypt('temp-password'), // Temporary password
            ]
        );

        // Generate a temporary token
        $token = $user->createToken('api-service-client-temp', ['*'], now()->addMinutes(5));

        return $token->plainTextToken;
    }

    /**
     * Clean up the temporary token after use
     *
     * @param string $plainTextToken
     * @return void
     */
    protected function cleanupTempToken(string $plainTextToken): void
    {
        $hashedToken = hash('sha256', $plainTextToken);
        PersonalAccessToken::where('token', $hashedToken)
            ->where('name', 'api-service-client-temp')
            ->delete();
    }

    /**
     * Generate equivalent curl command
     *
     * @param string $uri
     * @param string $method
     * @param string|null $data
     * @param array $headers
     * @param string $tempToken
     * @param string|null $host
     * @return string
     */
    protected function generateCurlCommand(string $uri, string $method, ?string $data, array $headers, string $tempToken, ?string $host = null): string
    {
        $curl = 'curl';

        // Add method
        if ($method !== 'GET') {
            $curl .= " -X {$method}";
        }

        // Build full URL
        $fullUrl = $host ? $host . $uri : url($uri);

        $curl .= " -H 'Authorization: Bearer {$tempToken}'";

        // Add custom headers
        foreach ($headers as $header) {
            if (strpos($header, ':') !== false) {
                $curl .= " -H '{$header}'";
            }
        }

        // Add content-type header for POST/PUT/PATCH with data
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $curl .= " -H 'Content-Type: application/json'";

            // Add data
            $escapedData = addcslashes($data, "'");
            $curl .= " -d '{$escapedData}'";
        }

        // Add URL (quote it to handle special characters)
        $curl .= " '{$fullUrl}'";

        return $curl;
    }
}
