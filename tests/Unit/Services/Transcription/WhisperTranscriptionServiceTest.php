<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Transcription;

use App\Services\Transcription\WhisperTranscriptionService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WhisperTranscriptionServiceTest extends TestCase
{
    private const WHISPER_URL = 'http://whisper.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'transcription.whisper_url' => self::WHISPER_URL . '/',
            'transcription.model' => 'base.en',
            'transcription.whisper_model_id' => 'Systran/faster-whisper-base.en',
        ]);
    }

    public function testTranscribeTrimsClampsAndKeepsMissingConfidenceNull(): void
    {
        Http::fake([self::WHISPER_URL . '/v1/audio/transcriptions' => Http::response([
            'language' => 'en',
            'words' => [
                ['word' => '  ', 'start' => 0.0, 'end' => 0.1],
                ['word' => ' Early', 'start' => -0.2, 'end' => 0.3],
                ['word' => ' late', 'start' => 1.9, 'end' => 2.6],
                ['word' => 'broken'],
            ],
        ])]);

        $result = (new WhisperTranscriptionService())->transcribe('bytes', 'clip.wav', null, 2000);

        $this->assertSame([
            ['text' => 'Early', 'startMs' => 0, 'endMs' => 300, 'confidence' => null],
            ['text' => 'late', 'startMs' => 1900, 'endMs' => 2000, 'confidence' => null],
        ], $result->words);
    }

    /**
     * @return array<string, array{0: mixed, 1: ?string, 2: string, 3: ?string}>
     */
    public static function languageProvider(): array
    {
        return [
            'iso code from whisper' => ['de', 'en', 'base', 'de'],
            'language name falls back to request' => ['german', 'de', 'base', 'de'],
            'language name with english-only model' => ['english', null, 'base.en', 'en'],
            'nothing known' => [null, null, 'base', null],
        ];
    }

    #[DataProvider('languageProvider')]
    public function testTranscribeReportsALanguageCode(mixed $reported, ?string $requested, string $model, ?string $expected): void
    {
        config(['transcription.model' => $model]);
        Http::fake([self::WHISPER_URL . '/v1/audio/transcriptions' => Http::response([
            'language' => $reported,
            'words' => [],
        ])]);

        $result = (new WhisperTranscriptionService())->transcribe('bytes', 'clip.wav', $requested, 1000);

        $this->assertSame([$expected, $model], [$result->language, $result->model]);
    }

    public function testIsHealthyIsFalseWithoutAUrlAndSendsNothing(): void
    {
        config(['transcription.whisper_url' => '']);
        Http::fake();

        $this->assertFalse((new WhisperTranscriptionService())->isHealthy());
        Http::assertNothingSent();
    }
}
