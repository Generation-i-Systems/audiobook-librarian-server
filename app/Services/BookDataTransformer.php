<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\Series;
use Illuminate\Support\Str;

class BookDataTransformer
{
    public function toDocumentStoreBook(Book $book, ?int $userId = null): array
    {
        $bookArray = $book->toArray();
        $camelCasedBook = [];

        foreach ($bookArray as $key => $value) {
            if (!is_array($value)) {
                if ($key === 'cover_image') {
                    $camelCasedBook['coverImage'] = $this->buildCoverImageOutput($value, $book->directory_path);
                } else {
                    $camelCasedBook[Str::camel($key)] = $value;
                }
            }
        }

        if ($userId !== null) {
            $camelCasedBook = array_merge($camelCasedBook, $this->transformUserData($book));
        }

        if (isset($bookArray['needsReviewReasons'])) {
            $camelCasedBook['needsReviewReasons'] = $bookArray['needsReviewReasons'];
        }

        if (!empty($bookArray['authors'])) {
            $names = collect($bookArray['authors'])->pluck('name')->all();
            $camelCasedBook['author'] = $names;
            $camelCasedBook['authors'] = $names;
            $camelCasedBook['authors_data'] = collect($bookArray['authors'])
                ->map(fn ($author) => ['id' => $author['id'], 'name' => $author['name']])
                ->all();
        }

        if (!empty($bookArray['genres'])) {
            $names = collect($bookArray['genres'])->pluck('name')->all();
            $camelCasedBook['genre'] = $names;
            $camelCasedBook['genres'] = $names;
            $camelCasedBook['genres_data'] = collect($bookArray['genres'])
                ->map(fn ($genre) => ['id' => $genre['id'], 'name' => $genre['name']])
                ->all();
        }

        if (!empty($bookArray['narrators'])) {
            $camelCasedBook['narrator'] = collect($bookArray['narrators'])->pluck('name')->all();
            $camelCasedBook['narrators_data'] = collect($bookArray['narrators'])
                ->map(fn ($narrator) => ['id' => $narrator['id'], 'name' => $narrator['name']])
                ->all();
        }

        if (!empty($bookArray['series'])) {
            $camelCasedBook['series'] = collect($bookArray['series'])->map(function ($series) {
                return [
                    'seriesName' => $series['name'],
                    'number' => $series['pivot']['series_number'] ?? null,
                    'isCollection' => $series['is_collection'] ?? false,
                ];
            })->all();
            $camelCasedBook['series_data'] = collect($bookArray['series'])->map(function ($series) {
                return [
                    'id' => $series['id'],
                    'name' => $series['name'],
                    'series_number' => $series['pivot']['series_number'] ?? null,
                    'is_collection' => $series['is_collection'] ?? false,
                ];
            })->all();
        }

        if (!empty($bookArray['chapters'])) {
            $camelCasedBook['chapters'] = $bookArray['chapters'];
        }

        return $camelCasedBook;
    }

    public function toBookListItem(Book $book, ?int $userId = null): array
    {
        $coverUrl = null;
        if ($book->cover_image) {
            $coverUrl = $this->normalizeCoverUrl(request()->getSchemeAndHttpHost() . '/api/v1/books/' . $book->id . '/cover');
        }

        $baseData = [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->authors->pluck('name')->toArray(),
            'narrator' => $book->narrators->pluck('name')->toArray(),
            'series' => $book->series->map(function (Series $series): array {
                /** @var \Illuminate\Database\Eloquent\Relations\Pivot&object{series_number: mixed} $pivot */
                $pivot = $series->pivot;
                return [
                    'name' => $series->name,
                    'series_number' => $pivot->series_number,
                    'is_collection' => $series->is_collection ?? false,
                ];
            })->toArray(),
            'genre' => $book->genres->pluck('name')->toArray(),
            'year' => $book->release_date ? (int) $book->release_date->format('Y') : null,
            'duration' => $book->duration ? $this->formatDuration($book->duration) : null,
            'description' => $book->description,
            'coverImage' => $this->buildCoverImageOutput($book->cover_image, $book->directory_path),
            'directoryPath' => $book->directory_path,
            'cover_url' => $coverUrl,
            'needs_review' => (bool) $book->needs_review,
            'file_count' => $book->audio_file_count,
            'total_size' => $book->getAttribute('total_size'),
            'created_at' => $book->created_at ? $book->created_at->toIso8601String() : null,
            'updated_at' => $book->updated_at ? $book->updated_at->toIso8601String() : null,
            'authors_data' => $book->authors->toArray(),
            'genres_data' => $book->genres->toArray(),
            'series_data' => $book->series->map(function (Series $series): array {
                $seriesNumber = null;
                if ($series->pivot && isset($series->pivot->series_number)) {
                    $seriesNumber = (string) $series->pivot->series_number;
                }

                return [
                    'id' => $series->id,
                    'name' => $series->name,
                    'is_collection' => $series->is_collection,
                    'pivot' => [
                        'series_number' => $seriesNumber,
                    ],
                ];
            })->toArray(),
            'narrators_data' => $book->narrators->toArray(),
        ];

        if ($userId !== null) {
            $baseData = array_merge($baseData, $this->transformUserData($book));
        }

        return $baseData;
    }

    public function toExportedBook(Book $book): array
    {
        $bookArray = $book->toArray();
        $bookArray['_id'] = (string) $book->id;

        if (!isset($bookArray['directoryPath']) && isset($bookArray['directory_path'])) {
            $bookArray['directoryPath'] = $bookArray['directory_path'];
        }

        if (!empty($bookArray['series'])) {
            $series = [];

            foreach ($bookArray['series'] as $seriesItem) {
                $series[$seriesItem['name']] = $seriesItem['pivot']['series_number'] ?? null;
            }

            $bookArray['series'] = $series;
        }

        return $bookArray;
    }

    public function toRecentBook(Book $book): array
    {
        return [
            'id' => (string) $book->id,
            'title' => (string) $book->title,
            'directoryPath' => $book->directory_path,
            'coverImage' => $this->buildCoverImageOutput($book->cover_image, $book->directory_path),
            'createdAt' => $book->created_at ? $book->created_at->toIso8601String() : null,
            'description' => (string) ($book->description ?? ''),
            'duration' => (int) ($book->duration ?? 0),
            'releaseDate' => $book->release_date ? $book->release_date->toDateString() : null,
            'audioFileCount' => (int) ($book->audio_file_count ?? 0),
            'totalSize' => (int) ($book->total_size ?? 0),
            'authors' => $book->authors->pluck('name')->values()->all(),
            'narrators' => $book->narrators->pluck('name')->values()->all(),
        ];
    }

    private function buildCoverImageOutput(?string $coverImage, ?string $directoryPath): ?string
    {
        if ($coverImage === null) {
            return null;
        }

        $coverImage = trim($coverImage);

        if ($coverImage === '') {
            return null;
        }

        if (str_starts_with($coverImage, 'http://') || str_starts_with($coverImage, 'https://')) {
            return $this->normalizeCoverUrl($coverImage);
        }

        $baseName = basename($coverImage);

        if ($baseName === '') {
            return null;
        }

        $directoryPath = is_string($directoryPath) ? trim($directoryPath, '/') : '';

        if ($directoryPath === '') {
            return $baseName;
        }

        return $directoryPath . '/' . $baseName;
    }

    private function normalizeCoverUrl(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }

    private function transformUserData(Book $book): array
    {
        $userData = [
            'progress' => null,
            'status' => null,
            'recommendation' => null,
            'userTags' => [],
        ];

        if ($book->relationLoaded('progress') && $book->progress->isNotEmpty()) {
            $latestProgress = $book->progress->first();
            $userData['progress'] = [
                'position' => $latestProgress->current_position_seconds,
                'duration' => $latestProgress->total_duration_seconds,
                'percentage' => (float) $latestProgress->progress_percentage,
                'chapter' => $latestProgress->current_chapter,
                'chapterName' => $latestProgress->current_chapter_name,
                'lastListenedAt' => $latestProgress->last_listened_at?->toIso8601String(),
                'isCompleted' => (bool) $latestProgress->completed,
            ];
        }

        if ($book->relationLoaded('statuses') && $book->statuses->isNotEmpty()) {
            $status = $book->statuses->first();
            $userData['status'] = [
                'status' => $status->status,
                'order' => $status->order,
                'detail' => $status->status_detail,
                'readCount' => $status->read_count,
            ];
        }

        if ($book->relationLoaded('recommendations') && $book->recommendations->isNotEmpty()) {
            $recommendation = $book->recommendations->first();
            $sender = null;

            if ($recommendation->sender) {
                $sender = ['id' => $recommendation->sender->id, 'name' => $recommendation->sender->name];
            }

            $userData['recommendation'] = [
                'id' => $recommendation->id,
                'sender' => $sender,
                'message' => $recommendation->message,
                'sentAt' => $recommendation->created_at?->toIso8601String(),
            ];
        }

        if ($book->relationLoaded('userTags') && $book->userTags->isNotEmpty()) {
            $userData['userTags'] = $book->userTags->first()->tags ?? [];
        }

        return $userData;
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
