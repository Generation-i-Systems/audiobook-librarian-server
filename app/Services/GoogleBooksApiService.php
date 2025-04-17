<?php

namespace App\Services;

use GuzzleHttp\Client;

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
        $response = $this->client->request('GET', 'volumes', [
            'query' => [
                'q' => $query,
                'maxResults' => $maxResults,
                'key' => $this->apiKey
            ],
        ]);

        $body = $response->getBody()->getContents();
        return json_decode($body, true); // Decode as associative array
    }

    public function getBookDetails(string $volumeId)
    {
        $response = $this->client->request('GET', "volumes/{$volumeId}", [
             'query' => [
                'key' => $this->apiKey
            ],
        ]);

        $body = $response->getBody()->getContents();
        return json_decode($body, true);
    }
}
