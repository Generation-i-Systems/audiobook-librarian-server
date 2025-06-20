<?php

namespace Tests\Mocks;

use App\Contracts\DocumentStoreServiceInterface;

class MockDocumentStoreService implements DocumentStoreServiceInterface
{
    protected $books = [];
    protected $series = [];
    protected $genres = [];
    protected $authors = [];
    protected $users = [];
    protected $messages = [];
    protected $jobs = [];
    protected $queues = [];
    protected $readingProgress = [];
    protected $narrators = [];

    public function getBook($id)
    {
        return $this->books[$id] ?? null;
    }

    public function createBook(array $data)
    {
        // Debug logging
        echo "\nMockDocumentStoreService::createBook called with data: " . json_encode($data) . "\n";

        $id = $data['id'] ?? uniqid('book_');
        $data['id'] = $id;
        $this->books[$id] = $data;

        // Verify book was added
        echo "Books after adding: " . json_encode($this->books) . "\n";

        return $id;
    }

    public function updateBook($id, array $data)
    {
        if (!isset($this->books[$id])) {
            return false;
        }

        $this->books[$id] = array_merge($this->books[$id], $data);
        return true;
    }

    public function deleteBook($id)
    {
        if (!isset($this->books[$id])) {
            return false;
        }

        unset($this->books[$id]);
        return true;
    }

    public function searchBooks($query, $limit = 10, $offset = 0)
    {
        $results = [];
        foreach ($this->books as $book) {
            if (stripos($book['title'] ?? '', $query) !== false ||
                stripos($book['author'] ?? '', $query) !== false) {
                $results[] = $book;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return array_slice($results, $offset, $limit);
    }

    public function getBookByPath($path)
    {
        foreach ($this->books as $book) {
            if (($book['path'] ?? '') === $path) {
                return $book;
            }
        }

        return null;
    }

    public function getBooksByIds(array $ids)
    {
        $results = [];
        foreach ($ids as $id) {
            if (isset($this->books[$id])) {
                $results[] = $this->books[$id];
            }
        }

        return $results;
    }

    public function getBooksBySeries($seriesName)
    {
        $results = [];
        foreach ($this->books as $book) {
            if (isset($book['series']) && is_array($book['series'])) {
                foreach ($book['series'] as $series) {
                    if (isset($series['seriesName']) && $series['seriesName'] === $seriesName) {
                        $results[] = $book;
                        break;
                    }
                }
            }
        }

        return $results;
    }

    public function getBooksByAuthor($author)
    {
        $results = [];
        foreach ($this->books as $book) {
            if (isset($book['author']) && $book['author'] === $author) {
                $results[] = $book;
            }
        }

        return $results;
    }

    public function getBooksByGenre($genre)
    {
        $results = [];
        foreach ($this->books as $book) {
            if (isset($book['genres']) && in_array($genre, $book['genres'])) {
                $results[] = $book;
            }
        }

        return $results;
    }

    public function listBooks()
    {
        return array_values($this->books);
    }

    public function getBooksByAuthorAndGenre($author, $genre)
    {
        $results = [];
        foreach ($this->books as $book) {
            if ((isset($book['author']) && $book['author'] === $author) &&
                (isset($book['genres']) && in_array($genre, $book['genres']))) {
                $results[] = $book;
            }
        }

        return $results;
    }

    public function dumpAllBooks()
    {
        return $this->books;
    }

    public function getAllBooks(): array
    {
        return array_values($this->books);
    }

    // dumpAllBooks method already exists above

    public function getUserById($identifier)
    {
        return $this->users[$identifier] ?? null;
    }

    public function getUserByCredentials($credentials)
    {
        foreach ($this->users as $user) {
            if (isset($user['email']) && $user['email'] === ($credentials['email'] ?? '')) {
                return $user;
            }
        }

        return null;
    }

    public function getUserByRememberToken($identifier, $token)
    {
        $user = $this->getUserById($identifier);
        if ($user && isset($user['remember_token']) && $user['remember_token'] === $token) {
            return $user;
        }

        return null;
    }

    public function createUser(array $data)
    {
        $id = $data['id'] ?? uniqid('user_');
        $data['id'] = $id;
        $this->users[$id] = $data;
        return $id;
    }

    public function updateUser(string $id, array $data)
    {
        if (!isset($this->users[$id])) {
            return false;
        }

        $this->users[$id] = array_merge($this->users[$id], $data);
        return true;
    }

    public function deleteUser(string $id)
    {
        if (!isset($this->users[$id])) {
            return false;
        }

        unset($this->users[$id]);
        return true;
    }

    public function getUsersForMessaging(): array
    {
        return array_values($this->users);
    }

    public function createGenre(array $data)
    {
        $id = $data['id'] ?? uniqid('genre_');
        $data['id'] = $id;
        $this->genres[$id] = $data;
        return $id;
    }

    public function listGenres()
    {
        return array_values($this->genres);
    }

    public function deleteGenre(string $id)
    {
        if (!isset($this->genres[$id])) {
            return false;
        }

        unset($this->genres[$id]);
        return true;
    }

    public function createSeries(array $data)
    {
        $id = $data['id'] ?? uniqid('series_');
        $data['id'] = $id;
        $this->series[$id] = $data;
        return $id;
    }

    public function findOrCreateSeriesByName(string $name)
    {
        foreach ($this->series as $series) {
            if (isset($series['seriesName']) && $series['seriesName'] === $name) {
                return $series;
            }
        }

        // Create new series
        $id = uniqid('series_');
        $this->series[$id] = [
            'id' => $id,
            'seriesName' => $name
        ];

        return $this->series[$id];
    }

    public function getSeries(string $id)
    {
        return $this->series[$id] ?? null;
    }

    public function deleteSeries(string $id)
    {
        if (!isset($this->series[$id])) {
            return false;
        }

        unset($this->series[$id]);
        return true;
    }

    public function listSeries(): array
    {
        return array_values($this->series);
    }

    public function searchSeriesByName(string $term): array
    {
        $results = [];
        foreach ($this->series as $series) {
            if (isset($series['seriesName']) && stripos($series['seriesName'], $term) !== false) {
                $results[] = $series;
            }
        }

        return $results;
    }

    public function createAuthor(array $data)
    {
        $id = $data['id'] ?? uniqid('author_');
        $data['id'] = $id;
        $this->authors[$id] = $data;
        return $id;
    }

    public function listAuthors()
    {
        return array_values($this->authors);
    }

    public function deleteAuthor(string $id): void
    {
        if (isset($this->authors[$id])) {
            unset($this->authors[$id]);
        }
    }

    public function searchAuthorsByName(string $term): array
    {
        $results = [];
        foreach ($this->authors as $author) {
            if (isset($author['name']) && stripos($author['name'], $term) !== false) {
                $results[] = $author;
            }
        }

        return $results;
    }

    public function searchNarratorsByName(string $term): array
    {
        $results = [];
        foreach ($this->narrators as $narrator) {
            if (isset($narrator['name']) && stripos($narrator['name'], $term) !== false) {
                $results[] = $narrator;
            }
        }

        return $results;
    }

    public function createMessage(array $messageData): ?string
    {
        $id = $messageData['id'] ?? uniqid('message_');
        $messageData['id'] = $id;
        $this->messages[$id] = $messageData;
        return $id;
    }

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        if ($userId === null) {
            return array_slice(array_values($this->messages), 0, $limit);
        }

        $results = [];
        foreach ($this->messages as $message) {
            if (isset($message['userId']) && $message['userId'] === $userId) {
                if ($includeAcknowledged || !($message['acknowledged'] ?? false)) {
                    $results[] = $message;
                }
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function listJobs(?string $type = null, ?string $status = null, int $limit = 50, string $orderBy = 'updated_at', string $direction = 'DESC', ?string $startAfterId = null): array
    {
        $results = [];
        foreach ($this->jobs as $job) {
            if (($type === null || ($job['type'] ?? '') === $type) &&
                ($status === null || ($job['status'] ?? '') === $status)) {
                $results[] = $job;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function deleteJob(string $jobId): bool
    {
        if (!isset($this->jobs[$jobId])) {
            return false;
        }

        unset($this->jobs[$jobId]);
        return true;
    }

    public function getBookQueue(string $userId): array
    {
        return $this->queues[$userId] ?? [];
    }

    public function getQueueCollection($name)
    {
        return $this->queues[$name] ?? [];
    }

    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        $key = $userId . '_' . $bookId;
        if (isset($this->readingProgress[$key])) {
            unset($this->readingProgress[$key]);
            return true;
        }

        return false;
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        $collections = [
            'books' => $this->books,
            'users' => $this->users,
            'series' => $this->series,
            'genres' => $this->genres,
            'authors' => $this->authors,
            'messages' => $this->messages,
            'jobs' => $this->jobs,
        ];

        return $collections[$collection][$docId] ?? null;
    }

    public function getClient()
    {
        // Return a mock client object
        return new class () {
            public function collection($name)
            {
                return $this;
            }

            public function document($id)
            {
                return $this;
            }

            public function set($data)
            {
                return true;
            }

            public function get()
            {
                return null;
            }
        };
    }

    public function addSeries($name)
    {
        $id = uniqid('series_');
        $this->series[$id] = ['id' => $id, 'seriesName' => $name];
        return $id;
    }

    public function addAuthor($name)
    {
        $id = uniqid('author_');
        $this->authors[$id] = ['id' => $id, 'name' => $name];
        return $id;
    }

    // Add any other methods required by the interface
}
