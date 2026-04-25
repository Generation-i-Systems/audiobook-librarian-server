<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ResolveLibraryProfileFromHost
{
    /**
     * Resolve per-request library profile from host and apply runtime config.
     *
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $profileName = $this->resolveProfileNameFromHeader($request);

        if ($profileName === null) {
            $host = $this->normalizeHost($request);
            $profileName = $this->resolveProfileName($host);
        }

        if ($profileName !== null) {
            $host = $this->normalizeHost($request);
            $this->applyProfileConfiguration($request, $profileName, $host);
        }

        return $next($request);
    }

    private function resolveProfileNameFromHeader(Request $request): ?string
    {
        $headerValue = $request->header('X-Library-Profile');
        if (!is_string($headerValue) || trim($headerValue) === '') {
            return null;
        }

        $name = strtolower(trim($headerValue));
        $profiles = config('library_profiles.profiles', []);
        if (!is_array($profiles) || !array_key_exists($name, $profiles)) {
            Log::warning('X-Library-Profile header specified unknown profile', ['profile' => $name]);
            return null;
        }

        return $name;
    }

    private function normalizeHost(Request $request): string
    {
        $forwardedHost = $request->header('X-Forwarded-Host');
        if (is_string($forwardedHost) && trim($forwardedHost) !== '') {
            $forwardedHosts = explode(',', $forwardedHost);
            $first = trim($forwardedHosts[0]);
            if ($first !== '') {
                return strtolower($this->stripPort($first));
            }
        }

        $hostHeader = $request->header('Host');
        if (is_string($hostHeader) && trim($hostHeader) !== '') {
            return strtolower($this->stripPort($hostHeader));
        }

        return strtolower($this->stripPort((string) $request->getHost()));
    }

    private function stripPort(string $host): string
    {
        if (str_contains($host, ':')) {
            return (string) explode(':', $host)[0];
        }

        return $host;
    }

    private function resolveProfileName(string $host): ?string
    {
        $profiles = config('library_profiles.profiles', []);
        if (!is_array($profiles) || empty($profiles)) {
            return null;
        }

        foreach ($profiles as $name => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $hosts = Arr::wrap($profile['hosts'] ?? []);
            $normalizedHosts = array_map(
                fn ($candidate): string => strtolower($this->stripPort((string) $candidate)),
                array_filter($hosts, fn ($candidate): bool => is_string($candidate) && trim($candidate) !== '')
            );

            if (in_array($host, $normalizedHosts, true)) {
                return (string) $name;
            }
        }

        $fallbackEnabled = (bool) config('library_profiles.fallback_to_default_on_unknown_host', true);
        if (!$fallbackEnabled) {
            Log::warning('No library profile matched host and fallback is disabled', ['host' => $host]);
            return null;
        }

        $defaultProfile = (string) config('library_profiles.default_profile', 'main');
        Log::warning('No library profile matched host; using default profile', [
            'host' => $host,
            'default_profile' => $defaultProfile,
        ]);

        return $defaultProfile;
    }

    private function applyProfileConfiguration(Request $request, string $profileName, string $host): void
    {
        $profile = config('library_profiles.profiles.' . $profileName, []);
        if (!is_array($profile)) {
            return;
        }

        config([
            'library_profiles.active_profile' => $profileName,
            'library_profiles.active_host' => $host,
            'library_profiles.active_source_mode' => (string) ($profile['source_mode'] ?? 'local'),
        ]);

        $request->attributes->set('library_profile', $profileName);
        $request->attributes->set('library_source_mode', (string) ($profile['source_mode'] ?? 'local'));

        $this->applyDatabaseConnection($profileName, $profile);
        $this->applyBookStorage($profile);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function applyDatabaseConnection(string $profileName, array $profile): void
    {
        $connection = (string) ($profile['database_connection'] ?? '');
        if ($connection === '') {
            return;
        }

        $configuredConnection = config('database.connections.' . $connection);
        if (!is_array($configuredConnection)) {
            Log::warning('Library profile references unknown database connection', [
                'profile' => $profileName,
                'database_connection' => $connection,
            ]);
            return;
        }

        $previousConnection = DB::getDefaultConnection();

        if ($previousConnection === $connection) {
            return;
        }

        DB::purge($previousConnection);
        DB::setDefaultConnection($connection);

        config(['database.default' => $connection]);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function applyBookStorage(array $profile): void
    {
        $bookStoragePath = (string) ($profile['book_storage_path'] ?? '');
        if ($bookStoragePath === '') {
            return;
        }

        $normalizedPath = rtrim($bookStoragePath, '/');

        config([
            'filesystems.disks.books.root' => $normalizedPath,
            'app.book_root' => $normalizedPath,
            'app.library_repair_sync_path' => $normalizedPath . '/sync',
        ]);

        Storage::forgetDisk('books');
    }
}
