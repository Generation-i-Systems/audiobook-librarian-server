<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\MySqlService;
use App\Services\MongoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MigrateMongoToMysql extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-mongo-to-mysql {--force : Skip confirmation prompt} {--limit=0 : Limit the number of books to process (0 for no limit)} {--no-backup : Skip automatic database backup} {--fix-data : Apply data fixing logic during migration} {--no-wipe : Do not wipe existing MySQL data before migration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate data from MongoDB to MySQL (creates a database backup by default, with optional data fixing and wipe control)';

    protected MySqlService $mysqlService;
    protected MongoService $mongoService;
    protected $processedBooks = 0;
    protected $bookLimit = 0;
    protected int $mergedAuthorCount = 0;
    protected int $mergedNarratorCount = 0;
    protected int $failedBookCount = 0;

    public function __construct(MySqlService $mysqlService)
    {
        parent::__construct();
        $this->mysqlService = $mysqlService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before migration...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        // Explicitly resolve MongoService here to ensure we always use it for MongoDB operations
        Log::debug('MigrateMongoToMysql: Instantiating MongoService');
        $this->mongoService = app(\App\Services\MongoService::class);

        $this->info('Starting data migration from MongoDB to MySQL...');

        // Get book counts before any truncation
        $mongoBookCount = $this->mongoService->dumpAllBooks()['total'];
        $mysqlBookCount = Book::count();

        Log::debug("MigrateMongoToMysql: MongoDB book count: {$mongoBookCount}");
        Log::debug("MigrateMongoToMysql: MySQL book count: {$mysqlBookCount}");

        $shouldTruncate = false;
        
        // Check if --no-wipe option is set
        if ($this->option('no-wipe')) {
            $this->info('--no-wipe option set. Skipping database wipe. Existing MySQL records will be kept and new records will be added/updated.');
            $shouldTruncate = false;
        } elseif ($mongoBookCount === $mysqlBookCount) {
            $this->info("MongoDB and MySQL book counts match ({$mongoBookCount} records). Proceeding with truncation.");
            $shouldTruncate = true;
        } else {
            $this->warn("MongoDB has {$mongoBookCount} books, while MySQL has {$mysqlBookCount} books.");
            if ($this->option('force') || $this->confirm('Do you want to wipe the MySQL database before migrating? (Default: No, will add records without deleting old ones)')) {
                $shouldTruncate = true;
            } else {
                $this->info('Skipping database wipe. Existing MySQL records will be kept and new records will be added/updated.');
            }
        }

        if ($shouldTruncate) {
            Log::debug("MigrateMongoToMysql: Before truncateTables()");
            $this->truncateTables();
            Log::debug("MigrateMongoToMysql: After truncateTables()");
        } else {
            Log::debug("MigrateMongoToMysql: Skipping truncateTables() as per user confirmation.");
        }

        try {
            $this->info("Migrating users...");
            Log::debug("MigrateMongoToMysql: Before getAllUsers()");
            $mongoUsers = $this->mongoService->getAllUsers();
            Log::debug("MigrateMongoToMysql: After getAllUsers(), found " . count($mongoUsers) . " users.");
            $userProgressBar = $this->output->createProgressBar(count($mongoUsers));
            $userProgressBar->start();

            foreach ($mongoUsers as $mongoUser) {
                $email = $mongoUser['email'] ?? null;
                if (empty($email)) {
                    $this->warn("Skipping user with missing email");
                    continue;
                }

                $existingUser = $this->mysqlService->getUserByEmail($email);

                // Generate username from email if not provided
                $username = $mongoUser['username'] ?? null;
                if (empty($username)) {
                    $username = strtok($email, '@'); // Use part before @ as username
                    if ($this->option('verbose')) {
                        $this->info("Generated username '{$username}' for user with email '{$email}'");
                    }
                }

                $userData = [
                    'name' => $mongoUser['name'] ?? $username, // Use username as name if name is not provided
                    'email' => $email,
                    'username' => $username,
                    'password' => $mongoUser['password'] ?? bcrypt(Str::random(16)), // Generate random password if not provided
                    'role' => $mongoUser['role'] ?? 'user',
                    'email_verified_at' => now(), // Mark email as verified
                ];

                if ($existingUser) {
                    $this->mysqlService->updateUser($existingUser['id'], $userData);
                } else {
                    $this->mysqlService->createUser($userData);
                }
                $userProgressBar->advance();
            }
            $userProgressBar->finish();
            $this->output->newLine();
            $this->info("Successfully migrated " . count($mongoUsers) . " users.");

            Log::debug("MigrateMongoToMysql: Before dumpAllBooks()");
            $mongoBooksResult = $this->mongoService->dumpAllBooks();
            $mongoBooks = $mongoBooksResult['data'];
            $totalBooks = $mongoBooksResult['total'];
            Log::debug("MigrateMongoToMysql: After dumpAllBooks(), found " . count($mongoBooks) . " books.");
            $this->bookLimit = (int) $this->option('limit');

            if ($this->bookLimit > 0 && $this->bookLimit < $totalBooks) {
                $this->info("Processing only first {$this->bookLimit} books out of $totalBooks (limited by --limit option)");
                $mongoBooks = array_slice($mongoBooks, 0, $this->bookLimit);
            }

            if ($this->option('fix-data')) {
                $this->info('Identifying and filtering duplicate books by directoryPath...');
                $uniqueMongoBooks = [];
                $seenDirectoryPaths = [];
                $skippedDuplicates = 0;

                foreach ($mongoBooks as $book) {
                    $dir = $book['directoryPath'] ?? null;
                    if ($dir) {
                        if (isset($seenDirectoryPaths[$dir])) {
                            // This is a duplicate, skip it
                            $this->warn("Skipping duplicate book by directoryPath: {$dir} (ID: {$book['_id']})");
                            $skippedDuplicates++;
                            continue;
                        } else {
                            $seenDirectoryPaths[$dir] = true;
                        }
                    }
                    $uniqueMongoBooks[] = $book;
                }
                $mongoBooks = $uniqueMongoBooks;
                $this->info("Skipped {$skippedDuplicates} duplicate books based on directoryPath.");
            }

            if (empty($mongoBooks)) {
                $this->info('No books found in MongoDB to migrate after filtering.');
                return 0;
            }

            $allAuthors = [];
            $allNarrators = [];
            $allGenres = [];

            foreach ($mongoBooks as $mongoBook) {
                foreach ((array) ($mongoBook['author'] ?? []) as $authorName) {
                    $allAuthors[] = is_array($authorName) && isset($authorName['name']) ? $authorName['name'] : $authorName;
                }
                foreach ((array) ($mongoBook['narrator'] ?? []) as $narratorName) {
                    $allNarrators[] = is_array($narratorName) && isset($narratorName['name']) ? $narratorName['name'] : $narratorName;
                }
                foreach ((array) ($mongoBook['genre'] ?? []) as $genreName) {
                    $allGenres[] = is_array($genreName) && isset($genreName['name']) ? $genreName['name'] : $genreName;
                }
            }

            $normalize = function ($name) {
                if (!is_string($name)) return null;
                // Normalize to UTF-8 and remove BOM
                $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
                $bom = pack('H*', 'EFBBBF');
                $name = preg_replace("/^$bom/", '', $name);
                // Standardize whitespace and convert to lowercase
                return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)), 'UTF-8');
            };

            // Get unique raw names first
            $uniqueRawAuthors = array_unique(array_filter($allAuthors));
            $normalizedAuthors = [];
            foreach ($uniqueRawAuthors as $authorName) {
                $normalized = $normalize($authorName);
                if ($normalized && !isset($normalizedAuthors[$normalized])) {
                    $normalizedAuthors[$normalized] = $authorName; // Keep first-seen casing
                }
            }
            $this->mergedAuthorCount = count($uniqueRawAuthors) - count($normalizedAuthors);
            $authorsToInsert = array_values($normalizedAuthors);

            // Normalize and count narrator merges
            $uniqueRawNarrators = array_unique(array_filter($allNarrators));
            $normalizedNarrators = [];
            foreach ($uniqueRawNarrators as $narratorName) {
                $normalized = $normalize($narratorName);
                if ($normalized && !isset($normalizedNarrators[$normalized])) {
                    $normalizedNarrators[$normalized] = $narratorName;
                }
            }
            $this->mergedNarratorCount = count($uniqueRawNarrators) - count($normalizedNarrators);
            $narratorsToInsert = array_values($normalizedNarrators);

            $allGenres = array_unique(array_filter($allGenres));

            if ($this->option('verbose')) {
                $this->info("Found " . count($authorsToInsert) . " unique authors (after merging {$this->mergedAuthorCount} duplicates). ");
                $this->info("Found " . count($narratorsToInsert) . " unique narrators (after merging {$this->mergedNarratorCount} duplicates). ");
                $this->info("Found " . count($allGenres) . " unique genres.");
            }

            // Bulk insert authors, narrators, and genres
            if ($this->option('verbose')) {
                $this->info("Bulk inserting authors...");
            }
            $authorInserts = [];
            foreach ($authorsToInsert as $authorName) {
                $authorInserts[] = ['name' => $authorName];
            }
            if (!empty($authorInserts)) {
                Author::insertOrIgnore($authorInserts);
            }

            if ($this->option('verbose')) {
                $this->info("Bulk inserting narrators...");
            }
            $narratorInserts = [];
            foreach ($narratorsToInsert as $narratorName) {
                $narratorInserts[] = ['name' => $narratorName];
            }
            if (!empty($narratorInserts)) {
                Narrator::insertOrIgnore($narratorInserts);
            }

            if ($this->option('verbose')) {
                $this->info("Bulk inserting genres...");
            }
            $genreInserts = [];
            foreach ($allGenres as $genreName) {
                $genreInserts[] = ['name' => $genreName];
            }
            if (!empty($genreInserts)) {
                Genre::insertOrIgnore($genreInserts);
            }
            if ($this->option('verbose')) {
                $this->info("Bulk inserts complete.");
            }

            // Fetch all authors, narrators, and genres from MySQL into maps
            $mysqlAuthorsMap = Author::all()->pluck('id', 'name')->toArray();
            $mysqlNarratorsMap = Narrator::all()->pluck('id', 'name')->toArray();
            $mysqlGenresMap = Genre::all()->pluck('id', 'name')->toArray();

            // Reset progress bar for book processing
            $progressBar = $this->output->createProgressBar($totalBooks);
            $progressBar->start();

            foreach ($mongoBooks as $mongoBook) {
                $this->createOrUpdateBook($mongoBook, $this->mysqlService, $mysqlAuthorsMap, $mysqlNarratorsMap, $mysqlGenresMap);
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->output->newLine();
            $this->info("Successfully migrated $totalBooks books.");

            if ($this->failedBookCount > 0) {
                $this->warn("{$this->failedBookCount} books failed to migrate. Details logged to failed_books.log");
            }

            $this->runSanityChecks($this->mysqlService);

            // Run cover image fixing and title processing after migration
            $this->info("Running cover image fixes and title processing...");
            $this->call('cover:check');
            $this->call('books:process-titles-interactive');

        } catch (\Exception $e) {
            $this->error("An error occurred during migration: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function truncateTables()
    {
        $this->info('Truncating MySQL tables...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Book::query()->delete();
        Author::query()->delete();
        Narrator::query()->delete();
        Genre::query()->delete();
        Series::query()->delete();
        Chapter::query()->delete();
        DB::table('author_book')->delete();
        DB::table('book_narrator')->delete();
        DB::table('book_genre')->delete();
        DB::table('users')->delete(); // Add this line to truncate users table
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $this->info('Tables truncated.');
    }

    private function createOrUpdateBook($mongoBook, MySqlService $mysqlService, array $mysqlAuthorsMap, array $mysqlNarratorsMap, array $mysqlGenresMap)
    {
        // Helper function to normalize names
        $normalizeName = function ($name) {
            if (!is_string($name)) {
                return null;
            }

            // Convert to UTF-8 if not already
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');

            // Remove BOM if present
            $bom = pack('H*', 'EFBBBF');
            $name = preg_replace("/^$bom/", '', $name);

            // Normalize line endings and whitespace
            $name = preg_replace('/\s+/', ' ', trim($name));

            // Convert to lowercase for case-insensitive comparison
            return mb_strtolower($name, 'UTF-8');
        };

        // Process authors
        $authors = [];
        $authorData = $mongoBook['author'] ?? null;
        $authorNames = [];

        if (is_array($authorData)) {
            // Handle case where author is an array of names or objects
            foreach ($authorData as $authorItem) {
                $name = is_array($authorItem) ? ($authorItem['name'] ?? $authorItem['author']['name'] ?? null) : $authorItem;
                if ($name && $normalizedName = $normalizeName($name)) {
                    $authorNames[] = $normalizedName;
                }
            }
        } elseif (is_string($authorData)) {
            // Handle case where author is a single string
            if ($normalizedName = $normalizeName($authorData)) {
                $authorNames[] = $normalizedName;
            }
        } elseif (isset($mongoBook['authors']) && is_array($mongoBook['authors'])) {
            // Fallback to 'authors' field if 'author' is not set
            foreach ($mongoBook['authors'] as $authorItem) {
                $name = is_array($authorItem) ? ($authorItem['name'] ?? $authorItem['author']['name'] ?? null) : $authorItem;
                if ($name && $normalizedName = $normalizeName($name)) {
                    $authorNames[] = $normalizedName;
                }
            }
        }

        // Remove duplicates while preserving original case in the map
        $uniqueAuthors = [];
        foreach (array_unique($authorNames) as $name) {
            if (empty($name))
                continue;
            $uniqueAuthors[$name] = $name; // Use normalized name as key, original as value
        }

        foreach ($uniqueAuthors as $normalizedName) {
            $authorId = null;
            $found = false;

            // Try to find in the current map first (case-insensitive)
            foreach ($mysqlAuthorsMap as $storedName => $id) {
                if ($normalizeName($storedName) === $normalizedName) {
                    $authorId = $id;
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $authors[] = $authorId;
            } else {
                try {
                    // Try to find existing author in DB with case-insensitive search
                    $authorModel = Author::whereRaw('LOWER(name) = ?', [$normalizedName])->first();

                    if (!$authorModel) {
                        // Create new author if not found
                        $authorModel = Author::create(['name' => $normalizedName]); // Store normalized name
                    }

                    $authors[] = $authorModel->id;
                    // Update the map with the actual name from the DB and its ID
                    $mysqlAuthorsMap[$authorModel->name] = $authorModel->id;
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->errorInfo[1] == 1062) { // Duplicate entry
                        $this->mergedAuthorCount++;
                        $authorModel = Author::whereRaw('LOWER(name) = ?', [$normalizedName])->first();
                        if ($authorModel) {
                            $authors[] = $authorModel->id;
                            $mysqlAuthorsMap[$authorModel->name] = $authorModel->id;
                        }
                    } else {
                        throw $e;
                    }
                }
            }
        }

        // Process narrators
        $narrators = [];
        $narratorData = $mongoBook['narrator'] ?? $mongoBook['narrators'] ?? null;
        $narratorNames = [];

        Log::debug("Narrator data from MongoDB: " . json_encode($narratorData));

        // Helper function to normalize names
        $normalizeName = function ($name) {
            if (!is_string($name))
                return null;

            // Normalize to UTF-8
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');

            // Remove BOM if present
            $bom = pack('H*', 'EFBBBF');
            $name = preg_replace("/^$bom/", '', $name);

            // Normalize line endings and whitespace
            $name = preg_replace('/\s+/', ' ', trim($name));

            // Convert to title case for consistency (but preserve original for display)
            $normalized = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');

            return $normalized;
        };

        // Handle different narrator data formats
        if (is_array($narratorData)) {
            foreach ($narratorData as $narrator) {
                if (is_string($narrator)) {
                    if ($normalized = $normalizeName($narrator)) {
                        $narratorNames[] = $normalized;
                    }
                } elseif (is_array($narrator)) {
                    // Try different possible name fields
                    $name = null;
                    if (isset($narrator['name'])) {
                        $name = $narrator['name'];
                    } elseif (isset($narrator['narrator']['name'])) {
                        $name = $narrator['narrator']['name'];
                    } elseif (!empty($narrator)) {
                        // Try to use the first string value in the array
                        $firstValue = reset($narrator);
                        if (is_string($firstValue)) {
                            $name = $firstValue;
                        }
                    }

                    if ($name && $normalized = $normalizeName($name)) {
                        $narratorNames[] = $normalized;
                    }
                }
            }
        } elseif (is_string($narratorData)) {
            if ($normalized = $normalizeName($narratorData)) {
                $narratorNames[] = $normalized;
            }
        }

        Log::debug("Extracted narrator names (before unique): " . json_encode($narratorNames));

        // Remove duplicates while preserving original case in the map
        $uniqueNarrators = [];
        foreach (array_unique($narratorNames) as $name) {
            if (empty($name))
                continue;

            $lowerName = mb_strtolower($name, 'UTF-8');
            if (!isset($uniqueNarrators[$lowerName])) {
                $uniqueNarrators[$lowerName] = $name;
            }
        }

        Log::debug("Unique narrators (normalized => original): " . json_encode($uniqueNarrators));

        // Process each unique narrator
        foreach ($uniqueNarrators as $normalized => $originalName) {
            Log::debug("Processing unique narrator: {$originalName} (Normalized: {$normalized})");
            // Check if we already have this narrator in our map (case-insensitive)
            $found = false;
            $narratorId = null;

            foreach ($mysqlNarratorsMap as $storedName => $id) {
                if (mb_strtolower($storedName, 'UTF-8') === $normalized) {
                    $found = true;
                    $narratorId = $id;
                    Log::debug("  -> Found in MySQL map: {$storedName} (ID: {$id})");
                    break;
                }
            }

            if ($found) {
                $narrators[] = $narratorId;
            } else {
                try {
                    // First try to find existing narrator with case-insensitive search
                    $narratorModel = Narrator::whereRaw('LOWER(name) = ?', [mb_strtolower($originalName, 'UTF-8')])->first();

                    if (!$narratorModel) {
                        // Create new narrator if not found
                        $narratorModel = Narrator::create(['name' => $originalName]);
                        Log::debug("  -> Created new narrator: {$originalName} (ID: {$narratorModel->id})");
                    }

                    $narrators[] = $narratorModel->id;
                    $mysqlNarratorsMap[$originalName] = $narratorModel->id;
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->errorInfo[1] == 1062) { // Duplicate entry
                        $this->mergedNarratorCount++;
                        $narratorModel = Narrator::whereRaw('LOWER(name) = ?', [mb_strtolower($originalName, 'UTF-8')])->first();
                        if ($narratorModel) {
                            $narrators[] = $narratorModel->id;
                            $mysqlNarratorsMap[$narratorModel->name] = $narratorModel->id;
                        }
                    } else {
                        throw $e;
                    }
                } catch (\Exception $e) {
                    $this->error("Error processing narrator '$originalName': " . $e->getMessage());
                    Log::error("Error processing narrator '$originalName': " . $e->getMessage() . "\n" . $e->getTraceAsString());
                }
            }
        }

        // Process genres
        $genres = [];
        $genreData = $mongoBook['genre'] ?? null;
        $genreNames = [];

        if (is_array($genreData)) {
            $genreNames = $genreData;
        } elseif (is_string($genreData)) {
            $genreNames = [$genreData];
        } elseif (isset($mongoBook['genres']) && is_array($mongoBook['genres'])) {
            $genreNames = $mongoBook['genres'];
        }

        foreach ($genreNames as $genre) {
            $name = is_array($genre) ? ($genre['name'] ?? $genre['genre']['name'] ?? null) : $genre;
            if ($name) {
                if (isset($mysqlGenresMap[$name])) {
                    $genres[] = $mysqlGenresMap[$name];
                } else {
                    if ($this->option('verbose')) {
                        $this->warn("Genre not found in map: " . $name);
                    }
                    $genreModel = Genre::firstOrCreate(['name' => $name]);
                    $genres[] = $genreModel->id;
                    $mysqlGenresMap[$name] = $genreModel->id;
                }
            }
        }

        // Handle series data
        $series = null;
        $seriesNumber = null;

        // Check for series data in different possible formats
        if (!empty($mongoBook['series'])) {
            // Handle array of series with seriesName and number
            if (is_array($mongoBook['series']) && isset($mongoBook['series'][0])) {
                $seriesData = $mongoBook['series'][0];
                $seriesName = $seriesData['seriesName'] ?? $seriesData['name'] ?? null;
                $seriesNumber = $seriesData['number'] ?? $seriesData['sequence'] ?? null;

                if ($seriesName) {
                    $series = Series::firstOrCreate(['name' => $seriesName]);
                }
            }
            // Handle single series name string
            elseif (is_string($mongoBook['series'])) {
                $series = Series::firstOrCreate(['name' => $mongoBook['series']]);
            }
        }
        // Fallback to series_name field
        elseif (!empty($mongoBook['series_name'])) {
            $series = Series::firstOrCreate(['name' => $mongoBook['series_name']]);
            $seriesNumber = $mongoBook['series_number'] ?? $mongoBook['book_number'] ?? null;
        }

        // Get the MongoDB ID with robust checking for $oid format
        $mongoId = null;
        if (isset($mongoBook['_id'])) {
            if (is_object($mongoBook['_id']) && property_exists($mongoBook['_id'], '$oid')) {
                $mongoId = $mongoBook['_id']->{'$oid'};
                Log::debug("Mongo ID (from $oid): " . $mongoId);
            } elseif (is_object($mongoBook['_id']) && method_exists($mongoBook['_id'], '__toString')) {
                $mongoId = (string) $mongoBook['_id'];
                Log::debug("Mongo ID (from __toString): " . $mongoId);
            } elseif (is_scalar($mongoBook['_id'])) {
                $mongoId = (string) $mongoBook['_id'];
                Log::debug("Mongo ID (from scalar): " . $mongoId);
            }

            if (empty($mongoId) && isset($mongoBook['id'])) {
                $mongoId = (string) $mongoBook['id'];
                Log::debug("Mongo ID (from 'id' field): " . $mongoId);
            }
        }
        Log::debug("Final Mongo ID for " . ($mongoBook['title'] ?? 'Unknown') . ": " . ($mongoId ?? 'NULL'));

        // Prepare the book data with all fields
        $bookData = [
            // Basic fields
            'mongo_id' => $mongoId,
            'title' => !empty($mongoBook['title']) ? $mongoBook['title'] : 'Untitled',
            'description' => $mongoBook['description'] ?? $mongoBook['summary'] ?? null,
            'release_date' => $mongoBook['release_date'] ?? $mongoBook['publication_year'] ?? $mongoBook['year'] ?? null,
            'cover_image' => $mongoBook['cover_image'] ?? $mongoBook['coverImage'] ?? null,
            'language' => $mongoBook['language'] ?? 'en',
            'source' => $mongoBook['source'] ?? 'unknown',

            // New fields from MongoDB
            'duration' => $mongoBook['duration'] ?? $mongoBook['length'] ?? null,
            'publisher' => $mongoBook['publisher'] ?? $mongoBook['publisherName'] ?? null,
            'needs_review' => $mongoBook['needsReview'] ?? $mongoBook['needs_review'] ?? false,
            'needs_review_reasons' => isset($mongoBook['needsReviewReasons'])
                ? (is_string($mongoBook['needsReviewReasons'])
                    ? $mongoBook['needsReviewReasons']
                    : json_encode($mongoBook['needsReviewReasons']))
                : null,
            'audio_file_count' => $mongoBook['audioFileCount'] ?? $mongoBook['num_files'] ?? 0,
            'directory_path' => $mongoBook['directoryPath'] ?? $mongoBook['path'] ?? null,

            // JSON fields - handle both string and array input
            'mongo_record' => is_string($mongoBook) ? $mongoBook : json_encode($mongoBook, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'file_tags' => !empty($mongoBook['fileTags'])
                ? (is_string($mongoBook['fileTags'])
                    ? $mongoBook['fileTags']
                    : json_encode((array) $mongoBook['fileTags']))
                : null,
            'audible_info' => !empty($mongoBook['audible'])
                ? (is_string($mongoBook['audible'])
                    ? $mongoBook['audible']
                    : json_encode((array) $mongoBook['audible']))
                : null,
            'google_books_info' => !empty($mongoBook['googleBooks'])
                ? (is_string($mongoBook['googleBooks'])
                    ? $mongoBook['googleBooks']
                    : json_encode((array) $mongoBook['googleBooks']))
                : null,
            'hardcover_info' => !empty($mongoBook['hardcover'])
                ? (is_string($mongoBook['hardcover'])
                    ? $mongoBook['hardcover']
                    : json_encode((array) $mongoBook['hardcover']))
                : null,
            'audiobook_bay_info' => !empty($mongoBook['audiobookBay'])
                ? (is_string($mongoBook['audiobookBay'])
                    ? $mongoBook['audiobookBay']
                    : json_encode((array) $mongoBook['audiobookBay']))
                : null,

            // Timestamps - handle both string and Carbon instances
            'created_at' => $mongoBook['dateAdded'] ?? $mongoBook['created_at'] ?? now(),
            'updated_at' => $mongoBook['updatedAt'] ?? $mongoBook['updated_at'] ?? now(),
        ];

        // Apply data fixing if --fix-data option is enabled
        if ($this->option('fix-data')) {
            // Fix title: remove leading spaces/dashes and extract leading numbers as series number
            $titleInfo = $this->extractLeadingNumberAsSeries($bookData['title']);
            $bookData['title'] = $this->cleanLeadingChars($titleInfo['title']);
            if ($titleInfo['seriesNumber'] !== null) {
                $seriesNumber = $titleInfo['seriesNumber'];
            }

            // Fix cover image path
            if (!empty($bookData['cover_image']) && !empty($bookData['directory_path'])) {
                $bookData['cover_image'] = $this->processCoverImagePath($bookData['cover_image'], $bookData['directory_path']);
            } elseif (empty($bookData['cover_image']) && !empty($bookData['directory_path'])) {
                // If no cover image is set, try to find the best one in the directory
                $bestImage = $this->findBestCoverImage($bookData, 'books');
                if ($bestImage) {
                    $bookData['cover_image'] = $bestImage;
                }
            }
        }

        // Clean up any empty strings that should be null
        $bookData = array_map(fn($value) => $value === '' ? null : $value, $bookData);

        try {
            $book = null;
            Log::debug("Attempting to find book by mongo_id: " . ($mongoId ?? 'NULL'));
            // 1. Try to find existing book by mongo_id first (most reliable)
            if ($mongoId) {
                $book = Book::where('mongo_id', $mongoId)->first();
            }

            if ($book) {
                Log::debug("Book found by mongo_id. Updating book: " . $book->id);
                // Book found by mongo_id, update it
                $book->update($bookData);
            } else {
                Log::debug("Book not found by mongo_id. Attempting to find by title and authors.");
                // 2. If not found by mongo_id, try to find by title and authors (more flexible matching)
                $book = Book::where('title', $bookData['title'])
                    ->whereHas('authors', function ($q) use ($authors) {
                        $q->whereIn('author_id', $authors);
                    })
                    ->first();

                if ($book) {
                    Log::debug("Book found by title/author. Updating mongo_id and book data for book: " . $book->id);
                    // Book found by title/author, update its mongo_id and other data
                    $bookData['mongo_id'] = $mongoId; // Ensure mongo_id is set for existing book
                    $book->update($bookData);
                } else {
                    Log::debug("Book not found by title/author. Creating new book.");
                    // 3. Otherwise, create a new book
                    $book = $mysqlService->createBook($bookData);
                    Log::debug("New book created with ID: " . ($book->id ?? 'NULL'));
                }
            }

            // Sync relationships with additional pivot data if needed
            if (!empty($authors)) {
                Log::debug("Syncing authors for book: " . $book->id);
                $book->authors()->sync($authors);
            } else {
                Log::debug("No authors to sync for book: " . ($book->title ?? 'Unknown'));
            }

            if (!empty($narrators)) {
                Log::debug("Syncing narrators for book: " . $book->id);
                $book->narrators()->sync($narrators);
            } else {
                Log::debug("No narrators to sync for book: " . ($book->title ?? 'Unknown'));
            }

            if (!empty($genres)) {
                Log::debug("Syncing genres for book: " . $book->id);
                $book->genres()->sync($genres);
            } else {
                Log::debug("No genres to sync for book: " . ($book->title ?? 'Unknown'));
            }

            // Sync series with series_number in the pivot table if series exists
            if ($series) {
                Log::debug("Syncing series for book: " . $book->id);
                // Ensure we have a valid series number (default to null if not set)
                $seriesNumber ??= null;

                // Detach any existing series relationships first to avoid duplicates
                $book->series()->detach();

                // Attach with series_number in the pivot table
                $book->series()->attach($series->id, ['series_number' => $seriesNumber]);
            } else {
                Log::debug("No series to sync for book: " . ($book->title ?? 'Unknown'));
            }
        } catch (\Exception $e) {
            $this->failedBookCount++;
            $errorMessage = "Error processing book {$bookData['title']}: " . $e->getMessage();
            $this->error($errorMessage); // Output to console

            // Log full exception details to the main application log
            Log::error("Migration Error: " . $errorMessage . "\n" . $e->getTraceAsString());

            // Log failed book details to the dedicated failed_books channel
            Log::channel('failed_books')->error("Failed Book Migration", [
                'title' => $bookData['title'],
                'mongo_id' => $mongoId,
                'error' => $e->getMessage(),
                'mongo_document' => $mongoBook,
                'stack_trace' => $e->getTraceAsString(),
            ]);

            return null;
        }

        if (isset($mongoBook['chapters'])) {
            Log::debug("Processing chapters for book: " . $book->id);
            foreach ($mongoBook['chapters'] as $chapterData) {
                Chapter::create([
                    'book_id' => $book->id,
                    'chapter_number' => $chapterData['chapter_number'] ?? 0,
                    'file_name' => $chapterData['filename'] ?? null,
                    'format' => $chapterData['format'] ?? null,
                    'duration' => $chapterData['duration'] ?? null,
                    'size_bytes' => $chapterData['size'] ?? null,
                ]);
            }
        }

        return $book;
    }



    protected function cleanLeadingChars(string $title): string
    {
        return preg_replace('/^[\s\-]+/', '', trim($title));
    }

    protected function extractLeadingNumberAsSeries(string $title): array
    {
        $seriesNumber = null;
        $cleanedTitle = $title;

        if (preg_match('/^(\d+)[\s\-]* (.*)$/', $title, $matches)) {
            $extractedNumber = (int) $matches[1];
            $remainingTitle = $matches[2];

            // Simple heuristic: if it's a small number and not a year
            if ($extractedNumber > 0 && $extractedNumber < 1000) {
                $seriesNumber = $extractedNumber;
                $cleanedTitle = $remainingTitle;
            }
        }
        return ['title' => $cleanedTitle, 'seriesNumber' => $seriesNumber];
    }

    protected function findBestCoverImage(array $bookData, string $diskName): ?string
    {
        $directoryPath = $bookData['directory_path'] ?? null;
        if (empty($directoryPath)) {
            return null;
        }

        $fullBookDirPath = rtrim($directoryPath, '/');

        if (!Storage::disk($diskName)->exists($fullBookDirPath)) {
            return null;
        }

        $filesInDir = Storage::disk($diskName)->files($fullBookDirPath);

        $bestCoverCandidate = null;
        $audibleGoogleCandidate = null;
        $bestTitleMatchCandidate = null;
        $anyImageCandidate = null;

        $normalizedBookTitle = Str::slug($bookData['title'] ?? '');

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        foreach ($filesInDir as $filePath) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            if (!in_array(strtolower($extension), $imageExtensions)) {
                continue;
            }

            $fileName = basename($filePath);
            $normalizedFileName = Str::slug(pathinfo($fileName, PATHINFO_FILENAME));

            if (Str::contains(strtolower($fileName), 'cover')) {
                $bestCoverCandidate = $filePath;
                break;
            }

            if (Str::contains(strtolower($fileName), ['audible', 'google'])) {
                if (empty($audibleGoogleCandidate)) {
                    $audibleGoogleCandidate = $filePath;
                }
            }

            if (!empty($normalizedBookTitle) && Str::contains($normalizedFileName, $normalizedBookTitle)) {
                if (empty($bestTitleMatchCandidate)) {
                    $bestTitleMatchCandidate = $filePath;
                }
            }

            if (empty($anyImageCandidate)) {
                $anyImageCandidate = $filePath;
            }
        }

        return $bestCoverCandidate ?? $audibleGoogleCandidate ?? $bestTitleMatchCandidate ?? $anyImageCandidate;
    }

    protected function processCoverImagePath(string $coverImagePath, ?string $directoryPath = null): string
    {
        if (empty($directoryPath)) {
            return $coverImagePath;
        }

        // If the cover image path already contains the directory path, return as-is
        if (Str::startsWith($coverImagePath, $directoryPath)) {
            return $coverImagePath;
        }

        // If the coverImagePath is just a filename, check if it needs directoryPath prefix
        $baseFileName = basename($coverImagePath);
        $coverWithoutDir = rtrim($directoryPath, '/') . '/' . $baseFileName;

        // Check if file exists with directoryPath prefix
        if (Storage::disk('books')->exists($coverWithoutDir)) {
            return $coverWithoutDir;
        }

        return $coverImagePath;
    }

    /**
     *
     * Run sanity checks to verify data integrity after migration
     *
     *
     * @param MySqlService $mysqlService
     *
     * @return void
     */
    private function runSanityChecks(MySqlService $mysqlService): void
    {
        $this->info("\nRunning sanity checks...");

        $mongoBookCount = $this->mongoService->dumpAllBooks()['total'];
        $mysqlBookCount = Book::count();

        $this->info("MongoDB books: $mongoBookCount");
        $this->info("MySQL books: $mysqlBookCount");

        if ($mongoBookCount !== $mysqlBookCount) {
            $this->warn("Book count mismatch!");
        } else {
            $this->info("Book counts match.");
        }

        // Check authors
        $this->info("\nChecking authors...");
        try {
            $mongoAuthors = $this->mongoService->listAuthors();
            $mysqlAuthors = $mysqlService->listAuthors();

            $mongoAuthorCount = is_countable($mongoAuthors) ? count($mongoAuthors) : 0;
            $mysqlAuthorCount = is_countable($mysqlAuthors) ? count($mysqlAuthors) : 0;

            $this->info("MongoDB authors (original): $mongoAuthorCount");
            $this->info("MySQL authors (created): $mysqlAuthorCount");
            $this->info("Merged authors (due to case/UTF-8 differences): {$this->mergedAuthorCount}");

            $expectedMySqlCount = $mongoAuthorCount - $this->mergedAuthorCount;

            if ($mysqlAuthorCount !== $expectedMySqlCount) {
                $this->warn("Author count mismatch! Expected {$expectedMySqlCount}, found {$mysqlAuthorCount}");

                // Extract names safely
                $mongoAuthorNames = [];
                if (is_iterable($mongoAuthors)) {
                    foreach ($mongoAuthors as $author) {
                        $name = is_array($author)
                            ? ($author['name'] ?? null)
                            : (is_object($author) ? ($author->name ?? null) : $author);
                        if ($name) {
                            $mongoAuthorNames[] = $name;
                        }
                    }
                }
                $mysqlAuthorNames = [];

                // For MySQL, extract names from the author objects/arrays
                if (is_iterable($mysqlAuthors)) {
                    foreach ($mysqlAuthors as $author) {
                        $name = is_array($author)
                            ? ($author['name'] ?? null)
                            : (is_object($author) ? ($author->name ?? null) : $author);
                        if ($name) {
                            $mysqlAuthorNames[] = $name;
                        }
                    }
                }

                // Find missing authors
                $missingAuthors = array_diff($mongoAuthorNames, $mysqlAuthorNames);

                if (!empty($missingAuthors)) {
                    $this->info("Missing Authors in MySQL:");
                    if (count($missingAuthors) <= 20) {
                        foreach ($missingAuthors as $author) {
                            $this->info(
                                "  - " . $author . " (Hex: " .
                                (is_string($author) ? bin2hex($author) : 'N/A') . ")"
                            );
                        }
                    } else {
                        $this->info("  (Too many missing authors to list: " . count($missingAuthors) . ")");
                    }
                }

                // Find extra authors in MySQL that aren't in MongoDB
                $extraAuthors = array_diff($mysqlAuthorNames, $mongoAuthorNames);
                if (!empty($extraAuthors)) {
                    $this->info("Extra Authors in MySQL (not in MongoDB):");
                    if (count($extraAuthors) <= 20) {
                        foreach ($extraAuthors as $author) {
                            $this->info("  + " . $author);
                        }
                    } else {
                        $this->info("  (Too many extra authors to list: " . count($extraAuthors) . ")");
                    }
                }
            } else {
                $this->info("Author counts match after accounting for merges.");
            }
        } catch (\Exception $e) {
            $this->warn("Error checking author counts: " . $e->getMessage());
            $this->warn("Stack trace: " . $e->getTraceAsString());
        }

        // Check narrators using search with an empty string to get all
        $this->info("\nChecking narrators...");
        try {
            // Get all narrators from MongoDB using an empty search term (should return all)
            $mongoNarrators = $this->mongoService->searchNarratorsByName('');
            $mysqlNarrators = $mysqlService->listNarrators();

            $mongoNarratorCount = is_countable($mongoNarrators)
                ? count($mongoNarrators)
                : 0;
            $mysqlNarratorCount = is_countable($mysqlNarrators)
                ? count($mysqlNarrators)
                : 0;

            $this->info("MongoDB narrators (original): $mongoNarratorCount");
            $this->info("MySQL narrators (created): $mysqlNarratorCount");
            $this->info("Merged narrators (due to case/UTF-8 differences): {$this->mergedNarratorCount}");

            $expectedMySqlCount = $mongoNarratorCount - $this->mergedNarratorCount;

            if ($mysqlNarratorCount !== $expectedMySqlCount) {
                $this->warn("Narrator count mismatch! Expected {$expectedMySqlCount}, found {$mysqlNarratorCount}");

                // Extract names safely
                $mongoNarratorNames = is_iterable($mongoNarrators) ? $mongoNarrators : [];
                $mysqlNarratorNames = [];

                // For MySQL, extract names from the narrator objects/arrays
                if (is_iterable($mysqlNarrators)) {
                    foreach ($mysqlNarrators as $narrator) {
                        $name = is_array($narrator)
                            ? ($narrator['name'] ?? null)
                            : (is_object($narrator)
                                ? ($narrator->name ?? null)
                                : $narrator
                            );
                        if ($name) {
                            $mysqlNarratorNames[] = $name;
                        }
                    }
                }

                // Find missing narrators
                $missingNarrators = array_diff($mongoNarratorNames, $mysqlNarratorNames);

                if (!empty($missingNarrators)) {
                    $this->info("Missing Narrators in MySQL:");
                    if (count($missingNarrators) <= 20) {
                        foreach ($missingNarrators as $narrator) {
                            $this->info(
                                "  - " . $narrator . " (Hex: " .
                                (is_string($narrator) ? bin2hex($narrator) : 'N/A') . ")"
                            );
                        }
                    } else {
                        $this->info("  (Too many missing narrators to list: " . count($missingNarrators) . ")");
                    }
                }

                // Find extra narrators in MySQL that aren't in MongoDB
                $extraNarrators = array_diff($mysqlNarratorNames, $mongoNarratorNames);
                if (!empty($extraNarrators)) {
                    $this->info("Extra Narrators in MySQL (not in MongoDB):");
                    if (count($extraAuthors) <= 20) {
                        foreach ($extraAuthors as $narrator) {
                            $this->info("  + " . $narrator);
                        }
                    } else {
                        $this->info("  (Too many extra narrators to list: " . count($extraAuthors) . ")");
                    }
                }
            } else {
                $this->info("Narrator counts match after accounting for merges.");
            }
        } catch (\Exception $e) {
            $this->warn("Error checking narrator counts: " . $e->getMessage());
            $this->warn("Stack trace: " . $e->getTraceAsString());
        }

        // Filtering Sanity Checks
        $this->info("\nRunning filtering sanity checks...");

        // Test author filter
        $testAuthor = "Lee Child";
        try {
            $mongoResult = $this->mongoService->getBooksByAuthorAndGenre($testAuthor, null);
            $mongoBooksByAuthor = $mongoResult['data'] ?? [];
            $mysqlBooksByAuthor = Book::whereHas('authors', function ($q) use ($testAuthor) {
                $q->where('name', $testAuthor);
            })->get();

            $mongoCount = is_countable($mongoBooksByAuthor) ? count($mongoBooksByAuthor) : 0;
            $mysqlBookCount = is_countable($mysqlBooksByAuthor) ? count($mysqlBooksByAuthor) : 0;

            $this->info("Books by author '{$testAuthor}' in MongoDB: $mongoCount");
            $this->info("Books by author '{$testAuthor}' in MySQL: $mysqlBookCount");

            if ($mongoCount !== $mysqlBookCount) {
                $this->warn("Book count mismatch for author '{$testAuthor}'!");
            } else {
                $this->info("Book counts match for author '{$testAuthor}'.");
            }
        } catch (\Exception $e) {
            $this->warn("Error checking books by author: " . $e->getMessage());
        }

        // Test genre filter
        $testGenre = "Fantasy";
        try {
            $mongoResult = $this->mongoService->getBooksByAuthorAndGenre(null, $testGenre);
            $mongoBooksByGenre = $mongoResult['data'] ?? [];
            $mysqlBooksByGenre = Book::whereHas('genres', function ($q) use ($testGenre) {
                $q->where('name', $testGenre);
            })->get();

            $mongoCount = is_countable($mongoBooksByGenre) ? count($mongoBooksByGenre) : 0;
            $mysqlBookCount = is_countable($mysqlBooksByGenre) ? count($mysqlBooksByGenre) : 0;

            $this->info("Books by genre '{$testGenre}' in MongoDB: $mongoCount");
            $this->info("Books by genre '{$testGenre}' in MySQL: $mysqlBookCount");

            if ($mongoCount !== $mysqlBookCount) {
                $this->warn("Book count mismatch for genre '{$testGenre}'!");
            } else {
                $this->info("Book counts match for genre '{$testGenre}'.");
            }
        } catch (\Exception $e) {
            $this->warn("Error checking books by genre: " . $e->getMessage());
        }
    }
}
