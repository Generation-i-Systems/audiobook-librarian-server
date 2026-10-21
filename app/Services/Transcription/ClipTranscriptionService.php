<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\BookFileChunkHashService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transcribes short clips for text sync: either a range of a book file on this server
 * (cut with ffmpeg) or a clip the client uploads. Stores nothing; logs ids, timing and
 * status only, never audio or transcript text.
 */
class ClipTranscriptionService
{
    public const SOURCE_SERVER_FILE = 'server_file';
    public const SOURCE_UPLOAD = 'upload';

    public function __construct(
        private readonly DocumentStoreServiceInterface $documentStoreService,
        private readonly WhisperTranscriptionService $whisper,
        private readonly AudioClipExtractor $extractor,
        private readonly AudioClipInspector $inspector
    ) {
    }

    /**
     * @return array<string, mixed> the transcription response body
     *
     * @throws TranscriptionException
     */
    public function transcribeBookFile(
        int|string $bookId,
        string $fileName,
        int $startMs,
        int $durationMs,
        int $expectedSize,
        ?string $expectedChunkHash,
        ?string $language
    ): array {
        if (! $this->whisper->isConfigured() || ! $this->extractor->isAvailable()) {
            throw TranscriptionException::unavailable('Server-file transcription is not available on this server.');
        }

        $fullPath = $this->resolveBookFile($bookId, $fileName);
        $this->assertSameFile($fullPath, $expectedSize, $expectedChunkHash);

        $fileDurationMs = $this->extractor->durationMs($fullPath);
        if ($fileDurationMs !== null && $startMs >= $fileDurationMs) {
            throw TranscriptionException::invalidClip('CLIP_OUT_OF_RANGE', 'startMs is past the end of the file.');
        }
        $clipMs = min($durationMs, $this->maxClipMs());
        if ($fileDurationMs !== null) {
            $clipMs = min($clipMs, $fileDurationMs - $startMs);
        }

        $startedAt = microtime(true);
        $clipPath = $this->extractor->extract($fullPath, $startMs, $clipMs);
        try {
            $clipBytes = file_get_contents($clipPath);
            if ($clipBytes === false || $clipBytes === '') {
                throw TranscriptionException::extractionFailed();
            }
            $result = $this->whisper->transcribe($clipBytes, 'clip.wav', $language, $clipMs);
        } finally {
            @unlink($clipPath);
        }

        $this->logCompleted(self::SOURCE_SERVER_FILE, $startedAt, $clipMs, count($result->words), [
            'book_id' => $bookId,
            'start_ms' => $startMs,
        ]);

        return $result->toResponse($clipMs, self::SOURCE_SERVER_FILE);
    }

    /**
     * @return array<string, mixed> the transcription response body
     *
     * @throws TranscriptionException
     */
    public function transcribeUpload(string $audioBytes, ?string $language): array
    {
        if (! $this->whisper->isConfigured()) {
            throw TranscriptionException::unavailable('Speech-to-text is not configured on this server.');
        }

        $maxBytes = (int) config('transcription.max_upload_bytes', 1024 * 1024);
        if (strlen($audioBytes) > $maxBytes) {
            throw TranscriptionException::invalidClip('AUDIO_TOO_LARGE', "audio must be at most {$maxBytes} bytes.");
        }

        $clip = $this->inspector->inspect($audioBytes);
        if ($clip === null) {
            throw TranscriptionException::invalidClip(
                'UNSUPPORTED_AUDIO',
                'audio must be a WAV or FLAC clip (16 kHz mono) whose header states its length.'
            );
        }
        if ($clip['durationMs'] > $this->maxClipMs()) {
            throw TranscriptionException::invalidClip(
                'CLIP_TOO_LONG',
                'audio must be at most ' . ($this->maxClipMs() / 1000) . ' seconds long.'
            );
        }

        $startedAt = microtime(true);
        $result = $this->whisper->transcribe($audioBytes, 'clip.' . $clip['format'], $language, $clip['durationMs']);
        $this->logCompleted(self::SOURCE_UPLOAD, $startedAt, $clip['durationMs'], count($result->words));

        return $result->toResponse($clip['durationMs'], self::SOURCE_UPLOAD);
    }

    /**
     * Same resolution as the per-file download route: the file name is relative to the
     * book's directory on the books disk. Parent-directory segments are refused.
     */
    private function resolveBookFile(int|string $bookId, string $fileName): string
    {
        $book = $this->documentStoreService->getBook($bookId);
        if (! $book || ($book['source'] ?? null) === 'librivox') {
            throw TranscriptionException::fileNotFound();
        }

        $directoryPath = $book['directoryPath'] ?? null;
        $segments = explode('/', str_replace('\\', '/', $fileName));
        if (! is_string($directoryPath) || $directoryPath === '' || in_array('..', $segments, true)) {
            throw TranscriptionException::fileNotFound();
        }

        $filePath = $directoryPath . '/' . $fileName;
        $disk = Storage::disk('books');
        if (! $disk->exists($filePath)) {
            throw TranscriptionException::fileNotFound();
        }

        $fullPath = $disk->path($filePath);

        return is_file($fullPath) ? $fullPath : throw TranscriptionException::fileNotFound();
    }

    /**
     * The client names the copy it holds by size and, optionally, by the first-chunk hash
     * the download manifest publishes; a different file on the server would give word
     * times that do not match the client's audio.
     */
    private function assertSameFile(string $fullPath, int $expectedSize, ?string $expectedChunkHash): void
    {
        clearstatcache(true, $fullPath);
        if (filesize($fullPath) !== $expectedSize) {
            throw TranscriptionException::fileMismatch('The server file size differs from expectedSize.');
        }

        if ($expectedChunkHash === null || $expectedChunkHash === '') {
            return;
        }

        $expected = strtolower((string) preg_replace('/^sha256:/i', '', $expectedChunkHash));
        $firstChunk = file_get_contents($fullPath, false, null, 0, BookFileChunkHashService::DOWNLOAD_CHUNK_SIZE);
        if ($firstChunk === false || ! hash_equals(hash('sha256', $firstChunk), $expected)) {
            throw TranscriptionException::fileMismatch('The server file first-chunk hash differs.');
        }
    }

    private function maxClipMs(): int
    {
        return max(1, (int) config('transcription.max_clip_seconds', 30)) * 1000;
    }

    /**
     * @param array<string, int|string> $context
     */
    private function logCompleted(
        string $source,
        float $startedAt,
        int $clipMs,
        int $wordCount,
        array $context = []
    ): void {
        Log::info('Transcription completed', array_merge($context, [
            'source' => $source,
            'user_id' => auth()->id(),
            'clip_ms' => $clipMs,
            'word_count' => $wordCount,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]));
    }
}
