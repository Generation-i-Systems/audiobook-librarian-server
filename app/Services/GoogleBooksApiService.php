<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class GoogleBooksApiService
{
    protected $client;
    protected $apiKey; //Optional, required for some calls

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://www.googleapis.com/books/v1/',
            'timeout'  => 5.0,
        ]);

       $this->apiKey = config('services.googlebooks.key');
    }

    public function searchBooks(string $query, int $maxResults = 5)
    {
        $cacheKey = 'google_books_search_' . md5($query . '_' . $maxResults);
        // 3 months TTL in minutes: 3 * 30 * 24 * 60 = 129600
        return Cache::remember($cacheKey, 129600, function () use ($query, $maxResults) {
            $response = $this->client->request('GET', 'volumes', [
                'query' => [
                    'q' => $query,
                    'maxResults' => $maxResults,
                    'key' => $this->apiKey
                ],
            ]);

            $body = $response->getBody()->getContents();
            return json_decode($body, true); // Decode as associative array
        });
    }

    public function getBookDetails(string $volumeId)
    {
        $cacheKey = 'google_books_details_' . md5($volumeId);
        // 3 months TTL in minutes: 129600
        return Cache::remember($cacheKey, 129600, function () use ($volumeId) {
            $response = $this->client->request('GET', "volumes/{$volumeId}", [
                 'query' => [
                    'key' => $this->apiKey
                ],
            ]);

            $body = $response->getBody()->getContents();
            return json_decode($body, true);
        });
    }
}
