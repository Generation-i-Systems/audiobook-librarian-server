<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LibriVox\Author;
use App\Models\LibriVox\Book;
use App\Models\LibriVox\Chapter;
use App\Models\LibriVox\Genre;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LibriVoxImportService
{
    public function __construct(private readonly LibriVoxApiService $api)
    {
    }

    public function importBook(array $apiBook): Book
    {
        $librivoxId = (string) $apiBook['id'];

        return DB::transaction(function () use ($apiBook, $librivoxId): Book {
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

            Log::info('LibriVox book imported', [
                'librivox_id' => $librivoxId,
                'book_id'     => $book->id,
                'title'       => $book->title,
            ]);

            return $book;
        });
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
