<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MailConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class ApiRootController extends Controller
{
    /**
     * Get the API root document with links to available endpoints.
     */
    public function index(): JsonResponse
    {
        /** @var \Illuminate\Routing\RouteCollection $routeCollection */
        $routeCollection = Route::getRoutes();
        $routes = $routeCollection->getRoutes();
        $apiRoutes = [];
        $baseUrl = url('/');

        foreach ($routes as $route) {
            $uri = $route->uri();

            // Filter for API v1 routes
            if (!Str::startsWith($uri, 'api/v1')) {
                continue;
            }

            // Skip the root endpoint itself to avoid recursion/redundancy if desired,
            // but usually it's fine to include or skip. Let's skip the exact match if it exists.
            if ($uri === 'api/v1') {
                continue;
            }

            // Get HTTP methods
            $methods = $route->methods();
            // Remove HEAD as it's usually automatic
            $methods = array_filter($methods, fn ($m) => $m !== 'HEAD');

            // Get route name if available
            $name = $route->getName();

            // Group by resource if possible (simple heuristic)
            // e.g., api/v1/books/{book} -> resource: books
            $parts = explode('/', $uri);
            // parts[0] is api, parts[1] is v1, parts[2] is usually the resource
            $resource = $parts[2] ?? 'root';

            // Format the entry
            $apiRoutes[$resource][] = [
                'method' => array_values($methods),
                'uri' => $uri,
                'url' => $baseUrl . '/' . $uri,
                'name' => $name,
            ];
        }

        return response()->json([
            'name' => 'Librarian API',
            'version' => 'v1',
            'environment' => config('app.env'),
            'database' => config('database.default'),
            'is_devel_site' => config('database.default') === 'mysql_devel',
            'documentation' => $baseUrl . '/docs/api', // Assuming this exists or points to something useful
            'openapi' => route('api.v1.openapi'), // We'll name the existing openapi route
            'otp_available' => MailConfiguration::isMailConfigured(),
            'resources' => $apiRoutes,
        ]);
    }
}
