<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Transcription;

use App\Services\Transcription\AudioClipInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AudioClipInspectorTest extends TestCase
{
    private static function wav(int $sampleRate, int $channels, int $dataBytes, ?int $declaredSize = null, string $extraChunk = ''): string
    {
        $byteRate = $sampleRate * $channels * 2;

        return 'RIFF' . pack('V', 36 + $dataBytes) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $channels * 2, 16)
            . $extraChunk
            . 'data' . pack('V', $declaredSize ?? $dataBytes) . str_repeat("\0", $dataBytes);
    }

    private static function flac(int $sampleRate, int $totalSamples, int $blockType = 0): string
    {
        $packed = ($sampleRate << 44) | (15 << 36) | $totalSamples;

        return 'fLaC' . chr(0x80 | $blockType) . "\x00\x00\x22" . str_repeat("\0", 10) . pack('J', $packed)
            . str_repeat("\0", 16);
    }

    /**
     * @return array<string, array{0: string, 1: array{format: string, durationMs: int}|null}>
     */
    public static function clipProvider(): array
    {
        return [
            'wav 16 kHz mono' => [self::wav(16000, 1, 32000 * 3), ['format' => 'wav', 'durationMs' => 3000]],
            'wav 44.1 kHz stereo' => [self::wav(44100, 2, 176400), ['format' => 'wav', 'durationMs' => 1000]],
            'wav with odd-sized chunk before data' => [
                self::wav(16000, 1, 16000, null, 'LIST' . pack('V', 3) . "abc\0"),
                ['format' => 'wav', 'durationMs' => 500],
            ],
            'streaming wav with unset size' => [self::wav(16000, 1, 32000, 0), ['format' => 'wav', 'durationMs' => 1000]],
            'streaming wav with maximum size' => [
                self::wav(16000, 1, 32000, 0xFFFFFFFF),
                ['format' => 'wav', 'durationMs' => 1000],
            ],
            'wav without data chunk' => ['RIFF' . pack('V', 4) . 'WAVE', null],
            'flac' => [self::flac(16000, 16000 * 2), ['format' => 'flac', 'durationMs' => 2000]],
            'flac without sample count' => [self::flac(16000, 0), null],
            'flac without leading streaminfo' => [self::flac(16000, 16000, 4), null],
            'mp3' => ['ID3' . str_repeat("\0", 64), null],
            'empty' => ['', null],
        ];
    }

    /**
     * @param array{format: string, durationMs: int}|null $expected
     */
    #[DataProvider('clipProvider')]
    public function testInspectReadsFormatAndDuration(string $bytes, ?array $expected): void
    {
        $this->assertSame($expected, (new AudioClipInspector())->inspect($bytes));
    }
}
