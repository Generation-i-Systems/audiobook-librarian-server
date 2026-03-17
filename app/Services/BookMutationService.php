<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;

class BookMutationService
{
    public function createBook(array $data): Book
    {
        $bookId = $data['id'] ?? null;
        $directoryPath = $data['directory_path'] ?? $data['directoryPath'] ?? null;
        $releaseDate = $data['release_date'] ?? $data['releaseDate'] ?? null;
        $needsReview = $data['needs_review'] ?? $data['needsReview'] ?? false;
        $needsReviewReasons = $data['needs_review_reasons'] ?? $data['needsReviewReasons'] ?? null;
        $audioFileCount = $data['audio_file_count'] ?? $data['audioFileCount'] ?? null;

        $attributes = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'release_date' => $releaseDate,
            'cover_image' => $this->normalizeCoverImageValue($data['cover_image'] ?? $data['coverImage'] ?? null),
            'language' => $data['language'] ?? 'en',
            'source' => $data['source'] ?? 'unknown',
            'series_id' => $data['series_id'] ?? null,
            'mongo_id' => $data['mongo_id'] ?? null,
            'directory_path' => $directoryPath,
            'duration' => $data['duration'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'needs_review' => $needsReview,
            'needs_review_reasons' => $needsReviewReasons,
            'audio_file_count' => $audioFileCount,
            'mongo_record' => $data['mongo_record'] ?? null,
            'file_tags' => $data['file_tags'] ?? null,
            'audible_info' => $data['audible_info'] ?? null,
            'google_books_info' => $data['google_books_info'] ?? null,
            'hardcover_info' => $data['hardcover_info'] ?? null,
            'audiobook_bay_info' => $data['audiobook_bay_info'] ?? null,
        ];

        if ($bookId !== null && is_numeric($bookId)) {
            $existingBook = Book::withTrashed()->find((int) $bookId);

            if ($existingBook) {
                if ($existingBook->trashed()) {
                    $existingBook->restore();
                }

                $existingBook->update($attributes);
                $book = $existingBook;
            } else {
                $book = new Book();
                $book->id = (int) $bookId;
                $book->fill($attributes);
                $book->save();
            }
        } else {
            $book = Book::create($attributes);
        }

        $this->syncCreateRelations($book, $data);
        $this->syncChapters($book, $data, false);

        return $book;
    }

    public function updateBook(string $id, array $data): Book
    {
        $book = Book::findOrFail($id);

        if (isset($data['publishedYear']) && !empty($data['publishedYear']) && is_numeric($data['publishedYear'])) {
            $data['release_date'] = $data['publishedYear'] . '-01-01';
        }

        $book->update([
            'title' => $data['title'] ?? $book->title,
            'description' => $data['description'] ?? $book->description,
            'language' => $data['language'] ?? $book->language,
            'source' => $data['source'] ?? $book->source,
            'series_id' => $data['series_id'] ?? $book->series_id,
            'mongo_id' => $data['mongo_id'] ?? $book->mongo_id,
            'release_date' => $data['release_date'] ?? $book->release_date,
            'cover_image' => $this->normalizeCoverImageValue($data['cover_image'] ?? $data['coverImage'] ?? null) ?? $book->cover_image,
            'directory_path' => $data['directory_path'] ?? $data['directoryPath'] ?? $book->directory_path,
            'duration' => $data['duration'] ?? $book->duration,
            'publisher' => $data['publisher'] ?? $book->publisher,
            'needs_review' => $data['needs_review'] ?? $data['needsReview'] ?? $book->needs_review,
            'needs_review_reasons' => $this->resolveNeedsReviewReasons($data, $book),
            'audio_file_count' => $data['audio_file_count'] ?? $book->audio_file_count,
            'mongo_record' => $data['mongo_record'] ?? $book->mongo_record,
            'file_tags' => $data['file_tags'] ?? $book->file_tags,
            'audible_info' => $data['audible_info'] ?? $book->audible_info,
            'google_books_info' => $data['google_books_info'] ?? $book->google_books_info,
            'hardcover_info' => $data['hardcover_info'] ?? $book->hardcover_info,
            'audiobook_bay_info' => $data['audiobook_bay_info'] ?? $book->audiobook_bay_info,
        ]);

        $this->syncUpdateRelations($book, $data);
        $this->syncSeries($book, $data);
        $this->syncChapters($book, $data, true);

        $book->refresh();
        $book->load(['authors', 'narrators', 'genres', 'series', 'publisher']);

        return $book;
    }

    private function syncCreateRelations(Book $book, array $data): void
    {
        $authorData = $data['authors'] ?? $data['author'] ?? null;
        if (is_array($authorData)) {
            $book->authors()->sync($this->resolveCreateRelationIds($authorData, Author::class));
        }

        $narratorData = $data['narrators'] ?? $data['narrator'] ?? null;
        if (is_array($narratorData)) {
            $book->narrators()->sync($this->resolveCreateRelationIds($narratorData, Narrator::class));
        }

        $genreData = $data['genres'] ?? $data['genre'] ?? null;
        if (is_array($genreData)) {
            $book->genres()->sync($this->resolveCreateRelationIds($genreData, Genre::class));
        }

        $this->syncSeries($book, $data);
    }

    private function syncUpdateRelations(Book $book, array $data): void
    {
        $authorData = $data['authors'] ?? $data['author'] ?? null;
        if (is_array($authorData)) {
            $book->authors()->sync($this->resolveUpdateRelationIds($authorData, Author::class));
        }

        $narratorData = $data['narrators'] ?? $data['narrator'] ?? null;
        if (is_array($narratorData)) {
            $book->narrators()->sync($this->resolveUpdateRelationIds($narratorData, Narrator::class));
        }

        $genreData = $data['genres'] ?? $data['genre'] ?? null;
        if (is_array($genreData)) {
            $book->genres()->sync($this->resolveUpdateRelationIds($genreData, Genre::class));
        }
    }

    private function resolveCreateRelationIds(array $items, string $modelClass): array
    {
        if ($this->isLikelyIdList($items)) {
            return $this->normalizeRelatedIds($items);
        }

        $ids = [];

        foreach ($items as $item) {
            if (is_string($item) || is_int($item)) {
                $name = trim((string) $item);

                if ($name === '') {
                    continue;
                }

                $model = $modelClass::firstOrCreate(['name' => $name]);
                $ids[] = $model->id;
            }
        }

        return $ids;
    }

    private function resolveUpdateRelationIds(array $items, string $modelClass): array
    {
        if ($this->isLikelyIdList($items)) {
            return $this->normalizeRelatedIds($items);
        }

        $ids = [];

        foreach ($items as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }

            $name = trim((string) $item);

            if ($name === '') {
                continue;
            }

            if (is_numeric($name)) {
                $existingModel = $modelClass::find($name);

                if ($existingModel) {
                    $ids[] = $existingModel->id;
                    continue;
                }
            }

            $model = $modelClass::firstOrCreate(['name' => $name]);
            $ids[] = $model->id;
        }

        return $ids;
    }

    private function syncSeries(Book $book, array $data): void
    {
        if (isset($data['series']) && is_array($data['series'])) {
            $seriesSyncData = [];

            foreach ($data['series'] as $seriesEntry) {
                $seriesName = $seriesEntry['seriesName'] ?? $seriesEntry['name'] ?? null;

                if (!$seriesName) {
                    continue;
                }

                $series = Series::firstOrCreate(['name' => $seriesName]);
                $seriesSyncData[$series->id] = [
                    'series_number' => $seriesEntry['number'] ?? null,
                ];
            }

            $book->series()->sync($seriesSyncData);

            return;
        }

        if (!array_key_exists('series_name', $data)) {
            return;
        }

        if ($data['series_name']) {
            $series = Series::firstOrCreate(['name' => $data['series_name']]);
            $book->series()->sync([
                $series->id => [
                    'series_number' => null,
                ],
            ]);

            return;
        }

        $book->series()->detach();
    }

    private function syncChapters(Book $book, array $data, bool $replaceExisting): void
    {
        if (!isset($data['chapters'])) {
            return;
        }

        if ($replaceExisting) {
            $book->chapters()->delete();
        }

        foreach ($data['chapters'] as $chapterData) {
            $book->chapters()->create($chapterData);
        }
    }

    private function normalizeCoverImageValue(?string $coverImage): ?string
    {
        if ($coverImage === null) {
            return null;
        }

        $coverImage = trim($coverImage);

        if ($coverImage === '') {
            return null;
        }

        if (str_starts_with($coverImage, 'file://')) {
            $parsedPath = parse_url($coverImage, PHP_URL_PATH);

            if (is_string($parsedPath) && $parsedPath !== '') {
                $coverImage = $parsedPath;
            }
        }

        $parsedUrl = parse_url($coverImage);

        if (
            is_array($parsedUrl)
            && isset($parsedUrl['scheme'])
            && in_array($parsedUrl['scheme'], ['http', 'https'], true)
        ) {
            return $coverImage;
        }

        $coverImage = str_replace('\\', '/', $coverImage);

        $baseName = basename($coverImage);

        if ($baseName === '') {
            return null;
        }

        return $baseName;
    }

    private function normalizeRelatedIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $ids[] = $item['id'];
                continue;
            }

            if (is_string($item) || is_int($item)) {
                $value = trim((string) $item);

                if ($value !== '') {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function isLikelyIdList(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        $ids = $this->normalizeRelatedIds($items);

        if (empty($ids)) {
            return false;
        }

        foreach ($ids as $id) {
            if (!is_string($id) || $id === '' || !is_numeric($id)) {
                return false;
            }
        }

        return true;
    }

    private function resolveNeedsReviewReasons(array $data, Book $book): mixed
    {
        if (array_key_exists('needs_review_reasons', $data)) {
            return $data['needs_review_reasons'];
        }

        if (array_key_exists('needsReviewReasons', $data)) {
            return $data['needsReviewReasons'];
        }

        return $book->needs_review_reasons;
    }
}
