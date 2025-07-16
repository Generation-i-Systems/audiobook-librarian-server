<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateMongoToMysql extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-mongo-to-mysql';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate all book data from MongoDB to the MySQL database.';

    protected DocumentStoreServiceInterface $mongoService;

    public function __construct(DocumentStoreServiceInterface $mongoService)
    {
        parent::__construct();
        $this->mongoService = $mongoService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting data migration from MongoDB to MySQL...');

        try {
            $mongoBooks = $this->mongoService->dumpAllBooks();
            $bookCount = count($mongoBooks);

            if ($bookCount === 0) {
                $this->info('No books found in MongoDB to migrate.');
                return 0;
            }

            $progressBar = $this->output->createProgressBar($bookCount);
            $progressBar->start();

            foreach ($mongoBooks as $mongoBook) {
                DB::transaction(function () use ($mongoBook) {
                    // 1. Handle Series
                    $series = null;
                    if (!empty($mongoBook['series_name'])) {
                        $series = Series::firstOrCreate(['name' => $mongoBook['series_name']]);
                    }

                    // 2. Create Book
                    $book = Book::create([
                        'title' => $mongoBook['title'] ?? 'Untitled',
                        'description' => $mongoBook['description'] ?? null,
                        'publication_year' => $mongoBook['publication_year'] ?? null,
                        'cover_image' => $mongoBook['coverImage'] ?? null,
                        'language' => $mongoBook['language'] ?? 'en',
                        'book_number' => $mongoBook['book_number'] ?? null,
                        'path' => $mongoBook['path'] ?? null,
                        'source' => $mongoBook['source'] ?? 'unknown',
                        'audio_sample_path' => $mongoBook['audio_sample_path'] ?? null,
                        'series_id' => $series ? $series->id : null,
                    ]);

                    // 3. Handle Authors
                    $authorNames = (array) ($mongoBook['author_name'] ?? []);
                    foreach ($authorNames as $authorName) {
                        $author = Author::firstOrCreate(['name' => $authorName]);
                        $book->authors()->attach($author->id);
                    }

                    // 4. Handle Narrators
                    $narratorNames = (array) ($mongoBook['narrator_name'] ?? []);
                    foreach ($narratorNames as $narratorName) {
                        $narrator = Narrator::firstOrCreate(['name' => $narratorName]);
                        $book->narrators()->attach($narrator->id);
                    }

                    // 5. Handle Genres
                    $genreNames = (array) ($mongoBook['genre'] ?? []);
                    foreach ($genreNames as $genreName) {
                        $genre = Genre::firstOrCreate(['name' => $genreName]);
                        $book->genres()->attach($genre->id);
                    }

                    // 6. Handle Chapters
                    $chapters = (array) ($mongoBook['chapters'] ?? []);
                    foreach ($chapters as $chapterData) {
                        Chapter::create([
                            'book_id' => $book->id,
                            'chapter_number' => $chapterData['chapter_number'] ?? 0,
                            'file_name' => $chapterData['filename'] ?? null,
                            'format' => $chapterData['format'] ?? null,
                            'duration' => $chapterData['duration'] ?? null,
                            'size_bytes' => $chapterData['size'] ?? null,
                        ]);
                    }
                });

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->info("\nSuccessfully migrated $bookCount books.");

        } catch (\Exception $e) {
            $this->error("\nAn error occurred during migration: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
