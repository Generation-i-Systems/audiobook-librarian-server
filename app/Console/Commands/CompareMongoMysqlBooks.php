<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\Client as MongoClient;

class CompareMongoMysqlBooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:compare-mongo-mysql-books';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compares MongoDB book _ids with MySQL book mongo_ids to find unmigrated books.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting comparison of MongoDB and MySQL book IDs...');

        // Configure MongoDB connection
        $mongoConfig = config('database.connections.mongodb');
        $mongoClient = new MongoClient($mongoConfig['dsn']);
        $mongoDb = $mongoClient->{$mongoConfig['database']};
        $mongoCollection = $mongoDb->books;

        // Get all MongoDB book _ids
        $mongoBookIds = [];
        $this->info('Fetching MongoDB book IDs...');
        foreach ($mongoCollection->find([], ['projection' => ['_id' => 1]]) as $book) {
            $mongoBookIds[] = (string) $book['_id'];
        }
        $this->info('Found ' . count($mongoBookIds) . ' MongoDB book IDs.');

        $logPath = storage_path('logs/missing_books.log');

        // Check for duplicates within MongoDB
        $this->info('Checking for duplicate books within MongoDB...');
        $mongoBooksForDuplicateCheck = $mongoCollection->find([], [
            'projection' => [
                '_id' => 1,
                'title' => 1,
                'author' => 1,
                'directoryPath' => 1
            ]
        ]);

        $mongoDuplicates = [];
        $seenBooks = [];

        foreach ($mongoBooksForDuplicateCheck as $book) {
            $title = isset($book['title']) ? (is_array($book['title']) || $book['title'] instanceof \MongoDB\Model\BSONArray ? implode(', ', (array)$book['title']) : (string)$book['title']) : 'N/A';
            $author = isset($book['author']) ? (is_array($book['author']) || $book['author'] instanceof \MongoDB\Model\BSONArray ? implode(', ', (array)$book['author']) : (string)$book['author']) : 'N/A';
            $directoryPath = isset($book['directoryPath']) ? (is_array($book['directoryPath']) || $book['directoryPath'] instanceof \MongoDB\Model\BSONArray ? implode(', ', (array)$book['directoryPath']) : (string)$book['directoryPath']) : 'N/A';

            $keyByPath = md5($directoryPath);
            $keyByTitleAuthor = md5($title . '::' . $author);

            if (isset($seenBooks[$keyByPath])) {
                $mongoDuplicates['by_directory_path'][] = [
                    'original_id' => (string)$seenBooks[$keyByPath]['_id'],
                    'duplicate_id' => (string)$book['_id'],
                    'directoryPath' => $directoryPath,
                    'title' => $title,
                    'author' => $author,
                ];
            } else {
                $seenBooks[$keyByPath] = [
                    '_id' => $book['_id'],
                    'title' => $title,
                    'author' => $author,
                    'directoryPath' => $directoryPath,
                ];
            }

            if (isset($seenBooks[$keyByTitleAuthor]) && $keyByPath !== $keyByTitleAuthor) { // Avoid double counting if path and title/author are same
                $mongoDuplicates['by_title_author'][] = [
                    'original_id' => (string)$seenBooks[$keyByTitleAuthor]['_id'],
                    'duplicate_id' => (string)$book['_id'],
                    'directoryPath' => $directoryPath,
                    'title' => $title,
                    'author' => $author,
                ];
            } else {
                $seenBooks[$keyByTitleAuthor] = [
                    '_id' => $book['_id'],
                    'title' => $title,
                    'author' => $author,
                    'directoryPath' => $directoryPath,
                ];
            }
        }

        if (!empty($mongoDuplicates)) {
            Log::channel('daily')->info('--- MongoDB Duplicates Report (' . now()->toDateTimeString() . ') ---');
            if (isset($mongoDuplicates['by_directory_path'])) {
                Log::channel('daily')->info('Duplicates by Directory Path:');
                foreach ($mongoDuplicates['by_directory_path'] as $duplicate) {
                    Log::channel('daily')->info("  - Original ID: {$duplicate['original_id']}, Duplicate ID: {$duplicate['duplicate_id']}, Path: {$duplicate['directoryPath']}");
                }
            }
            if (isset($mongoDuplicates['by_title_author'])) {
                Log::channel('daily')->info('Duplicates by Title and Author:');
                foreach ($mongoDuplicates['by_title_author'] as $duplicate) {
                    Log::channel('daily')->info("  - Original ID: {$duplicate['original_id']}, Duplicate ID: {$duplicate['duplicate_id']}, Title: {$duplicate['title']}, Author: {$duplicate['author']}");
                }
            }
            $this->warn("MongoDB duplicate details logged to {$logPath}");
        } else {
            $this->info('No duplicate books found in MongoDB.');
        }

        $this->info('MongoDB Duplicates Summary:');
        $duplicatesByPathCount = isset($mongoDuplicates['by_directory_path']) ? count($mongoDuplicates['by_directory_path']) : 0;
        $duplicatesByTitleAuthorCount = isset($mongoDuplicates['by_title_author']) ? count($mongoDuplicates['by_title_author']) : 0;
        $this->info("  - By Directory Path: {$duplicatesByPathCount}");
        $this->info("  - By Title and Author: {$duplicatesByTitleAuthorCount}");

        // Get all MySQL book mongo_ids
        $mysqlBookMongoIds = [];
        $this->info('Fetching MySQL book mongo_ids...');
        $mysqlBooks = DB::table('books')->select('mongo_id')->get();
        foreach ($mysqlBooks as $book) {
            if ($book->mongo_id) {
                $mysqlBookMongoIds[] = $book->mongo_id;
            }
        }
        $this->info('Found ' . count($mysqlBookMongoIds) . ' MySQL book mongo_ids.');

        // Find MongoDB IDs that are not in MySQL
        $missingMongoIds = array_diff($mongoBookIds, $mysqlBookMongoIds);

        $this->info('Found ' . count($missingMongoIds) . ' books in MongoDB not yet migrated to MySQL.');

        if (!empty($missingMongoIds)) {
            $logPath = storage_path('logs/missing_books.log');
            Log::channel('daily')->info('--- Missing Books Report (' . now()->toDateTimeString() . ') ---');
            Log::channel('daily')->info('Total MongoDB Books: ' . count($mongoBookIds));
            Log::channel('daily')->info('Total MySQL Books (with mongo_id): ' . count($mysqlBookMongoIds));
            Log::channel('daily')->info('Unmigrated Books Count: ' . count($missingMongoIds));
            Log::channel('daily')->info('Unmigrated MongoDB Book IDs:');

            foreach ($missingMongoIds as $mongoId) {
                // Optionally, fetch more details for the missing book from MongoDB
                $logMessage = "  - MongoDB ID: {$mongoId}";
                $bookDetails = null;

                // Validate if the mongoId is a valid ObjectId before attempting to create a new ObjectId
                if (preg_match('/^[a-f0-9]{24}$/i', $mongoId)) {
                    try {
                        $bookDetails = $mongoCollection->findOne(['_id' => new \MongoDB\BSON\ObjectId($mongoId)]);
                    } catch (\Exception $e) {
                        Log::channel('daily')->warning("Could not fetch details for MongoDB ID {$mongoId}: " . $e->getMessage());
                    }
                } else {
                    $logMessage .= " | Note: Not a valid ObjectId format.";
                }

                if ($bookDetails) {
                    $logMessage .= " | Title: " . (isset($bookDetails['title']) ? (is_array($bookDetails['title']) || $bookDetails['title'] instanceof \MongoDB\Model\BSONArray ? implode(', ', (array)$bookDetails['title']) : (string)$bookDetails['title']) : 'N/A');
                    $logMessage .= " | Author: " . (isset($bookDetails['author']) ? (is_array($bookDetails['author']) || $bookDetails['author'] instanceof \MongoDB\Model\BSONArray ? implode(', ', (array)$bookDetails['author']) : (string)$bookDetails['author']) : 'N/A');
                    $logMessage .= " | Directory Path: " . (isset($bookDetails['directoryPath']) ? (is_array($bookDetails['directoryPath']) || $bookDetails['directoryPath'] instanceof \MongoDB\Model\BSONArray ? implode(', ', (array)$bookDetails['directoryPath']) : (string)$bookDetails['directoryPath']) : 'N/A');

                    // Check for existing books in MySQL with the same directoryPath or title/author
                    $mysqlPotentialMatches = \App\Models\Book::query()
                        ->where('directory_path', $bookDetails['directoryPath'] ?? null)
                        ->orWhere(function ($query) use ($bookDetails) {
                            $title = isset($bookDetails['title']) ? (is_array($bookDetails['title']) || $bookDetails['title'] instanceof \MongoDB\Model\BSONArray ? implode(', ', (array)$bookDetails['title']) : (string)$bookDetails['title']) : null;
                            $author = isset($bookDetails['author']) ? (is_array($bookDetails['author']) || $bookDetails['author'] instanceof \MongoDB\Model\BSONArray ? implode(', ', (array)$bookDetails['author']) : (string)$bookDetails['author']) : null;

                            $query->where('title', $title);
                            if ($author) {
                                $query->whereHas('authors', function ($q) use ($author) {
                                    $q->where('name', $author);
                                });
                            }
                        })
                        ->get();

                    if ($mysqlPotentialMatches->isNotEmpty()) {
                        $logMessage .= " | Potential MySQL Matches (by directoryPath or title/author): ";
                        foreach ($mysqlPotentialMatches as $match) {
                            $duplicateStatus = '';
                            if ($match->mongo_id !== null && $match->mongo_id !== $mongoId) {
                                $duplicateStatus = ' (Already Migrated - Different MongoID)';
                            } elseif ($match->mongo_id === $mongoId) {
                                $duplicateStatus = ' (Already Migrated - Same MongoID)';
                            }
                            $logMessage .= "[ID: {$match->id}, MongoID: {$match->mongo_id}, Title: {$match->title}, Author: {$match->author}, DirPath: {$match->directory_path}]{$duplicateStatus} ";
                        }
                    }
                }
                Log::channel('daily')->info($logMessage);
            }
            $this->warn("Details logged to {$logPath}");
        } else {
            $this->info('All MongoDB books appear to be migrated to MySQL.');
        }

        $this->info('Comparison complete.');
    }
}
