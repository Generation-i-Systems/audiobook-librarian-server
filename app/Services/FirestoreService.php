<?php

namespace App\Services;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Contracts\DocumentStoreServiceInterface;

class FirestoreService implements DocumentStoreServiceInterface
{
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
        return [];
    }
    /**
     * {@inheritdoc}
     */
    public function updateUser(string $id, array $data)
    {
        // TODO: Implement updateUser() method.
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteUser(string $id)
    {
        // TODO: Implement deleteUser() method.
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteGenre(string $id)
    {
        // TODO: Implement deleteGenre() method.
        return null;
    }









    /**
     * {@inheritdoc}
     */
    public function deleteSeries(string $id)
    {
        // TODO: Implement deleteSeries() method.
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function createAuthor(array $data)
    {
        // TODO: Implement createAuthor() method.
        return null;
    }













    /**
     * {@inheritdoc}
     */
    public function getBookQueue(string $userId): array
    {
        // TODO: Implement getBookQueue() method.
        return [];
    }

    /**
     * Reset reading progress for a user and book.
     *
     * @param string $userId
     * @param string $bookId
     * @return bool
     */
    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        try {
            if (!$this->db) {
                return false;
            }
            $progressDocs = $this->db->collection('reading_progress')
                ->where('user_id', '=', $userId)
                ->where('book_id', '=', $bookId)
                ->documents();
            foreach ($progressDocs as $doc) {
                if ($doc->exists()) {
                    $doc->reference()->delete();
                }
            }
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to reset reading progress', [
                'userId' => $userId,
                'bookId' => $bookId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */


    /** @var FirestoreClient|null */
    protected $db;

    /** @var string */
    protected $projectId;

    /** @var bool */
    protected static $inProviderCall = false;

    // --- AUTHENTICATION METHODS ---

    /**
     * Get a document from any collection by ID (for debugging).
     */
    public function getDocument(string $collection, string $docId): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }
            $snap = $this->db->collection($collection)->document($docId)->snapshot();
            if (!$snap->exists()) {
                return null;
            }
            $data = $snap->data();
            $data['id'] = $docId;

            return $data;
        } catch (\Throwable $e) {
            Log::error('Firestore getDocument failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Get a user document by ID.
     *
     * @param string $identifier
     * @return array|null
     */
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
     * Get the user's role from Firestore.
     *
     * @param string $userId
     * @return string|null  The user's role (e.g., 'admin'), or null if not set
     */
    public function getUserRole(string $userId): ?string
    {
        $user = $this->getUserById($userId);
        if ($user && isset($user['role'])) {
            return $user['role'];
        }
        return null;
    }

    /**
     * Check if the user is an admin.
     *
     * @param string $userId
     * @return bool  True if user is admin, false otherwise
     */
    public function isAdmin(string $userId): bool
    {
        return $this->getUserRole($userId) === 'admin';
    }


    /**
     * Retrieve user by remember token.
     *
     * @param  string  $identifier
     * @param  string  $token
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
     *
     * @param  string  $identifier
     * @param  string  $token
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
     *
     * @param  array  $credentials
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
     *
     * @param  array|object  $user
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
            $db = new FirestoreClient([
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

    public function dumpAllBooks()
    {
        return $this->dumpAllBooksFromCollection('books');
    }

    /**
     * Dump all documents from the specified Firestore collection.
     *
     * @param string $collectionName
     * @return array
     */
    public function dumpAllBooksFromCollection(string $collectionName)
    {
        try {
            $projectId = env('FIREBASE_PROJECT_ID');
            $credentials = base_path(env('FIREBASE_CREDENTIALS'));
            $db = new FirestoreClient([
                'projectId' => $projectId,
                'keyFilePath' => $credentials,
            ]);
            $docs = [];
            $documents = $db->collection($collectionName)->documents();
            foreach ($documents as $doc) {
                if ($doc->exists()) {
                    $data = $doc->data();
                    $data['id'] = $doc->id();
                    $docs[] = $data;
                }
            }
            return $docs;
        } catch (\Throwable $e) {
            error_log('Firestore dumpAllBooksFromCollection error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function __construct()
    {
        try {
            $this->projectId = env('FIREBASE_PROJECT_ID');
            $credentials = base_path(env('FIREBASE_CREDENTIALS'));
            $this->db = new FirestoreClient([
                'projectId' => $this->projectId,
                'keyFilePath' => $credentials,
            ]);
        } catch (\Throwable $e) {
            // Log error but do NOT trigger auth/user lookup!
            Log::error('Firestore client init failed: ' . $e->getMessage());
            $this->db = null;
        }
    }

    // QUEUE COLLECTION ACCESS
    /**
     * Get a Firestore collection reference for a queue.
     *
     * @param  string  $name
     * @return \Google\Cloud\Firestore\CollectionReference
     */
    public function getQueueCollection($name)
    {
        return $this->db->collection($name);
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
     *
     * @return array
     */
    public function listGenres()
    {
        return config('genres.list');
    }

    // MESSAGES

    /**
     * Create a new message in Firestore
     *
     * @return string|null Returns the document ID or null on failure
     */
    public function createMessage(array $messageData): ?string
    {
        if (!$this->db) {
            Log::error('Cannot create message: Firestore client not initialized');

            return null;
        }

        try {
            $messageData['created_at'] = $this->getServerTimestamp();
            $messageData['updated_at'] = $this->getServerTimestamp();

            $docRef = $this->db->collection('messages')->add($messageData);

            return $docRef->id();
        } catch (\Exception $e) {
            Log::error('Failed to create message: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Get all messages, optionally filtered by user ID
     *
     * @param  string|null  $userId  Optional user ID to filter by
     * @param  bool  $includeAcknowledged  Whether to include acknowledged messages
     * @param  int  $limit  Maximum number of messages to return
     */
    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        if (!$this->db) {
            Log::error('Cannot get messages: Firestore client not initialized');

            return [];
        }

        try {
            $query = $this->db->collection('messages');

            if ($userId) {
                $query = $query->where('to_user_id', '==', $userId);
            }

            if (!$includeAcknowledged) {
                $query = $query->where('acknowledged_at', '==', null);
            }

            $query = $query->orderBy('created_at', 'DESC')
                ->limit($limit);

            $snapshots = $query->documents();

            $messages = [];
            foreach ($snapshots as $snapshot) {
                $message = $snapshot->data();
                $message['id'] = $snapshot->id();
                $messages[] = $message;
            }

            return $messages;
        } catch (\Exception $e) {
            Log::error('Failed to get messages: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Get a single message by ID
     */
    public function getMessage(string $messageId): ?array
    {
        if (!$this->db) {
            Log::error('Cannot get message: Firestore client not initialized');

            return null;
        }

        try {
            $snapshot = $this->db->collection('messages')->document($messageId)->snapshot();

            if (!$snapshot->exists()) {
                return null;
            }

            $message = $snapshot->data();
            $message['id'] = $snapshot->id();

            return $message;
        } catch (\Exception $e) {
            Log::error('Failed to get message: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Acknowledge a message
     */
    public function acknowledgeMessage(string $messageId): bool
    {
        if (!$this->db) {
            Log::error('Cannot acknowledge message: Firestore client not initialized');

            return false;
        }

        try {
            $this->db->collection('messages')->document($messageId)->update([
                ['path' => 'acknowledged_at', 'value' => $this->getServerTimestamp()],
                ['path' => 'updated_at', 'value' => $this->getServerTimestamp()],
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to acknowledge message: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Get all users for the admin message interface
     */
    public function getUsersForMessaging(): array
    {
        if (!$this->db) {
            Log::error('Cannot get users: Firestore client not initialized');

            return [];
        }

        try {
            $snapshots = $this->db->collection('users')
                ->select(['id', 'name', 'email'])
                ->documents();

            $users = [];
            foreach ($snapshots as $snapshot) {
                $user = $snapshot->data();
                $user['id'] = $snapshot->id();
                $users[] = $user;
            }

            return $users;
        } catch (\Exception $e) {
            Log::error('Failed to get users: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Helper method to get server timestamp for Firestore
     *
     * @return \Google\Cloud\Firestore\FieldValue\ServerTimestampValue
     */
    private function getServerTimestamp()
    {
        return new \Google\Cloud\Firestore\FieldValue\ServerTimestampValue();
    }

    // BOOKS CRUD
    /**
     * Create a new book in Firestore
     *
     * @return string|null Returns the document ID or null on failure
     */
    public function createBook(array $data): ?string
    {
        if (!$this->db) {
            Log::error('Cannot create book: Firestore client not initialized');

            return null;
        }
        try {
            // Ensure dateAdded is set (should be set by caller, fallback to server timestamp)
            if (!isset($data['dateAdded'])) {
                $data['dateAdded'] = $this->getServerTimestamp();
            }

            // Use the provided ID if available, otherwise generate one
            $docId = $data['id'] ?? null;

            if ($docId) {
                // Use set() to create document with specific ID
                $docRef = $this->db->collection('books')->document($docId);
                $docRef->set($data);
                return $docId;
            } else {
                // Use add() to auto-generate ID
                $docRef = $this->db->collection('books')->add($data);
                return $docRef->id();
            }
        } catch (\Throwable $e) {
            Log::error('Failed to create book: ' . $e->getMessage());

            return null;
        }
    }

    // REVIEWS CRUD
    /**
     * Create a new review in Firestore
     *
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
     *
     * @param  string  $authorId
     * @param  string  $genreId
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
     *
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
     * @param  string  $term  The search term.
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
     * Search for narrator names starting with a given term.
     *
     * @param  string  $term  The search term.
     * @return array A list of unique narrator names.
     */
    public function searchNarratorsByName(string $term): array
    {
        if (empty($term)) {
            return [];
        }
        $termLower = strtolower($term);
        $matches = [];

        // Get all books and extract unique narrators
        if ($this->db) {
            $booksRef = $this->db->collection('books');
            $query = $booksRef->limit(100); // Limit to prevent excessive reads
            $snapshot = $query->documents();

            foreach ($snapshot as $document) {
                $book = $document->data();
                if (isset($book['narrator'])) {
                    if (is_array($book['narrator'])) {
                        foreach ($book['narrator'] as $narrator) {
                            if (stripos($narrator, $termLower) === 0) {
                                $matches[] = $narrator;
                            }
                        }
                    } else {
                        if (stripos($book['narrator'], $termLower) === 0) {
                            $matches[] = $book['narrator'];
                        }
                    }
                }
            }
        }

        return array_unique($matches);
    }

    public function updateAuthor(string $id, array $data): void
    {
        $this->db->collection('authors')->document($id)->set($data, ['merge' => true]);
    }

    public function deleteAuthor(string $id): void
    {
        $this->db->collection('authors')->document($id)->delete();
    }

    // GENRES CRUD
    /**
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
     *
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

    public function listSeries(): array
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
     * @param  string  $term  The search term.
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
     * @return array|null Returns book data if found, null otherwise
     */
    public function findBookByDirectoryPath(string $directoryPath): ?array
    {
        try {
            if (!$this->db) {
                return null;
            }

            // First try with camelCase field name
            $query = $this->db->collection('books')
                ->where('directoryPath', '=', $directoryPath)
                ->limit(1);

            $documents = $query->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    return array_merge(['id' => $document->id()], $document->data());
                }
            }

            // If not found, try with snake_case field name for backward compatibility
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
        } catch (\Exception $e) {
            Log::error('Firestore findBookByDirectoryPath failed: ' . $e->getMessage());

            return null;
        }
    }

    // BOOK QUEUE (STUBS)


    /**
     * Add a book to a user's queue (stub: implement as needed)
     */
    public function addBookToQueue(string $userId, string $bookId): void
    {
        // Implement as needed
    }

    /**
     * Remove a book from a user's queue (stub: implement as needed)
     */
    public function removeBookFromQueue(string $userId, string $bookId): void
    {
        // Implement as needed
    }

    // JOB QUEUE MANAGEMENT

    /**
     * Create or update a job status in Firestore
     *
     * @return string Job ID
     */
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
}
