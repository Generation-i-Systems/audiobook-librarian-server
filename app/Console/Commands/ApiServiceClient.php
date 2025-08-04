<?php

namespace App\Console\Commands;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Helper\Table;

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
                            {--method=GET : HTTP method to use}
                            {--data= : JSON data to send with the request}
                            {--no-color : Disable colored output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Service client to make API calls as a specific user or admin';

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
        $noColor = $this->option('no-color');

        try {
            // Get the user to impersonate
            $user = $this->getUser($userId);
            if (!$user) {
                $this->error('Could not find user to impersonate');
                return 1;
            }

            $this->info("Making {$method} request as user: {$user->name} ({$user->getAuthIdentifier()})");

            // Parse the URL
            $uri = $this->parseUrl($url);
            $this->info("Request URI: {$uri}");

            // Make the API call
            $response = $this->makeApiCall($uri, $method, $data, $user);

            // Display the response
            $this->displayResponse($response, $noColor);

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
     * Parse URL to extract URI
     *
     * @param string $url
     * @return string
     */
    protected function parseUrl(string $url): string
    {
        // If it's already a URI (starts with /), return as is
        if (str_starts_with($url, '/')) {
            return $url;
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

        return $uri;
    }

    /**
     * Make the API call
     *
     * @param string $uri
     * @param string $method
     * @param string|null $data
     * @param DocumentstoreUser $user
     * @return array
     */
    protected function makeApiCall(string $uri, string $method, ?string $data, DocumentstoreUser $user): array
    {
        // Set up authentication
        Auth::setUser($user);

        // Create a request object
        $request = Request::create($uri, $method);

        // Add JSON data if provided
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $jsonData = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Invalid JSON data: " . json_last_error_msg());
            }
            $request->merge($jsonData);
            $request->headers->set('Content-Type', 'application/json');
        }

        // Set the authenticated user in the request
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        // Dispatch the request through Laravel's router
        $response = app()->handle($request);

        // Get response content
        $content = $response->getContent();
        $statusCode = $response->getStatusCode();

        // Try to decode JSON response
        $jsonResponse = json_decode($content, true);

        return [
            'status_code' => $statusCode,
            'headers' => $response->headers->all(),
            'content' => $jsonResponse !== null ? $jsonResponse : $content,
            'is_json' => $jsonResponse !== null
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
        // Display status code
        $statusColor = $response['status_code'] >= 200 && $response['status_code'] < 300 ? 'info' : 'error';
        $this->line('');
        $this->{$statusColor}("Status Code: {$response['status_code']}");

        // Display headers (only important ones)
        $importantHeaders = ['content-type', 'content-length', 'cache-control'];
        $this->line('');
        $this->line('<comment>Response Headers:</comment>');
        foreach ($importantHeaders as $header) {
            if (isset($response['headers'][$header])) {
                $value = is_array($response['headers'][$header]) ? implode(', ', $response['headers'][$header]) : $response['headers'][$header];
                $this->line("  {$header}: {$value}");
            }
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
}
