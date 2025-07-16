<?php

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Models\Job;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MySqlService implements DocumentStoreServiceInterface
{
    public function getBook(string $id)
    {
        $book = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters'])->find($id);
        return $book ? $book->toArray() : null;
    }

    public function listBooks()
    {
        return Book::with(['authors', 'narrators', 'genres', 'series'])->get()->toArray();
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

    public function listSeries(): array
    {
        return Series::orderBy('name')->get()->toArray();
    }

    public function getBooksInSeries(string $seriesId): array
    {
        return Book::where('series_id', $seriesId)->with(['authors', 'narrators', 'genres', 'series'])->get()->toArray();
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

    // --- Placeholder Implementations ---

    public function createBook(array $data)
    {
        $book = Book::create([
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'summary' => $data['summary'] ?? null,
            'year' => $data['year'] ?? null,
            'cover' => $data['cover'] ?? null,
            'play_time' => $data['play_time'] ?? null,
        ]);

        if (!empty($data['authors'])) {
            $authorIds = [];
            foreach ($data['authors'] as $authorName) {
                $author = Author::firstOrCreate(['name' => $authorName]);
                $authorIds[] = $author->id;
            }
            $book->authors()->sync($authorIds);
        }

        if (!empty($data['narrators'])) {
            $narratorIds = [];
            foreach ($data['narrators'] as $narratorName) {
                $narrator = Narrator::firstOrCreate(['name' => $narratorName]);
                $narratorIds[] = $narrator->id;
            }
            $book->narrators()->sync($narratorIds);
        }

        if (!empty($data['genres'])) {
            $genreIds = [];
            foreach ($data['genres'] as $genreName) {
                $genre = Genre::firstOrCreate(['name' => $genreName]);
                $genreIds[] = $genre->id;
            }
            $book->genres()->sync($genreIds);
        }

        if (!empty($data['series_name'])) {
            $series = Series::firstOrCreate(['name' => $data['series_name']]);
            $book->series()->associate($series);
            $book->save();
        }

        if (!empty($data['chapters'])) {
            foreach ($data['chapters'] as $chapterData) {
                $book->chapters()->create($chapterData);
            }
        }

        return $book->id;
    }
    public function updateBook(string $id, array $data)
    {
        $book = Book::findOrFail($id);

        $book->update([
            'title' => $data['title'] ?? $book->title,
            'subtitle' => $data['subtitle'] ?? $book->subtitle,
            'summary' => $data['summary'] ?? $book->summary,
            'year' => $data['year'] ?? $book->year,
            'cover' => $data['cover'] ?? $book->cover,
            'play_time' => $data['play_time'] ?? $book->play_time,
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

    public function deleteBook(string $id)
    {
        $book = Book::findOrFail($id);
        $book->delete();
    }

    public function getBooksByAuthorAndGenre($author, $genre)
    {
        return Book::whereHas('authors', function ($query) use ($author) {
            $query->where('name', $author);
        })->whereHas('genres', function ($query) use ($genre) {
            $query->where('name', $genre);
        })->with(['authors', 'narrators', 'genres', 'series'])->get()->toArray();
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
            'email' => $data['email'],
            'password' => $data['password'], // Hashed automatically by model cast
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
        User::findOrFail($id)->delete();
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

    public function updateJob(string $jobId, array $data): void
    {
        $job = Job::findOrFail($jobId);
        $job->update($data);
    }

    public function deleteJob(string $jobId): bool
    {
        $job = Job::find($jobId);
        if ($job) {
            return $job->delete();
        }

        return false;
    }

    public function createGenre(array $data)
    {
        return Genre::create($data);
    }

    public function deleteGenre(string $id)
    {
        Genre::findOrFail($id)->delete();
    }

    public function createSeries(array $data)
    {
        return Series::create($data);
    }

    public function findOrCreateSeriesByName(string $name)
    {
        return Series::firstOrCreate(['name' => $name]);
    }

    public function getSeries(string $id)
    {
        return Series::find($id);
    }

    public function deleteSeries(string $id)
    {
        Series::findOrFail($id)->delete();
    }

    public function searchSeriesByName(string $term): array
    {
        return $this->autocompleteSeries($term);
    }

    public function createAuthor(array $data)
    {
        return Author::create($data);
    }

    public function deleteAuthor(string $id): void
    {
        Author::findOrFail($id)->delete();
    }

    public function searchAuthorsByName(string $term): array
    {
        return $this->autocompleteAuthors($term);
    }

    public function searchNarratorsByName(string $term): array
    {
        return $this->autocompleteNarrators($term);
    }

    public function createMessage(array $messageData): ?string
    {
        $message = Message::create($messageData);
        return (string) $message->id;
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

    public function getBookQueue(string $userId): array
    {
        $user = User::findOrFail($userId);
        return $user->queuedBooks()->get()->toArray();
    }

    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        $user = User::findOrFail($userId);
        $user->books()->updateExistingPivot($bookId, ['progress' => 0]);

        return true;
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
        $bookmark = Bookmark::where('id', $bookmarkId)->where('user_id', $userId)->where('book_id', $bookId)->firstOrFail();
        return $bookmark->delete();
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        throw new \Exception('Not implemented');
    }

    public function getClient()
    {
        throw new \Exception('Not implemented');
    }

    public function getQueueCollection($name)
    {
        return DB::table($name);
    }

    public function getManifestForBook(string $bookId): array
    {
        $book = Book::with(['authors', 'narrators', 'genres', 'series'])->findOrFail($bookId);
        return $book->toArray();
    }
}
