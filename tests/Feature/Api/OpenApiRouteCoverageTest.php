<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class OpenApiRouteCoverageTest extends TestCase
{
    /**
     * Test that all registered API routes are present in the OpenAPI specification.
     */
    #[Test]
    public function all_api_routes_are_documented_in_openapi_spec(): void
    {
        $specPath = base_path('docs/openapi.json');
        $this->assertFileExists($specPath, 'OpenAPI specification file (docs/openapi.json) not found.');

        $spec = json_decode(file_get_contents($specPath), true);
        $documentedPaths = array_keys($spec['paths'] ?? []);

        // Get all registered routes
        /** @var \Illuminate\Routing\RouteCollection $routeCollection */
        $routeCollection = Route::getRoutes();
        $routes = $routeCollection->getRoutes();
        $missingRoutes = [];

        foreach ($routes as $route) {
            $uri = $route->uri();

            // We only care about API routes
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }

            // Normalize URI for comparison with OpenAPI spec
            // 1. Remove optional parameters like {param?} -> {param}
            // 2. In docs/openapi.json, paths are relative to the server URL (which includes /api/v1)
            // So we strip the /api/v1 prefix if it exists.

            $normalizedUri = '/' . ltrim(preg_replace('/\{(\w+)\?\}/', '{$1}', $uri), '/');

            if (str_starts_with($normalizedUri, '/api/v1')) {
                $normalizedUri = '/' . ltrim(substr($normalizedUri, 7), '/');
            } elseif (str_starts_with($normalizedUri, '/api')) {
                $normalizedUri = '/' . ltrim(substr($normalizedUri, 4), '/');
            }

            // Handle Aliases: Some routes are registered both with and without /auth prefix
            // In OpenAPI spec, they might be documented under one or the other.
            $urisToCheck = [$normalizedUri];
            if (str_starts_with($normalizedUri, '/auth/')) {
                $urisToCheck[] = '/' . substr($normalizedUri, 6);
            } elseif ($normalizedUri !== '/') {
                $urisToCheck[] = '/auth' . $normalizedUri;
            }

            // Skip internal or development routes
            if ($this->shouldSkipRoute($normalizedUri)) {
                continue;
            }

            $methods = $route->methods();
            // Filter out HEAD, as it's often automatic
            $methods = array_filter($methods, fn ($m) => $m !== 'HEAD');

            foreach ($methods as $method) {
                $methodLower = strtolower($method);

                $found = false;
                foreach ($urisToCheck as $uriToCheck) {
                    if (isset($spec['paths'][$uriToCheck][$methodLower])) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $missingRoutes[] = "[$method] $normalizedUri";
                }
            }
        }

        $this->assertEmpty($missingRoutes, "The following API routes are missing from openapi.json:\n" . implode("\n", array_unique($missingRoutes)));
    }

    /**
     * Determine if a route should be skipped for coverage checks.
     */
    protected function shouldSkipRoute(string $uri): bool
    {
        $skipPatterns = [
            '/^\/api\/user$/', // Default Sanctum user route
            '/^\/api\/debug/', // Debug routes
            '/^\/api\/health/', // Health check routes
            '/^\/api\/v1\/auth\/google/', // Auth routes often handled via redirects
            '/^\/api\/v1\/broadcasting/', // Laravel Echo
            '/^\/sanctum\/csrf-cookie/', // Sanctum
            '/^\/_\//', // Internal vendor routes
        ];

        // Also check against normalized (stripped) URI for some general skips
        $strippedUri = $uri;
        if (str_starts_with($uri, '/api/v1')) {
            $strippedUri = '/' . ltrim(substr($uri, 7), '/');
        }

        $strippedSkipPatterns = [
            '/^\/up$/', // Health check
            // Web-only AJAX routes (not part of v1 API)
            '/^\/books\/json$/',
            '/^\/books\/recent\/json$/',
            // TODO: Document these routes in openapi.json and remove from skip list
            '/^\\/book-requests/',
            '/^\\/follow/',
            '/^\\/unfollow/',
            '/^\\/recommendations/',
            '/^\\/books\\/.*\\/recommend/',
            '/^\\/badges/',
            '/^\\/download\\/remote/',
            '/^\\/books\\/.*\\/contributions/',
            '/^\\/contributions/',
            '/^\\/admin\\/contributions/',
            '/^\\/admin\\/users/',
            '/^\\/auth\\/set-initial-password/',
            '/^\\/auth\\/otp\/request/',
            '/^\\/auth\\/otp\/verify/',
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $uri)) {
                return true;
            }
        }

        foreach ($strippedSkipPatterns as $pattern) {
            if (preg_match($pattern, $strippedUri)) {
                return true;
            }
        }

        return false;
    }
}
