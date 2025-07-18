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
    protected $signature = 'app:migrate-mongo-to-mysql {--force : Skip confirmation prompt} {--limit=0 : Limit the number of books to process (0 for no limit)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate data from MongoDB to MySQL';

    protected DocumentStoreServiceInterface $mongoService;
    protected MySqlService $mysqlService;
    protected $processedBooks = 0;
    protected $bookLimit = 0;

    public function __construct(DocumentStoreServiceInterface $mongoService, MySqlService $mysqlService)
    {
        parent::__construct();
        $this->mongoService = $mongoService;
        $this->mysqlService = $mysqlService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting data migration from MongoDB to MySQL...');

        $this->truncateTables();

        try {
            $this->info("Migrating users...");
            $mongoUsers = $this->mongoService->getAllUsers();
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
                    $this->info("Generated username '{$username}' for user with email '{$email}'");
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
            $this->info("Successfully migrated " . count($mongoUsers) . " users.");

            $mongoBooks = $this->mongoService->dumpAllBooks();
            $totalBooks = count($mongoBooks);
            $this->bookLimit = (int) $this->option('limit');

            if ($this->bookLimit > 0 && $this->bookLimit < $totalBooks) {
                $this->info("Processing only first {$this->bookLimit} books out of $totalBooks (limited by --limit option)");
                $mongoBooks = array_slice($mongoBooks, 0, $this->bookLimit);
            } else {
                $this->info("Found $totalBooks books in MongoDB");
            }

            if (empty($mongoBooks)) {
                $this->info('No books found in MongoDB to migrate.');
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

            $allAuthors = array_unique(array_filter($allAuthors));
            $allNarrators = array_unique(array_filter($allNarrators));
            $allGenres = array_unique(array_filter($allGenres));

            $this->info("Found " . count($allAuthors) . " unique authors.");
            $this->info("Found " . count($allNarrators) . " unique narrators.");
            $this->info("Found " . count($allGenres) . " unique genres.");

            // Bulk insert authors, narrators, and genres
            $this->info("Bulk inserting authors...");
            $existingAuthors = Author::all()->pluck('id', 'name')->toArray();
            $authorInserts = [];
            foreach ($allAuthors as $authorName) {
                if (!isset($existingAuthors[$authorName])) {
                    $authorInserts[] = ['name' => $authorName];
                }
            }
            if (!empty($authorInserts)) {
                Author::insertOrIgnore($authorInserts);
            }

            $this->info("Bulk inserting narrators...");
            $existingNarrators = Narrator::all()->pluck('id', 'name')->toArray();
            $narratorInserts = [];
            foreach ($allNarrators as $narratorName) {
                if (!isset($existingNarrators[$narratorName])) {
                    $narratorInserts[] = ['name' => $narratorName];
                }
            }
            if (!empty($narratorInserts)) {
                Narrator::insertOrIgnore($narratorInserts);
            }

            $this->info("Bulk inserting genres...");
            $existingGenres = Genre::all()->pluck('id', 'name')->toArray();
            $genreInserts = [];
            foreach ($allGenres as $genreName) {
                if (!isset($existingGenres[$genreName])) {
                    $genreInserts[] = ['name' => $genreName];
                }
            }
            if (!empty($genreInserts)) {
                Genre::insertOrIgnore($genreInserts);
            }
            $this->info("Bulk inserts complete.");

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
            $this->info("Successfully migrated $totalBooks books.");

            $this->runSanityChecks($this->mysqlService);

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
        // Process authors
        $authors = [];
        $authorData = $mongoBook['author'] ?? null;
        $authorNames = [];

        if (is_array($authorData)) {
            // Handle case where author is an array of names or objects
            $authorNames = $authorData;
        } elseif (is_string($authorData)) {
            // Handle case where author is a single string
            $authorNames = [$authorData];
        } elseif (isset($mongoBook['authors']) && is_array($mongoBook['authors'])) {
            // Fallback to 'authors' field if 'author' is not set
            $authorNames = $mongoBook['authors'];
        }

        // Log author data for debugging
        $this->info('Author data: ' . json_encode([
            'authorData' => $authorData,
            'authorNames' => $authorNames,
            'authorsField' => $mongoBook['authors'] ?? null,
            'mysqlAuthorsMap' => array_slice($mysqlAuthorsMap, 0, 5, true) // First 5 entries for debugging
        ]));

        foreach ($authorNames as $author) {
            $name = is_array($author) ? ($author['name'] ?? $author['author']['name'] ?? null) : $author;
            if ($name) {
                // Try to find the author in the map, if not found, create a new one
                if (isset($mysqlAuthorsMap[$name])) {
                    $authors[] = $mysqlAuthorsMap[$name];
                } else {
                    $this->warn("Author not found in map: " . $name);
                    // Create the author if it doesn't exist
                    $authorModel = Author::firstOrCreate(['name' => $name]);
                    $authors[] = $authorModel->id;
                    // Update the map for future references
                    $mysqlAuthorsMap[$name] = $authorModel->id;
                }
            }
        }

        // Process narrators
        $narrators = [];
        $narratorData = $mongoBook['narrator'] ?? $mongoBook['narrators'] ?? null;
        $narratorNames = [];

        // Helper function to normalize names
        $normalizeName = function($name) {
            if (!is_string($name)) return null;

            // Normalize to UTF-8
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');

            // Remove BOM if present
            $bom = pack('H*','EFBBBF');
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

        // Remove duplicates while preserving original case in the map
        $uniqueNarrators = [];
        foreach (array_unique($narratorNames) as $name) {
            if (empty($name)) continue;

            $lowerName = mb_strtolower($name, 'UTF-8');
            if (!isset($uniqueNarrators[$lowerName])) {
                $uniqueNarrators[$lowerName] = $name;
            }
        }

        // Process each unique narrator
        foreach ($uniqueNarrators as $normalized => $originalName) {
            // Check if we already have this narrator in our map (case-insensitive)
            $found = false;
            $narratorId = null;

            foreach ($mysqlNarratorsMap as $storedName => $id) {
                if (mb_strtolower($storedName, 'UTF-8') === $normalized) {
                    $found = true;
                    $narratorId = $id;
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
                        $this->info("Created narrator: " . $originalName);
                    }

                    $narrators[] = $narratorModel->id;
                    $mysqlNarratorsMap[$originalName] = $narratorModel->id;
                } catch (\Exception $e) {
                    $this->error("Error processing narrator '$originalName': " . $e->getMessage());
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
                    $this->warn("Genre not found in map: " . $name);
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

        // Debug log the MongoDB document structure to a file
        $debugLog = storage_path('logs/mongo_debug.log');
        $debugInfo = [
            'time' => now()->toDateTimeString(),
            'title' => $mongoBook['title'] ?? 'Unknown',
            'document_keys' => array_keys($mongoBook),
            '_id_info' => [
                'exists' => array_key_exists('_id', $mongoBook),
                'type' => gettype($mongoBook['_id'] ?? null),
                'value' => $mongoBook['_id'] ?? null,
                'string_value' => isset($mongoBook['_id']) ? (string) $mongoBook['_id'] : null,
            ],
            'mongo_book' => $mongoBook, // Full document for reference
        ];

        file_put_contents($debugLog, json_encode($debugInfo, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

        // Get the MongoDB ID with robust checking for $oid format
        $mongoId = null;
        if (isset($mongoBook['_id'])) {
            // Handle MongoDB ObjectId format
            if (is_object($mongoBook['_id']) && property_exists($mongoBook['_id'], '$oid')) {
                $mongoId = $mongoBook['_id']->{'$oid'};
            }
            // Fallback to string conversion
            elseif (is_object($mongoBook['_id']) && method_exists($mongoBook['_id'], '__toString')) {
                $mongoId = (string) $mongoBook['_id'];
            }
            // Handle scalar values
            elseif (is_scalar($mongoBook['_id'])) {
                $mongoId = (string) $mongoBook['_id'];
            }

            // If we still don't have an ID, try to get it from the 'id' field
            if (empty($mongoId) && isset($mongoBook['id'])) {
                $mongoId = (string) $mongoBook['id'];
            }

            $this->info("Extracted MongoDB ID: " . ($mongoId ?? 'NULL'));
        }

        $this->info("Processing book: " . ($mongoBook['title'] ?? 'Unknown') . " - MongoDB ID: " . ($mongoId ?? 'NULL'));

        // Prepare the book data with all fields
        $bookData = [
            // Basic fields
            'mongo_id' => $mongoId,
            'title' => $mongoBook['title'] ?? 'Untitled',
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
                    : json_encode($mongoBook['fileTags']))
                : null,
            'audible_info' => !empty($mongoBook['audible'])
                ? (is_string($mongoBook['audible'])
                    ? $mongoBook['audible']
                    : json_encode($mongoBook['audible']))
                : null,
            'google_books_info' => !empty($mongoBook['googleBooks'])
                ? (is_string($mongoBook['googleBooks'])
                    ? $mongoBook['googleBooks']
                    : json_encode($mongoBook['googleBooks']))
                : null,
            'hardcover_info' => !empty($mongoBook['hardcover'])
                ? (is_string($mongoBook['hardcover'])
                    ? $mongoBook['hardcover']
                    : json_encode($mongoBook['hardcover']))
                : null,
            'audiobook_bay_info' => !empty($mongoBook['audiobookBay'])
                ? (is_string($mongoBook['audiobookBay'])
                    ? $mongoBook['audiobookBay']
                    : json_encode($mongoBook['audiobookBay']))
                : null,

            // Timestamps - handle both string and Carbon instances
            'created_at' => $mongoBook['dateAdded'] ?? $mongoBook['created_at'] ?? now(),
            'updated_at' => $mongoBook['updatedAt'] ?? $mongoBook['updated_at'] ?? now(),
        ];

        // Clean up any empty strings that should be null
        $bookData = array_map(fn($value) => $value === '' ? null : $value, $bookData);

        try {
            // First try to find existing book by title and author
            $existingBook = Book::where('title', $bookData['title'])
                ->whereHas('authors', function ($q) use ($authors) {
                    $q->whereIn('author_id', $authors);
                })->first();

            if ($existingBook) {
                $book = $existingBook;
                $book->update($bookData);
            } else {
                $book = $mysqlService->createBook($bookData);
            }

            // Sync relationships with additional pivot data if needed
            if (!empty($authors)) {
                $this->info('Syncing authors: ' . json_encode($authors));
                $book->authors()->sync($authors);
                $this->info('Authors synced successfully');
            } else {
                $this->warn("No authors to sync for book: " . $book->title);
            }

            if (!empty($narrators)) {
                $book->narrators()->sync($narrators);
                $this->info("Synced " . count($narrators) . " narrators with book: " . $book->title);
            } else {
                $this->warn("No narrators to sync for book: " . $book->title);
            }

            if (!empty($genres)) {
                $book->genres()->sync($genres);
                $this->info("Synced " . count($genres) . " genres with book: " . $book->title);
            } else {
                $this->warn("No genres to sync for book: " . $book->title);
            }

            // Sync series with series_number in the pivot table if series exists
            if ($series) {
                // Ensure we have a valid series number (default to null if not set)
                $seriesNumber ??= null;

                // Detach any existing series relationships first to avoid duplicates
                $book->series()->detach();

                // Attach with series_number in the pivot table
                $book->series()->attach($series->id, ['series_number' => $seriesNumber]);

                $this->info(sprintf(
                    'Book "%s" associated with series "%s" (number: %s)',
                    $book->title,
                    $series->name,
                    $seriesNumber
                ));
            }
        } catch (\Exception $e) {
            $this->error("Error processing book {$bookData['title']}: " . $e->getMessage());
            return null;
        }

        if (isset($mongoBook['chapters'])) {
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



    /**
     * Run sanity checks to verify data integrity after migration
     *
     * @param MySqlService $mysqlService
     * @return void
     */
    private function runSanityChecks(MySqlService $mysqlService): void
    {
        $this->info("\nRunning sanity checks...");

        $mongoBookCount = count($this->mongoService->dumpAllBooks());
        $mysqlBookCount = count($mysqlService->listBooks());

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

            $this->info("MongoDB authors: $mongoAuthorCount");
            $this->info("MySQL authors: $mysqlAuthorCount");

            if ($mongoAuthorCount !== $mysqlAuthorCount) {
                $this->warn("Author count mismatch!");

                // Extract names safely
                $mongoAuthorNames = is_iterable($mongoAuthors) ? $mongoAuthors : [];
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
                    foreach ($missingAuthors as $author) {
                        $this->info(
                            "  - " . $author . " (Hex: " .
                            (is_string($author) ? bin2hex($author) : 'N/A') . ")"
                        );
                    }
                }

                // Find extra authors in MySQL that aren't in MongoDB
                $extraAuthors = array_diff($mysqlAuthorNames, $mongoAuthorNames);
                if (!empty($extraAuthors)) {
                    $this->info("Extra Authors in MySQL (not in MongoDB):");
                    foreach ($extraAuthors as $author) {
                        $this->info("  + " . $author);
                    }
                }
            } else {
                $this->info("Author counts match.");
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

            $this->info("MongoDB narrators: $mongoNarratorCount");
            $this->info("MySQL narrators: $mysqlNarratorCount");

            if ($mongoNarratorCount !== $mysqlNarratorCount) {
                $this->warn("Narrator count mismatch!");

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
                    foreach ($missingNarrators as $narrator) {
                        $this->info(
                            "  - " . $narrator . " (Hex: " .
                            (is_string($narrator) ? bin2hex($narrator) : 'N/A') . ")"
                        );
                    }
                }

                // Find extra narrators in MySQL that aren't in MongoDB
                $extraNarrators = array_diff($mysqlNarratorNames, $mongoNarratorNames);
                if (!empty($extraNarrators)) {
                    $this->info("Extra Narrators in MySQL (not in MongoDB):");
                    foreach ($extraNarrators as $narrator) {
                        $this->info("  + " . $narrator);
                    }
                }
            } else {
                $this->info("Narrator counts match.");
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
            $mongoBooksByAuthor = $this->mongoService->getBooksByAuthorAndGenre($testAuthor, null);
            $mysqlBooksByAuthor = $mysqlService->getBooksByAuthorAndGenre($testAuthor, null);

            $mongoCount = is_countable($mongoBooksByAuthor) ? count($mongoBooksByAuthor) : 0;
            $mysqlCount = is_countable($mysqlBooksByAuthor) ? count($mysqlBooksByAuthor) : 0;

            $this->info("Books by author '{$testAuthor}' in MongoDB: $mongoCount");
            $this->info("Books by author '{$testAuthor}' in MySQL: $mysqlCount");

            if ($mongoCount !== $mysqlCount) {
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
            $mongoBooksByGenre = $this->mongoService->getBooksByAuthorAndGenre(null, $testGenre);
            $mysqlBooksByGenre = $mysqlService->getBooksByAuthorAndGenre(null, $testGenre);

            $mongoCount = is_countable($mongoBooksByGenre) ? count($mongoBooksByGenre) : 0;
            $mysqlCount = is_countable($mysqlBooksByGenre) ? count($mysqlBooksByGenre) : 0;

            $this->info("Books by genre '{$testGenre}' in MongoDB: $mongoCount");
            $this->info("Books by genre '{$testGenre}' in MySQL: $mysqlCount");

            if ($mongoCount !== $mysqlCount) {
                $this->warn("Book count mismatch for genre '{$testGenre}'!");
            } else {
                $this->info("Book counts match for genre '{$testGenre}'.");
            }
        } catch (\Exception $e) {
            $this->warn("Error checking books by genre: " . $e->getMessage());
        }
    }
}
