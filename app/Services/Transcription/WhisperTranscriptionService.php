<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to an OpenAI-compatible Whisper server (faster-whisper) for word-timed transcripts
 * of short clips. Nothing is stored; only timing and status are logged.
 */
class WhisperTranscriptionService
{
    private const HEALTH_TIMEOUT_SECONDS = 3;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '';
    }

    public function isHealthy(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return (bool) Cache::remember(
            'transcription.whisper_healthy.' . sha1($this->baseUrl()),
            (int) config('transcription.health_cache_seconds', 60),
            function (): bool {
                try {
                    return Http::timeout(self::HEALTH_TIMEOUT_SECONDS)
                        ->get($this->baseUrl() . '/health')
                        ->successful();
                } catch (ConnectionException) {
                    return false;
                }
            }
        );
    }

    public function modelName(): string
    {
        return (string) config('transcription.model', 'base.en');
    }

    /**
     * @throws TranscriptionException
     */
    public function transcribe(
        string $audioBytes,
        string $fileName,
        ?string $language,
        int $clipDurationMs
    ): TranscriptionResult {
        if (! $this->isConfigured()) {
            throw TranscriptionException::unavailable('Speech-to-text is not configured on this server.');
        }

        $fields = [
            'model' => (string) config('transcription.whisper_model_id'),
            'response_format' => 'verbose_json',
            'timestamp_granularities[]' => 'word',
        ];
        if ($language !== null) {
            $fields['language'] = $language;
        }

        $startedAt = microtime(true);
        try {
            $response = Http::timeout((int) config('transcription.timeout_seconds', 30))
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->attach('file', $audioBytes, $fileName)
                ->post($this->baseUrl() . '/v1/audio/transcriptions', $fields);
        } catch (ConnectionException $e) {
            $message = $e->getMessage();
            $timedOut = str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false;
            Log::warning('Whisper transcription request failed', [
                'status' => $timedOut ? 'timeout' : 'connection_failed',
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            if ($timedOut) {
                throw TranscriptionException::timeout();
            }

            throw TranscriptionException::unavailable('The speech-to-text service could not be reached.');
        }

        if (! $response->successful() || ! is_array($response->json('words'))) {
            Log::warning('Whisper transcription request failed', [
                'status' => 'http_' . $response->status(),
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw TranscriptionException::unavailable('The speech-to-text service returned an error.');
        }

        /** @var array<int, mixed> $rawWords */
        $rawWords = $response->json('words');

        return new TranscriptionResult(
            $this->normalizeWords($rawWords, $clipDurationMs),
            $this->normalizeLanguage($response->json('language'), $language),
            $this->modelName()
        );
    }

    /**
     * @param array<int, mixed> $rawWords
     * @return list<array{text: string, startMs: int, endMs: int, confidence: float|null}>
     */
    private function normalizeWords(array $rawWords, int $clipDurationMs): array
    {
        $words = [];
        foreach ($rawWords as $rawWord) {
            if (! is_array($rawWord) || ! isset($rawWord['word'], $rawWord['start'], $rawWord['end'])) {
                continue;
            }
            $text = trim((string) $rawWord['word']);
            if ($text === '') {
                continue;
            }
            $startMs = max(0, min($clipDurationMs, (int) round((float) $rawWord['start'] * 1000)));
            $endMs = max($startMs, min($clipDurationMs, (int) round((float) $rawWord['end'] * 1000)));
            $confidence = null;
            if (isset($rawWord['probability']) && is_numeric($rawWord['probability'])) {
                $confidence = round((float) $rawWord['probability'], 4);
            }

            $words[] = ['text' => $text, 'startMs' => $startMs, 'endMs' => $endMs, 'confidence' => $confidence];
        }

        return $words;
    }

    /**
     * Whisper servers report either an ISO code ("en") or the language name ("english");
     * clients get a code, falling back to what they asked for or what an English-only
     * model implies.
     */
    private function normalizeLanguage(mixed $reported, ?string $requested): ?string
    {
        if (is_string($reported) && preg_match('/^[a-z]{2,3}$/', $reported) === 1) {
            return $reported;
        }
        if ($requested !== null) {
            return $requested;
        }

        return str_ends_with($this->modelName(), '.en') ? 'en' : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('transcription.whisper_url', ''), '/');
    }
}
