<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LibriVox\Author;
use App\Models\LibriVox\Book;
use App\Models\LibriVox\Genre;

/**
 * Converts LibriVox\Book models into the document store array shape so they
 * pass through the existing API pipeline (BookTransformTrait, etc.) identically
 * to local books.
 */
class LibriVoxBookAdapter
{
    public function toDocumentStoreBook(Book $book): array
    {
        $authors = $book->relationLoaded('authors')
            ? $book->authors->map(fn (Author $a) => ['id' => $a->id, 'name' => $a->name])->all()
            : $book->authors()->get()->map(fn (Author $a) => ['id' => $a->id, 'name' => $a->name])->all();

        $genres = $book->relationLoaded('genres')
            ? $book->genres->map(fn (Genre $g) => ['id' => $g->id, 'name' => $g->name])->all()
            : $book->genres()->get()->map(fn (Genre $g) => ['id' => $g->id, 'name' => $g->name])->all();
        $narrators = $book->relationLoaded('chapters')
            ? $book->chapters->pluck('reader')->filter()->unique()->values()->all()
            : $book->chapters()->pluck('reader')->filter()->unique()->values()->all();

        $info = $book->librivox_info ?? [];
        $coverImage = $book->cover_image ?? $info['cover_url'] ?? null;

        $authorNames = array_column($authors, 'name');
        $genreNames = array_column($genres, 'name');

        return [
            'id'               => (string) $book->id,
            'title'            => $book->title ?? '',
            'author'           => $authorNames,
            'authors'          => $authorNames,
            'authors_data'     => $authors,
            'narrator'         => $narrators,
            'narrators'        => $narrators,
            'narrators_data'   => array_map(fn (string $name) => ['id' => null, 'name' => $name], $narrators),
            'genre'            => $genreNames,
            'genres'           => $genreNames,
            'genres_data'      => $genres,
            'series'           => [],
            'series_data'      => [],
            'description'      => $book->description,
            'year'             => $book->year,
            'published_year'   => $book->year,
            'duration'         => $this->formatDuration($book->duration),
            'audio_file_count' => $book->audio_file_count,
            'total_size'       => null,
            'created_at'       => $book->created_at?->toIso8601String(),
            'updated_at'       => $book->updated_at?->toIso8601String(),
            'coverImage'       => $coverImage,
            'directoryPath'    => null,
            'needs_review'     => false,
            'source'           => 'librivox',
            'librivox_id'      => $book->librivox_id,
            'librivoxInfo'     => $info,
        ];
    }

    /**
     * Convert a raw LibriVox API response array (from LibriVoxApiService) to the
     * document store shape. Used when the local sync table is empty.
     *
     * @param array<string, mixed> $apiBook
     * @return array<string, mixed>
     */
    public function fromApiArray(array $apiBook): array
    {
        $authors = [];
        foreach ($apiBook['authors'] ?? [] as $a) {
            $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
            $authors[] = ['id' => (string) ($a['id'] ?? ''), 'name' => $name];
        }

        $genres = [];
        foreach ($apiBook['genres'] ?? [] as $g) {
            $genres[] = ['id' => (string) ($g['id'] ?? ''), 'name' => (string) ($g['name'] ?? '')];
        }

        $librivoxId = (string) ($apiBook['id'] ?? '');
        $coverImage = $apiBook['cover_url'] ?? null;
        $duration = isset($apiBook['totaltimesecs']) ? (int) $apiBook['totaltimesecs'] : null;

        $authorNames = array_column($authors, 'name');
        $genreNames = array_column($genres, 'name');
        $narrators = array_values(array_unique(array_filter(array_map(
            fn (array $section) => trim((string) ($section['reader'] ?? '')),
            $apiBook['sections'] ?? []
        ))));

        return [
            'id'               => $librivoxId,
            'title'            => (string) ($apiBook['title'] ?? ''),
            'author'           => $authorNames,
            'authors'          => $authorNames,
            'authors_data'     => $authors,
            'narrator'         => $narrators,
            'narrators'        => $narrators,
            'narrators_data'   => array_map(fn (string $name) => ['id' => null, 'name' => $name], $narrators),
            'genre'            => $genreNames,
            'genres'           => $genreNames,
            'genres_data'      => $genres,
            'series'           => [],
            'series_data'      => [],
            'description'      => $apiBook['description'] ?? null,
            'year'             => isset($apiBook['copyright_year']) ? (int) $apiBook['copyright_year'] : null,
            'published_year'   => isset($apiBook['copyright_year']) ? (int) $apiBook['copyright_year'] : null,
            'duration'         => $this->formatDuration($duration),
            'audio_file_count' => isset($apiBook['num_sections']) ? (int) $apiBook['num_sections'] : null,
            'total_size'       => null,
            'created_at'       => null,
            'updated_at'       => null,
            'coverImage'       => is_string($coverImage) && $coverImage !== '' ? $coverImage : null,
            'directoryPath'    => null,
            'needs_review'     => false,
            'source'           => 'librivox',
            'librivox_id'      => $librivoxId,
            'librivoxInfo'     => $apiBook,
        ];
    }

    private function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
}
