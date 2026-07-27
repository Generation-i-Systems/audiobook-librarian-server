<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Collection;

class BookChapterService
{
    /**
     * @param array<int, mixed>|Collection<int, mixed> $chapters
     * @return array<int, array{title: string, start: float|null, duration: int|float|null, file: string}>
     */
    public function toJsonChapters(array|Collection $chapters): array
    {
        $items = $chapters instanceof Collection ? $chapters->all() : $chapters;

        return collect($items)
            ->sortBy(fn (mixed $chapter): int => $this->intValue(
                $this->value($chapter, 'chapter_number') ?? $this->value($chapter, 'chapterNumber')
            ) ?? 0)
            ->values()
            ->map(function (mixed $chapter): array {
                $chapterNumber = $this->intValue(
                    $this->value($chapter, 'chapter_number') ?? $this->value($chapter, 'chapterNumber')
                ) ?? 1;
                $start = $this->firstValue($chapter, ['start_seconds', 'startSeconds', 'start']);
                $file = $this->firstValue($chapter, ['file_name', 'fileName', 'file']) ?? '';

                return [
                    'title' => (string) ($this->value($chapter, 'title') ?? ('Chapter ' . $chapterNumber)),
                    'start' => $this->floatValue($start),
                    'duration' => $this->durationValue($this->value($chapter, 'duration')),
                    'file' => (string) $file,
                ];
            })
            ->all();
    }

    /**
     * @param array<int, mixed> $chapters
     */
    public function replaceBookChapters(Book $book, array $chapters, string $source): void
    {
        $book->chapters()->delete();

        foreach ($this->toDatabaseChapters($chapters, $source) as $chapterData) {
            $book->chapters()->create($chapterData);
        }
    }

    /**
     * @param array<int, mixed> $chapters
     */
    public function importJsonChaptersIfMissing(Book $book, array $chapters, string $source = 'librarian_json'): bool
    {
        if ($chapters === [] || $book->chapters()->exists()) {
            return false;
        }

        $this->replaceBookChapters($book, $chapters, $source);

        return true;
    }

    /**
     * @param array<int, mixed> $chapters
     * @return array<int, array<string, mixed>>
     */
    public function toDatabaseChapters(array $chapters, string $source): array
    {
        $normalized = [];
        foreach (array_values($chapters) as $index => $chapter) {
            if (! is_array($chapter)) {
                continue;
            }

            $fileName = (string) ($chapter['file_name'] ?? $chapter['file'] ?? '');
            $normalized[] = [
                'chapter_number' => (int) ($chapter['chapter_number'] ?? ($index + 1)),
                'title' => (string) ($chapter['title'] ?? 'Chapter ' . ($index + 1)),
                'start_seconds' => $this->floatValue($chapter['start_seconds'] ?? $chapter['start'] ?? null),
                'file_name' => $fileName,
                'format' => (string) ($chapter['format'] ?? strtolower(pathinfo($fileName, PATHINFO_EXTENSION))),
                'duration' => $this->durationValue($chapter['duration'] ?? null),
                'size_bytes' => $this->intValue($chapter['size_bytes'] ?? null),
                'source' => $source,
            ];
        }

        return $normalized;
    }

    private function value(mixed $chapter, string $key): mixed
    {
        if (is_array($chapter)) {
            return $chapter[$key] ?? null;
        }

        if (is_object($chapter)) {
            return $chapter->{$key} ?? null;
        }

        return null;
    }

    /**
     * @param array<int, string> $keys
     */
    private function firstValue(mixed $chapter, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $this->value($chapter, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function floatValue(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function intValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function durationValue(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return floor($float) === $float ? (int) $float : $float;
    }
}
