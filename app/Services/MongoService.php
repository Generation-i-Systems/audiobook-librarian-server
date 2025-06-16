<?php

namespace App\Services;

use MongoDB\Client;
use App\Contracts\DocumentStoreServiceInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use RuntimeException;

class MongoService implements DocumentStoreServiceInterface
{
    /** @var Client */
    protected $client;
    /** @var \MongoDB\Database */
    protected $db;

    public function __construct()
    {
        $uri = env('MONGODB_URI', 'mongodb://localhost:27017');
        $dbName = env('MONGODB_DB', 'ab_librarian');
        $this->client = new Client($uri);
        $this->db = $this->client->$dbName;
    }

    public function getCollection($name)
    {
        return $this->db->$name;
    }

    // BOOKS
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
        }
        return $value;
    }

    /** @inheritDoc */
    public function listBooks()
    {
        $cursor = $this->getCollection('books')->find();
        $books = [];
        foreach ($cursor as $doc) {
            if ($doc instanceof \MongoDB\Model\BSONDocument) {
                $doc = (array) $doc;
            }
            $doc['id'] = (string) $doc['_id'];
            // Recursively normalize author, series, and genre fields to never return BSONArray/BSONDocument
            foreach (['author', 'series', 'genre'] as $field) {
                if (isset($doc[$field])) {
                    $doc[$field] = $this->normalizeMongoValue($doc[$field]);
                }
            }
            $books[] = $doc;
        }
        return $books;
    }
    /** @inheritDoc */
    public function createBook(array $data)
    {
        $result = $this->getCollection('books')->insertOne($data);
        return (string) $result->getInsertedId();
    }
    /** @inheritDoc */
    public function updateBook(string $id, array $data)
    {
        return $this->getCollection('books')->updateOne(['_id' => $id], ['$set' => $data]);
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
            $doc['id'] = (string) $doc['_id'];
            $books[] = $doc;
        }
        return $books;
    }
    /** @inheritDoc */
    public function dumpAllBooks()
    {
        return $this->listBooks();
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
        return config('genres.list', []);
    }
    /** @inheritDoc */
    public function deleteGenre(string $id)
    {
        return $this->getCollection('genres')->deleteOne(['_id' => $id]);
    }

    // SERIES
    /** @inheritDoc */
    public function createSeries(array $data)
    {
        $result = $this->getCollection('series')->insertOne($data);
        return (string) $result->getInsertedId();
    }
    /** @inheritDoc */
    public function findOrCreateSeriesByName(string $name)
    {
        $doc = $this->getCollection('series')->findOne(['name' => $name]);
        if ($doc) {
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
        $id = $this->createSeries(['name' => $name]);
        return ['id' => $id, 'name' => $name];
    }
    /** @inheritDoc */
    public function getSeries(string $id)
    {
        $doc = $this->getCollection('series')->findOne(['_id' => $id]);
        if (!$doc) {
            return null;
        }
        $doc['id'] = (string) $doc['_id'];
        return $doc;
    }
    /** @inheritDoc */
    public function deleteSeries(string $id)
    {
        return $this->getCollection('series')->deleteOne(['_id' => $id]);
    }

    // AUTHORS
    /** @inheritDoc */
    public function createAuthor(array $data)
    {
        $result = $this->getCollection('authors')->insertOne($data);
        return (string) $result->getInsertedId();
    }
    /** @inheritDoc */
    public function listAuthors()
    {
        $cursor = $this->getCollection('authors')->find();
        $names = [];
        foreach ($cursor as $doc) {
            if (isset($doc['name'])) {
                $names[] = $doc['name'];
            }
        }
        return array_unique($names);
    }
    /** @inheritDoc */
    public function deleteAuthor(string $id): void
    {
        $this->getCollection('authors')->deleteOne(['_id' => $id]);
    }
    /** @inheritDoc */
    public function searchAuthorsByName(string $term): array
    {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($term), 'i');
        $cursor = $this->getCollection('authors')->find(['name' => $regex]);
        $names = [];
        foreach ($cursor as $doc) {
            if (isset($doc['name'])) {
                $names[] = $doc['name'];
            }
        }
        return array_unique($names);
    }

    // MESSAGES
    /** @inheritDoc */
    public function createMessage(array $messageData): ?string
    {
        $result = $this->getCollection('messages')->insertOne($messageData);
        return (string) $result->getInsertedId();
    }
    /** @inheritDoc */
    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        $filter = [];
        if ($userId) {
            $filter['user_id'] = $userId;
        }
        if (!$includeAcknowledged) {
            $filter['acknowledged_at'] = null;
        }
        $cursor = $this->getCollection('messages')->find($filter, ['limit' => $limit, 'sort' => ['created_at' => -1]]);
        $messages = [];
        foreach ($cursor as $doc) {
            $doc['id'] = (string) $doc['_id'];
            $messages[] = $doc;
        }
        return $messages;
    }

    // JOBS
    /** @inheritDoc */
    public function listJobs(?string $type = null, ?string $status = null, int $limit = 50, string $orderBy = 'updated_at', string $direction = 'DESC', ?string $startAfterId = null): array
    {
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
        // Not implemented: placeholder
        return [];
    }
    /** @inheritDoc */
    public function getQueueCollection($name)
    {
        return $this->getCollection($name);
    }

    // GENERIC
    /** @inheritDoc */
    public function getDocument(string $collection, string $docId): ?array
    {
        $doc = $this->getCollection($collection)->findOne(['_id' => $docId]);
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
    public function getClient()
    {
        return $this->client;
    }
}
