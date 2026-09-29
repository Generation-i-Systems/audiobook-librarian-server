<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Imports\ImportIdempotencyKey;
use App\Services\Imports\ImportApiException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Database-backed Idempotency-Key handling for imports.v1 POST actions.
 *
 * A retried request with the same key and body replays the stored successful
 * response instead of acting twice. The same key with a different body is an
 * error rather than a silent replay.
 */
class RequireImportIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[0-9a-fA-F-]{16,64}$/', $key) !== 1) {
            return ImportApiException::validation(
                'The request is missing a valid Idempotency-Key header.'
            )->render();
        }

        $userId = (int) $request->user()?->id;
        $routeKey = $request->method() . ' ' . ($request->route()?->uri() ?? $request->path())
            . ' ' . implode(',', array_map('strval', $request->route()?->parameters() ?? []));
        $requestHash = hash('sha256', $request->getContent());

        $existing = ImportIdempotencyKey::query()
            ->where('owner_user_id', $userId)
            ->where('route_key', $routeKey)
            ->where('idempotency_key', $key)
            ->where('expires_at', '>', now())
            ->first();
        if ($existing !== null) {
            if (!hash_equals($existing->request_hash, $requestHash)) {
                return (new ImportApiException(
                    422,
                    'idempotency_key_reused',
                    'This request key was already used for a different request.'
                ))->render();
            }

            return response($existing->response_body, $existing->response_status, array_merge(
                $existing->response_headers ?? [],
                ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']
            ));
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->remember($userId, $routeKey, $key, $requestHash, $response);
        }

        return $response;
    }

    private function remember(int $userId, string $routeKey, string $key, string $requestHash, Response $response): void
    {
        $headers = [];
        $etag = $response->headers->get('ETag');
        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        try {
            ImportIdempotencyKey::query()->updateOrCreate(
                ['owner_user_id' => $userId, 'route_key' => $routeKey, 'idempotency_key' => $key],
                [
                    'request_hash' => $requestHash,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => (string) $response->getContent(),
                    'response_headers' => $headers,
                    'expires_at' => now()->addHours((int) config('import_drafts.idempotency_ttl_hours')),
                ]
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent retry stored the same key first; its stored response wins.
        }
    }
}
