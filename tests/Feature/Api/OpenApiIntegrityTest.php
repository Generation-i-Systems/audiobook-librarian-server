<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiIntegrityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spec = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return \Generator<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function operations(): \Generator
    {
        foreach ($this->spec['paths'] as $path => $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (isset($item[$method])) {
                    yield [strtoupper($method), $path, $item[$method]];
                }
            }
        }
    }

    /**
     * @return array<string, \Illuminate\Routing\Route>
     */
    private function routesBySpecKey(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() !== 'api/v1' && !str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = '/' . ltrim(substr(preg_replace('/\{(\w+)\?\}/', '{$1}', $route->uri()), 7), '/');
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes["{$method} {$path}"] = $route;
            }
        }

        return $routes;
    }

    public function testEveryRefResolves(): void
    {
        $unresolved = [];
        $walk = function (mixed $node, string $where) use (&$walk, &$unresolved): void {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['$ref']) && is_string($node['$ref'])) {
                $target = $this->spec;
                foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                    $target = is_array($target) ? ($target[$segment] ?? null) : null;
                }
                if ($target === null) {
                    $unresolved[$node['$ref']][] = $where;
                }
            }
            foreach ($node as $key => $child) {
                $walk($child, "{$where}/{$key}");
            }
        };
        $walk($this->spec, '');

        $this->assertSame([], array_map(fn (array $w): string => $w[0], $unresolved));
    }

    public function testOperationIdsAreUniqueAndPresent(): void
    {
        $ids = [];
        foreach ($this->operations() as [$method, $path, $op]) {
            $this->assertNotEmpty($op['operationId'] ?? null, "{$method} {$path} has no operationId");
            $ids[$op['operationId']][] = "{$method} {$path}";
        }

        $this->assertSame([], array_filter($ids, fn (array $ops): bool => count($ops) > 1));
    }

    public function testTagNamesAreUnique(): void
    {
        $names = array_column($this->spec['tags'], 'name');

        $this->assertSame($names, array_values(array_unique($names)));
    }

    public function testEveryDocumentedOperationHasARouteWithMatchingPathParameters(): void
    {
        $routes = $this->routesBySpecKey();
        $problems = [];
        foreach ($this->operations() as [$method, $path, $op]) {
            if (!isset($routes["{$method} {$path}"])) {
                $problems[] = "{$method} {$path} has no route";
                continue;
            }
            preg_match_all('/\{(\w+)\}/', $path, $m);
            $documented = array_map(
                fn (array $p): string => $p['name'],
                array_filter($op['parameters'] ?? [], fn (array $p): bool => ($p['in'] ?? '') === 'path')
            );
            $documented = array_merge($documented, array_map(
                fn (array $p): string => $p['name'],
                array_filter($this->spec['paths'][$path]['parameters'] ?? [], fn (array $p): bool => ($p['in'] ?? '') === 'path')
            ));
            if (array_diff($m[1], $documented) !== [] || array_diff($documented, $m[1]) !== []) {
                $problems[] = "{$method} {$path} path parameters differ from the URL";
            }
        }

        $this->assertSame([], $problems);
    }

    public function testSecurityIsDeclaredExactlyOnAuthenticatedRoutes(): void
    {
        $routes = $this->routesBySpecKey();
        $problems = [];
        foreach ($this->operations() as [$method, $path, $op]) {
            $route = $routes["{$method} {$path}"] ?? null;
            if ($route === null) {
                continue;
            }
            $middleware = implode(' ', array_map('strval', $route->gatherMiddleware()));
            $routeAuthenticated = str_contains($middleware, 'api.auth') || str_contains($middleware, 'auth:');
            $specAuthenticated = !empty($op['security']);
            if ($routeAuthenticated !== $specAuthenticated) {
                $problems[] = "{$method} {$path}: route authenticated=" . var_export($routeAuthenticated, true)
                    . ' spec security=' . var_export($specAuthenticated, true);
            }
        }

        $this->assertSame([], $problems);
    }
}
