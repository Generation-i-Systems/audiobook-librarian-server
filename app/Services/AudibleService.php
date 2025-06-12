<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudibleService extends BaseBookService
{
    /**
     * @var \Psr\Log\LoggerInterface|null
     */
    protected $customLogger = null;

    /**
     * @var string
     */
    protected string $logLevel = 'info';

    /**
     * Set a custom logger for this instance.
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger($logger): void
    {
        $this->customLogger = $logger;
    }

    /**
     * Set the log level for this instance.
     * @param string $level
     */
    public function setLogLevel(string $level): void
    {
        $this->logLevel = $level;
    }

    /**
     * Get the logger for this instance.
     * @return \Psr\Log\LoggerInterface
     */
    protected function getLogger()
    {
        return $this->customLogger ?: Log::channel('stack');
    }

    /**
     * Log with the instance's log level.
     * @param string $message
     * @param array $context
     */
    protected function log($message, array $context = [])
    {
        $logger = $this->getLogger();
        $level = $this->logLevel;
        if (method_exists($logger, $level)) {
            $logger->$level($message, $context);
        } else {
            $logger->info($message, $context);
        }
    }

    /**
     * Log debug messages regardless of logLevel.
     * @param string $message
     * @param array $context
     */
    protected function logDebug($message, array $context = [])
    {
        $logger = $this->getLogger();
        if (method_exists($logger, 'debug')) {
            $logger->debug($message, $context);
        }
    }
    public int $apiCallCount = 0;

    protected string $baseUrl = 'https://api.audible.com/1.0/catalog';

    public function getServiceName(): string
    {
        return 'Audible';
    }

    protected function performSearch(string $query, array $options = []): ?array
    {
        $this->log('AudibleService: performSearch called.', ['query' => $query, 'options' => $options]);
        $this->logDebug('AudibleService: performSearch RAW QUERY', ['query' => $query, 'options' => $options]);
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

        $this->logDebug('AudibleService: performSearch RAW RESPONSE', ['body' => $response->body(), 'status' => $response->status()]);

        if (!$response->successful()) {
            $this->getLogger()->error(
                'Audible API search failed.',
                [
                    'query' => $query,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]
            );
            return null;
        }

        $products = $response->json()['products'] ?? [];
        $this->log('AudibleService: performSearch products: ' . count($products));
        $results = [];
        foreach ($products as $book) {
            $results[] = $this->transform($book);
        }
        $this->logDebug('AudibleService: performSearch results', ['results' => $results]);
        return $results;
    }

    public function performGetBookDetails(string $id): ?array
    {
        $this->apiCallCount++;
        $response = Http::timeout(15)->get($this->baseUrl . '/products/' . $id, [
            'response_groups' => 'product_attrs,product_desc,product_extended_attrs,series,contributors,'
                . 'media,product_images',
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
        $audibleAuthors = [];
        $audibleNarrators = [];
        $narratorsList = [];

        // Prioritize 'contributors' if present
        if (isset($book['contributors']) && is_array($book['contributors'])) {
            foreach ($book['contributors'] as $contributor) {
                if (isset($contributor['role'], $contributor['name'])) {
                    $id = $contributor['asin'] ?? md5($contributor['name']);

                    if (strtolower($contributor['role']) === 'author') {
                        $authorsData[] = [
                            'author' => [
                                'name' => $contributor['name'],
                                'id' => $id,
                            ],
                        ];
                        $audibleAuthors[$id] = $contributor['name'];
                    }
                    if (strtolower($contributor['role']) === 'narrator') {
                        $narratorsData[] = [
                            'narrator' => [
                                'name' => $contributor['name'],
                                'id' => $id,
                            ],
                        ];
                        $audibleNarrators[$id] = $contributor['name'];
                        $narratorsList[] = $contributor['name'];
                    }
                }
            }
        }

        // Fallback to direct 'authors' key if not populated from 'contributors'
        if (empty($authorsData) && isset($book['authors']) && is_array($book['authors'])) {
            foreach ($book['authors'] as $author) {
                if (isset($author['name'])) {
                    $id = $author['asin'] ?? md5($author['name']);
                    $authorsData[] = ['author' => ['name' => $author['name'], 'id' => $id]];
                    $audibleAuthors[$id] = $author['name'];
                }
            }
        }

        // Fallback to direct 'narrators' key if not populated from 'contributors'
        if (empty($narratorsData) && isset($book['narrators']) && is_array($book['narrators'])) {
            foreach ($book['narrators'] as $narrator) {
                if (isset($narrator['name'])) {
                    $id = $narrator['asin'] ?? md5($narrator['name']);
                    $narratorsData[] = ['narrator' => ['name' => $narrator['name'], 'id' => $id]];
                    $audibleNarrators[$id] = $narrator['name'];
                    $narratorsList[] = $narrator['name'];
                }
            }
        }

        // Cover image: Check common locations from 'media' or 'product_images' response groups
        $coverUrl = $book['media']['source_url'] ??
            $book['product_images']['500'] ??
            $book['media']['image']['url'] ??
            $book['image_url'] ?? // A common fallback key
            null;

        // Format series data as {seriesName: seriesNumber}
        $series = null;
        if (!empty($book['series']) && is_array($book['series']) && isset($book['series'][0])) {
            $firstSeries = $book['series'][0]; // Assuming the first series is the primary one
            $seriesName = $firstSeries['title'] ?? null;
            $seriesNumber = $firstSeries['sequence'] ?? ($firstSeries['part'] ?? null);

            if ($seriesName) {
                $series = [$seriesName => $seriesNumber];
            }
        }

        // Clean description - strip HTML tags
        $description = $book['merchandising_summary'] ?? $book['publisher_summary'] ?? null;
        if ($description) {
            // Remove HTML tags
            $description = strip_tags($description);
            // Remove leading <p> tags
            $description = preg_replace('/^<p>/', '', $description);            // Trim whitespace
            $description = trim($description);
        }

        return [
            'source' => $this->getServiceName(),
            'id' => $book['asin'] ?? null,
            'title' => $book['title'] ?? null,
            'audibleAuthors' => $audibleAuthors,
            'audibleNarrators' => $audibleNarrators,
            'narrator' => array_values($audibleNarrators),
            'audibleCoverImageUrl' => $coverUrl,
            'description' => $description,
            'series' => $series,
            'releaseDate' => $book['release_date'] ?? null,
            'runtime' => isset($book['runtime_length_min']) ? round($book['runtime_length_min']) : null,
            'publisher' => $book['publisher_name'] ?? null,
            'language' => $book['language'] ?? null,
        ];
    }

    /**
     * Search for a book and merge the results with the existing book data
     *
     * @param array $book The existing book data
     * @return array|null The merged book data or null if no match found
     */
    public function searchAndMerge(array $book): ?array
    {
        $query = $book['title'] ?? '';
        $author = $book['author'] ?? '';

        if (empty($query)) {
            Log::warning('AudibleService: Empty query for searchAndMerge');
            return null;
        }

        $options = [];
        if (!empty($author)) {
            $options['author'] = $author;
        }

        $results = $this->performSearch($query, $options);

        if (empty($results)) {
            return null;
        }

        // Use the first result as the best match
        $bestMatch = $results[0];

        // Download cover image if available
        if (!empty($bestMatch['audibleCoverImageUrl']) && !empty($bestMatch['id'])) {
            $coverPath = $this->downloadCoverImage(
                $bestMatch['audibleCoverImageUrl'],
                $bestMatch['id']
            );

            if ($coverPath) {
                $bestMatch['audibleCoverPath'] = $coverPath;
            }
        }

        // Use the audibleAuthors if available
        if (!empty($bestMatch['audibleAuthors']) && is_array($bestMatch['audibleAuthors'])) {
            // Already in the right format
        } elseif (!empty($bestMatch['authorsMap']) && is_array($bestMatch['authorsMap'])) {
            $bestMatch['audibleAuthors'] = $bestMatch['authorsMap'];
        } elseif (!empty($bestMatch['authors']) && is_array($bestMatch['authors'])) {
            // Fallback to extracting from authors array
            $audibleAuthors = [];
            foreach ($bestMatch['authors'] as $authorData) {
                if (isset($authorData['author']['name'], $authorData['author']['id'])) {
                    $id = $authorData['author']['id'];
                    $audibleAuthors[$id] = $authorData['author']['name'];
                }
            }
            if (!empty($audibleAuthors)) {
                $bestMatch['audibleAuthors'] = $audibleAuthors;
            }
        }

        // Use the audibleNarrators if available
        if (!empty($bestMatch['audibleNarrators']) && is_array($bestMatch['audibleNarrators'])) {
            // Already in the right format
            $bestMatch['narrator'] = array_values($bestMatch['audibleNarrators']);
        } elseif (!empty($bestMatch['narratorsMap']) && is_array($bestMatch['narratorsMap'])) {
            $bestMatch['audibleNarrators'] = $bestMatch['narratorsMap'];
            $bestMatch['narrator'] = array_values($bestMatch['narratorsMap']);
        } elseif (!empty($bestMatch['narrators']) && is_array($bestMatch['narrators'])) {
            // Fallback to extracting from narrators array
            $audibleNarrators = [];
            foreach ($bestMatch['narrators'] as $narratorData) {
                if (isset($narratorData['narrator']['name'], $narratorData['narrator']['id'])) {
                    $id = $narratorData['narrator']['id'];
                    $name = $narratorData['narrator']['name'];
                    $audibleNarrators[$id] = $name;
                }
            }
            if (!empty($audibleNarrators)) {
                $bestMatch['audibleNarrators'] = $audibleNarrators;
                $bestMatch['narrator'] = array_values($audibleNarrators);
            }
        }

        // Format series information as {seriesName: seriesNumber}
        if (!empty($bestMatch['series'])) {
            if (is_array($bestMatch['series']) && isset($bestMatch['series']['name'])) {
                $seriesName = $bestMatch['series']['name'];
                $seriesNumber = $bestMatch['series']['part'];
                $bestMatch['series'] = [$seriesName => $seriesNumber];
            }
        }

        // Simplify publisher
        if (isset($bestMatch['publisher']) && is_array($bestMatch['publisher'])) {
            if (isset($bestMatch['publisher']['name'])) {
                $bestMatch['publisher'] = $bestMatch['publisher']['name'];
            }
        }

        return $bestMatch;
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
