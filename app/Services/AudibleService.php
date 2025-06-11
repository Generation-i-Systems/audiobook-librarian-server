<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudibleService extends BaseBookService
{
    public int $apiCallCount = 0;

    protected string $baseUrl = 'https://api.audible.com/1.0/catalog';

    public function getServiceName(): string
    {
        return 'Audible';
    }

    protected function performSearch(string $query, array $options = []): ?array
    {
        Log::info(
            'AudibleService: performSearch called.',
            ['query' => $query, 'options' => $options]
        );

        $this->apiCallCount++;
        $requestUrl = $this->baseUrl . '/products';

        $titleFromQuery = $query;
        $authorFromOptions = $options['author'] ?? null;

        // If author is not explicitly provided in options, try to parse from the query string
        if (empty($authorFromOptions) && stripos($titleFromQuery, ' by ') !== false) {
            if (preg_match('/^(.*?)\s+by\s+(.+)$/i', $titleFromQuery, $matches)) {
                $titleFromQuery = trim($matches[1]);
                $authorFromOptions = trim($matches[2]);
            }
        }

        $params = [
            'title' => $titleFromQuery,
            'response_groups' => 'product_attrs,product_desc,product_extended_attrs,series,contributors,media',
        ];

        if (!empty($authorFromOptions)) {
            $params['author'] = $authorFromOptions;
        }

        $response = Http::timeout(15)->get($requestUrl, $params);

        if (!$response->successful()) {
            Log::error(
                'Audible API search failed.',
                [
                    'query' => $query,
                    'status' => $response->status(),
                ]
            );

            return null;
        }

        $products = $response->json()['products'] ?? [];
        $results = [];
        foreach ($products as $book) {
            $results[] = $this->transform($book);
        }

        return $results;
    }

    public function performGetBookDetails(string $id): ?array
    {
        $this->apiCallCount++;
        $response = Http::timeout(15)->get($this->baseUrl . '/products/' . $id, [
            'response_groups' => 'product_attrs,product_desc,product_extended_attrs,series,contributors,media,product_images',
        ]);

        if (!$response->successful()) {
            Log::error(
                'Audible API get book details failed.',
                ['id' => $id, 'status' => $response->status()]
            );

            return null;
        }

        $product = $response->json()['product'] ?? null;
        if (!$product) {
            return null;
        }

        return $this->transform($product);
    }

    public function downloadCoverImage(string $imageUrl, string $asin, string $subDirectoryPrefix = 'covers'): ?string
    {
        if (empty($imageUrl) || empty($asin)) {
            Log::warning(
                'AudibleService: downloadCoverImage called with empty imageUrl or asin.',
                compact('imageUrl', 'asin')
            );

            return null;
        }

        try {
            $response = Http::timeout(15)->get($imageUrl);

            if (!$response->successful()) {
                Log::error('AudibleService: HTTP error while downloading image.', [
                    'url' => $imageUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $imageContents = $response->body();
            $extension = $this->getImageExtension($response->header('Content-Type'), $imageUrl);

            $fileName = $asin . '.' . $extension;
            $fullDirectoryPath = ltrim(rtrim($subDirectoryPrefix, '/'), '/');
            $filePath = ($fullDirectoryPath ? $fullDirectoryPath . '/' . $fileName : $fileName);

            if ($fullDirectoryPath && !Storage::disk('public')->exists($fullDirectoryPath)) {
                Storage::disk('public')->makeDirectory($fullDirectoryPath);
            }

            if (Storage::disk('public')->put($filePath, $imageContents)) {
                Log::debug('AudibleService: Cover image downloaded successfully.');
                Log::info('AudibleService: Cover image downloaded successfully.', ['path' => $filePath]);

                return $filePath;
            }

            Log::error('AudibleService: Failed to save image to storage.', ['path' => $filePath]);

            return null;
        } catch (\Exception $e) {
            Log::error('AudibleService: Exception during downloadCoverImage.', [
                'message' => $e->getMessage(),
                'trace' => Str::limit($e->getTraceAsString(), 1000),
                'url' => $imageUrl,
            ]);

            return null;
        }
    }

    private function transform(array $book): array
    {
        $authorsData = [];
        $narratorsData = [];

        // Prioritize 'contributors' if present
        if (isset($book['contributors']) && is_array($book['contributors'])) {
            foreach ($book['contributors'] as $contributor) {
                if (isset($contributor['role'], $contributor['name'])) {
                    if (strtolower($contributor['role']) === 'author') {
                        $authorsData[] = [
                            'author' => [
                                'name' => $contributor['name'],
                                'id' => $contributor['asin'] ?? null,
                            ],
                        ];
                    }
                    if (strtolower($contributor['role']) === 'narrator') {
                        $narratorsData[] = [
                            'narrator' => [
                                'name' => $contributor['name'],
                                'id' => $contributor['asin'] ?? null,
                            ],
                        ];
                    }
                }
            }
        }

        // Fallback to direct 'authors' key if not populated from 'contributors'
        if (empty($authorsData) && isset($book['authors']) && is_array($book['authors'])) {
            foreach ($book['authors'] as $author) {
                if (isset($author['name'])) {
                    $authorsData[] = ['author' => ['name' => $author['name'], 'id' => $author['asin'] ?? null]];
                }
            }
        }

        // Fallback to direct 'narrators' key if not populated from 'contributors'
        if (empty($narratorsData) && isset($book['narrators']) && is_array($book['narrators'])) {
            foreach ($book['narrators'] as $narrator) {
                if (isset($narrator['name'])) {
                    // Direct 'narrators' key from API log did not show 'asin'
                    $narratorsData[] = ['narrator' => ['name' => $narrator['name'], 'id' => $narrator['asin'] ?? null]];
                }
            }
        }

        // Cover image: Check common locations from 'media' or 'product_images' response groups
        $coverUrl = $book['media']['source_url'] ??
            $book['product_images']['500'] ??
            $book['media']['image']['url'] ??
            $book['image_url'] ?? // A common fallback key
            null;

        $seriesInfo = null;
        if (!empty($book['series']) && is_array($book['series']) && isset($book['series'][0])) {
            $firstSeries = $book['series'][0]; // Assuming the first series is the primary one
            $seriesInfo = [
                'name' => $firstSeries['title'] ?? null,
                'part' => $firstSeries['sequence'] ?? ($firstSeries['part'] ?? null),
            ];
        }

        return [
            'source' => $this->getServiceName(),
            'id' => $book['asin'] ?? null,
            'title' => $book['title'] ?? null,
            'authors' => $authorsData,
            'narrators' => $narratorsData,
            'cover_image_url' => $coverUrl,
            'description' => $book['merchandising_summary'] ?? $book['publisher_summary'] ?? null,
            'series' => $seriesInfo,
            'release_date' => $book['release_date'] ?? null,
            'runtime' => $book['runtime_length_min'] ?? null,
            'publisher' => ['name' => $book['publisher_name'] ?? null],
            'language' => $book['language'] ?? null,
        ];
    }

    private function getImageExtension(?string $contentType, string $imageUrl): string
    {
        if ($contentType) {
            if (Str::contains($contentType, ['jpeg', 'jpg'])) {
                return 'jpg';
            }
            if (Str::contains($contentType, 'png')) {
                return 'png';
            }
            if (Str::contains($contentType, 'gif')) {
                return 'gif';
            }
            if (Str::contains($contentType, 'webp')) {
                return 'webp';
            }
        }

        $pathInfo = pathinfo(parse_url($imageUrl, PHP_URL_PATH));
        $extension = strtolower($pathInfo['extension'] ?? '');
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if ($extension && in_array($extension, $allowedExtensions)) {
            return $extension;
        }

        Log::warning(
            'AudibleService: Could not determine image extension, defaulting to jpg.',
            ['url' => $imageUrl, 'contentType' => $contentType]
        );

        return 'jpg';
    }
}
