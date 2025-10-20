<?php

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Model\BSONDocument;
use RuntimeException;

/**
 * @deprecated Archived: MongoService is retained for migration utilities only (e.g., MigrateMongoToMysql).
 *              Do not bind or use at runtime for the application. The active document store is MySqlService.
 */
class MongoService implements DocumentStoreServiceInterface
{
    /** @var Client */
    protected $client;

    /** @var \MongoDB\Database */
    protected $db;

    public function __construct()
    {
        // Constructor no longer connects directly. Connection is now on-demand in getCollection.
    }

    public function getCollection($name)
    {
        // Only connect if we're actually configured to use MongoDB
        if (config('documentstore.driver') !== 'mongodb') {
            // This should ideally not be reached if DocumentStoreServiceProvider is correctly configured
            // but as a fallback, throw an exception or log a critical error.
            $caller = '';
            $stackTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            foreach ($stackTrace as $trace) {
                if (isset($trace['file']) && !str_contains($trace['file'], 'MongoService.php')) {
                    $caller = ($trace['file'] ?? 'unknown') . ':' . ($trace['line'] ?? 'unknown');
                    break;
                }
            }
            SafeLoggingService::safeLog('warning', "MongoService: Attempted to get collection '{$name}' but documentstore.driver is not 'mongodb'. Called from: " . $caller);
            // throw new \RuntimeException("MongoDB service not configured.");
        }

        // Connect on demand if not already connected
        if (!$this->client || !$this->db) {
            try {
                $uri = config('mongodb.uri');
                $dbName = config('mongodb.database');

                if (!$uri || !$dbName) {
                    throw new \RuntimeException("MongoDB configuration missing. Set MONGODB_URI and MONGODB_DB environment variables.");
                }

                $this->client = new Client($uri);
                $this->db = $this->client->$dbName;
                // Attempt a simple operation to verify connection
                $this->db->command(['ping' => 1]);
                SafeLoggingService::safeLog('info', "Successfully connected to MongoDB: {$uri} / {$dbName}");
            } catch (\Exception $e) {
                SafeLoggingService::safeLog('error', "Failed to connect to MongoDB: " . $e->getMessage());
                throw new \RuntimeException("Could not connect to MongoDB: " . $e->getMessage(), 0, $e);
            }
        }

        SafeLoggingService::safeLog('debug', "MongoService: Attempting to get collection: {$name}");
        if (!$this->db) {
            SafeLoggingService::safeLog('error', "MongoService: \$this->db is null or invalid when trying to get collection {$name}");
            throw new \RuntimeException("MongoDB database object is not initialized.");
        }
        try {
            $collection = $this->db->$name;
            SafeLoggingService::safeLog('debug', "MongoService: Successfully retrieved collection: {$name}");
            return $collection;
        } catch (\Exception $e) {
            SafeLoggingService::safeLog('error', "MongoService: Error getting collection {$name}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw new \RuntimeException("Could not retrieve MongoDB collection {$name}: " . $e->getMessage(), 0, $e);
        }
    }


    /**
     * Get unique values for a specific field across all books
     *
     * @param string $field The field to get unique values for (e.g., 'genre', 'author')
     * @param string|null $subField Optional subfield for nested data (e.g., 'seriesName' when field is 'series')
     * @return array Array of unique values
     */
    public function getUniqueValues(string $field, ?string $subField = null): array
    {
        try {
            $collection = $this->getCollection('books');
            $pipeline = [];

            switch ($field) {
                case 'author':
                    $pipeline = [
                        ['$unwind' => '$authors'],
                        ['$group' => ['_id' => '$authors.name']],
                        ['$sort' => ['_id' => 1]],
                        ['$project' => ['_id' => 0, 'name' => '$_id']],
                    ];
                    break;

                case 'genre':
                    $pipeline = [
                        ['$unwind' => '$genres'],
                        ['$group' => ['_id' => '$genres.name']],
                        ['$sort' => ['_id' => 1]],
                        ['$project' => ['_id' => 0, 'name' => '$_id']],
                    ];
                    break;

                case 'series':
                    if ($subField === 'seriesName') {
                        $pipeline = [
                            ['$match' => ['series' => ['$exists' => true, '$ne' => null]]],
                            ['$unwind' => '$series'],
                            ['$group' => ['_id' => '$series.seriesName']],
                            ['$sort' => ['_id' => 1]],
                            ['$project' => ['_id' => 0, 'seriesName' => '$_id']],
                        ];
                    } else {
                        return [];
                    }
                    break;

                default:
                    return [];
            }

            $cursor = $collection->aggregate($pipeline);
            $results = [];

            foreach ($cursor as $doc) {
                $doc = (array) $doc;
                if ($field === 'series' && $subField === 'seriesName') {
                    if (isset($doc['seriesName'])) {
                        $results[] = $doc['seriesName'];
                    }
                } elseif (isset($doc['name'])) {
                    $results[] = $doc['name'];
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error("Error getting unique values for field {$field}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    // BOOKS
    /**
     * Autocomplete author names using MongoDB Atlas Search with fuzzy matching.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function autocompleteAuthors(string $query, int $limit = 10): array
    {
        $pipeline = [
            [
                '$search' => [
                    'index' => 'default',
                    'autocomplete' => [
                        'query' => $query,
                        'path' => 'name',
                        'fuzzy' => [
                            'maxEdits' => 2,
                            'prefixLength' => 1,
                            'maxExpansions' => 50,
                        ],
                    ],
                ],
            ],
            ['$limit' => $limit],
            [
                '$project' => [
                    'name' => 1,
                    '_id' => 0,
                ],
            ],
        ];
        $results = $this->getCollection('authors')->aggregate($pipeline);
        $authors = [];
        foreach ($results as $doc) {
            if (isset($doc['name'])) {
                $authors[] = $doc['name'];
            }
        }
        return $authors;
    }

    /**
     * Autocomplete narrator names using MongoDB Atlas Search with fuzzy matching.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function autocompleteNarrators(string $query, int $limit = 10): array
    {
        $pipeline = [
            [
                '$search' => [
                    'index' => 'default',
                    'autocomplete' => [
                        'query' => $query,
                        'path' => 'narrator',
                        'fuzzy' => [
                            'maxEdits' => 2,
                            'prefixLength' => 1,
                            'maxExpansions' => 50,
                        ],
                    ],
                ],
            ],
            ['$limit' => $limit],
            [
                '$project' => [
                    'narrator' => 1,
                    '_id' => 0,
                ],
            ],
        ];
        $results = $this->getCollection('books')->aggregate($pipeline);
        $narrators = [];
        foreach ($results as $doc) {
            if (isset($doc['narrator'])) {
                if (is_array($doc['narrator'])) {
                    foreach ($doc['narrator'] as $n) {
                        $narrators[] = $n;
                    }
                } else {
                    $narrators[] = $doc['narrator'];
                }
            }
        }
        return array_values(array_unique($narrators));
    }

    /**
     * Autocomplete series names using MongoDB Atlas Search with fuzzy matching.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function autocompleteSeries(string $query, int $limit = 10): array
    {
        $pipeline = [
            [
                '$search' => [
                    'index' => 'default',
                    'autocomplete' => [
                        'query' => $query,
                        'path' => 'seriesName',
                        'fuzzy' => [
                            'maxEdits' => 2,
                            'prefixLength' => 1,
                            'maxExpansions' => 50,
                        ],
                    ],
                ],
            ],
            ['$limit' => $limit],
            [
                '$project' => [
                    'seriesName' => 1,
                    '_id' => 0,
                ],
            ],
        ];
        $results = $this->getCollection('series')->aggregate($pipeline);
        $series = [];
        foreach ($results as $doc) {
            if (isset($doc['seriesName'])) {
                $series[] = $doc['seriesName'];
            }
        }
        return $series;
    }
    /** @inheritDoc */
    public function getBook(string $id)
    {
        $doc = $this->getCollection('books')->findOne(['_id' => $id]);
        if (!$doc) {
            return null;
        }
        if ($doc instanceof \MongoDB\Model\BSONDocument) {
            $doc = (array) $doc;
        }
        // Recursively normalize all BSONArray/BSONDocument to PHP arrays
        $doc = $this->normalizeMongoValue($doc);
        // Hotfix: forcibly cast genre to array if still BSONArray
        if (isset($doc['genre']) && $doc['genre'] instanceof \MongoDB\Model\BSONArray) {
            $doc['genre'] = (array) $doc['genre'];
        }
        $doc['id'] = (string) $doc['_id'];
        return $doc;
    }
    /**
     * Recursively normalize MongoDB BSONArray/BSONDocument to PHP arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeMongoValue($value)
    {
        if ($value instanceof \MongoDB\Model\BSONArray || $value instanceof \MongoDB\Model\BSONDocument) {
            $value = (array) $value;
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalizeMongoValue($v);
            }
        } elseif (is_array($value)) {
            // Also normalize arrays that might contain BSONArray/BSONDocument objects
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalizeMongoValue($v);
            }
        }
        return $value;
    }

    /**
     * @inheritdoc
     */
    public function listBooks(int $page = 1, int $perPage = 24, array $filters = [], bool $withRelated = true, string $sort = 'title', string $order = 'asc'): array
    {
        // Validate order direction
        $order = in_array(strtolower($order), ['asc', 'desc']) ? strtolower($order) : 'asc';

        $collection = $this->getCollection('books');
        SafeLoggingService::safeLog('debug', "MongoService: Querying collection: 'books'");

        // Build query filters
        $query = [];

        // Apply filters
        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query['$or'] = [
                ['title' => new \MongoDB\BSON\Regex(preg_quote($searchTerm), 'i')],
                ['description' => new \MongoDB\BSON\Regex(preg_quote($searchTerm), 'i')],
                ['authors.name' => new \MongoDB\BSON\Regex(preg_quote($searchTerm), 'i')],
                ['narrators.name' => new \MongoDB\BSON\Regex(preg_quote($searchTerm), 'i')],
                ['series.name' => new \MongoDB\BSON\Regex(preg_quote($searchTerm), 'i')],
            ];
        }

        if (!empty($filters['title'])) {
            $query['title'] = new \MongoDB\BSON\Regex(preg_quote($filters['title']), 'i');
        }

        if (!empty($filters['author'])) {
            $query['authors.name'] = new \MongoDB\BSON\Regex(preg_quote($filters['author']), 'i');
        }

        if (!empty($filters['genre'])) {
            $query['genres.name'] = $filters['genre'];
        }

        if (!empty($filters['series'])) {
            $query['series.name'] = new \MongoDB\BSON\Regex(preg_quote($filters['series']), 'i');
        }

        if (!empty($filters['publication_date'])) {
            // Assuming release_date is stored as a string or date object
            $year = (int) $filters['publication_date'];
            $query['release_date'] = [
                '$gte' => new \MongoDB\BSON\UTCDateTime(strtotime("{$year}-01-01") * 1000),
                '$lt' => new \MongoDB\BSON\UTCDateTime(strtotime("{$year}+1-01-01") * 1000),
            ];
        }

        if (!empty($filters['date_added'])) {
            // Handle 'recent' as a special keyword
            if ($filters['date_added'] === 'recent') {
                // Use the same logic as getRecentBooks - default to 30 days
                $days = 30;
                $dateThreshold = time() - ($days * 24 * 60 * 60);
                $query['created_at'] = [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateThreshold * 1000)
                ];
            } else {
                // Handle as a specific date
                try {
                    $date = new \DateTime($filters['date_added']);
                    $query['created_at'] = [
                        '$gte' => new \MongoDB\BSON\UTCDateTime($date->getTimestamp() * 1000),
                        '$lt' => new \MongoDB\BSON\UTCDateTime(($date->getTimestamp() + 86400) * 1000), // Add 1 day
                    ];
                } catch (\Exception $e) {
                    // Log invalid date format
                    \Illuminate\Support\Facades\Log::warning("Invalid date format for date_added filter: {$filters['date_added']}");
                }
            }
        }

        SafeLoggingService::safeLog('debug', "MongoService: Query filters: " . json_encode($query));

        // Count total matching documents
        $total = $collection->countDocuments($query);
        SafeLoggingService::safeLog('debug', "MongoService: Total documents found for query: {$total}");

        // Calculate pagination
        $skip = ($page - 1) * $perPage;
        $lastPage = max(1, ceil($total / $perPage));

        // Set up options for the query
        $options = [
            'sort' => [$sort => ($order === 'asc' ? 1 : -1)],
            'skip' => $skip,
            'limit' => $perPage,
        ];

        // Execute query with pagination
        $cursor = $collection->find($query, $options);

        // Convert documents to array and transform to match OpenAPI spec
        $books = [];
        foreach ($cursor as $doc) {
            $book = $this->normalizeMongoValue($doc);
            $book['id'] = (string) $book['_id'];

            // Transform authors, narrators, genres to arrays of names
            $book['author'] = collect($book['authors'] ?? [])->pluck('name')->toArray();
            $book['narrator'] = collect($book['narrators'] ?? [])->pluck('name')->toArray();
            $book['genre'] = collect($book['genres'] ?? [])->pluck('name')->toArray();

            // Transform series
            $seriesName = null;
            $seriesNumber = null;
            if (!empty($book['series'])) {
                // Assuming series is an array of objects, take the first one
                $firstSeries = collect($book['series'])->first();
                if ($firstSeries) {
                    $seriesName = $firstSeries['name'] ?? $firstSeries['seriesName'] ?? null;
                    $seriesNumber = $firstSeries['seriesNumber'] ?? $firstSeries['pivot']['series_number'] ?? null;
                }
            }
            $book['series'] = $seriesName;
            $book['series_number'] = (string) $seriesNumber; // Ensure string type

            // Format duration to HH:MM:SS
            $book['duration'] = isset($book['duration']) ? gmdate("H:i:s", $book['duration']) : null;

            // Extract year from release_date
            $book['year'] = isset($book['release_date']) ? (int) date('Y', strtotime($book['release_date'])) : null;

            // Format cover_url to API endpoint
            $book['cover_url'] = isset($book['id']) ? url('/api/v1/books/' . $book['id'] . '/cover') : null;

            // Ensure timestamps are ISO 8601
            $book['created_at'] = isset($book['created_at']) ? (new \DateTime($book['created_at']))->format('Y-m-d\TH:i:s\Z') : null;
            $book['updated_at'] = isset($book['updated_at']) ? (new \DateTime($book['updated_at']))->format('Y-m-d\TH:i:s\Z') : null;

            // Remove internal MongoDB fields and original relationship arrays
            unset($book['_id'], $book['authors'], $book['narrators'], $book['genres'], $book['chapters'], $book['coverImage']);

            $books[] = $book;
        }
        SafeLoggingService::safeLog('debug', "MongoService: Number of books processed in loop: " . count($books));

        return [
            'data' => $books,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Get recently added books
     *
     * @param int $limit Maximum number of recent books to return
     * @param int $days Number of days to look back for recent books
     * @return array
     */
    public function getRecentBooks(int $limit = 10, int $days = 30): array
    {
        try {
            $collection = $this->getCollection('books');
            $dateThreshold = new UTCDateTime((time() - ($days * 24 * 60 * 60)) * 1000);

            $pipeline = [
                [
                    '$match' => [
                        'created_at' => [
                            '$gte' => $dateThreshold,
                        ],
                    ],
                ],
                [
                    '$sort' => ['created_at' => -1],
                ],
                [
                    '$limit' => $limit,
                ],
            ];

            $cursor = $collection->aggregate($pipeline);
            $recentBooks = [];

            foreach ($cursor as $doc) {
                $book = $this->normalizeMongoValue($doc);
                $book = $this->loadRelatedData($book);
                $recentBooks[] = $book;
            }

            return $recentBooks;
        } catch (\Exception $e) {
            Log::error('Error fetching recent books: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Load related data for a book (authors, series, etc.)
     *
     * @param array $book
     * @return array
     */
    protected function loadRelatedData(array $book): array
    {
        // Load full author objects if we only have author IDs
        if (isset($book['author_ids']) && is_array($book['author_ids'])) {
            $authorIds = array_map(function ($id) {
                return new \MongoDB\BSON\ObjectId($id);
            }, $book['author_ids']);

            $authors = $this->getCollection('authors')->find([
                '_id' => ['$in' => $authorIds],
            ]);

            $book['authors'] = [];
            foreach ($authors as $author) {
                $author = $this->normalizeMongoValue($author);
                $book['authors'][] = $author;
            }
        }

        // Ensure series is always an array of objects with seriesName
        if (isset($book['series'])) {
            if (is_string($book['series'])) {
                $book['series'] = [['seriesName' => $book['series']]];
            } elseif (is_array($book['series']) && !empty($book['series'])) {
                // Convert simple array of series names to array of objects
                if (!isset($book['series'][0]) || !is_array($book['series'][0])) {
                    $book['series'] = array_map(function ($series) {
                        return is_string($series) ? ['seriesName' => $series] : $series;
                    }, $book['series']);
                }

                // Ensure seriesName is used instead of name
                foreach ($book['series'] as &$series) {
                    if (is_array($series) && isset($series['name']) && !isset($series['seriesName'])) {
                        $series['seriesName'] = $series['name'];
                        unset($series['name']);
                    }
                }
            }
        }

        return $book;
    }
    /** @inheritDoc */
    public function createBook(array $data)
    {
        // If an ID is provided, use it as the _id field
        if (isset($data['id'])) {
            $data['_id'] = $data['id'];
            unset($data['id']); // Remove the id field since MongoDB uses _id
        }

        $result = $this->getCollection('books')->insertOne($data);
        return (string) $result->getInsertedId();
    }
    /** @inheritDoc */
    public function updateBook(string $id, array $data)
    {
        // Normalize incoming payload keys to match our Mongo schema
        // Accept both singular/plural and snake_case/camelCase variants
        $normalized = $data;

        // Authors/Genres/Narrators: prefer singular camelCase keys in storage
        if (isset($normalized['authors']) && !isset($normalized['author'])) {
            $normalized['author'] = $normalized['authors'];
            unset($normalized['authors']);
        }
        if (isset($normalized['genres']) && !isset($normalized['genre'])) {
            $normalized['genre'] = $normalized['genres'];
            unset($normalized['genres']);
        }
        if (isset($normalized['narrators']) && !isset($normalized['narrator'])) {
            $normalized['narrator'] = $normalized['narrators'];
            unset($normalized['narrators']);
        }

        // Directory path and published year
        if (isset($normalized['directory_path']) && !isset($normalized['directoryPath'])) {
            $normalized['directoryPath'] = $normalized['directory_path'];
            unset($normalized['directory_path']);
        }
        if (isset($normalized['published_year']) && !isset($normalized['publishedYear'])) {
            $normalized['publishedYear'] = $normalized['published_year'];
            unset($normalized['published_year']);
        }

        // Clean arrays: trim strings and drop empties for narrator/author/genre
        foreach (['author', 'narrator', 'genre'] as $listKey) {
            if (isset($normalized[$listKey]) && is_array($normalized[$listKey])) {
                $normalized[$listKey] = array_values(array_filter(array_map(function ($v) {
                    return is_string($v) ? trim($v) : $v;
                }, $normalized[$listKey]), function ($v) {
                    return $v !== null && $v !== '';
                }));
            }
        }

        // Series: map legacy 'name' to 'seriesName' and filter
        if (isset($normalized['series']) && is_array($normalized['series'])) {
            $seriesArr = [];
            foreach ($normalized['series'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['name']) && !isset($item['seriesName'])) {
                    $item['seriesName'] = $item['name'];
                    unset($item['name']);
                }
                // Normalize keys we expect
                $seriesName = isset($item['seriesName']) ? trim((string) $item['seriesName']) : null;
                $number = $item['number'] ?? ($item['series_number'] ?? null);
                if ($seriesName !== null && $seriesName !== '') {
                    $seriesArr[] = [
                        'seriesName' => $seriesName,
                        'number' => is_string($number) || is_numeric($number) ? (string) $number : null,
                    ];
                }
            }
            $normalized['series'] = $seriesArr;
        }

        // Build update with $set and optionally $unset to remove legacy keys
        $set = $normalized;
        $unset = [];
        // If we set singular keys, clear out plural/snake_case legacy ones
        foreach (
            [
            'authors', 'genres', 'narrators',
            'directory_path', 'published_year',
            ] as $legacyKey
        ) {
            if (array_key_exists($legacyKey, $set)) {
                // already unset above, skip
                continue;
            }
        }

        // Execute update
        $update = ['$set' => $set];
        if (!empty($unset)) {
            $update['$unset'] = array_fill_keys(array_keys($unset), '');
        }

        return $this->getCollection('books')->updateOne(['_id' => $id], $update);
    }
    /** @inheritDoc */
    public function deleteBook(string $id)
    {
        return $this->getCollection('books')->deleteOne(['_id' => $id]);
    }
    /** @inheritDoc */
    public function getBooksByAuthorAndGenre($author, $genre)
    {
        $cursor = $this->getCollection('books')->find(['author' => $author, 'genre' => $genre]);
        $books = [];
        foreach ($cursor as $doc) {
            if ($doc instanceof \MongoDB\Model\BSONDocument) {
                $doc = (array) $doc;
            }
            $doc = $this->normalizeMongoValue($doc);
            $doc['id'] = (string) $doc['_id'];
            $books[] = $doc;
        }
        return $books;
    }
    /** @inheritDoc */
    public function dumpAllBooks()
    {
        try {
            $collection = $this->getCollection('books');
            $cursor = $collection->find([]); // Find all documents
            $books = [];
            foreach ($cursor as $doc) {
                $books[] = $this->normalizeMongoValue($doc);
            }
            Log::debug("MongoService: dumpAllBooks() - Retrieved " . count($books) . " documents directly from 'books' collection.");
            return ['data' => $books, 'total' => count($books)]; // Return in the expected format for MigrateMongoToMysql
        } catch (\Exception $e) {
            Log::error('MongoService dumpAllBooks failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return ['data' => [], 'total' => 0];
        }
    }
    /** @inheritDoc */
    public static function dumpAllBooksFromCollection(string $collectionName)
    {
        throw new RuntimeException('Not implemented for static context.');
    }

    // USERS
    /** @inheritDoc */
    public function getUserById($identifier)
    {
        $doc = $this->getCollection('users')->findOne(['_id' => $identifier]);
        if (!$doc) {
            return null;
        }
        if ($doc instanceof \MongoDB\Model\BSONDocument) {
            $doc = (array) $doc;
        }
        // Normalize author if present
        if (isset($doc['author']) && $doc['author'] instanceof \MongoDB\Model\BSONArray) {
            $doc['author'] = (array) $doc['author'];
        }
        // Normalize series if present
        if (isset($doc['series']) && $doc['series'] instanceof \MongoDB\Model\BSONDocument) {
            $doc['series'] = (array) $doc['series'];
        }
        $doc['id'] = (string) $doc['_id'];
        return $doc;
    }
    /** @inheritDoc */
    public function getUserByCredentials($credentials)
    {
        $query = [];
        foreach ($credentials as $k => $v) {
            if ($k !== 'password') {
                $query[$k] = $v;
            }
        }
        $doc = $this->getCollection('users')->findOne($query);
        if (!$doc) {
            return null;
        }
        if ($doc instanceof \MongoDB\Model\BSONDocument) {
            $doc = (array) $doc;
        }
        // Normalize author if present
        if (isset($doc['author']) && $doc['author'] instanceof \MongoDB\Model\BSONArray) {
            $doc['author'] = (array) $doc['author'];
        }
        // Normalize series if present
        if (isset($doc['series']) && $doc['series'] instanceof \MongoDB\Model\BSONDocument) {
            $doc['series'] = (array) $doc['series'];
        }
        $doc['id'] = (string) $doc['_id'];
        return $doc;
    }
    /** @inheritDoc */
    public function getUserByRememberToken($identifier, $token)
    {
        $doc = $this->getCollection('users')->findOne(['_id' => $identifier, 'remember_token' => $token]);
        if (!$doc) {
            return null;
        }
        if ($doc instanceof \MongoDB\Model\BSONDocument) {
            $doc = (array) $doc;
        }
        // Normalize author if present
        if (isset($doc['author']) && $doc['author'] instanceof \MongoDB\Model\BSONArray) {
            $doc['author'] = (array) $doc['author'];
        }
        // Normalize series if present
        if (isset($doc['series']) && $doc['series'] instanceof \MongoDB\Model\BSONDocument) {
            $doc['series'] = (array) $doc['series'];
        }
        $doc['id'] = (string) $doc['_id'];
        return $doc;
    }
    /** @inheritDoc */
    public function createUser(array $data)
    {
        $result = $this->getCollection('users')->insertOne($data);
        return (string) $result->getInsertedId();
    }
    /** @inheritDoc */
    public function updateUser(string $id, array $data)
    {
        return $this->getCollection('users')->updateOne(['_id' => $id], ['$set' => $data]);
    }
    /** @inheritDoc */
    public function deleteUser(string $id)
    {
        return $this->getCollection('users')->deleteOne(['_id' => $id]);
    }

    /** @inheritDoc */
    public function getUserByEmail(string $email): ?array
    {
        $doc = $this->getCollection('users')->findOne([
            'email' => $email,
        ]);

        if (!$doc) {
            return null;
        }
        // Normalize any BSONArray/BSONDocument fields
        $doc = $this->normalizeMongoValue($doc);
        $doc['id'] = (string) $doc['_id'];
        return $doc;
    }
    public function userExistsByEmail(string $email): bool
    {
        $count = $this->getCollection('users')->countDocuments([
            'email' => $email,
        ]);

        return $count > 0;
    }

    /** @inheritDoc */
    public function userExistsByUsername(string $username): bool
    {
        $count = $this->getCollection('users')->countDocuments([
            'username' => $username,
        ]);

        return $count > 0;
    }

    /**
     * @inheritDoc
     */
    public function getUserByUsername(string $username): ?array
    {
        $doc = $this->getCollection('users')->findOne([
            'username' => $username,
        ]);

        if (!$doc) {
            return null;
        }

        $user = $this->normalizeMongoValue($doc);
        $user['id'] = (string) $user['_id'];

        return $user;
    }

    /** @inheritDoc */
    public function getAdminUsers(): array
    {
        $cursor = $this->getCollection('users')
            ->find(['role' => 'admin']);

        $admins = [];
        foreach ($cursor as $doc) {
            $admin = $this->normalizeMongoValue($doc);
            $admin['id'] = (string) $admin['_id'];
            $admins[] = $admin;
        }

        return $admins;
    }

    /** @inheritDoc */
    public function getUsersForMessaging(): array
    {
        $cursor = $this->getCollection('users')->find([], ['projection' => ['id' => 1, 'name' => 1, 'email' => 1]]);
        $users = [];
        foreach ($cursor as $doc) {
            $doc['id'] = (string) $doc['_id'];
            $users[] = $doc;
        }
        return $users;
    }

    /**
     * Get all users in the system
     *
     * @return array List of all users
     */
    public function getAllUsers(): array
    {
        try {
            $collection = $this->getCollection('users');
            try {
                $count = $collection->countDocuments([]);
                Log::debug("MongoService: getAllUsers() - Directly counted documents in 'users' collection: {$count}");
            } catch (\Exception $e) {
                Log::error("MongoService: Error counting documents in 'users' collection: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                return [];
            }

            try {
                $cursor = $collection->find([]);
            } catch (\Exception $e) {
                Log::error("MongoService: Error finding documents in 'users' collection: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                return [];
            }

            $users = [];
            foreach ($cursor as $doc) {
                $normalizedDoc = $this->normalizeMongoValue($doc);
                $normalizedDoc['id'] = (string) $doc['_id'];
                $users[] = $normalizedDoc;
            }
            return $users;
        } catch (\Exception $e) {
            // This catch block is for any other unexpected errors in the method
            Log::error('MongoService getAllUsers failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [];
        }
    }

    // GENRES
    /** @inheritDoc */
    public function createGenre(array $data)
    {
        $result = $this->getCollection('genres')->insertOne($data);
        return (string) $result->getInsertedId();
    }

    /** @inheritDoc */




    public function listGenres()
    {
        $cursor = $this->getCollection('genres')->find();
        $genres = [];
        foreach ($cursor as $doc) {
            if (isset($doc['name'])) {
                $genres[] = $doc['name'];
            }
        }
        return array_unique($genres);
    }

    public function updateGenre(string $id, array $data): bool
    {
        try {
            $result = $this->getCollection('genres')->updateOne(
                ['_id' => $id],
                ['$set' => $data]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MongoService updateGenre failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a genre by ID
     *
     * @param string $id The genre ID
     * @return array|null The genre data or null if not found
     */
    public function getGenre(string $id): ?array
    {
        try {
            // First check if this is a built-in genre from config
            $configGenres = config('genres', []);
            foreach ($configGenres as $genre) {
                if (($genre['id'] ?? '') === $id) {
                    return $genre;
                }
            }

            // If not found in config, try to get from database
            $doc = $this->getCollection('genres')->findOne(['_id' => $id]);

            if (!$doc) {
                return null;
            }

            $genre = $this->normalizeMongoValue($doc);
            $genre['id'] = (string) $doc['_id'];

            return $genre;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MongoDB getGenre failed: ' . $e->getMessage());
            return null;
        }
    }

    /** @inheritDoc */
    public function deleteGenre(string $id)
    {
        return $this->getCollection('genres')->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($id)]);
    }

    // SERIES
    /** @inheritDoc */
    public function createSeries(string $name, bool $isCollection = false): string
    {
        $data = [
            'seriesName' => $name,
            'isCollection' => $isCollection,
        ];
        $result = $this->getCollection('series')->insertOne($data);
        return (string) $result->getInsertedId();
    }

    /** @inheritDoc */
    public function updateSeries(int $id, array $data)
    {
        // MongoDB uses string IDs, convert if needed
        $result = $this->getCollection('series')->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId((string) $id)],
            ['$set' => $data]
        );
        return $result->getModifiedCount() > 0;
    }

    /** @inheritDoc */
    public function findOrCreateSeriesByName(string $name)
    {
        $doc = $this->getCollection('series')->findOne(['seriesName' => $name]);
        if ($doc) {
            if ($doc instanceof \MongoDB\Model\BSONDocument) {
                $doc = (array) $doc;
            }
            $doc['id'] = (string) $doc['_id'];
            return $doc;
        }
        $id = $this->createSeries($name);
        return ['id' => $id, 'seriesName' => $name];
    }

    /** @inheritDoc */
    public function getSeries(string $id)
    {
        $doc = $this->getCollection('series')->findOne(['_id' => new ObjectId($id)]);
        if (!$doc) {
            return null;
        }
        if ($doc instanceof \MongoDB\Model\BSONDocument) {
            $doc = (array) $doc;
        }
        $doc = $this->normalizeMongoValue($doc);
        $doc['id'] = (string) $doc['_id'];
        return $doc;
    }

    /** @inheritDoc */
    public function deleteSeries(string $id)
    {
        return $this->getCollection('series')->deleteOne(['_id' => new ObjectId($id)]);
    }

    /** @inheritDoc */
    public function listSeries(): array
    {
        $cursor = $this->getCollection('series')->find();
        $series = [];
        foreach ($cursor as $doc) {
            if ($doc instanceof \MongoDB\Model\BSONDocument) {
                $doc = (array) $doc;
            }
            $doc = $this->normalizeMongoValue($doc);
            $doc['id'] = (string) $doc['_id'];
            $series[] = $doc;
        }
        return $series;
    }

    // AUTHORS
    /** @inheritDoc */
    public function createAuthor(array $data)
    {
        $result = $this->getCollection('authors')->insertOne($data);
        return (string) $result->getInsertedId();
    }

    /** @inheritDoc */
    public function getAuthor(string $id): ?array
    {
        try {
            $doc = $this->getCollection('authors')->findOne(['_id' => new ObjectId($id)]);

            if (!$doc) {
                return null;
            }

            $doc = $this->normalizeMongoValue($doc);
            $doc['id'] = (string) $doc['_id'];

            return $doc;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** @inheritDoc */
    public function updateAuthor(string $id, array $data): bool
    {
        try {
            $result = $this->getCollection('authors')->updateOne(
                ['_id' => new ObjectId($id)],
                ['$set' => $data]
            );

            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
    /** @inheritDoc */
    public function listAuthors()
    {
        $pipeline = [
            ['$unwind' => '$author'],
            ['$group' => ['_id' => '$author']],
            ['$project' => ['_id' => 0, 'name' => '$_id']],
        ];
        $results = $this->getCollection('books')->aggregate($pipeline);
        $authors = [];
        foreach ($results as $doc) {
            if (isset($doc['name'])) {
                $authors[] = ['name' => $doc['name']];
            }
        }
        return $authors;
    }

    public function listNarrators(): array
    {
        $pipeline = [
            ['$unwind' => '$narrator'],
            ['$group' => ['_id' => '$narrator']],
            ['$project' => ['_id' => 0, 'name' => '$_id']],
        ];
        $results = $this->getCollection('books')->aggregate($pipeline);
        $narrators = [];
        foreach ($results as $doc) {
            if (isset($doc['name'])) {
                $narrators[] = ['name' => $doc['name']];
            }
        }
        return $narrators;
    }

    /** @inheritDoc */
    public function deleteAuthor(string $id): void
    {
        $this->getCollection('authors')->deleteOne(['_id' => $id]);
    }
    /**
     * @inheritDoc
     * @uses \MongoDB\BSON\Regex
     */
    public function searchAuthorsByName(string $term): array
    {
        /** @var \MongoDB\BSON\Regex $regex */
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($term), 'i');
        $cursor = $this->getCollection('authors')->find(['name' => $regex]);
        $names = [];
        foreach ($cursor as $doc) {
            if ($doc instanceof \MongoDB\Model\BSONDocument) {
                $doc = (array) $doc;
            }
            if (isset($doc['name'])) {
                $name = $doc['name'];
                if ($name instanceof \MongoDB\Model\BSONArray) {
                    $name = $this->normalizeMongoValue($name);
                }
                $names[] = $name;
            }
        }
        return array_unique($names);
    }

    /**
     * @inheritDoc
     * @uses \MongoDB\BSON\Regex
     */
    public function searchNarratorsByName(string $term): array
    {
        /** @var \MongoDB\BSON\Regex $regex */
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($term), 'i');
        $cursor = $this->getCollection('books')->find(['narrator' => $regex]);
        $names = [];
        foreach ($cursor as $doc) {
            if ($doc instanceof \MongoDB\Model\BSONDocument) {
                $doc = (array) $doc;
            }
            $doc = $this->normalizeMongoValue($doc);

            if (isset($doc['narrator'])) {
                if (is_array($doc['narrator'])) {
                    foreach ($doc['narrator'] as $narrator) {
                        if (stripos($narrator, $term) === 0) {
                            $names[] = $narrator;
                        }
                    }
                } else {
                    if (stripos($doc['narrator'], $term) === 0) {
                        $names[] = $doc['narrator'];
                    }
                }
            }
        }
        return array_unique($names);
    }

    /**
     * Search for series titles starting with a given term (matches keys in the 'series' map field).
     *
     * @param  string  $term  The search term.
     * @return array A list of unique series titles.
     */
    public function searchSeriesByName(string $term): array
    {
        if (empty($term)) {
            return [];
        }
        // Use aggregation to filter by series key prefix on the server side
        $pipeline = [
            [
                '$project' => [
                    'seriesKeys' => [
                        '$objectToArray' => '$series',
                    ],
                ],
            ],
            [
                '$unwind' => '$seriesKeys',
            ],
            [
                '$match' => [
                    'seriesKeys.k' => [
                        '$regex' => '^' . preg_quote($term),
                        '$options' => 'i',
                    ],
                ],
            ],
            [
                '$group' => [
                    '_id' => null,
                    'uniqueKeys' => [
                        '$addToSet' => '$seriesKeys.k',
                    ],
                ],
            ],
        ];
        $result = $this->getCollection('series')->aggregate($pipeline)->toArray();
        if (isset($result[0]['uniqueKeys'])) {
            $uniqueKeys = $result[0]['uniqueKeys'];
            if ($uniqueKeys instanceof \MongoDB\Model\BSONArray) {
                $uniqueKeys = $this->normalizeMongoValue($uniqueKeys);
            }
            return array_values($uniqueKeys);
        }
        return [];
    }

    // MESSAGES
    /** @inheritDoc */
    public function createMessage(array $messageData): ?string
    {
        $result = $this->getCollection('messages')->insertOne($messageData);
        return (string) $result->getInsertedId();
    }

    /**
     * @inheritDoc
     */
    public function createApiToken(array $tokenData): ?string
    {
        // Ensure timestamps are in the correct format for MongoDB
        if (isset($tokenData['created_at']) && $tokenData['created_at'] instanceof \DateTime) {
            $tokenData['created_at'] = new UTCDateTime($tokenData['created_at']);
        }

        if (isset($tokenData['expires_at']) && $tokenData['expires_at'] instanceof \DateTime) {
            $tokenData['expires_at'] = new UTCDateTime($tokenData['expires_at']);
        }

        $result = $this->getCollection('api_tokens')->insertOne($tokenData);
        return (string) $result->getInsertedId();
    }

    /**
     * @inheritDoc
     */
    public function deleteApiTokenByValue(string $tokenValue): bool
    {
        $result = $this->getCollection('api_tokens')->deleteMany([
            'token' => $tokenValue,
        ]);

        return $result->getDeletedCount() > 0;
    }
    /** @inheritDoc */
    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        $filter = [];
        if ($userId) {
            $filter['to_user_id'] = $userId;
            if (!$includeAcknowledged) {
                $filter['acknowledged'] = ['$ne' => true];
            }
        }

        $options = [
            'sort' => ['created_at' => -1],
            'limit' => $limit,
        ];

        $cursor = $this->getCollection('messages')->find($filter, $options);
        $messages = [];

        foreach ($cursor as $doc) {
            $messages[] = $this->normalizeMongoValue($doc);
        }

        return $messages;
    }

    /**
     * Check if a follow relationship exists
     *
     * @param string $userId The ID of the user who is following
     * @param string $followableType Type of the entity being followed (e.g., 'author', 'series')
     * @param string $followableId ID of the entity being followed
     * @return bool True if the follow relationship exists
     */
    public function followExists(string $userId, string $followableType, string $followableId): bool
    {
        try {
            $filter = [
                'user_id' => $userId,
                'followable_type' => $followableType,
                'followable_id' => $followableId,
            ];

            $count = $this->getCollection('follows')->countDocuments($filter);
            return $count > 0;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error checking if follow exists: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a follow relationship
     *
     * @param string $userId The ID of the user who is following
     * @param string $followableType Type of the entity being followed (e.g., 'author', 'series')
     * @param string $followableId ID of the entity being followed
     * @return bool True if the follow was created successfully
     */
    public function createFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            $result = $this->getCollection('follows')->insertOne([
                'user_id' => $userId,
                'followable_type' => $followableType,
                'followable_id' => $followableId,
                'created_at' => new UTCDateTime(),
                'updated_at' => new UTCDateTime(),
            ]);

            return $result->isAcknowledged() && $result->getInsertedCount() === 1;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error creating follow: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a follow relationship
     *
     * @param string $userId The ID of the user who is following
     * @param string $followableType Type of the entity being followed (e.g., 'author', 'series')
     * @param string $followableId ID of the entity being followed
     * @return bool True if the follow was deleted successfully
     */
    public function deleteFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            $filter = [
                'user_id' => $userId,
                'followable_type' => $followableType,
                'followable_id' => $followableId,
            ];

            $result = $this->getCollection('follows')->deleteMany($filter);
            return $result->isAcknowledged() && $result->getDeletedCount() > 0;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error deleting follow: ' . $e->getMessage());
            return false;
        }
    }

    // JOBS
    /**
     * List jobs with filtering and pagination
     *
     * @param string|null $type Filter by job type
     * @param string|null $status Filter by status
     * @param int $limit Maximum number of results
     * @param string $orderBy Field to order by
     * @param string $direction Order direction ('ASC' or 'DESC')
     * @param string|null $startAfterId Start after specific job ID for pagination
     * @return array List of jobs with their data
     */
    public function listJobs(
        ?string $type = null,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'updated_at',
        string $direction = 'DESC',
        ?string $startAfterId = null
    ): array {
        $filter = [];
        if ($type) {
            $filter['type'] = $type;
        }
        if ($status) {
            $filter['status'] = $status;
        }
        $options = [
            'sort' => [$orderBy => ($direction === 'DESC' ? -1 : 1)],
            'limit' => $limit,
        ];
        if ($startAfterId) {
            $filter['_id'] = ['$gt' => $startAfterId];
        }
        $cursor = $this->getCollection('jobs')->find($filter, $options);
        $jobs = [];
        foreach ($cursor as $doc) {
            $doc['id'] = (string) $doc['_id'];
            $jobs[] = $doc;
        }
        return $jobs;
    }
    /** @inheritDoc */
    public function deleteJob(string $jobId): bool
    {
        $result = $this->getCollection('jobs')->deleteOne(['_id' => $jobId]);
        return $result->getDeletedCount() > 0;
    }

    // READING PROGRESS
    /**
     * Reset reading progress for a user and book.
     *
     * @param string $userId
     * @param string $bookId
     * @return bool Success status
     */
    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        try {
            $result = $this->getCollection('reading_progress')->deleteMany([
                'user_id' => $userId,
                'book_id' => $bookId,
            ]);
            return $result->getDeletedCount() > 0;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to reset reading progress (MongoDB)', [
                'userId' => $userId,
                'bookId' => $bookId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // QUEUES
    /** @inheritDoc */
    public function getBookQueue(string $userId): array
    {
        $user = $this->getUserById($userId);
        return $user['queue'] ?? [];
    }

    /** @inheritDoc */
    public function addBookToQueue(string $userId, string $bookId): bool
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            return false;
        }

        $queue = $user['queue'] ?? [];

        // Check if book is already in queue
        foreach ($queue as $item) {
            if ($item['book_id'] === $bookId) {
                return true; // Book already in queue
            }
        }

        // Add book to queue
        $queue[] = [
            'book_id' => $bookId,
            'added_at' => time(),
            'order' => count($queue) + 1,
        ];

        // Update user document
        $result = $this->getCollection('users')->updateOne(
            ['_id' => new ObjectId($userId)],
            ['$set' => ['queue' => $queue]]
        );

        return $result->getModifiedCount() > 0;
    }

    /** @inheritDoc */
    public function removeBookFromQueue(string $userId, string $bookId): bool
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            return false;
        }

        $queue = $user['queue'] ?? [];
        $newQueue = [];
        $found = false;

        // Filter out the book to remove
        foreach ($queue as $item) {
            if ($item['book_id'] !== $bookId) {
                $newQueue[] = $item;
            } else {
                $found = true;
            }
        }

        if (!$found) {
            return true; // Book wasn't in queue
        }

        // Reorder remaining books
        foreach ($newQueue as $index => $item) {
            $newQueue[$index]['order'] = $index + 1;
        }

        // Update user document
        $result = $this->getCollection('users')->updateOne(
            ['_id' => new ObjectId($userId)],
            ['$set' => ['queue' => $newQueue]]
        );

        return $result->getModifiedCount() > 0;
    }

    public function getQueueCollection($name)
    {
        return $this->getCollection($name);
    }

    // GENERIC
    /** @inheritDoc */
    public function getDocument(string $collection, string $docId): ?array
    {
        try {
            $doc = $this->getCollection($collection)->findOne(['_id' => new ObjectId($docId)]);
            if (!$doc) {
                return null;
            }
            $doc = $this->normalizeMongoValue($doc);
            $doc['id'] = (string) $doc['_id'];
            return $doc;
        } catch (\Exception $e) {
            Log::error('Failed to get document: ' . $e->getMessage());
            return null;
        }
    }

    public function updateDocument(string $collection, string $id, array $data): bool
    {
        try {
            $result = $this->getCollection($collection)->updateOne(
                ['_id' => new ObjectId($id)],
                ['$set' => $data]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            Log::error('Failed to update document: ' . $e->getMessage());
            return false;
        }
    }

    public function getClient()
    {
        return $this->client;
    }

    public function cleanupOldJobs(int $daysOld): int
    {
        try {
            $cutoffDate = new UTCDateTime(strtotime("-$daysOld days") * 1000);
            $result = $this->getCollection('jobs')->deleteMany([
                'status' => ['$in' => ['completed', 'failed', 'cancelled']],
                'updated_at' => ['$lt' => $cutoffDate],
            ]);
            return $result->getDeletedCount();
        } catch (\Exception $e) {
            Log::error('Failed to clean up old jobs: ' . $e->getMessage());
            return 0;
        }
    }

    // ACCOUNT REQUESTS

    /**
     * @inheritDoc
     */
    public function getPendingAccountRequests(): array
    {
        $cursor = $this->getCollection('account_requests')->find([
            'status' => 'pending',
        ]);

        $requests = [];
        foreach ($cursor as $doc) {
            $doc = $this->normalizeMongoValue($doc);
            $doc['id'] = (string) $doc['_id'];
            $requests[] = $doc;
        }

        return $requests;
    }

    /**
     * @inheritDoc
     */
    public function getAccountRequest(string $id): ?array
    {
        $doc = $this->getCollection('account_requests')->findOne([
            '_id' => new ObjectId($id),
        ]);

        if (!$doc) {
            return null;
        }

        $doc = $this->normalizeMongoValue($doc);
        $doc['id'] = (string) $doc['_id'];

        return $doc;
    }

    /**
     * @inheritDoc
     */
    public function approveAccountRequest(string $id): bool
    {
        $request = $this->getAccountRequest($id);

        if (!$request) {
            return false;
        }

        // Create a new user from the request data
        $userData = [
            'name' => $request['name'],
            'email' => $request['email'],
            'password' => $request['password'], // Password already hashed
            'role' => 'user',
        ];

        $this->createUser($userData);

        // Update request status to approved
        $result = $this->getCollection('account_requests')->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => ['status' => 'approved']]
        );

        return $result->getModifiedCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function rejectAccountRequest(string $id): bool
    {
        $result = $this->getCollection('account_requests')->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => ['status' => 'rejected']]
        );

        return $result->getModifiedCount() > 0;
    }

    // BOOKMARKS
    /** @inheritDoc */
    public function getBookmarks(string $userId, string $bookId): array
    {
        $cursor = $this->getCollection('bookmarks')->find([
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);

        $bookmarks = [];
        foreach ($cursor as $doc) {
            $doc = $this->normalizeMongoValue($doc);
            $doc['id'] = (string) $doc['_id'];
            $bookmarks[] = $doc;
        }

        return $bookmarks;
    }

    /** @inheritDoc */
    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array
    {
        $doc = $this->getCollection('bookmarks')->findOne([
            '_id' => $bookmarkId,
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);

        if (!$doc) {
            return null;
        }

        $doc = $this->normalizeMongoValue($doc);
        $doc['id'] = (string) $doc['_id'];

        return $doc;
    }

    /** @inheritDoc */
    public function createBookmark(array $data): string
    {
        $result = $this->getCollection('bookmarks')->insertOne($data);
        return (string) $result->getInsertedId();
    }

    /** @inheritDoc */
    public function updateBookmark(string $bookmarkId, array $data): bool
    {
        $result = $this->getCollection('bookmarks')->updateOne(
            ['_id' => $bookmarkId],
            ['$set' => $data]
        );

        return $result->getModifiedCount() > 0;
    }

    /** @inheritDoc */
    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool
    {
        $result = $this->getCollection('bookmarks')->deleteOne([
            '_id' => $bookmarkId,
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);

        return $result->getDeletedCount() > 0;
    }

    // EXTERNAL READS
    /** {@inheritDoc} */
    public function getExternalReads(string $userId, string $bookId): array
    {
        $cursor = $this->getCollection('external_reads')->find([
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);

        $reads = [];
        foreach ($cursor as $doc) {
            $doc = $this->normalizeMongoValue($doc);
            $doc['id'] = (string) $doc['_id'];
            $reads[] = $doc;
        }

        return $reads;
    }

    /** {@inheritDoc} */
    public function getExternalRead(string $externalReadId, string $userId, string $bookId): ?array
    {
        $doc = $this->getCollection('external_reads')->findOne([
            '_id' => $externalReadId,
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);

        if (! $doc) {
            return null;
        }

        $doc = $this->normalizeMongoValue($doc);
        $doc['id'] = (string) $doc['_id'];

        return $doc;
    }

    /** {@inheritDoc} */
    public function createExternalRead(array $data): string
    {
        $result = $this->getCollection('external_reads')->insertOne($data);

        return (string) $result->getInsertedId();
    }

    /** {@inheritDoc} */
    public function updateExternalRead(string $externalReadId, array $data): bool
    {
        $result = $this->getCollection('external_reads')->updateOne(
            ['_id' => $externalReadId],
            ['$set' => $data]
        );

        return $result->getModifiedCount() > 0;
    }

    /** {@inheritDoc} */
    public function deleteExternalRead(string $externalReadId, string $userId, string $bookId): bool
    {
        $result = $this->getCollection('external_reads')->deleteOne([
            '_id' => $externalReadId,
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);

        return $result->getDeletedCount() > 0;
    }

    // SERIES BOOKS
    public function getBooksInSeries(string $seriesId): array
    {
        // TODO: Implement getBooksInSeries() method.
        return [];
    }

    /**
     * @inheritDoc
     */
    public function findOrCreateMany(string $collection, array $names): array
    {
        $collectionHandle = $this->getCollection($collection);
        $ids = [];

        foreach ($names as $name) {
            $trimmedName = trim($name);
            if (empty($trimmedName)) {
                continue;
            }

            $document = $collectionHandle->findOne(['name' => $trimmedName]);

            if ($document) {
                $ids[] = (string) $document['_id'];
            } else {
                $result = $collectionHandle->insertOne(['name' => $trimmedName]);
                $ids[] = (string) $result->getInsertedId();
            }
        }

        return $ids;
    }

    /**
     * Get the manifest of contents for a book download
     *
     * @param string $bookId
     * @return array
     */
    public function getManifestForBook(string $bookId): array
    {
        // TODO: Implement getManifestForBook() method.
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getSeriesByName(string $name): ?array
    {
        $doc = $this->getCollection('series')->findOne(['seriesName' => $name]);

        if (!$doc) {
            return null;
        }

        return $this->normalizeMongoValue($doc);
    }

    /**
     * @inheritDoc
     */
    public function isAdmin(string $userId): bool
    {
        $user = $this->getUserById($userId);
        return $user && ($user['is_admin'] ?? false);
    }

    public function validateUserCredentials($user, array $credentials): bool
    {
        if (!isset($credentials['password'])) {
            return false;
        }

        // Handle DocumentstoreUser object
        if ($user instanceof \App\Auth\DocumentstoreUser) {
            $password = $user->getAuthPassword();
            return $password && Hash::check($credentials['password'], $password);
        }

        // If $user is an array (from getUserByCredentials), get the user by ID
        if (is_array($user)) {
            // If we have an _id, try to get the full user record
            if (isset($user['_id'])) {
                $user = $this->getUserById($user['_id']);
                if (!$user) {
                    return false;
                }
            }
            // Check the password directly from the array
            if (isset($user['password'])) {
                return Hash::check($credentials['password'], $user['password']);
            }
        }

        return false;
    }

    public function updateRememberToken(string $identifier, string $token): void
    {
        $this->getCollection('users')->updateOne(
            ['_id' => $identifier],
            ['$set' => ['remember_token' => $token]]
        );
    }

    /**
     * @inheritDoc
     */
    public function getJobs(): array
    {
        $cursor = $this->getCollection('jobs')->find([]);
        $jobs = [];

        foreach ($cursor as $job) {
            $jobs[] = $this->normalizeMongoValue($job);
        }

        return $jobs;
    }

    /**
     * @inheritDoc
     */
    public function getJobCount(): int
    {
        return $this->getCollection('jobs')->countDocuments([]);
    }

    /**
     * @inheritDoc
     */
    public function clearJobs(): bool
    {
        $result = $this->getCollection('jobs')->deleteMany([]);
        return $result->getDeletedCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function jobExistsByDirectoryPath(string $directoryPath): bool
    {
        $count = $this->getCollection('jobs')->countDocuments([
            'directory_path' => $directoryPath,
        ]);

        return $count > 0;
    }

    /**
     * @inheritDoc
     */
    public function bookExistsByDirectoryPath(string $directoryPath): bool
    {
        $count = $this->getCollection('books')->countDocuments([
            'directory_path' => $directoryPath,
        ]);

        return $count > 0;
    }

    /**
     * Get a job by ID
     *
     * @param string $jobId The job ID
     * @return array|null The job data or null if not found
     */
    public function getJob(string $jobId): ?array
    {
        try {
            $job = $this->getCollection('jobs')->findOne(['_id' => new ObjectId($jobId)]);
            if ($job) {
                $job = $this->normalizeMongoValue($job);
                $job['id'] = (string) $job['_id'];
                return $job;
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get job: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update a job
     *
     * @param string $jobId The job ID
     * @param array $data The updated job data
     * @return bool Success status
     */
    public function updateJob(string $jobId, array $data): bool
    {
        try {
            $result = $this->getCollection('jobs')->updateOne(
                ['_id' => new ObjectId($jobId)],
                ['$set' => $data]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            Log::error('Failed to update job: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Search for genres by name
     *
     * @param  string  $term  The search term.
     * @return array A list of unique genre names.
     */
    public function searchGenres(string $term): array
    {
        if (empty($term)) {
            return [];
        }

        try {
            $collection = $this->getCollection('books');

            // Use aggregation to find genres that match the search term
            $pipeline = [
                ['$unwind' => '$genre'],
                [
                    '$match' => [
                        'genre' => new \MongoDB\BSON\Regex($term, 'i'),
                    ],
                ],
                ['$group' => ['_id' => '$genre']],
                ['$sort' => ['_id' => 1]],
                ['$limit' => 20],
            ];

            $cursor = $collection->aggregate($pipeline);
            $genres = [];

            foreach ($cursor as $doc) {
                $genres[] = $doc['_id'];
            }

            return $genres;
        } catch (\Exception $e) {
            Log::error('Failed to search genres: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Deprecated backend: return empty results for needs review listings.
     * Kept only to satisfy DocumentStoreServiceInterface for legacy tools.
     *
     * @param string|null $reason
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function listNeedsReviewBooks(?string $reason = null, int $limit = 100, int $page = 1): array
    {
        // Mongo backend is deprecated and not queried by the app; return empty set.
        return [];
    }

    /**
     * Count books needing review, optionally filtered by reason.
     *
     * @param string|null $reason
     * @return int
     */
    public function countNeedsReviewBooks(?string $reason = null): int
    {
        // Mongo backend is deprecated and not queried by the app; return 0.
        return 0;
    }

    /**
     * Return distinct needs_review reasons across all flagged books.
     *
     * @return array
     */
    public function listNeedsReviewReasons(): array
    {
        // Mongo backend is deprecated and not queried by the app; return empty set.
        return [];
    }

    /**
     * Rename a series across all books
     *
     * @param string $oldName
     * @param string $newName
     * @return int Number of books updated
     */
    public function renameSeries(string $oldName, string $newName): int
    {
        // Mongo backend is deprecated and not queried by the app; return 0.
        return 0;
    }

    /**
     * Backward-compatible implementation to satisfy the interface.
     * Delegates to searchGenres().
     */
    public function searchGenresByName(string $term): array
    {
        return $this->searchGenres($term);
    }
}
