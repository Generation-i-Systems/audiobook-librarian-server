<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LibriVox\Author;
use App\Models\LibriVox\Book;
use App\Models\LibriVox\Chapter;
use App\Models\LibriVox\Genre;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LibriVoxImportService
{
    public function __construct(private readonly LibriVoxApiService $api)
    {
    }

    public function importBook(array $apiBook): Book
    {
        $librivoxId = (string) $apiBook['id'];

        $book = DB::transaction(function () use ($apiBook, $librivoxId): Book {
            $sections = $apiBook['sections'] ?? $this->api->getSections($librivoxId);

            $attrs = [
                'title'            => $apiBook['title'] ?? 'Unknown',
                'description'      => strip_tags((string) ($apiBook['description'] ?? '')),
                'language'         => $apiBook['language'] ?? null,
                'cover_image'      => null,
                'year'             => !empty($apiBook['copyright_year']) ? (int) $apiBook['copyright_year'] : null,
                'duration'         => !empty($apiBook['totaltimesecs']) ? (int) $apiBook['totaltimesecs'] : null,
                'audio_file_count' => !empty($apiBook['num_sections']) ? (int) $apiBook['num_sections'] : null,
                'librivox_info'    => [
                    'id'              => $librivoxId,
                    'url_zip_file'    => $apiBook['url_zip_file'] ?? null,
                    'url_librivox'    => $apiBook['url_librivox'] ?? null,
                    'url_iarchive'    => $apiBook['url_iarchive'] ?? null,
                    'url_text_source' => $apiBook['url_text_source'] ?? null,
                    'cover_url'       => null,
                    'imported_at'     => now()->toISOString(),
                ],
            ];

            $book = Book::withTrashed()->updateOrCreate(
                ['librivox_id' => $librivoxId],
                $attrs,
            );

            if ($book->trashed()) {
                $book->restore();
            }

            $this->syncAuthors($book, $apiBook['authors'] ?? []);
            $this->syncGenres($book, $apiBook['genres'] ?? []);
            $this->syncChapters($book, $sections);

            return $book;
        });

        Log::info('LibriVox book imported', [
            'librivox_id' => $librivoxId,
            'book_id'     => $book->id,
            'title'       => $book->title,
        ]);

        return $book;
    }

    /**
     * @return array{imported: int, skipped: int, failed: int}
     */
    public function importPage(int $limit = 50, int $offset = 0, ?int $since = null): array
    {
        $response = $this->api->listBooks($limit, $offset, $since);

        if ($response === null || empty($response['books'])) {
            return ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $imported = $skipped = $failed = 0;

        foreach ($response['books'] as $apiBook) {
            $librivoxId = (string) ($apiBook['id'] ?? '');
            if ($librivoxId === '') {
                $failed++;
                continue;
            }

            try {
                $this->importBook($apiBook);
                $imported++;
            } catch (\Exception $e) {
                Log::error('LibriVox import failed', [
                    'librivox_id' => $librivoxId,
                    'error'       => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @param array<int, array<string, mixed>> $apiAuthors
     */
    private function syncAuthors(Book $book, array $apiAuthors): void
    {
        $authorIds = [];

        foreach ($apiAuthors as $apiAuthor) {
            $firstName = trim((string) ($apiAuthor['first_name'] ?? ''));
            $lastName  = trim((string) ($apiAuthor['last_name'] ?? ''));
            $name      = trim($firstName . ' ' . $lastName);

            if ($name === '' || $name === 'Anonymous') {
                continue;
            }

            $authorIds[] = Author::firstOrCreate(['name' => $name])->id;
        }

        $book->authors()->sync($authorIds);
    }

    /**
     * @param array<int, array<string, mixed>> $apiGenres
     */
    private function syncGenres(Book $book, array $apiGenres): void
    {
        $genreIds = [];

        foreach ($apiGenres as $apiGenre) {
            $name = trim((string) ($apiGenre['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $genreIds[] = Genre::firstOrCreate(['name' => $name])->id;
        }

        $book->genres()->sync($genreIds);
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     */
    private function syncChapters(Book $book, array $sections): void
    {
        $book->chapters()->delete();

        foreach ($sections as $section) {
            $readers = collect($section['readers'] ?? [])->map(function ($r) {
                return is_array($r)
                    ? trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))
                    : (string) $r;
            })->filter()->join(', ');

            Chapter::create([
                'book_id'        => $book->id,
                'chapter_number' => (int) ($section['section_number'] ?? 0),
                'title'          => $section['title'] ?? null,
                'reader'         => $readers ?: null,
                'file_name'      => $section['file_name'] ?? basename((string) ($section['listen_url'] ?? '')),
                'format'         => 'mp3',
                'duration'       => $this->parseDuration((string) ($section['playtime'] ?? '0')),
                'size_bytes'     => 0,
                'listen_url'     => $section['listen_url'] ?? null,
            ]);
        }
    }

    /**
     * Fetch content-length for all zero-size chapters of a book via concurrent HEAD requests.
     */
    public function fetchChapterSizes(Book $book): void
    {
        $chapters = $book->chapters()
            ->whereNotNull('listen_url')
            ->where('size_bytes', 0)
            ->get(['id', 'listen_url']);

        if ($chapters->isEmpty()) {
            return;
        }

        foreach ($chapters->chunk(50) as $batch) {
            $indexed = $batch->values();
            $urls = $indexed->pluck('listen_url')->all();

            try {
                $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($urls): array {
                    return array_map(
                        fn (string $url) => $pool->withOptions(['allow_redirects' => true])->head($url),
                        $urls
                    );
                });
            } catch (\Throwable $e) {
                Log::warning('LibriVox HEAD pool failed', ['book_id' => $book->id, 'error' => $e->getMessage()]);
                continue;
            }

            foreach ($indexed as $i => $chapter) {
                $response = $responses[$i] ?? null;
                if ($response === null || $response instanceof \Throwable || !$response->successful()) {
                    continue;
                }

                $length = $response->header('Content-Length');
                if ($length !== '' && ((int) $length) > 0) {
                    Chapter::where('id', $chapter->id)->update(['size_bytes' => (int) $length]);
                }
            }
        }
    }

    /**
     * Backfill size_bytes for all chapters that still have size_bytes = 0.
     * Calls $onProgress(processed, updated) after each batch if provided.
     * Returns the number of chapters updated.
     *
     * @param callable(int, int): void|null $onProgress
     */
    public function backfillChapterSizes(?int $bookId = null, ?callable $onProgress = null): int
    {
        $query = Chapter::whereNotNull('listen_url')->where('size_bytes', 0);

        if ($bookId !== null) {
            $query->where('book_id', $bookId);
        }

        $processed = 0;
        $updated   = 0;

        $query->select(['id', 'book_id', 'listen_url'])
            ->chunkById(50, function ($batch) use (&$processed, &$updated, $onProgress): void {
                $indexed = $batch->values();
                $urls = $indexed->pluck('listen_url')->all();

                try {
                    $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($urls): array {
                        return array_map(
                            fn (string $url) => $pool->withOptions(['allow_redirects' => true])->head($url),
                            $urls
                        );
                    });
                } catch (\Throwable $e) {
                    Log::warning('LibriVox HEAD backfill pool failed', ['error' => $e->getMessage()]);
                    $processed += count($indexed);
                    if ($onProgress !== null) {
                        ($onProgress)($processed, $updated);
                    }
                    return;
                }

                foreach ($indexed as $i => $chapter) {
                    $response = $responses[$i] ?? null;
                    if ($response === null || $response instanceof \Throwable || !$response->successful()) {
                        continue;
                    }

                    $length = $response->header('Content-Length');
                    if ($length !== '' && ((int) $length) > 0) {
                        Chapter::where('id', $chapter->id)->update(['size_bytes' => (int) $length]);
                        $updated++;
                    }
                }

                $processed += count($indexed);
                if ($onProgress !== null) {
                    ($onProgress)($processed, $updated);
                }
            });

        return $updated;
    }

    private function parseDuration(string $duration): int
    {
        if (preg_match('/^(\d+):(\d+):(\d+)$/', $duration, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }
        if (preg_match('/^(\d+):(\d+)$/', $duration, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        return (int) $duration;
    }
}
