<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ChapterDetectionService
{
    private const AUDIO_EXTENSIONS = [
        'aac',
        'flac',
        'm4a',
        'm4b',
        'mp3',
        'mp4',
        'oga',
        'ogg',
        'opus',
        'wav',
        'wma',
    ];

    /**
     * @return array<int, array{title: string, start: float, duration: float|null, file: string}>
     */
    public function detectForDirectory(?string $directoryPath): array
    {
        if ($directoryPath === null || trim($directoryPath) === '') {
            return [];
        }

        $directoryPath = trim($directoryPath, '/');
        $files = Storage::disk('books')->allFiles($directoryPath);
        $audioFiles = array_values(array_filter($files, fn (string $file): bool => $this->isAudioFile($file)));
        natsort($audioFiles);

        $chapters = [];
        foreach ($audioFiles as $storagePath) {
            $fullPath = Storage::disk('books')->path($storagePath);
            if (!is_file($fullPath)) {
                continue;
            }

            $ffprobeData = $this->readFfprobeChapters($fullPath);
            if ($ffprobeData === []) {
                continue;
            }

            /** @var array<int, array<string, mixed>> $ffprobeChapters */
            $ffprobeChapters = $ffprobeData['chapters'];
            array_push(
                $chapters,
                ...$this->normalizeFfprobeChapters(
                    $this->relativeFilePath($directoryPath, $storagePath),
                    $ffprobeChapters
                )
            );
        }

        return $chapters;
    }

    /**
     * @param array<int, array<string, mixed>> $chapters
     * @return array<int, array{title: string, start: float, duration: float|null, file: string}>
     */
    public function normalizeFfprobeChapters(string $relativeFilePath, array $chapters): array
    {
        $normalized = [];
        foreach ($chapters as $index => $chapter) {
            $start = $this->floatOrNull($chapter['start_time'] ?? null);
            if ($start === null) {
                continue;
            }

            $end = $this->floatOrNull($chapter['end_time'] ?? null);
            $duration = $end === null ? null : max(0.0, round($end - $start, 3));
            $tags = $chapter['tags'] ?? [];
            $title = is_array($tags) && !empty($tags['title']) ? (string) $tags['title'] : 'Chapter ' . ($index + 1);

            $normalized[] = [
                'title' => $title,
                'start' => round($start, 3),
                'duration' => $duration,
                'file' => $relativeFilePath,
            ];
        }

        return $normalized;
    }

    private function isAudioFile(string $file): bool
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return in_array($extension, self::AUDIO_EXTENSIONS, true);
    }

    /**
     * @return array{chapters: array<int, array<string, mixed>>}|array{}
     */
    private function readFfprobeChapters(string $fullPath): array
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'quiet',
            '-print_format',
            'json',
            '-show_chapters',
            $fullPath,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::debug('ffprobe did not return chapter metadata', [
                'file' => $fullPath,
                'error' => $process->getErrorOutput(),
            ]);
            return [];
        }

        $decoded = json_decode($process->getOutput(), true);
        if (!is_array($decoded) || empty($decoded['chapters']) || !is_array($decoded['chapters'])) {
            return [];
        }

        return ['chapters' => $decoded['chapters']];
    }

    private function relativeFilePath(string $directoryPath, string $storagePath): string
    {
        $prefix = trim($directoryPath, '/') . '/';
        if (str_starts_with($storagePath, $prefix)) {
            return substr($storagePath, strlen($prefix));
        }

        return basename($storagePath);
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
