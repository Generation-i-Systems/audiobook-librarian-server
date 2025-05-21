<?php

namespace App\Services;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class FirestoreService
{
    /** @var FirestoreClient|null */
    protected $db;

    /** @var string */
    protected $projectId;

    /** @var bool */
    protected static $inProviderCall = false;

    // --- AUTHENTICATION METHODS ---

    public function getUserById($identifier)
    {
        try {
            if (!$this->db) {
                return null;
            }
            $snap = $this->db->collection('users')->document($identifier)->snapshot();
            if (!$snap->exists()) {
                return null;
            }
            $user = $snap->data();
            $user['id'] = $identifier;
            return $user;
        } catch (\Throwable $e) {
            Log::error('Firestore getUserById failed: ' . $e->getMessage());
            return null;
        } finally {
            self::$inProviderCall = false;
        }
    }

    /**
     * Retrieve user by remember token.
     * @param string $identifier
     * @param string $token
     * @return array|null
     */
    public function getUserByRememberToken($identifier, $token)
    {
        try {
            if (!$this->db) {
                return null;
            }
            $snap = $this->db->collection('users')->document($identifier)->snapshot();
            if (!$snap->exists()) {
                return null;
            }
            $user = $snap->data();
            $user['id'] = $identifier;
            if (($user['remember_token'] ?? null) === $token) {
                return $user;
            }
            return null;
        } catch (\Throwable $e) {
            Log::error('Firestore getUserByRememberToken failed: ' . $e->getMessage());
            return null;
        } finally {
            self::$inProviderCall = false;
        }
    }

    /**
     * Update the remember token for the user.
     * @param string $identifier
     * @param string $token
     * @return void
     */
    public function updateRememberToken($identifier, $token)
    {
        $this->db->collection('users')->document($identifier)->set([
            'remember_token' => $token,
        ], ['merge' => true]);
    }

    /**
     * Retrieve user by credentials (e.g. email).
     * @param array $credentials
     * @return array|null
     */
    public function getUserByCredentials($credentials)
    {
        try {
            // Only log which keys are being used, not values
            Log::debug('getUserByCredentials', ['credential_keys' => array_keys($credentials)]);
            if (!$this->db) {
                Log::error('getUserByCredentials: db not initialized');
                return null;
            }
            $query = $this->db->collection('users');
            foreach ($credentials as $key => $value) {
                if ($key === 'password') {
                    continue; // Never filter by password
                }
                Log::debug('getUserByCredentials: adding filter: ' . $key);
                // For username/email, fetch all users and filter in PHP for case-insensitive match
                if (in_array($key, ['username', 'email'])) {
                    $allDocs = $this->db->collection('users')->documents();
                    foreach ($allDocs as $doc) {
                        if (!$doc->exists()) {
                            continue;
                        }
                        $data = $doc->data();
                        if (isset($data[$key]) && mb_strtolower($data[$key]) === mb_strtolower($value)) {
                            $data['id'] = $doc->id();
                            return $data;
                        }
                    }
                    return null;
                } else {
                    $query = $query->where($key, '=', $value);
                }
            }
            $documents = $query->documents();
            if ($documents->size() === 0) {
                return null;
            }
            $user = $documents->rows()[0]->data();
            $user['id'] = $documents->rows()[0]->id();
            return $user;
        } catch (\Throwable $e) {
            Log::error('Firestore getUserByCredentials failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate user credentials (e.g. password).
     * @param array|object $user
     * @param array $credentials
     * @return bool
     */
    public function validateUserCredentials($user, array $credentials)
    {
        Log::debug('validateUserCredentials', ['user' => $user, 'credentials' => $credentials]);
        try {
            // FirestoreUser may be an object, so handle both
            $userArr = is_array($user) ? $user : (method_exists($user, 'getRawUser') ? $user->getRawUser() : []);
            if (!isset($userArr['password']) || !isset($credentials['password'])) {
                Log::debug('Missing password field', ['userArr' => $userArr, 'credentials' => $credentials]);
                return false;
            }
            $plain = $credentials['password'];
            $hash = $userArr['password'];
            $result = Hash::check($plain, $hash);
            Log::debug('validateUserCredentials: plain: ' . $plain);
            Log::debug('validateUserCredentials: hash: ' . $hash);
            Log::debug('Checking password', [
                'plain' => $plain,
                'hash' => $hash,
                'result' => $result,
            ]);
            Log::debug('validateUserCredentials result: ' . ($result ? 'true' : 'false'));
            return $result;
        } catch (\Throwable $e) {
            Log::error('Firestore validateUserCredentials failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getClient()
    {
        return $this->db;
    }

    public static function dumpAllUsers()
    {
        try {
            $projectId = env('FIREBASE_PROJECT_ID');
            $credentials = base_path(env('FIREBASE_CREDENTIALS'));
            $db = new \Google\Cloud\Firestore\FirestoreClient([
                'projectId' => $projectId,
                'keyFilePath' => $credentials,
            ]);
            $users = [];
            $documents = $db->collection('users')->documents();
            foreach ($documents as $doc) {
                if ($doc->exists()) {
                    $data = $doc->data();
                    $data['id'] = $doc->id();
                    $users[] = $data;
                }
            }
            return $users;
        } catch (\Throwable $e) {
            error_log('Firestore dumpAllUsers error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    public static function dumpAllBooks()
    {
        try {
            $projectId = env('FIREBASE_PROJECT_ID');
            $credentials = base_path(env('FIREBASE_CREDENTIALS'));
            $db = new \Google\Cloud\Firestore\FirestoreClient([
                'projectId' => $projectId,
                'keyFilePath' => $credentials,
            ]);
            $books = [];
            $documents = $db->collection('books')->documents();
            foreach ($documents as $doc) {
                if ($doc->exists()) {
                    $data = $doc->data();
                    $data['id'] = $doc->id();
                    $books[] = $data;
                }
            }
            return $books;
        } catch (\Throwable $e) {
            error_log('Firestore dumpAllBooks error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function __construct()
    {
        try {
            $this->projectId = env('FIREBASE_PROJECT_ID');
            $credentials = base_path(env('FIREBASE_CREDENTIALS'));
            $this->db = new \Google\Cloud\Firestore\FirestoreClient([
                'projectId' => $this->projectId,
                'keyFilePath' => $credentials,
            ]);
        } catch (\Throwable $e) {
            // Log error but do NOT trigger auth/user lookup!
            Log::error('Firestore client init failed: ' . $e->getMessage());
            $this->db = null;
        }
    }

    // USER CRUD
    public function createUser(array $data)
    {
        if (!$this->db) {
            return null;
        }
        // Default role to 'preview' if not set
        if (!isset($data['role'])) {
            $data['role'] = 'preview';
        }
        try {
            $docRef = $this->db->collection('users')->add($data);
            return $docRef->id();
        } catch (\Throwable $e) {
            Log::error('Firestore createUser failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the global genre list from config/genres.php
     * @return array
     */
    public static function listGenres()
    {
        return config('genres.list');
    }

    // BOOKS CRUD
    /**
     * Create a new book in Firestore
     * @param array $data
     * @return string|null Returns the document ID or null on failure
     */
    public function createBook(array $data): ?string
    {
        if (!$this->db) {
            Log::error('Cannot create book: Firestore client not initialized');
            return null;
        }
        try {
            $docRef = $this->db->collection('books')->add($data);
            return $docRef->id();
        } catch (\Throwable $e) {
            Log::error('Failed to create book: ' . $e->getMessage());
            return null;
        }
    }

    // REVIEWS CRUD
    /**
     * @param array $data
     * @return string
     */
    /**
     * Create a new review in Firestore
     * @param array $data
     * @return string|null Returns the document ID or null on failure
     */
    public function createReview(array $data): ?string
    {
        if (!$this->db) {
            Log::error('Cannot create review: Firestore client not initialized');
            return null;
        }
        try {
            $docRef = $this->db->collection('reviews')->add($data);
            return $docRef->id();
        } catch (\Throwable $e) {
            Log::error('Failed to create review: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all books by author and genre
     * @param string $authorId
     * @param string $genreId
     * @return array
     */
    public function getBooksByAuthorAndGenre($author, $genre)
    {
        $books = $this->listBooks();
        return array_values(array_filter($books, function ($book) use ($author, $genre) {
            return (isset($book['author']) && $book['author'] === $author)
                && (isset($book['genre']) && $book['genre'] === $genre);
        }));
    }

    public function updateBook(string $id, array $data)
    {
        $this->db->collection('books')->document($id)->set($data);
    }

    public function getBook(string $id)
    {
        $snapshot = $this->db->collection('books')->document($id)->snapshot();
        if (!$snapshot->exists()) {
            return null;
        }
        $data = $snapshot->data();
        $data['id'] = $id; // Ensure the ID is included in the returned data
        return $data;
    }

    public function deleteBook(string $id)
    {
        $this->db->collection('books')->document($id)->delete();
    }

    public function listBooks()
    {
        $documents = $this->db->collection('books')->documents();
        $books = [];
        foreach ($documents as $doc) {
            $book = $doc->data();
            $book['id'] = $doc->id();
            $books[] = $book;
        }
        return $books;
    }

    // // AUTHORS CRUD
    // /**
    //  * Finds an author by name, or creates one if not found.
    //  * @param string $name
    //  * @return array Author data including id
    //  */
    // public function findOrCreateAuthorByName(string $name)
    // {
    //     $authors = $this->db->collection('authors')->where('name', '=', $name)->documents();
    //     foreach ($authors as $doc) {
    //         if ($doc->exists()) {
    //             $author = $doc->data();
    //             $author['id'] = $doc->id();
    //             return $author;
    //         }
    //     }
    //     $id = $this->createAuthor(['name' => $name]);
    //     return [ 'id' => $id, 'name' => $name ];
    // }
    // /**
    //  * @param array $data
    //  * @return string
    //  */
    // public function createAuthor(array $data)
    // {
    //     $docRef = $this->db->collection('authors')->add($data);
    //     return $docRef->id();
    // }

    /**
     * @param string $id
     * @return array|null
     */
    public function getAuthor(string $id)
    {
        $snap = $this->db->collection('authors')->document($id)->snapshot();
        if (!$snap->exists()) {
            return null;
        }
        $author = $snap->data();
        $author['id'] = $id;
        return $author;
    }

    /**
     * Get a list of unique authors that are currently being used in books
     * @return array
     */
    public function listAuthors()
    {
        try {
            if (!$this->db) {
                return [];
            }
            $allBooks = $this->db->collection('books')->documents();
            $authors = [];
            foreach ($allBooks as $bookDoc) {
                if ($bookDoc->exists()) {
                    $bookData = $bookDoc->data();
                    $authorData = null;
                    if (isset($bookData['authors']) && is_array($bookData['authors'])) {
                        $authorData = $bookData['authors'];
                    } elseif (isset($bookData['author']) && is_array($bookData['author'])) {
                        $authorData = $bookData['author'];
                    }
                    if ($authorData) {
                        foreach ($authorData as $authorName) {
                            if (is_string($authorName) && !empty($authorName)) {
                                $authors[$authorName] = true;
                            }
                        }
                    } elseif (
                        isset($bookData['author']) &&
                        is_string($bookData['author']) &&
                        !empty($bookData['author'])
                    ) {
                        $authors[$bookData['author']] = true;
                    }
                }
            }
            $uniqueAuthors = array_keys($authors);
            sort($uniqueAuthors);
            return $uniqueAuthors;
        } catch (\Exception $e) {
            Log::error('Firestore listAuthors failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search for author names starting with a given term.
     *
     * @param string $term The search term.
     * @return array A list of unique author names.
     */
    public function searchAuthorsByName(string $term): array
    {
        if (empty($term)) {
            return [];
        }
        $termLower = strtolower($term);
        $allAuthors = $this->listAuthors(); // Leverage existing method to get all unique authors
        $matches = [];
        foreach ($allAuthors as $authorName) {
            if (stripos($authorName, $termLower) === 0) { // Case-insensitive starts-with
                $matches[] = $authorName;
            }
        }
        // Firestore might return them sorted, but ensure sorting if listAuthors changes
        // sort($matches);
        return $matches;
    }

    /**
     * @param string $id
     * @param array $data
     * @return void
     */
    public function updateAuthor(string $id, array $data): void
    {
        $this->db->collection('authors')->document($id)->set($data, ['merge' => true]);
    }

    /**
     * @param string $id
     * @return void
     */
    public function deleteAuthor(string $id): void
    {
        $this->db->collection('authors')->document($id)->delete();
    }

    // GENRES CRUD
    /**
     * @param array $data
     * @return string
     */
    public function createGenre(array $data)
    {
        $docRef = $this->db->collection('genres')->add($data);
        return $docRef->id();
    }

    // SERIES CRUD
    /**
     * Finds a series by name, or creates one if not found.
     * @param string $name
     * @return array Series data including id
     */
    public function findOrCreateSeriesByName(string $name)
    {
        $series = $this->db->collection('series')->where('name', '=', $name)->documents();
        foreach ($series as $doc) {
            if ($doc->exists()) {
                $ser = $doc->data();
                $ser['id'] = $doc->id();
                return $ser;
            }
        }
        $id = $this->createSeries(['name' => $name]);
        return ['id' => $id, 'name' => $name];
    }
    public function createSeries(array $data)
    {
        $docRef = $this->db->collection('series')->add($data);
        return $docRef->id();
    }
    public function getSeries(string $id)
    {
        $snap = $this->db->collection('series')->document($id)->snapshot();
        if (!$snap->exists()) {
            return null;
        }
        $series = $snap->data();
        $series['id'] = $id;
        return $series;
    }
    public function listSeries()
    {
        try {
            if (!$this->db) {
                return [];
            }
            $allBooks = $this->db->collection('books')->documents();
            $series = [];
            foreach ($allBooks as $bookDoc) {
                if ($bookDoc->exists()) {
                    $bookData = $bookDoc->data();
                    if (isset($bookData['series']) && is_array($bookData['series'])) {
                        $seriesNames = array_keys($bookData['series']);
                        foreach ($seriesNames as $seriesName) {
                            if (is_string($seriesName) && !empty($seriesName)) {
                                $series[$seriesName] = true;
                            }
                        }
                    }
                }
            }
            $uniqueSeries = array_keys($series);
            sort($uniqueSeries);
            return $uniqueSeries;
        } catch (\Exception $e) {
            Log::error('Firestore listSeries failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search for series titles starting with a given term.
     *
     * @param string $term The search term.
     * Search for series titles starting with a given term.
     *
     * @param string $term The search term.
     * @return array A list of unique series titles.
     */
    public function searchSeriesByName(string $term): array
    {
        if (empty($term)) {
            return [];
        }
        $termLower = strtolower($term);
        $allSeries = $this->listSeries();
        $matches = [];
        foreach ($allSeries as $seriesName) {
            if (stripos($seriesName, $termLower) === 0) {
                $matches[] = $seriesName;
            }
        }
        // sort($matches);
        return $matches;
    }

    /**
     * Find a book by its directory path
     *
     * @param string $directoryPath
     * @return array|null Returns book data if found, null otherwise
     */
    public function findBookByDirectoryPath(string $directoryPath): ?array
    {
        $query = $this->db->collection('books')
            ->where('directory_path', '=', $directoryPath)
            ->limit(1);

        $documents = $query->documents();

        foreach ($documents as $document) {
            if ($document->exists()) {
                return array_merge(['id' => $document->id()], $document->data());
            }
        }

        return null;
    }

    // BOOK QUEUE (STUBS)
    /**
     * Get a user's book queue (stub: implement as needed)
     * @param string $userId
     * @return array
     */
    public function getBookQueue(string $userId): array
    {
        // Example: return [];
        // Implement actual logic if you want to store queues in Firestore
        return [];
    }

    /**
     * Add a book to a user's queue (stub: implement as needed)
     * @param string $userId
     * @param string $bookId
     * @return void
     */
    public function addBookToQueue(string $userId, string $bookId): void
    {
        // Implement as needed
    }

    /**
     * Remove a book from a user's queue (stub: implement as needed)
     * @param string $userId
     * @param string $bookId
     * @return void
     */
    public function removeBookFromQueue(string $userId, string $bookId): void
    {
        // Implement as needed
    }

    // JOB QUEUE MANAGEMENT

    /**
     * Create or update a job status in Firestore
     * @param string $jobId
     * @param string $type
     * @param string $status
     * @param array $data
     * @return string Job ID
     */
    /**
     * Create or update a job status in Firestore with detailed tracking
     *
     * @param string $jobId Unique job identifier
     * @param string $type Job type (e.g., 'book_import', 'directory_import')
     * @param string $status Job status ('queued', 'processing', 'completed', 'failed')
     * @param array $data Additional job data
     * @param string|null $message Optional status message
     * @param array $error Optional error details if job failed
     * @param array $logs Optional array of log entries
     * @return string Job ID
     */
    public function updateJobStatus(
        string $jobId,
        string $type,
        string $status,
        array $data = [],
        ?string $message = null,
        ?array $error = null,
        array $logs = []
    ): string {
        $now = now()->toDateTimeString();
        $jobData = [
            'type' => $type,
            'status' => $status,
            'updated_at' => $now,
            'data' => $data,
        ];

        // Set timestamps based on status
        if ($status === 'queued') {
            $jobData['created_at'] = $now;
            $jobData['queued_at'] = $now;
        } elseif ($status === 'processing') {
            $jobData['started_at'] = $now;
        } elseif (in_array($status, ['completed', 'failed'])) {
            $jobData['completed_at'] = $now;

            // Calculate duration if we have start time
            if (isset($jobData['started_at'])) {
                $startTime = \Carbon\Carbon::parse($jobData['started_at']);
                $endTime = now();
                $jobData['duration'] = $endTime->diffInSeconds($startTime);
            }
        }

        // Add message if provided
        if ($message !== null) {
            $jobData['message'] = $message;
        }

        // Add error details if provided
        if ($error !== null) {
            $jobData['error'] = $error;
        }

        // Append logs if provided
        if (!empty($logs)) {
            $jobData['logs'] = array_merge($jobData['logs'] ?? [], $logs);
        }

        $this->db->collection('jobs')->document($jobId)->set($jobData, ['merge' => true]);
        return $jobId;
    }

    /**
     * Get job status
     * @param string $jobId
     * @return array|null
     */
    /**
     * Get job status by ID
     *
     * @param string $jobId
     * @return array|null Job data or null if not found
     */
    public function getJobStatus(string $jobId): ?array
    {
        try {
            $doc = $this->db->collection('jobs')->document($jobId)->snapshot();
            if (!$doc->exists()) {
                return null;
            }

            $data = $doc->data();
            $data['id'] = $jobId;

            return $data;
        } catch (\Exception $e) {
            Log::error("Failed to get job status: " . $e->getMessage());
            return null;
        }
    }

    /**
     * List jobs by type and/or status
     * @param string|null $type
     * @param string|null $status
     * @param int $limit
     * @return array
     */
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
        try {
            $query = $this->db->collection('jobs');

            // Apply filters
            if ($type) {
                $query = $query->where('type', '=', $type);
            }

            if ($status) {
                $query = $query->where('status', '=', $status);
            }

            // Apply cursor for pagination
            if ($startAfterId) {
                $startAfterDoc = $this->db->collection('jobs')->document($startAfterId)->snapshot();
                if ($startAfterDoc->exists()) {
                    $query = $query->startAfter($startAfterDoc);
                }
            }

            // Apply ordering and limit
            $query = $query->orderBy($orderBy, $direction)->limit($limit);

            // Execute query and process results
            $results = [];
            $documents = $query->documents();

            foreach ($documents as $doc) {
                $data = $doc->data();
                $data['id'] = $doc->id();
                $results[] = $data;
            }

            return $results;
        } catch (\Exception $e) {
            Log::error('Failed to list jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Add a log entry to a job
     *
     * @param string $jobId
     * @param string $level Log level (info, warning, error, etc.)
     * @param string $message Log message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public function addJobLog(string $jobId, string $level, string $message, array $context = []): bool
    {
        try {
            $logEntry = [
                'timestamp' => now()->toDateTimeString(),
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ];

            $this->db->collection('jobs')
                ->document($jobId)
                ->update([
                    ['path' => 'logs', 'value' => \Google\Cloud\Firestore\FieldValue::arrayUnion([$logEntry])],
                ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to add job log: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update job progress
     *
     * @param string $jobId
     * @param int $current Current progress value
     * @param int|null $total Total value (optional)
     * @param string|null $message Optional status message
     * @return bool Success status
     */
    public function updateJobProgress(string $jobId, int $current, ?int $total = null, ?string $message = null): bool
    {
        try {
            $updateData = [
                'progress' => [
                    'current' => $current,
                    'updated_at' => now()->toDateTimeString(),
                ],
            ];

            if ($total !== null) {
                $updateData['progress']['total'] = $total;
                $updateData['progress']['percent'] = $total > 0 ? round(($current / $total) * 100, 2) : 0;
            }

            if ($message !== null) {
                $updateData['message'] = $message;
            }

            $this->db->collection('jobs')
                ->document($jobId)
                ->set($updateData, ['merge' => true]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update job progress: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a job and its data
     *
     * @param string $jobId
     * @return bool Success status
     */
    public function deleteJob(string $jobId): bool
    {
        try {
            $this->db->collection('jobs')->document($jobId)->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete job: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up old completed/failed jobs
     *
     * @param int $daysOld Delete jobs older than this many days
     * @param int $batchSize Maximum number of jobs to delete in one operation
     * @return int Number of jobs deleted
     */
    public function cleanupOldJobs(int $daysOld = 30, int $batchSize = 100): int
    {
        try {
            $cutoffDate = now()->subDays($daysOld)->toDateTimeString();

            $query = $this->db->collection('jobs')
                ->where('status', 'in', ['completed', 'failed', 'cancelled'])
                ->where('updated_at', '<', $cutoffDate)
                ->limit($batchSize);

            $deleted = 0;
            $documents = $query->documents();

            foreach ($documents as $doc) {
                $doc->reference()->delete();
                $deleted++;
            }

            return $deleted;
        } catch (\Exception $e) {
            Log::error('Failed to clean up old jobs: ' . $e->getMessage());
            return 0;
        }
    }
}
