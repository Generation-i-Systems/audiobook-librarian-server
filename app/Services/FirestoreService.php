<?php

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Timestamp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Collection;

class FirestoreService implements DocumentStoreServiceInterface
{
    protected $db;


    public function __construct()
    {
        $projectId = config('firebase.project_id');
        $keyFilePath = config('firebase.credentials_path');

        if (!file_exists($keyFilePath)) {
            Log::error("Firebase credentials file not found at: {$keyFilePath}");
            throw new \Exception("Firebase credentials file not found.");
        }

        $this->db = new FirestoreClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);
    }

    public function autocompleteSeries(string $query, int $limit = 10): array
    {
        return [];
    }

    public function autocompleteAuthors(string $query, int $limit = 10): array
    {
        return [];
    }

    public function autocompleteNarrators(string $query, int $limit = 10): array
    {
        // Implementation for autocompleteNarrators
        return [];
    }

    public function getBook(string $id)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('books')->document($id);
            $snapshot = $docRef->snapshot();

            if ($snapshot->exists()) {
                return array_merge(['id' => $snapshot->id()], $snapshot->data());
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting book: ' . $e->getMessage());
            return null;
        }
    }

    public function listBooks()
    {
        try {
            if (!$this->db) {
                return [];
            }

            $books = [];
            $query = $this->db->collection('books');
            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $books[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $books;
        } catch (\Exception $e) {
            Log::error('Error listing books: ' . $e->getMessage());
            return [];
        }
    }

    public function createBook(array $data)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('books')->newDocument();
            $docRef->set($data);

            return $docRef->id();
        } catch (\Exception $e) {
            Log::error('Error creating book: ' . $e->getMessage());
            return null;
        }
    }

    public function updateBook(string $id, array $data)
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('books')->document($id);
            $docRef->set($data, ['merge' => true]);

            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating book: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteBook(string $id)
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('books')->document($id);
            $docRef->delete();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error deleting book: ' . $e->getMessage());
            return false;
        }
    }

    public function getBooksByAuthorAndGenre($author, $genre)
    {
        try {
            if (!$this->db) {
                return [];
            }

            $books = [];
            $query = $this->db->collection('books');

            if ($author) {
                $query = $query->where('authors', 'array-contains', $author);
            }

            if ($genre) {
                $query = $query->where('genre', '=', $genre);
            }

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $books[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $books;
        } catch (\Exception $e) {
            \Log::error('Error getting books by author and genre: ' . $e->getMessage());
            return [];
        }
    }

    public function dumpAllBooks()
    {
        try {
            if (!$this->db) {
                return [];
            }

            $books = [];
            $documents = $this->db->collection('books')->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $books[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $books;
        } catch (\Exception $e) {
            \Log::error('Error dumping all books: ' . $e->getMessage());
            return [];
        }
    }

    public function getUserById($identifier)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('users')->document($identifier);
            $snapshot = $docRef->snapshot();

            if ($snapshot->exists()) {
                return array_merge(['id' => $snapshot->id()], $snapshot->data());
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('Error getting user by ID: ' . $e->getMessage());
            return null;
        }
    }

    public function getUserByCredentials($credentials)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $query = $this->db->collection('users')
                ->where('email', '=', $credentials['email']);

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $user = $document->data();
                    if (password_verify($credentials['password'], $user['password'] ?? '')) {
                        return array_merge(['id' => $document->id()], $user);
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('Error getting user by credentials: ' . $e->getMessage());
            return null;
        }
    }

    public function getUserByRememberToken($identifier, $token)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('users')->document($identifier);
            $snapshot = $docRef->snapshot();

            if ($snapshot->exists()) {
                $user = $snapshot->data();
                if (isset($user['remember_token']) && hash_equals($user['remember_token'], $token)) {
                    return array_merge(['id' => $snapshot->id()], $user);
                }
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('Error getting user by remember token: ' . $e->getMessage());
            return null;
        }
    }

    public function createUser(array $data)
    {
        try {
            if (!$this->db) {
                \Log::error('Firestore DB connection not initialized');
                return null;
            }

            // Hash password if present
            if (isset($data['password'])) {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }

            // Add timestamps
            $now = new \DateTime();
            $data['created_at'] = $now;
            $data['updated_at'] = $now;

            \Log::debug('Creating user in Firestore', $data);
            
            $docRef = $this->db->collection('users')->newDocument();
            $docRef->set($data);
            $userId = $docRef->id();
            
            \Log::debug('User created successfully', ['user_id' => $userId]);
            
            return $userId;
        } catch (\Exception $e) {
            \Log::error('Error creating user: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    public function updateUser(string $id, array $data)
    {
        try {
            if (!$this->db) {
                return false;
            }

            // Don't update the password if it's empty
            if (isset($data['password']) && empty($data['password'])) {
                unset($data['password']);
            } elseif (isset($data['password'])) {
                // Hash the new password
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }

            $docRef = $this->db->collection('users')->document($id);
            $docRef->set($data, ['merge' => true]);

            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating user: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteUser(string $id)
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('users')->document($id);
            $docRef->delete();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error deleting user: ' . $e->getMessage());
            return false;
        }
    }

    public function isAdmin(string $userId): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('users')->document($userId);
            $snapshot = $docRef->snapshot();

            if ($snapshot->exists()) {
                $user = $snapshot->data();
                return $user['is_admin'] ?? false;
            }

            return false;
        } catch (\Exception $e) {
            \Log::error('Error checking admin status: ' . $e->getMessage());
            return false;
        }
    }

    public function getUsersForMessaging(): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $users = [];
            $query = $this->db->collection('users')
                ->where('notifications_enabled', '=', true);

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $users[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $users;
        } catch (\Exception $e) {
            \Log::error('Error getting users for messaging: ' . $e->getMessage());
            return [];
        }
    }

    public function createGenre(array $data)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('genres')->newDocument();
            $docRef->set($data);

            return $docRef->id();
        } catch (\Exception $e) {
            Log::error('Error creating genre: ' . $e->getMessage());
            return null;
        }
    }

    public function getGenre(string $id): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('genres')->document($id);
            $snapshot = $docRef->snapshot();

            if ($snapshot->exists()) {
                return array_merge(['id' => $snapshot->id()], $snapshot->data());
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting genre: ' . $e->getMessage());
            return null;
        }
    }

    public function listGenres()
    {
        try {
            if (!$this->db) {
                return [];
            }

            $genres = [];
            $documents = $this->db->collection('genres')->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $genres[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $genres;
        } catch (\Exception $e) {
            \Log::error('Error listing genres: ' . $e->getMessage());
            return [];
        }
    }

    public function deleteGenre(string $id)
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('genres')->document($id);
            $docRef->delete();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error deleting genre: ' . $e->getMessage());
            return false;
        }
    }

    public function updateGenre(string $id, array $data): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('genres')->document($id);
            $docRef->set($data, ['merge' => true]);

            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating genre: ' . $e->getMessage());
            return false;
        }
    }

    public function createSeries(string $name)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $data = [
                'name' => $name,
                'created_at' => new Timestamp(new \DateTime()),
                'updated_at' => new Timestamp(new \DateTime()),
            ];

            $docRef = $this->db->collection('series')->newDocument();
            $docRef->set($data);

            return $docRef->id();
        } catch (\Exception $e) {
            \Log::error('Error creating series: ' . $e->getMessage());
            return null;
        }
    }

    public function getSeriesByName(string $name): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            $query = $this->db->collection('series')
                ->where('name', '=', $name)
                ->limit(1);

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    return array_merge(['id' => $document->id()], $document->data());
                }
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('Error getting series by name: ' . $e->getMessage());
            return null;
        }
    }

    public function findOrCreateSeriesByName(string $name)
    {
        $series = $this->getSeriesByName($name);

        if ($series) {
            return $series['id'];
        }

        return $this->createSeries($name);
    }

    public function getSeries(string $id)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('series')->document($id);
            $snapshot = $docRef->snapshot();

            if ($snapshot->exists()) {
                return array_merge(['id' => $snapshot->id()], $snapshot->data());
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('Error getting series: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteSeries(string $id): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('series')->document($id);
            $docRef->delete();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error deleting series: ' . $e->getMessage());
            return false;
        }
    }

    public function listSeries(): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $series = [];
            $documents = $this->db->collection('series')
                ->orderBy('name')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $series[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $series;
        } catch (\Exception $e) {
            \Log::error('Error listing series: ' . $e->getMessage());
            return [];
        }
    }

    public function searchSeriesByName(string $term): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $query = $this->db->collection('series')
                ->where('name', '>=', $term)
                ->where('name', '<=', $term . '\uf8ff')
                ->orderBy('name');

            $series = [];
            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $series[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $series;
        } catch (\Exception $e) {
            \Log::error('Error searching series by name: ' . $e->getMessage());
            return [];
        }
    }

    public function createAuthor(array $data)
    {
        try {
            if (!$this->db) {
                return null;
            }

            $data['created_at'] = new Timestamp(new \DateTime());
            $data['updated_at'] = new Timestamp(new \DateTime());

            $docRef = $this->db->collection('authors')->newDocument();
            $docRef->set($data);

            return $docRef->id();
        } catch (\Exception $e) {
            \Log::error('Error creating author: ' . $e->getMessage());
            return null;
        }
    }

    public function listAuthors()
    {
        try {
            if (!$this->db) {
                return [];
            }

            $authors = [];
            $documents = $this->db->collection('authors')
                ->orderBy('name')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $authors[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $authors;
        } catch (\Exception $e) {
            \Log::error('Error listing authors: ' . $e->getMessage());
            return [];
        }
    }

    public function deleteAuthor(string $id): void
    {
        try {
            if (!$this->db) {
                return;
            }

            $docRef = $this->db->collection('authors')->document($id);
            $docRef->delete();
        } catch (\Exception $e) {
            \Log::error('Error deleting author: ' . $e->getMessage());
            throw $e;
        }
    }

    public function searchAuthorsByName(string $term): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $query = $this->db->collection('authors')
                ->where('name', '>=', $term)
                ->where('name', '<=', $term . '\uf8ff')
                ->orderBy('name');

            $authors = [];
            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $authors[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $authors;
        } catch (\Exception $e) {
            \Log::error('Error searching authors by name: ' . $e->getMessage());
            return [];
        }
    }

    public function searchNarratorsByName(string $term): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $query = $this->db->collection('authors')
                ->where('is_narrator', '==', true)
                ->where('name', '>=', $term)
                ->where('name', '<=', $term . '\uf8ff')
                ->orderBy('name');

            $narrators = [];
            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $narrators[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $narrators;
        } catch (\Exception $e) {
            \Log::error('Error searching narrators by name: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update a user's book queue with a new list of book IDs
     *
     * @param string $userId The user ID
     * @param array $bookIds List of book IDs for the queue
     * @return bool Success status
     */
    public function updateBookQueue(string $userId, array $bookIds): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            // Get reference to user's queue
            $queueRef = $this->db->collection('queues')->document($userId);

            // Start a batch operation
            $batch = $this->db->batch();

            // First, delete all existing books in queue
            $existingBooks = $queueRef->collection('books')->documents();
            foreach ($existingBooks as $book) {
                if ($book->exists()) {
                    $batch->delete($book->reference());
                }
            }

            // Then add all new books
            $timestamp = time();
            foreach ($bookIds as $index => $bookId) {
                $bookRef = $queueRef->collection('books')->document($bookId);
                $batch->set($bookRef, [
                    'book_id' => $bookId,
                    'added_at' => $timestamp,
                    'position' => $index,
                ]);
            }

            // Commit the batch
            $batch->commit();

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Firestore updateBookQueue failed: ' . $e->getMessage());
            return false;
        }
    }

    // BOOK QUEUE (STUBS)

    /**
     * Add a book to a user's queue
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to add
     * @return bool Success status
     */
    public function addBookToQueue(string $userId, string $bookId): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            // Get current queue
            $queue = $this->getBookQueue($userId);

            // Check if book is already in queue
            foreach ($queue as $item) {
                if (($item['id'] ?? '') === $bookId) {
                    // Book already in queue, nothing to do
                    return true;
                }
            }

            // Add book to queue collection
            $this->db->collection('queues')
                ->document($userId)
                ->collection('books')
                ->document($bookId)
                ->set([
                    'book_id' => $bookId,
                    'added_at' => time(),
                ]);

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Firestore addBookToQueue failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a book from a user's queue
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to remove
     * @return bool Success status
     */
    public function removeBookFromQueue(string $userId, string $bookId): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            // Delete the book document from the user's queue
            $this->db->collection('queues')
                ->document($userId)
                ->collection('books')
                ->document($bookId)
                ->delete();

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Firestore removeBookFromQueue failed: ' . $e->getMessage());
            return false;
        }
    }

    // JOB QUEUE MANAGEMENT

    /**
     * Create or update a job status in Firestore with detailed tracking
     *
     * @param  string  $jobId  Unique job identifier
     * @param  string  $type  Job type (e.g., 'book_import', 'directory_import')
     * @param  string  $status  Job status ('queued', 'processing', 'completed', 'failed')
     * @param  array  $data  Additional job data
     * @param  string|null  $message  Optional status message
     * @param  array  $error  Optional error details if job failed
     * @param  array  $logs  Optional array of log entries
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
                $startTime = \Carbon\Carbon::parse($jobData['started']);
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
     */
    /**
     * Get job status by ID
     *
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
            Log::error('Failed to get job status: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * List jobs by type and/or status
     */
    /**
     * List jobs with filtering and pagination
     *
     * @param  string|null  $type  Filter by job type
     * @param  string|null  $status  Filter by status
     * @param  int  $limit  Maximum number of results
     * @param  string  $orderBy  Field to order by
     * @param  string  $direction  Order direction ('ASC' or 'DESC')
     * @param  string|null  $startAfterId  Start after specific job ID for pagination
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
     * @param  string  $level  Log level (info, warning, error, etc.)
     * @param  string  $message  Log message
     * @param  array  $context  Additional context data
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
     * @param  int  $current  Current progress value
     * @param  int|null  $total  Total value (optional)
     * @param  string|null  $message  Optional status message
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
     * @param  int  $daysOld  Delete jobs older than this many days
     * @param  int  $batchSize  Maximum number of jobs to delete in one operation
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

    public function getManifestForBook(string $bookId): array
    {
        try {
            if (!$this->db) {
                return [];
            }
            $bookRef = $this->db->collection('books')->document($bookId);
            $snapshot = $bookRef->snapshot();

            if (!$snapshot->exists()) {
                return [];
            }

            $bookData = $snapshot->data();
            $bookData['id'] = $snapshot->id();

            return $bookData;
        } catch (\Throwable $e) {
            Log::error('Firestore getManifestForBook failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getBookmarks(string $userId, string $bookId): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $query = $this->db->collection('bookmarks')
                ->where('user_id', '=', $userId)
                ->where('book_id', '=', $bookId);

            $documents = $query->documents();
            $bookmarks = [];

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $data = $document->data();
                    $data['id'] = $document->id();
                    $bookmarks[] = $data;
                }
            }

            return $bookmarks;
        } catch (\Throwable $e) {
            Log::error('Firestore getBookmarks failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('bookmarks')->document($bookmarkId);
            $document = $docRef->snapshot();

            if (
                $document->exists() &&
                ($document->data()['user_id'] ?? null) === $userId &&
                ($document->data()['book_id'] ?? null) === $bookId
            ) {
                $data = $document->data();
                $data['id'] = $document->id();
                return $data;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Firestore getBookmark failed: ' . $e->getMessage());
            return null;
        }
    }

    public function createBookmark(array $data): string
    {
        try {
            if (!$this->db) {
                return '';
            }

            $docRef = $this->db->collection('bookmarks')->add($data);
            return $docRef->id();
        } catch (\Throwable $e) {
            Log::error('Firestore createBookmark failed: ' . $e->getMessage());
            return '';
        }
    }

    public function updateBookmark(string $bookmarkId, array $data): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('bookmarks')->document($bookmarkId);
            $docRef->set($data, ['merge' => true]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Firestore updateBookmark failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('bookmarks')->document($bookmarkId);
            $document = $docRef->snapshot();

            if (
                $document->exists() &&
                ($document->data()['user_id'] ?? null) === $userId &&
                ($document->data()['book_id'] ?? null) === $bookId
            ) {
                $docRef->delete();
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            Log::error('Firestore deleteBookmark failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getJob(string $jobId): ?array
    {
        return $this->getJobStatus($jobId);
    }

    /**
     * @inheritDoc
     */
    public function getJobs(): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $jobsRef = $this->db->collection('jobs');
            $documents = $jobsRef->documents();

            $jobs = [];
            foreach ($documents as $document) {
                if ($document->exists()) {
                    $data = $document->data();
                    $data['id'] = $document->id();
                    $jobs[] = $data;
                }
            }

            return $jobs;
        } catch (\Throwable $e) {
            Log::error('Firestore getJobs failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getJobCount(): int
    {
        try {
            if (!$this->db) {
                return 0;
            }

            $jobsRef = $this->db->collection('jobs');
            $documents = $jobsRef->documents();

            $count = 0;
            foreach ($documents as $document) {
                if ($document->exists()) {
                    $count++;
                }
            }

            return $count;
        } catch (\Throwable $e) {
            Log::error('Firestore getJobCount failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @inheritDoc
     */
    public function clearJobs(): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $jobsRef = $this->db->collection('jobs');
            $documents = $jobsRef->documents();

            $batch = $this->db->batch();
            $hasJobs = false;

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $batch->delete($document->reference());
                    $hasJobs = true;
                }
            }

            if ($hasJobs) {
                $batch->commit();
            }

            return $hasJobs;
        } catch (\Throwable $e) {
            Log::error('Firestore clearJobs failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function jobExistsByDirectoryPath(string $directoryPath): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $jobsRef = $this->db->collection('jobs');
            $query = $jobsRef->where('directory_path', '==', $directoryPath);
            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Firestore jobExistsByDirectoryPath failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function bookExistsByDirectoryPath(string $directoryPath): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $booksRef = $this->db->collection('books');
            $query = $booksRef->where('directory_path', '==', $directoryPath);
            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Firestore bookExistsByDirectoryPath failed: ' . $e->getMessage());
            return false;
        }
    }

    public function findOrCreateMany(string $collection, array $names): array
    {
        if (!$this->db) {
            return [];
        }

        $ids = [];
        foreach ($names as $name) {
            $trimmedName = trim($name);
            if (empty($trimmedName)) {
                continue;
            }

            $collectionRef = $this->db->collection($collection);
            $query = $collectionRef->where('name', '=', $trimmedName)->limit(1);
            $documents = $query->documents();

            $existingDoc = null;
            // Need to iterate to get the first document
            foreach ($documents as $document) {
                if ($document->exists()) {
                    $existingDoc = $document;
                    break;
                }
            }

            if ($existingDoc) {
                $ids[] = $existingDoc->id();
            } else {
                $newDocRef = $collectionRef->add(['name' => $trimmedName]);
                $ids[] = $newDocRef->id();
            }
        }

        return $ids;
    }

    public function getBooksInSeries(string $seriesId): array
    {
        try {
            if (!$this->db) {
                return [];
            }
            $query = $this->db->collection('books')->where('series.id', '=', $seriesId);
            $documents = $query->documents();

            $books = [];
            foreach ($documents as $document) {
                if ($document->exists()) {
                    $data = $document->data();
                    $data['id'] = $document->id();
                    $books[] = $data;
                }
            }

            return $books;
        } catch (\Throwable $e) {
            Log::error('Firestore getBooksInSeries failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Get a user by their email address
     *
     * @param string $email The email address to search for
     * @return array|null The user data or null if not found
     */
    public function getUserByEmail(string $email): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            $query = $this->db->collection('users')
                ->where('email', '=', $email)
                ->limit(1);

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $userData = $document->data();
                    $userData['id'] = $document->id();
                    return $userData;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Firestore getUserByEmail failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a user with the given email exists
     *
     * @param string $email The email address to check
     * @return bool True if a user with this email exists
     */
    public function userExistsByEmail(string $email): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $query = $this->db->collection('users')
                ->where('email', '=', $email)
                ->limit(1);

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Firestore userExistsByEmail failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a user with the given username exists
     *
     * @param string $username The username to check
     * @return bool True if a user with this username exists
     */
    public function userExistsByUsername(string $username): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $query = $this->db->collection('users')
                ->where('username', '=', $username)
                ->limit(1);

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Firestore userExistsByUsername failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a user by their username
     *
     * @param string $username The username to search for
     * @return array|null The user data or null if not found
     */
    public function getUserByUsername(string $username): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            $query = $this->db->collection('users')
                ->where('username', '=', $username)
                ->limit(1);

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $userData = $document->data();
                    $userData['id'] = $document->id();
                    return $userData;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Firestore getUserByUsername failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all admin users
     *
     * @return array List of admin users
     */
    public function getAdminUsers(): array
    {
        try {
            $admins = $this->db->collection('users')
                ->where('is_admin', '=', true)
                ->documents();

            $adminUsers = [];
            foreach ($admins as $admin) {
                if ($admin->exists()) {
                    $adminData = $admin->data();
                    $adminData['id'] = $admin->id();
                    $adminUsers[] = $adminData;
                }
            }

            return $adminUsers;
        } catch (\Exception $e) {
            Log::error('Error getting admin users: ' . $e->getMessage());
            return [];
        }
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
            $follows = $this->db->collection('follows')
                ->where('user_id', '=', $userId)
                ->where('followable_type', '=', $followableType)
                ->where('followable_id', '=', $followableId)
                ->limit(1)
                ->documents();

            return !$follows->isEmpty();
        } catch (\Exception $e) {
            Log::error('Error checking if follow exists: ' . $e->getMessage());
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
            $this->db->collection('follows')->add([
                'user_id' => $userId,
                'followable_type' => $followableType,
                'followable_id' => $followableId,
                'created_at' => new \DateTime(),
                'updated_at' => new \DateTime(),
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('Error creating follow: ' . $e->getMessage());
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
            $follows = $this->db->collection('follows')
                ->where('user_id', '=', $userId)
                ->where('followable_type', '=', $followableType)
                ->where('followable_id', '=', $followableId)
                ->documents();

            $batch = $this->db->batch();
            $deleted = false;

            foreach ($follows as $follow) {
                if ($follow->exists()) {
                    $batch->delete($follow->reference());
                    $deleted = true;
                }
            }

            if ($deleted) {
                $batch->commit();
            }

            return $deleted;
        } catch (\Exception $e) {
            Log::error('Error deleting follow: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all users in the system
     *
     * @return array List of all users
     */
    public function getAllUsers(): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $documents = $this->db->collection('users')->documents();
            $users = [];

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $userData = $document->data();
                    $userData['id'] = $document->id();
                    $users[] = $userData;
                }
            }

            return $users;
        } catch (\Throwable $e) {
            Log::error('Firestore getAllUsers failed: ' . $e->getMessage());
            return [];
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
            if (!$this->db) {
                return false;
            }

            $jobRef = $this->db->collection('jobs')->document($jobId);
            $snapshot = $jobRef->snapshot();

            if (!$snapshot->exists()) {
                return false;
            }

            // Get current job data
            $currentData = $snapshot->data();

            // Merge with new data
            $updateData = [];
            foreach ($data as $key => $value) {
                if ($key === 'data' && isset($currentData['data']) && is_array($currentData['data'])) {
                    // Special handling for nested data field
                    $updateData[] = ['path' => 'data', 'value' => array_merge($currentData['data'], $value)];
                } else {
                    $updateData[] = ['path' => $key, 'value' => $value];
                }
            }

            // Always update the timestamp
            $updateData[] = ['path' => 'updated_at', 'value' => new \Google\Cloud\Firestore\Timestamp(time(), 0)];

            // Perform the update
            $jobRef->update($updateData);

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Firestore updateJob failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get an author by ID
     *
     * @param string $id The author ID
     * @return array|null The author data or null if not found
     */
    public function getAuthor(string $id): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }
            $snap = $this->db->collection('authors')->document($id)->snapshot();
            if (!$snap->exists()) {
                return null;
            }
            $author = $snap->data();
            $author['id'] = $id;

            return $author;
        } catch (\Throwable $e) {
            Log::error('Firestore getAuthor failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an author
     *
     * @param string $id The author ID
     * @param array $data The updated author data
     * @return bool True if the update was successful
     */
    public function updateAuthor(string $id, array $data): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('authors')->document($id);
            $snap = $docRef->snapshot();

            if (!$snap->exists()) {
                return false;
            }

            $docRef->set($data, ['merge' => true]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Firestore updateAuthor failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create an API token for a user
     *
     * @param array $tokenData The token data including user_id, token, etc.
     * @return string|null The token ID or null on failure
     */
    public function createApiToken(array $tokenData): ?string
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('api_tokens')->add($tokenData);
            return $docRef->id();
        } catch (\Throwable $e) {
            Log::error('Firestore createApiToken failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete an API token by its value
     *
     * @param string $tokenValue The token value to delete
     * @return bool True if token was deleted, false otherwise
     */
    public function deleteApiTokenByValue(string $tokenValue): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $query = $this->db->collection('api_tokens')
                ->where('token', '=', $tokenValue);

            $documents = $query->documents();
            $deleted = false;

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $document->reference()->delete();
                    $deleted = true;
                }
            }

            return $deleted;
        } catch (\Throwable $e) {
            Log::error('Firestore deleteApiTokenByValue failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get pending account requests
     *
     * @return array List of pending account requests
     */
    public function getPendingAccountRequests(): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $query = $this->db->collection('account_requests')
                ->where('status', '=', 'pending')
                ->orderBy('created_at', 'DESC');

            $documents = $query->documents();
            $requests = [];

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $data = $document->data();
                    $data['id'] = $document->id();
                    $requests[] = $data;
                }
            }

            return $requests;
        } catch (\Throwable $e) {
            Log::error('Firestore getPendingAccountRequests failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a specific account request by ID
     *
     * @param string $id The account request ID
     * @return array|null The account request data or null if not found
     */
    public function getAccountRequest(string $id): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('account_requests')->document($id);
            $document = $docRef->snapshot();

            if ($document->exists()) {
                $data = $document->data();
                $data['id'] = $document->id();
                return $data;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Firestore getAccountRequest failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Approve an account request
     *
     * @param string $id The account request ID
     * @return bool True if the request was approved successfully
     */
    public function approveAccountRequest(string $id): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            // Get the account request
            $docRef = $this->db->collection('account_requests')->document($id);
            $document = $docRef->snapshot();

            if (!$document->exists()) {
                return false;
            }

            $requestData = $document->data();

            // Update the status to approved
            $docRef->update([
                ['path' => 'status', 'value' => 'approved'],
                ['path' => 'updated_at', 'value' => new \Google\Cloud\Firestore\Timestamp(time(), 0)],
            ]);

            // Create a new user from the account request data
            $userData = [
                'name' => $requestData['name'] ?? '',
                'email' => $requestData['email'] ?? '',
                'username' => $requestData['username'] ?? '',
                'password' => Hash::make($requestData['password'] ?? ''),
                'created_at' => new \Google\Cloud\Firestore\Timestamp(time(), 0),
                'updated_at' => new \Google\Cloud\Firestore\Timestamp(time(), 0),
            ];

            $this->db->collection('users')->add($userData);

            return true;
        } catch (\Throwable $e) {
            Log::error('Firestore approveAccountRequest failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reject an account request
     *
     * @param string $id The account request ID
     * @return bool True if the request was rejected successfully
     */
    public function rejectAccountRequest(string $id): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            // Get the account request
            $docRef = $this->db->collection('account_requests')->document($id);
            $document = $docRef->snapshot();

            if (!$document->exists()) {
                return false;
            }

            // Update the status to rejected
            $docRef->update([
                ['path' => 'status', 'value' => 'rejected'],
                ['path' => 'updated_at', 'value' => new \Google\Cloud\Firestore\Timestamp(time(), 0)],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Firestore rejectAccountRequest failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getAccountRequests()
    {
        try {
            if (!$this->db) {
                return [];
            }

            $accountRequests = [];
            $documents = $this->db->collection('account_requests')
                ->orderBy('created_at', 'desc')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $accountRequests[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $accountRequests;
        } catch (\Throwable $e) {
            Log::error('Firestore getAccountRequests failed: ' . $e->getMessage());
            return [];
        }
    }



    public function deleteAccountRequest(string $id): void
    {
        try {
            if (!$this->db) {
                return;
            }

            $docRef = $this->db->collection('account_requests')->document($id);
            $docRef->delete();
        } catch (\Throwable $e) {
            Log::error('Firestore deleteAccountRequest failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getAccountRequestsByStatus(string $status): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $accountRequests = [];
            $documents = $this->db->collection('account_requests')
                ->where('status', '=', $status)
                ->orderBy('created_at', 'desc')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $accountRequests[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $accountRequests;
        } catch (\Throwable $e) {
            Log::error('Firestore getAccountRequestsByStatus failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getAccountRequestsByUser(string $userId): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $accountRequests = [];
            $documents = $this->db->collection('account_requests')
                ->where('user_id', '=', $userId)
                ->orderBy('created_at', 'desc')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $accountRequests[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $accountRequests;
        } catch (\Throwable $e) {
            Log::error('Firestore getAccountRequestsByUser failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getAccountRequestsByUserAndStatus(string $userId, string $status): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $accountRequests = [];
            $documents = $this->db->collection('account_requests')
                ->where('user_id', '=', $userId)
                ->where('status', '=', $status)
                ->orderBy('created_at', 'desc')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $accountRequests[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $accountRequests;
        } catch (\Throwable $e) {
            Log::error('Firestore getAccountRequestsByUserAndStatus failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getAccountRequestsByUserAndStatusAndType(string $userId, string $status, string $type): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $accountRequests = [];
            $documents = $this->db->collection('account_requests')
                ->where('user_id', '=', $userId)
                ->where('status', '=', $status)
                ->where('type', '=', $type)
                ->orderBy('created_at', 'desc')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $accountRequests[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $accountRequests;
        } catch (\Throwable $e) {
            Log::error('Firestore getAccountRequestsByUserAndStatusAndType failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getAccountRequestsByUserAndStatusAndTypeAndSource(string $userId, string $status, string $type, string $source): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $accountRequests = [];
            $documents = $this->db->collection('account_requests')
                ->where('user_id', '=', $userId)
                ->where('status', '=', $status)
                ->where('type', '=', $type)
                ->where('source', '=', $source)
                ->orderBy('created_at', 'desc')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $accountRequests[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $accountRequests;
        } catch (\Throwable $e) {
            Log::error('Firestore getAccountRequestsByUserAndStatusAndTypeAndSource failed: ' . $e->getMessage());
            return [];
        }
    }

    public function createMessage(array $messageData): string|null
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('messages')->add($messageData);
            return $docRef->id();
        } catch (\Throwable $e) {
            Log::error('Firestore createMessage failed: ' . $e->getMessage());
            return null;
        }
    }

    public function updateMessage(string $id, array $messageData): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('messages')->document($id);
            $document = $docRef->snapshot();

            if (!$document->exists()) {
                return false;
            }

            $docRef->update($messageData);
            return true;
        } catch (\Throwable $e) {
            Log::error('Firestore updateMessage failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteMessage(string $id): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('messages')->document($id);
            $document = $docRef->snapshot();

            if (!$document->exists()) {
                return false;
            }

            $docRef->delete();
            return true;
        } catch (\Throwable $e) {
            Log::error('Firestore deleteMessage failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getMessage(string $id): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection('messages')->document($id);
            $document = $docRef->snapshot();

            if (!$document->exists()) {
                return null;
            }

            return $document->data();
        } catch (\Throwable $e) {
            Log::error('Firestore getMessage failed: ' . $e->getMessage());
            return null;
        }
    }

    public function getBookQueue(string $userId): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $queue = [];
            $documents = $this->db->collection('users')
                ->document($userId)
                ->collection('book_queue')
                ->orderBy('position')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $queue[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $queue;
        } catch (\Throwable $e) {
            Log::error('Firestore getBookQueue failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getQueueCollection($name): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $queue = [];
            $documents = $this->db->collection('queues')
                ->document($name)
                ->collection('items')
                ->orderBy('position')
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $queue[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $queue;
        } catch (\Throwable $e) {
            Log::error('Firestore getQueueCollection failed: ' . $e->getMessage());
            return [];
        }
    }

    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection('users')
                ->document($userId)
                ->collection('reading_progress')
                ->document($bookId);

            $docRef->set([
                'progress' => 0,
                'updated_at' => new Timestamp(new \DateTime()),
                'is_finished' => false,
            ], ['merge' => true]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Firestore resetReadingProgress failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            $docRef = $this->db->collection($collection)->document($docId);
            $document = $docRef->snapshot();

            if (!$document->exists()) {
                return null;
            }

            return array_merge(['id' => $document->id()], $document->data());
        } catch (\Throwable $e) {
            Log::error("Firestore getDocument failed for collection {$collection} and ID {$docId}: " . $e->getMessage());
            return null;
        }
    }

    public function updateDocument(string $collection, string $id, array $data): bool
    {
        try {
            if (!$this->db) {
                return false;
            }

            $docRef = $this->db->collection($collection)->document($id);
            $document = $docRef->snapshot();

            if (!$document->exists()) {
                return false;
            }

            // Add updated_at timestamp
            $data['updated_at'] = new Timestamp(new \DateTime());

            $docRef->set($data, ['merge' => true]);
            return true;
        } catch (\Throwable $e) {
            Log::error("Firestore updateDocument failed for collection {$collection} and ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    public function getClient()
    {
        return $this->db;
    }

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        try {
            if (!$this->db) {
                return [];
            }

            $query = $this->db->collection('messages');

            // Filter by user ID if provided
            if ($userId !== null) {
                $query = $query->where('user_id', '==', $userId);
            }

            // Filter out acknowledged messages if needed
            if (!$includeAcknowledged) {
                $query = $query->where('acknowledged', '==', false);
            }

            // Apply limit and order
            $query = $query->orderBy('created_at', 'DESC')->limit($limit);

            $messages = [];
            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $messages[] = array_merge(['id' => $document->id()], $document->data());
                }
            }

            return $messages;
        } catch (\Throwable $e) {
            Log::error('Firestore getMessages failed: ' . $e->getMessage());
            return [];
        }
    }


}
