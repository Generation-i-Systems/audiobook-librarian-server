<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\DocumentstoreUser;
use App\Exceptions\GalleryProxyUnavailableException;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin HTTP client used by Api\SkinController / Api\ThemeController to proxy
 * skin/theme requests to audiobook-librarian-www, which is the real source
 * of truth for this data (see the extraction plan).
 *
 * Reads are forwarded anonymously — www's read endpoints are public and
 * rate-limited on its side. Writes are forwarded with a signed
 * X-Gallery-Trust header identifying the already-authenticated server user,
 * verified by App\Auth\GalleryTrustAuthenticator on the www side. This is a
 * transitional mechanism, not a permanent design — see docs/GALLERY_MIGRATION.md.
 *
 * Auth::user() on this app can be either App\Models\User (legacy api_tokens
 * fallback path in ApiAuth) or App\Auth\DocumentstoreUser (session/Sanctum
 * paths) depending on which guard authenticated the request, so every
 * "as user" method here accepts the common Authenticatable interface.
 */
class GalleryProxyClient
{
    public function get(string $path, array $query = []): Response
    {
        return $this->guarded(fn () => Http::baseUrl($this->baseUrl())
            ->timeout(10)
            ->retry(2, 200, throw: false)
            ->get($path, $query));
    }

    public function getAsUser(string $path, array $query, Authenticatable $user): Response
    {
        return $this->guarded(fn () => Http::baseUrl($this->baseUrl())
            ->timeout(10)
            ->withHeaders(['X-Gallery-Trust' => $this->buildTrustHeader($user)])
            ->get($path, $query));
    }

    /**
     * @param  array<string,UploadedFile>  $files  field name => uploaded file
     */
    public function postAsUser(string $path, array $data, Authenticatable $user, array $files = []): Response
    {
        return $this->requestAsUser('post', $path, $data, $user, $files);
    }

    public function patchAsUser(string $path, array $data, Authenticatable $user): Response
    {
        return $this->requestAsUser('patch', $path, $data, $user);
    }

    public function deleteAsUser(string $path, Authenticatable $user): Response
    {
        return $this->requestAsUser('delete', $path, [], $user);
    }

    /**
     * @param  array<string,UploadedFile>  $files
     */
    private function requestAsUser(string $method, string $path, array $data, Authenticatable $user, array $files = []): Response
    {
        return $this->guarded(function () use ($method, $path, $data, $user, $files) {
            $client = Http::baseUrl($this->baseUrl())
                ->timeout(15)
                ->withHeaders(['X-Gallery-Trust' => $this->buildTrustHeader($user)]);

            foreach ($files as $field => $file) {
                $client = $client->attach($field, file_get_contents($file->getRealPath()), $file->getClientOriginalName());
            }

            return $client->{$method}($path, $data);
        });
    }

    /**
     * @param  \Closure(): Response  $callback
     */
    private function guarded(\Closure $callback): Response
    {
        try {
            return $callback();
        } catch (ConnectionException $e) {
            throw new GalleryProxyUnavailableException('Unable to reach the gallery service: ' . $e->getMessage(), previous: $e);
        }
    }

    private function buildTrustHeader(Authenticatable $user): string
    {
        [$id, $email, $role] = match (true) {
            $user instanceof User => [$user->id, $user->email, $user->role],
            $user instanceof DocumentstoreUser => [$user->id, $user->email, $user->role],
            default => throw new \InvalidArgumentException('Unsupported user type for gallery trust header: ' . get_class($user)),
        };

        $secret = (string) config('services.gallery_www.trust_secret');
        $payload = implode('|', [$id, $email, $role, time(), Str::random(16)]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        return base64_encode($payload) . '.' . $hmac;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.gallery_www.base_url'), '/');
    }
}
