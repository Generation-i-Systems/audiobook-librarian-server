<?php

namespace App\Services;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class FirestoreService
{
    // ... existing properties and constructor ...

    // --- AUTHENTICATION METHODS ---

    /**
     * Retrieve user by unique identifier (ID).
     * @param string $identifier
     * @return array|null
     */
    protected static $inProviderCall = false;

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
                        if (!$doc->exists())
                            continue;
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
            if ($documents->size() === 0)
                return null;
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

    protected $db;
    protected $projectId;

    public function getClient()
    {
        return $this->db;
    }

    // TEMP DEBUG: Dump all users without triggering auth recursion
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
        if (!$this->db)
            return null;
        // Default role to 'preview' if not set
        if (!isset($data['role'])) {
            $data['role'] = 'preview';
        }
        try {
            $docRef = $this->db->collection('users')->add($data);
            return $docRef->id();
        } catch (\Throwable $e) {
            \Log::error('Firestore createUser failed: ' . $e->getMessage());
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
    public function createBook(array $data)
    {
        $docRef = $this->db->collection('books')->add($data);
        return $docRef->id();
    }

    // REVIEWS CRUD
    /**
     * @param array $data
     * @return string
     */
    public function createReview(array $data)
    {
        $docRef = $this->db->collection('reviews')->add($data);
        return $docRef->id();
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
        // First, get all books that have authors
        $booksQuery = $this->db->collection('books')
            ->where('author', '!=', null);

        $documents = $booksQuery->documents();

        // Extract and deduplicate authors
        $uniqueAuthors = [];

        foreach ($documents as $doc) {
            $bookData = $doc->data();
            if (!empty($bookData['author']) && is_array($bookData['author'])) {
                foreach ($bookData['author'] as $authorName) {
                    if (!empty($authorName) && !isset($uniqueAuthors[$authorName])) {
                        $uniqueAuthors[$authorName] = [
                            'name' => $authorName,
                            'id' => $authorName // Using name as ID since we're not storing in authors collection
                        ];
                    }
                }
            }
        }

        // Convert to indexed array and sort alphabetically by author name
        $authors = array_values($uniqueAuthors);
        usort($authors, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $authors;
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
        if (!$snap->exists())
            return null;
        $series = $snap->data();
        $series['id'] = $id;
        return $series;
    }
    public function listSeries()
    {
        $documents = $this->db->collection('series')->documents();
        $series = [];
        foreach ($documents as $doc) {
            $ser = $doc->data();
            $ser['id'] = $doc->id();
            $series[] = $ser;
        }
        return $series;
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
}

