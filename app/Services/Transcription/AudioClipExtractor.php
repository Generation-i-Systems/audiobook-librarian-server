<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Cuts a short 16 kHz mono WAV clip out of a book file with ffmpeg, for Whisper.
 */
class AudioClipExtractor
{
    private const VERSION_CHECK_TIMEOUT_SECONDS = 5;

    public function isAvailable(): bool
    {
        return (bool) Cache::remember(
            'transcription.ffmpeg_available',
            (int) config('transcription.health_cache_seconds', 60),
            fn (): bool => $this->binaryRuns($this->ffmpeg()) && $this->binaryRuns($this->ffprobe())
        );
    }

    /**
     * The file's duration in milliseconds, or null when ffprobe cannot read it.
     */
    public function durationMs(string $fullPath): ?int
    {
        try {
            $result = Process::timeout($this->timeoutSeconds())->run([
                $this->ffprobe(),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $fullPath,
            ]);
        } catch (ProcessTimedOutException) {
            throw TranscriptionException::timeout();
        }
        $output = trim($result->output());
        if (! $result->successful() || ! is_numeric($output)) {
            return null;
        }

        return (int) round((float) $output * 1000);
    }

    /**
     * Write the clip to a new temporary WAV file and return its path; the caller deletes it.
     *
     * @throws TranscriptionException
     */
    public function extract(string $fullPath, int $startMs, int $durationMs): string
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'transcription-clip-');
        if ($outputPath === false) {
            throw TranscriptionException::unavailable('Could not create a temporary audio file.');
        }

        try {
            $result = Process::timeout($this->timeoutSeconds())->run([
                $this->ffmpeg(),
                '-nostdin',
                '-hide_banner',
                '-loglevel', 'error',
                '-ss', $this->seconds($startMs),
                '-t', $this->seconds($durationMs),
                '-i', $fullPath,
                '-vn',
                '-ac', '1',
                '-ar', '16000',
                '-f', 'wav',
                '-y',
                $outputPath,
            ]);
        } catch (ProcessTimedOutException) {
            @unlink($outputPath);
            throw TranscriptionException::timeout();
        }

        if (! $result->successful()) {
            @unlink($outputPath);
            throw TranscriptionException::extractionFailed();
        }

        return $outputPath;
    }

    private function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 3, '.', '');
    }

    private function binaryRuns(string $binary): bool
    {
        try {
            return Process::timeout(self::VERSION_CHECK_TIMEOUT_SECONDS)->run([$binary, '-version'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('transcription.timeout_seconds', 30));
    }

    private function ffmpeg(): string
    {
        return (string) config('transcription.ffmpeg_binary', 'ffmpeg');
    }

    private function ffprobe(): string
    {
        return (string) config('transcription.ffprobe_binary', 'ffprobe');
    }
}
