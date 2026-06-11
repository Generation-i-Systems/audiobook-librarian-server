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

    protected string $logLevel = 'info';

    /**
     * Set a custom logger for this instance.
     *
     * @param  \Psr\Log\LoggerInterface  $logger
     */
    public function setLogger($logger): void
    {
        $this->customLogger = $logger;
    }

    /**
     * Set the log level for this instance.
     */
    public function setLogLevel(string $level): void
    {
        $this->logLevel = $level;
    }

    /**
     * Get the logger for this instance.
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function getLogger()
    {
        return $this->customLogger ?: Log::channel('stack');
    }

    /**
     * Log with the instance's log level.
     *
     * @param  string  $message
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
     *
     * @param  string  $message
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

    /**
     * Search for books with filtering and fallback logic
     *
     * @param  string|null  $title  The title to search for
     * @param  string|null  $author  The author to filter by
     * @param  array  $options  Additional options for the search
     * @return array The search results after filtering
     */
    public function searchBooksWithFiltering(?string $title, ?string $author = null, array $options = []): array
    {
        $this->log('AudibleService: searchBooksWithFiltering called', [
            'title' => $title,
            'author' => $author,
            'options' => $options,
        ]);

        $limit = $options['limit'] ?? 10;
        $results = [];

        // Build the initial search query
        if ($title) {
            $query = $title;
            $searchOptions = ['limit' => $limit];

            // If we have an author, include it in the options
            if ($author) {
                $searchOptions['author'] = $author;
                $this->log('AudibleService: Including author in search options', [
                    'query' => $query,
                    'author' => $author,
                    'options' => $searchOptions,
                ]);
            }

            $searchResults = $this->searchBooks($query, $searchOptions);

            // If we have an author, filter the results to match the author more precisely
            if ($author && $searchResults && is_array($searchResults)) {
                $filteredResults = [];
                foreach ($searchResults as $book) {
                    // Check if the book's author contains our search author (case-insensitive)
                    if (
                        isset($book['author']) &&
                        ((is_string($book['author']) && stripos($book['author'], $author) !== false) ||
                            (is_array($book['author']) && $this->authorArrayContains($book['author'], $author)))
                    ) {
                        $filteredResults[] = $book;
                    }
                }

                // If we found matches with the author filter, use those
                if (!empty($filteredResults)) {
                    $searchResults = $filteredResults;
                }
                // Otherwise, fall back to the original results (better than no results)
            }

            // If we still don't have results, try a different approach as a fallback
            if (empty($searchResults) && $author) {
                $this->log('AudibleService: First search returned no results, trying fallback search');
                // Try a more general search with just the title but still pass author in options
                $searchResults = $this->searchBooks($title, [
                    'limit' => $limit,
                    'author' => $author,
                ]);
            }

            if ($searchResults && is_array($searchResults)) {
                $results = $searchResults;
            }
        }

        $this->log('AudibleService: searchBooksWithFiltering results: ' . count($results) . ' items');

        return $results;
    }

    /**
     * Helper method to check if an author name exists in an array of authors
     *
     * @param  array  $authorArray  The array of authors to search in
     * @param  string  $authorName  The author name to search for
     * @return bool Whether the author name exists in the array
     */
    protected function authorArrayContains(array $authorArray, string $authorName): bool
    {
        foreach ($authorArray as $author) {
            if (stripos($author, $authorName) !== false) {
                return true;
            }
        }

        return false;
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
            'response_groups' => 'product_attrs,product_desc,product_extended_attrs,category_ladders,series,contributors,media',
        ];

        if (!empty($authorFromOptions)) {
            $params['author'] = $authorFromOptions;
        }

        $response = Http::timeout(15)->get($requestUrl, $params);
        Log::debug(
            'AudibleService: performSearch RAW REQUEST',
            ['fullUrl' => $requestUrl . '?' . http_build_query($params)]
        );

        $this->logDebug('AudibleService: performSearch RAW RESPONSE', [
            'body' => $response->body(),
            'status' => $response->status(),
        ]);

        if (!$response->successful()) {
            $this->getLogger()->error(
                'Audible API search failed.',
                [
                    'query' => $query,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]
            );

            // Tests expect an array (empty) to indicate no results on API failure
            return [];
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
            'response_groups' => 'product_attrs,product_desc,product_extended_attrs,category_ladders,series,contributors,media,' .
                'product_images',
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

    public function transform(array $book): array
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
        $coverUrl = $book['media']['source_url'] ?? $book['product_images']['500'] ?? $book['media']['image']['url'] ?? $book['image_url'] ?? // A common fallback key
            null;

        // Format series data as {seriesName: seriesNumber}
        $series = null;
        $seriesName = '';
        $seriesNumber = '';
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
            $description = preg_replace('/^<p>/', '', $description);
            // Trim whitespace
            $description = trim($description);
        }

        // Ensure we have the source field
        $result['source'] = $this->getServiceName();

        // Set the title field
        if (isset($book['title'])) {
            $result['title'] = $book['title'];
        }

        // Keep the ID as 'id' instead of renaming to 'audibleId'
        if (isset($book['asin'])) {
            $result['id'] = $book['asin'];
            // Remove the original asin field to avoid duplication
            unset($result['asin']);
        }

        // Ensure we have a properly named cover image URL
        $result['coverImageUrl'] = $coverUrl;
        unset($result['image_url'], $result['audibleCoverImageUrl']);

        // Ensure we have a properly formatted published year
        if (isset($book['release_date'])) {
            $result['publishedYear'] = substr($book['release_date'], 0, 4);
            unset($result['release_date']);
        }

        // Format author array
        if (!empty($audibleAuthors)) {
            $result['author'] = array_values($audibleAuthors);
        } else {
            $result['author'] = [];
        }

        // Set authors field with structured data
        $result['authors'] = $authorsData;

        // Ensure narrators is properly formatted - provide both formats for compatibility
        $result['narrators'] = $narratorsData;  // Complex structure for Feature tests
        $result['narratorsList'] = $narratorsList;  // Simple array for Unit tests and display

        // Ensure series is properly formatted
        $result['seriesName'] = $seriesName;
        $result['seriesNumber'] = $seriesNumber;
        $result['series'] = $seriesName; // For compatibility with Google Books format

        // Extract categories from category_ladders (Audible API format), falling back to genre/categories.
        // Collect every rung name from every ladder — mapToValidGenreList expands compound labels
        // like "Science Fiction & Fantasy" into ["Science Fiction", "Fantasy"] and the enrichment
        // service discards anything that maps to a weak genre.
        if (!empty($book['category_ladders']) && is_array($book['category_ladders'])) {
            $categoryNames = [];
            foreach ($book['category_ladders'] as $ladder) {
                if (!empty($ladder['ladder']) && is_array($ladder['ladder'])) {
                    foreach ($ladder['ladder'] as $rung) {
                        if (!empty($rung['name'])) {
                            $categoryNames[] = $rung['name'];
                        }
                    }
                }
            }
            $result['category'] = array_values(array_unique($categoryNames));
        } elseif (isset($book['genre'])) {
            if (is_string($book['genre'])) {
                $result['category'] = [$book['genre']];
            } elseif (is_array($book['genre'])) {
                $result['category'] = $book['genre'];
            } else {
                $result['category'] = [];
            }
        } elseif (isset($book['categories'])) {
            $result['category'] = $book['categories'];
        } else {
            $result['category'] = [];
        }

        // Format publisher - prioritize structured data over publisher_name
        if (isset($book['publisher']) && is_array($book['publisher'])) {
            $result['publisher'] = $book['publisher'];
        } elseif (isset($book['publisher_name'])) {
            if (is_string($book['publisher_name'])) {
                $result['publisher'] = ['name' => $book['publisher_name']];
            } elseif (is_array($book['publisher_name'])) {
                $result['publisher'] = $book['publisher_name'];
            } else {
                $result['publisher'] = [];
            }
        } else {
            $result['publisher'] = [];
        }

        // Add description if not already set
        if (!isset($result['description'])) {
            $result['description'] = $description;
        }

        // Include runtime length in minutes if provided by API
        if (isset($book['runtimeLengthMin'])) {
            $result['runtimeLengthMin'] = (int) $book['runtimeLengthMin'];
            $result['runtime'] = (int) $book['runtimeLengthMin'];
        } elseif (isset($book['runtime_length_min'])) {
            $result['runtimeLengthMin'] = (int) $book['runtime_length_min'];
            $result['runtime'] = (int) $book['runtime_length_min'];
        }

        // Include language if present
        if (isset($book['language'])) {
            $result['language'] = $book['language'];
        } elseif (isset($book['language_locale'])) {
            $result['language'] = $book['language_locale'];
        }

        // Convert all keys to camelCase for consistency
        return $this->convertKeysToCamelCase($result);
    }

    /**
     * Search for a book and merge the results with the existing book data
     *
     * @param  array  $book  The existing book data
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

    /**
     * Convert all keys in an array to camelCase.
     *
     * @param  array  $array  The array with keys to convert
     * @return array Array with camelCase keys
     */
    private function convertKeysToCamelCase(array $array): array
    {
        $camelCaseResult = [];
        foreach ($array as $key => $value) {
            // Convert snake_case to camelCase
            $camelKey = preg_replace_callback('/_([a-z])/', function ($matches) {
                return strtoupper($matches[1]);
            }, $key);

            $camelCaseResult[$camelKey] = $value;
        }

        return $camelCaseResult;
    }
}
