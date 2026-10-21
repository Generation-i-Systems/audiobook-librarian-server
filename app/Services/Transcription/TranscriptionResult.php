<?php

declare(strict_types=1);

namespace App\Services\Transcription;

final class TranscriptionResult
{
    /**
     * @param list<array{text: string, startMs: int, endMs: int, confidence: float|null}> $words
     */
    public function __construct(
        public readonly array $words,
        public readonly ?string $language,
        public readonly string $model
    ) {
    }

    /**
     * @return array{words: list<array{text: string, startMs: int, endMs: int, confidence: float|null}>,
     *     language: string|null, model: string, durationMs: int, source: string}
     */
    public function toResponse(int $durationMs, string $source): array
    {
        return [
            'words' => $this->words,
            'language' => $this->language,
            'model' => $this->model,
            'durationMs' => $durationMs,
            'source' => $source,
        ];
    }
}
