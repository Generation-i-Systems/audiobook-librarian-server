<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use RuntimeException;

/**
 * A transcription request that cannot be served, carrying the HTTP status and the coded
 * error clients switch on. Messages never contain audio or transcript text.
 */
class TranscriptionException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public static function unavailable(string $message): self
    {
        return new self(503, 'TRANSCRIPTION_UNAVAILABLE', $message);
    }

    public static function timeout(): self
    {
        return new self(504, 'TRANSCRIPTION_TIMEOUT', 'The speech-to-text service did not answer in time.');
    }

    public static function fileNotFound(): self
    {
        return new self(404, 'FILE_NOT_FOUND', 'The requested book file could not be found.');
    }

    public static function fileMismatch(string $message): self
    {
        return new self(409, 'FILE_MISMATCH', $message);
    }

    public static function extractionFailed(): self
    {
        return new self(500, 'CLIP_EXTRACTION_FAILED', 'The audio clip could not be extracted.');
    }

    public static function invalidClip(string $errorCode, string $message): self
    {
        return new self(422, $errorCode, $message);
    }
}
