<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the audnex.us public API (https://audnex.us), which serves
 * cleaned-up Audible catalog data keyed by ASIN. No API key is required.
 * Unlike AudibleApiService/AudibleService, audnex has no title/author search
 * endpoint — callers must already know the ASIN (typically from an Audible
 * search) before requesting details here.
 */
class AudnexApiService
{
    protected bool $enabled;

    protected string $baseUrl;

    protected string $region;

    protected int $cacheTtl;

    public function __construct(array $config = [])
    {
        $this->enabled = $config['enabled'] ?? config('services.audnex.enabled', true);
        $this->baseUrl = rtrim($config['base_url'] ?? config('services.audnex.base_url'), '/');
        $this->region = $config['region'] ?? config('services.audnex.region', 'us');
        $this->cacheTtl = $config['cache_ttl'] ?? config('services.audnex.cache_ttl', 86400);
    }

    /**
     * Fetch normalized book data for an ASIN, or null if audnex is disabled,
     * has no record for the ASIN, or the request fails. Callers should treat
     * a null return as "fall back to the primary Audible source" — never as
     * an error condition to surface to the user.
     */
    public function getBookByAsin(string $asin, array $options = []): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $asin = trim($asin);
        if ($asin === '') {
            return null;
        }

        $region = $options['region'] ?? $this->region;
        $cacheKey = 'audnex_book_' . $asin . '_' . $region;

        // A plain Cache::remember() would cache a transient outage for the
        // full TTL, "sticking" us in fallback mode for a day. So only a
        // confirmed outcome (found, or confirmed not found) gets cached;
        // request failures and transient errors are never written to cache,
        // meaning the very next lookup retries audnex instead of assuming
        // it's still down.
        $miss = '__audnex_cache_miss__';
        $cached = Cache::get($cacheKey, $miss);
        if ($cached !== $miss) {
            return $cached === false ? null : $cached;
        }

        try {
            $response = Http::timeout(15)->get($this->baseUrl . '/books/' . $asin, [
                'region' => $region,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AudnexApiService: request failed, not caching so the next lookup retries', [
                'asin' => $asin,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->serverError() || $response->status() === 429) {
            Log::warning('AudnexApiService: transient failure, not caching so the next lookup retries', [
                'asin' => $asin,
                'status' => $response->status(),
            ]);

            return null;
        }

        if (!$response->successful()) {
            // e.g. 404 — audnex is up and has confirmed it has no record for
            // this ASIN. That's a real (cacheable) answer, not an outage.
            Log::info('AudnexApiService: no data for ASIN', [
                'asin' => $asin,
                'status' => $response->status(),
            ]);
            Cache::put($cacheKey, false, $this->cacheTtl);

            return null;
        }

        $item = $response->json();
        if (empty($item) || empty($item['asin'])) {
            Cache::put($cacheKey, false, $this->cacheTtl);

            return null;
        }

        $normalized = $this->normalize($item);
        Cache::put($cacheKey, $normalized, $this->cacheTtl);

        return $normalized;
    }

    /**
     * Normalize an audnex book payload into the same key shape produced by
     * AudibleService::transform(), so callers can array_merge() the two.
     */
    protected function normalize(array $item): array
    {
        $result = [
            'id' => $item['asin'],
            'title' => $item['title'] ?? null,
            'subtitle' => $item['subtitle'] ?? null,
            'coverImageUrl' => $item['image'] ?? null,
            'description' => $item['summary'] ?? $item['description'] ?? null,
            'publisher' => !empty($item['publisherName']) ? ['name' => $item['publisherName']] : [],
            'language' => $item['language'] ?? null,
            'runtimeLengthMin' => $item['runtimeLengthMin'] ?? null,
            'runtime' => $item['runtimeLengthMin'] ?? null,
        ];

        if (!empty($item['description'])) {
            $result['description'] = strip_tags($item['description']);
        } elseif (!empty($item['summary'])) {
            $result['description'] = strip_tags($item['summary']);
        }

        if (!empty($item['releaseDate'])) {
            $year = date('Y', strtotime($item['releaseDate']));
            if ($year && $year > 1800) {
                $result['publishedYear'] = (int) $year;
            }
        }

        $authorNames = array_values(array_filter(array_map(
            fn ($a) => $a['name'] ?? null,
            $item['authors'] ?? []
        )));
        $result['author'] = $authorNames;
        $result['authors'] = array_map(fn ($name) => ['author' => ['name' => $name]], $authorNames);

        $narratorNames = array_values(array_filter(array_map(
            fn ($n) => $n['name'] ?? null,
            $item['narrators'] ?? []
        )));
        $result['narratorsList'] = $narratorNames;
        $result['narrators'] = array_map(fn ($name) => ['narrator' => ['name' => $name]], $narratorNames);

        $seriesPrimary = $item['seriesPrimary'] ?? null;
        if (!empty($seriesPrimary['name'])) {
            $result['seriesName'] = $seriesPrimary['name'];
            $result['seriesNumber'] = $seriesPrimary['position'] ?? null;
            $result['series'] = [$seriesPrimary['name'] => $seriesPrimary['position'] ?? null];
        }

        $genreNames = array_values(array_filter(array_map(
            fn ($g) => $g['name'] ?? null,
            $item['genres'] ?? []
        )));
        if (!empty($genreNames)) {
            $result['category'] = $genreNames;
        }

        return array_filter($result, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
