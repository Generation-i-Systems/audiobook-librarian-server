<?php

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Bookmark;
use App\Models\Genre;
use App\Models\Job;
use App\Models\Message;
use App\Models\Narrator;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MySqlService implements DocumentStoreServiceInterface
{
    public function getBook(string $id)
    {
        $book = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters'])->find($id);

        return $book ? $book->toArray() : null;
    }

    public function listBooks(
        string $orderBy = 'title',
        string $direction = 'asc',
        int $limit = -1,
        ?string $startAfter = null
    ): array {
        $query = Book::with(['authors', 'narrators', 'genres', 'series'])
            ->orderBy($orderBy, $direction);

        if ($startAfter) {
            $query->where('id', $direction === 'asc' ? '>' : '<', $startAfter);
        }
        if ($limit > 0) {
            return $query->limit($limit)->get()->toArray();
        }

        return $query->get()->toArray();
    }

    public function dumpAllBooks()
    {
        // This is memory intensive, but matches the existing interface.
        return Book::with(['authors', 'narrators', 'genres', 'series', 'chapters'])->get()->toArray();
    }

    public function listAuthors()
    {
        return Author::orderBy('name')->get()->toArray();
    }

    public function listGenres()
    {
        return Genre::orderBy('name')->get()->toArray();
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
            $genre = Genre::find($id);

            return $genre ? $genre->toArray() : null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService getGenre failed: ' . $e->getMessage());
            return null;
        }
    }

    public function listSeries(): array
    {
        return Series::orderBy('name')->get()->toArray();
    }

    public function getBooksInSeries(
        string $seriesId,
        string $orderBy = 'title',
        string $direction = 'asc',
        int $limit = 20,
        ?string $startAfter = null
    ): array {
        $query = Book::where('series_id', $seriesId)
            ->with(['authors', 'narrators', 'genres', 'series'])
            ->orderBy($orderBy, $direction);

        if ($startAfter) {
            $query->where('id', $direction === 'asc' ? '>' : '<', $startAfter);
        }

        return $query->limit($limit)->get()->toArray();
    }

    public function autocompleteAuthors(string $query, int $limit = 10): array
    {
        return Author::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function autocompleteNarrators(string $query, int $limit = 10): array
    {
        return Narrator::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function autocompleteSeries(string $query, int $limit = 10): array
    {
        return Series::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function listNarrators(): array
    {
        return Narrator::orderBy('name')->get()->toArray();
    }

    // --- Placeholder Implementations ---

    public function createBook(array $data)
    {
        $book = Book::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'release_date' => $data['release_date'] ?? null,
            'cover_image' => $data['cover_image'] ?? null,
            'language' => $data['language'] ?? 'en',
            'source' => $data['source'] ?? 'unknown',
            'series_id' => $data['series_id'] ?? null,
            'mongo_id' => $data['mongo_id'] ?? null,
            // add all missing fields from Book model
            'directory_path' => $data['directory_path'] ?? null,
            'duration' => $data['duration'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'needs_review' => $data['needs_review'] ?? null,
            'needs_review_reasons' => $data['needs_review_reasons'] ?? null,
            'audio_file_count' => $data['audio_file_count'] ?? null,
            'mongo_record' => $data['mongo_record'] ?? null,
            'file_tags' => $data['file_tags'] ?? null,
            'audible_info' => $data['audible_info'] ?? null,
            'google_books_info' => $data['google_books_info'] ?? null,
            'hardcover_info' => $data['hardcover_info'] ?? null,
            'audiobook_bay_info' => $data['audiobook_bay_info'] ?? null,
        ]);



        if (!empty($data['chapters'])) {
            foreach ($data['chapters'] as $chapterData) {
                $book->chapters()->create($chapterData);
            }
        }

        return $book;
    }

    public function updateBook(string $id, array $data)
    {
        $book = Book::findOrFail($id);

        $book->update([
            'title' => $data['title'] ?? $book->title,
            'description' => $data['description'] ?? $book->description,
            'language' => $data['language'] ?? $book->language,
            'source' => $data['source'] ?? $book->source,
            'series_id' => $data['series_id'] ?? $book->series_id,
            'mongo_id' => $data['mongo_id'] ?? $book->mongo_id,
            'release_date' => $data['release_date'] ?? $book->release_date,
            'cover_image' => $data['cover_image'] ?? $book->cover_image,
            'directory_path' => $data['directory_path'] ?? $book->directory_path,
            'duration' => $data['duration'] ?? $book->duration,
            'publisher' => $data['publisher'] ?? $book->publisher,
            'needs_review' => $data['needs_review'] ?? $book->needs_review,
            'needs_review_reasons' => $data['needs_review_reasons'] ?? $book->needs_review_reasons,
            'audio_file_count' => $data['audio_file_count'] ?? $book->audio_file_count,
            'mongo_record' => $data['mongo_record'] ?? $book->mongo_record,
            'file_tags' => $data['file_tags'] ?? $book->file_tags,
            'audible_info' => $data['audible_info'] ?? $book->audible_info,
            'google_books_info' => $data['google_books_info'] ?? $book->google_books_info,
            'hardcover_info' => $data['hardcover_info'] ?? $book->hardcover_info,
            'audiobook_bay_info' => $data['audiobook_bay_info'] ?? $book->audiobook_bay_info,
        ]);

        if (isset($data['authors'])) {
            $authorIds = [];
            foreach ($data['authors'] as $authorName) {
                $author = Author::firstOrCreate(['name' => $authorName]);
                $authorIds[] = $author->id;
            }
            $book->authors()->sync($authorIds);
        }

        if (isset($data['narrators'])) {
            $narratorIds = [];
            foreach ($data['narrators'] as $narratorName) {
                $narrator = Narrator::firstOrCreate(['name' => $narratorName]);
                $narratorIds[] = $narrator->id;
            }
            $book->narrators()->sync($narratorIds);
        }

        if (isset($data['genres'])) {
            $genreIds = [];
            foreach ($data['genres'] as $genreName) {
                $genre = Genre::firstOrCreate(['name' => $genreName]);
                $genreIds[] = $genre->id;
            }
            $book->genres()->sync($genreIds);
        }

        if (array_key_exists('series_name', $data)) {
            if ($data['series_name']) {
                $series = Series::firstOrCreate(['name' => $data['series_name']]);
                $book->series()->associate($series);
            } else {
                $book->series()->dissociate();
            }
            $book->save();
        }

        if (isset($data['chapters'])) {
            $book->chapters()->delete();
            foreach ($data['chapters'] as $chapterData) {
                $book->chapters()->create($chapterData);
            }
        }

        return $book->toArray();
    }

    public function getBooksByAuthorAndGenre(
        $author,
        $genre,
        string $orderBy = 'title',
        string $direction = 'asc',
        int $limit = 20,
        ?string $startAfter = null
    ): array {
        $query = Book::whereHas('authors', function ($q) use ($author) {
            $q->where('name', $author);
        })->whereHas('genres', function ($q) use ($genre) {
            $q->where('name', $genre);
        })->with(['authors', 'narrators', 'genres', 'series'])
            ->orderBy($orderBy, $direction);

        if ($startAfter) {
            $query->where('id', $direction === 'asc' ? '>' : '<', $startAfter);
        }

        return $query->limit($limit)->get()->toArray();
    }

    public function getUserById($identifier)
    {
        return User::find($identifier);
    }

    public function getUserByCredentials($credentials)
    {
        if (empty($credentials['email']) || empty($credentials['password'])) {
            return null;
        }

        $user = User::where('email', $credentials['email'])->first();

        if ($user && Hash::check($credentials['password'], $user->getAuthPassword())) {
            return $user;
        }

        return null;
    }

    public function getUserByRememberToken($identifier, $token)
    {
        return User::where('id', $identifier)->where('remember_token', $token)->first();
    }

    public function createUser(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'], // Hashed automatically by model cast
            'role' => $data['role'] ?? 'user',
            'email_verified_at' => $data['email_verified_at'] ?? null,
        ]);
    }

    public function updateUser(string $id, array $data)
    {
        $user = User::findOrFail($id);
        $user->update($data);

        return $user;
    }

    public function deleteUser(string $id)
    {
        return User::where('id', $id)->delete();
    }

    /**
     * Get a user by their email address
     *
     * @param string $email The email address to search for
     * @return array|null The user data or null if not found
     */
    public function getUserByEmail(string $email): ?array
    {
        $user = User::where('email', $email)->first();

        return $user ? $user->toArray() : null;
    }

    /**
     * Check if a user with the given email exists
     *
     * @param string $email The email address to check
     * @return bool True if a user with this email exists
     */
    public function userExistsByEmail(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    /**
     * Check if a user with the given username exists
     *
     * @param string $username The username to check
     * @return bool True if a user with this username exists
     */
    public function userExistsByUsername(string $username): bool
    {
        return User::where('username', $username)->exists();
    }

    /**
     * Get a user by their username
     *
     * @param string $username The username to search for
     * @return array|null The user data or null if not found
     */
    public function getUserByUsername(string $username): ?array
    {
        $user = User::where('username', $username)->first();
        return $user ? $user->toArray() : null;
    }

    /**
     * Get all admin users
     *
     * @return array List of admin users
     */
    public function getAdminUsers(): array
    {
        return User::where('role', 'admin')->get()->toArray();
    }

    public function isAdmin(string $userId): bool
    {
        $user = User::find($userId);

        return $user && $user->role === 'admin';
    }

    public function getJob(string $jobId): ?array
    {
        $job = Job::find($jobId);

        return $job ? $job->toArray() : null;
    }

    public function listJobs(
        ?string $type = null,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'updated_at',
        string $direction = 'DESC',
        ?string $startAfterId = null
    ): array {
        $query = Job::query();

        if ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($startAfterId) {
            $startJob = Job::find($startAfterId);
            if ($startJob) {
                $query->where('created_at', '<', $startJob->created_at);
            }
        }

        return $query->orderBy($orderBy, $direction)
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function updateJob(string $jobId, array $data): bool
    {
        $job = Job::findOrFail($jobId);
        return $job->update($data);
    }

    public function getJobCount(): int
    {
        return Job::count();
    }

    public function clearJobs(): bool
    {
        return Job::truncate();
    }

    public function jobExistsByDirectoryPath(string $directoryPath): bool
    {
        return Job::where('directory_path', $directoryPath)->exists();
    }

    public function bookExistsByDirectoryPath(string $directoryPath): bool
    {
        return Book::where('directory_path', $directoryPath)->exists();
    }

    /**
     * Update a genre
     *
     * @param string $id The genre ID
     * @param array $data The updated genre data
     * @return bool True if the update was successful
     */
    public function updateGenre(string $id, array $data): bool
    {
        try {
            $genre = Genre::find($id);
            if (!$genre) {
                return false;
            }

            return $genre->update($data);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService updateGenre failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getSeriesByName(string $name): ?array
    {
        $series = Series::where('name', $name)->first();

        return $series ? $series->toArray() : null;
    }

    public function findOrCreateSeriesByName(string $name)
    {
        $series = $this->getSeriesByName($name);
        if ($series) {
            return $series;
        }

        $id = $this->createSeries($name);

        return ['id' => $id, 'name' => $name];
    }

    public function getSeries(string $id)
    {
        return Series::find($id);
    }

    public function searchSeriesByName(string $term): array
    {
        return $this->autocompleteSeries($term);
    }

    public function createAuthor(array $data)
    {
        return Author::create($data);
    }

    public function searchAuthorsByName(string $term): array
    {
        return $this->autocompleteAuthors($term);
    }

    public function searchNarratorsByName(string $term): array
    {
        return $this->autocompleteNarrators($term);
    }

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        $query = Message::query();

        if ($userId) {
            $query->where('recipient_id', $userId);
        }

        if (!$includeAcknowledged) {
            $query->whereNull('acknowledged_at');
        }

        return $query->with('sender')
            ->limit($limit)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function getUsersForMessaging(): array
    {
        return User::all()->toArray();
    }

    /**
     * Get all users in the system
     *
     * @return array List of all users
     */
    public function getAllUsers(): array
    {
        try {
            return User::with(['roles'])->get()->toArray();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService getAllUsers failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a user's book queue
     *
     * @param string $userId The user ID
     * @return array List of books in the user's queue
     */
    public function getBookQueue(string $userId): array
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return [];
            }

            return $user->queuedBooks()->with(['authors', 'narrators', 'genres', 'series'])->get()->toArray();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService getBookQueue failed: ' . $e->getMessage());
            return [];
        }
    }

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
            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            $book = Book::find($bookId);
            if (!$book) {
                return false;
            }

            // Check if book is already in queue
            if ($user->queuedBooks()->where('book_id', $bookId)->exists()) {
                return true; // Book already in queue, nothing to do
            }

            // Get the current highest position
            $maxPosition = $user->queuedBooks()->max('position') ?? -1;

            // Add book to queue with the next position and current timestamp
            $user->queuedBooks()->attach($bookId, [
                'position' => $maxPosition + 1,
                'added_at' => now()->timestamp,
            ]);

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService addBookToQueue failed: ' . $e->getMessage());
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
            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            $book = Book::find($bookId);
            if (!$book) {
                return false;
            }

            $user->queuedBooks()->detach($bookId);

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService removeBookFromQueue failed: ' . $e->getMessage());
            return false;
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
            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            // Sync will remove all existing relationships and create new ones
            // The second parameter allows adding pivot data if needed
            $pivotData = [];
            foreach ($bookIds as $index => $bookId) {
                $pivotData[$bookId] = [
                    'position' => $index,
                    'added_at' => now()->timestamp,
                ];
            }

            $user->queuedBooks()->sync($pivotData);

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService updateBookQueue failed: ' . $e->getMessage());
            return false;
        }
    }

    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        try {
            // Delete all reading progress records for this user and book
            DB::table('reading_progress')
                ->where('user_id', $userId)
                ->where('book_id', $bookId)
                ->delete();

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService resetReadingProgress failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getBookmarks(string $userId, string $bookId): array
    {
        return Bookmark::where('user_id', $userId)->where('book_id', $bookId)->get()->toArray();
    }

    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array
    {
        $bookmark = Bookmark::where('id', $bookmarkId)->where('user_id', $userId)->where('book_id', $bookId)->first();

        return $bookmark ? $bookmark->toArray() : null;
    }

    public function createBookmark(array $data): string
    {
        $bookmark = Bookmark::create($data);

        return $bookmark->id;
    }

    public function updateBookmark(string $bookmarkId, array $data): bool
    {
        $bookmark = Bookmark::findOrFail($bookmarkId);

        return $bookmark->update($data);
    }

    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool
    {
        $bookmark = Bookmark::where('id', $bookmarkId)
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->firstOrFail();

        return $bookmark->delete();
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        $modelMap = [
            'users' => \App\Models\User::class,
            'messages' => \App\Models\Message::class,
            'genres' => \App\Models\Genre::class,
            'authors' => \App\Models\Author::class,
            'series' => \App\Models\Series::class,
            'books' => \App\Models\Book::class,
            'jobs' => \App\Models\Job::class,
            'bookmarks' => \App\Models\Bookmark::class,
        ];

        if (!isset($modelMap[$collection])) {
            return null;
        }

        $modelClass = $modelMap[$collection];

        try {
            $instance = $modelClass::find($docId);
            return $instance ? $instance->toArray() : null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(
                "Failed to get document from {$collection} (ID: {$docId}): " . $e->getMessage()
            );
            return null;
        }
    }

    public function updateDocument(string $collection, string $id, array $data): bool
    {
        $modelMap = [
            'users' => \App\Models\User::class,
            'messages' => \App\Models\Message::class,
            'genres' => \App\Models\Genre::class,
            'authors' => \App\Models\Author::class,
            'series' => \App\Models\Series::class,
            'books' => \App\Models\Book::class,
            'jobs' => \App\Models\Job::class,
            'bookmarks' => \App\Models\Bookmark::class,
        ];

        if (!isset($modelMap[$collection])) {
            return false;
        }

        $modelClass = $modelMap[$collection];

        try {
            $instance = $modelClass::findOrFail($id);
            return $instance->update($data);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(
                "Failed to update document in {$collection} (ID: {$id}): " . $e->getMessage()
            );
            return false;
        }
    }



    public function followExists(string $userId, string $followableType, string $followableId): bool
    {
        return false;
    }

    public function getQueueCollection($name)
    {
        return null;
    }

    public function getClient()
    {
        return null; // MySQL does not have a direct "client" object like NoSQL databases
    }

    public function cleanupOldJobs(int $daysOld): int
    {
        $deletedCount = Job::where('created_at', '<=', now()->subDays($daysOld))
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->delete();

        return $deletedCount;
    }

    public function findOrCreateMany(string $type, array $items): array
    {
        $createdIds = [];
        foreach ($items as $item) {
            $method = 'findOrCreate' . ucfirst($type);
            if (is_array($item) && isset($item['name'])) {
                $model = $this->$method(['name' => $item['name']]);
            } else if (is_string($item)) {
                $model = $this->$method(['name' => $item]);
            } else {
                // Log a warning or throw an exception if this case should not happen
                Log::warning("Unexpected item format in findOrCreateMany: " . json_encode($item));
                continue;
            }
            $createdIds[] = $model->id;
        }
        return $createdIds;
    }

    public function getManifestForBook(string $bookId): array
    {
        $book = Book::with(['authors', 'narrators', 'genres', 'series'])->findOrFail($bookId);

        return $book->toArray();
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
            $id = DB::table('api_tokens')->insertGetId($tokenData);
            return (string) $id;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService createApiToken failed: ' . $e->getMessage());
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
            $deleted = DB::table('api_tokens')
                ->where('token', $tokenValue)
                ->delete();

            return $deleted > 0;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteApiTokenByValue failed: ' . $e->getMessage());
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
        $author = Author::find($id);
        return $author ? $author->toArray() : null;
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
            $author = Author::find($id);
            if (!$author) {
                return false;
            }

            return $author->update($data);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService updateAuthor failed: ' . $e->getMessage());
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
            return DB::table('account_requests')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(
                'MySqlService getPendingAccountRequests failed: ' . $e->getMessage()
            );
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
            $request = DB::table('account_requests')->where('id', $id)->first();
            return $request ? (array) $request : null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService getAccountRequest failed: ' . $e->getMessage());
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
            DB::beginTransaction();

            // Get the account request
            $request = DB::table('account_requests')->where('id', $id)->first();
            if (!$request) {
                DB::rollBack();
                return false;
            }

            // Update the status to approved
            DB::table('account_requests')
                ->where('id', $id)
                ->update(['status' => 'approved', 'updated_at' => now()]);

            // Create a new user from the account request data
            $userData = [
                'name' => $request->name ?? '',
                'email' => $request->email ?? '',
                'username' => $request->username ?? '',
                'password' => Hash::make($request->password ?? Str::random(10)),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            User::create($userData);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('MySqlService approveAccountRequest failed: ' . $e->getMessage());
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
            $updated = DB::table('account_requests')
                ->where('id', $id)
                ->update([
                    'status' => 'rejected',
                    'updated_at' => now(),
                ]);

            return $updated > 0;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService rejectAccountRequest failed: ' . $e->getMessage());
            return false;
        }
    }



    public function createFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            $follow = Follow::create([
                'user_id' => $userId,
                'followable_type' => $followableType,
                'followable_id' => $followableId,
            ]);
            return $follow ? true : false;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService createFollow failed: ' . $e->getMessage());
            return false;
        }
    }

    public function createGenre(array $data)
    {
        return Genre::create($data);
    }

    public function createMessage(array $messageData): ?string
    {
        try {
            $message = Message::create([
                'user_id' => $messageData['user_id'],
                'content' => $messageData['content'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $message ? true : false;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService createMessage failed: ' . $e->getMessage());
            return false;
        }
    }

    public function createNarrator(array $data)
    {
        return Narrator::create($data);
    }

    public function createSeries(string $name)
    {
        try {
            $series = Series::create([
                'name' => $name,
            ]);
            return $series->id;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService createSeries failed: ' . $e->getMessage());
            return null;
        }
    }

    public function createJob(array $data)
    {
        try {
            $job = Job::create([
                'user_id' => $data['user_id'],
                'content' => $data['content'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $job ? true : false;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService createJob failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            $follow = Follow::where('user_id', $userId)
                ->where('followable_type', $followableType)
                ->where('followable_id', $followableId)
                ->first();
            if (!$follow) {
                return false;
            }
            $follow->delete();
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteFollow failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteJob(string $jobId): bool
    {
        try {
            $job = Job::where('id', $jobId)->first();
            if (!$job) {
                return false;
            }
            $job->delete();
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteJob failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteMessage(string $messageId): bool
    {
        try {
            $message = Message::where('id', $messageId)->first();
            if (!$message) {
                return false;
            }
            $message->delete();
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteMessage failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteNarrator(string $narratorId): bool
    {
        try {
            $narrator = Narrator::where('id', $narratorId)->first();
            if (!$narrator) {
                return false;
            }
            $narrator->delete();
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteNarrator failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteSeries(string $seriesId): bool
    {
        try {
            $series = Series::where('id', $seriesId)->first();
            if (!$series) {
                return false;
            }
            $series->delete();
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteSeries failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteGenre(string $genreId): bool
    {
        try {
            $genre = Genre::where('id', $genreId)->first();
            if (!$genre) {
                return false;
            }
            $genre->delete();
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteGenre failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteAuthor(string $authorId): void
    {
        try {
            $author = Author::where('id', $authorId)->first();
            if (!$author) {
                return;
            }
            $author->delete();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteAuthor failed: ' . $e->getMessage());
        }
    }

    public function deleteBook(string $bookId): bool
    {
        try {
            $book = Book::where('id', $bookId)->first();
            if (!$book) {
                return false;
            }
            $book->delete();
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteBook failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteQueue(string $queueId): bool
    {
        try {
            $queue = Queue::where('id', $queueId)->first();
            if (!$queue) {
                return false;
            }
            $queue->delete();
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MySqlService deleteQueue failed: ' . $e->getMessage());
            return false;
        }
    }
}
