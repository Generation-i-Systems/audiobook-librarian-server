<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

class TranscriptionApiTest extends ApiTestCase
{
    private const WHISPER_URL = 'http://whisper.test';
    private const BOOK_DIRECTORY = 'Some Author/Some Book';
    private const FILE_NAME = 'Part 01.mp3';
    private const FILE_DURATION_SECONDS = 3600;

    private Book $book;
    private string $fileContents;

    /** @var list<string> */
    private array $extractedClipPaths = [];

    /** @var list<array<int, string>> */
    private array $ffmpegCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('books');
        config([
            'transcription.whisper_url' => self::WHISPER_URL,
            'transcription.model' => 'base.en',
            'transcription.whisper_model_id' => 'Systran/faster-whisper-base.en',
            'transcription.max_clip_seconds' => 30,
            'transcription.timeout_seconds' => 30,
            'transcription.max_upload_bytes' => 1024 * 1024,
            'transcription.rate_limit_per_minute' => 30,
        ]);

        $this->book = Book::factory()->create(['directory_path' => self::BOOK_DIRECTORY, 'source' => 'local']);
        $this->fileContents = str_repeat('audio-bytes-', 500);
        Storage::disk('books')->put(self::BOOK_DIRECTORY . '/' . self::FILE_NAME, $this->fileContents);

        $this->fakeFfmpeg(available: true);
    }

    private function fakeFfmpeg(bool $available): void
    {
        $this->extractedClipPaths = [];
        $this->ffmpegCommands = [];

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($available) {
            /** @var array<int, string> $command */
            $command = (array) $process->command;
            if (! $available) {
                return Process::result(errorOutput: 'not found', exitCode: 127);
            }
            if (in_array('-version', $command, true)) {
                return Process::result(output: 'ffmpeg version 6.1');
            }
            if ($command[0] === 'ffprobe') {
                return Process::result(output: self::FILE_DURATION_SECONDS . ".000000\n");
            }

            $this->ffmpegCommands[] = $command;
            $outputPath = (string) end($command);
            file_put_contents($outputPath, $this->wavBytes(1));
            $this->extractedClipPaths[] = $outputPath;

            return Process::result();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function whisperResponse(): array
    {
        return [
            'task' => 'transcribe',
            'language' => 'en',
            'duration' => 2.5,
            'text' => 'Hello world',
            'words' => [
                ['word' => ' Hello', 'start' => 0.0, 'end' => 0.42, 'probability' => 0.93],
                ['word' => ' world', 'start' => 0.5, 'end' => 0.9, 'probability' => 0.88],
            ],
            'segments' => [],
        ];
    }

    /**
     * @return list<array{text: string, startMs: int, endMs: int, confidence: float}>
     */
    private function expectedWords(): array
    {
        return [
            ['text' => 'Hello', 'startMs' => 0, 'endMs' => 420, 'confidence' => 0.93],
            ['text' => 'world', 'startMs' => 500, 'endMs' => 900, 'confidence' => 0.88],
        ];
    }

    private function fakeWhisper(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::WHISPER_URL . '/health' => Http::response('OK'),
            self::WHISPER_URL . '/v1/audio/transcriptions' => Http::response($this->whisperResponse()),
        ]);
    }

    private function wavBytes(float $seconds, int $sampleRate = 16000, int $channels = 1): string
    {
        $byteRate = $sampleRate * $channels * 2;
        $dataSize = (int) round($seconds * $byteRate);

        return 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $channels * 2, 16)
            . 'data' . pack('V', $dataSize) . str_repeat("\0", $dataSize);
    }

    private function serverFileUrl(?string $fileName = null, ?int $bookId = null): string
    {
        return '/api/v1/books/' . ($bookId ?? $this->book->id) . '/files/'
            . rawurlencode($fileName ?? self::FILE_NAME) . '/transcriptions';
    }

    /**
     * @return array<string, mixed>
     */
    private function serverFileBody(int $startMs = 60000, int $durationMs = 30000): array
    {
        return [
            'startMs' => $startMs,
            'durationMs' => $durationMs,
            'expectedSize' => strlen($this->fileContents),
            'expectedChunkHash' => hash('sha256', $this->fileContents),
        ];
    }

    private function postUpload(string $bytes, string $name = 'clip.wav', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            '/api/v1/transcriptions',
            array_merge(['audio' => UploadedFile::fake()->createWithContent($name, $bytes)], $extra),
            ['Accept' => 'application/json']
        );
    }

    public function testServerFileTranscriptionReturnsWordsForTheClampedClip(): void
    {
        $this->fakeWhisper();

        $response = $this->postJson($this->serverFileUrl(), $this->serverFileBody(60000, 45000));

        $response->assertOk()->assertExactJson([
            'words' => $this->expectedWords(),
            'language' => 'en',
            'model' => 'base.en',
            'durationMs' => 30000,
            'source' => 'server_file',
        ]);
        $this->assertCount(1, $this->ffmpegCommands);
        $command = $this->ffmpegCommands[0];
        $this->assertSame('60.000', $command[array_search('-ss', $command, true) + 1]);
        $this->assertSame('30.000', $command[array_search('-t', $command, true) + 1]);
        $this->assertSame(
            Storage::disk('books')->path(self::BOOK_DIRECTORY . '/' . self::FILE_NAME),
            $command[array_search('-i', $command, true) + 1]
        );
        $this->assertSame(['1', '16000', 'wav'], [
            $command[array_search('-ac', $command, true) + 1],
            $command[array_search('-ar', $command, true) + 1],
            $command[array_search('-f', $command, true) + 1],
        ]);
        $this->assertFileDoesNotExist($this->extractedClipPaths[0]);
        Http::assertSent(function (HttpRequest $request): bool {
            if ($request->url() !== self::WHISPER_URL . '/v1/audio/transcriptions') {
                return false;
            }
            $fields = collect($request->data())->mapWithKeys(
                fn (array $part) => [$part['name'] => $part['name'] === 'file' ? 'binary' : $part['contents']]
            )->sortKeys()->all();

            return $fields === [
                'file' => 'binary',
                'model' => 'Systran/faster-whisper-base.en',
                'response_format' => 'verbose_json',
                'timestamp_granularities[]' => 'word',
            ];
        });
    }

    public function testServerFileTranscriptionClampsDurationToTheEndOfTheFile(): void
    {
        $this->fakeWhisper();
        $startMs = (self::FILE_DURATION_SECONDS - 10) * 1000;

        $response = $this->postJson($this->serverFileUrl(), $this->serverFileBody($startMs, 30000));

        $response->assertOk()->assertJsonPath('durationMs', 10000);
        $command = $this->ffmpegCommands[0];
        $this->assertSame('10.000', $command[array_search('-t', $command, true) + 1]);
    }

    public function testServerFileTranscriptionAcceptsAFileInASubdirectoryAndPassesTheLanguage(): void
    {
        $this->fakeWhisper();
        $nestedFile = 'Disc 1/Track 01.mp3';
        Storage::disk('books')->put(self::BOOK_DIRECTORY . '/' . $nestedFile, $this->fileContents);
        $url = '/api/v1/books/' . $this->book->id . '/files/' . $nestedFile . '/transcriptions';

        $response = $this->postJson($url, array_merge($this->serverFileBody(), ['language' => 'en']));

        $response->assertOk()->assertJsonPath('source', 'server_file');
        Http::assertSent(fn (HttpRequest $request): bool => collect($request->data())
            ->contains(fn (array $part) => $part['name'] === 'language' && $part['contents'] === 'en'));
    }

    public function testServerFileTranscriptionAcceptsAPrefixedChunkHashAndNoHash(): void
    {
        $this->fakeWhisper();
        $prefixed = array_merge($this->serverFileBody(), [
            'expectedChunkHash' => 'sha256:' . strtoupper(hash('sha256', $this->fileContents)),
        ]);
        $withoutHash = $this->serverFileBody();
        unset($withoutHash['expectedChunkHash']);

        $this->postJson($this->serverFileUrl(), $prefixed)->assertOk();
        $this->postJson($this->serverFileUrl(), $withoutHash)->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingFileProvider(): array
    {
        return [
            'file absent' => ['Part 99.mp3'],
            'parent traversal' => ['../Other Book/Part 01.mp3'],
        ];
    }

    #[DataProvider('missingFileProvider')]
    public function testServerFileTranscriptionReturnsFileNotFound(string $fileName): void
    {
        $this->fakeWhisper();
        Storage::disk('books')->put('Some Author/Other Book/Part 01.mp3', $this->fileContents);

        $this->postJson($this->serverFileUrl($fileName), $this->serverFileBody())
            ->assertNotFound()
            ->assertJsonPath('code', 'FILE_NOT_FOUND');
        $this->assertSame([], $this->ffmpegCommands);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/audio'));
    }

    public function testServerFileTranscriptionReturnsFileNotFoundForAnUnknownBook(): void
    {
        $this->fakeWhisper();

        $this->postJson($this->serverFileUrl(bookId: $this->book->id + 1000), $this->serverFileBody())
            ->assertNotFound()
            ->assertJsonPath('code', 'FILE_NOT_FOUND');
    }

    /**
     * @return array<string, array{0: string, 1: int|string}>
     */
    public static function mismatchProvider(): array
    {
        return [
            'size differs' => ['expectedSize', 1],
            'first chunk hash differs' => ['expectedChunkHash', str_repeat('0', 64)],
        ];
    }

    #[DataProvider('mismatchProvider')]
    public function testServerFileTranscriptionReturnsFileMismatch(string $field, int|string $value): void
    {
        $this->fakeWhisper();
        $body = array_merge($this->serverFileBody(), [$field => $value]);

        $this->postJson($this->serverFileUrl(), $body)
            ->assertStatus(409)
            ->assertJsonPath('code', 'FILE_MISMATCH');
        $this->assertSame([], $this->ffmpegCommands);
    }

    public function testServerFileTranscriptionRejectsAStartPastTheEndOfTheFile(): void
    {
        $this->fakeWhisper();

        $this->postJson($this->serverFileUrl(), $this->serverFileBody(self::FILE_DURATION_SECONDS * 1000, 30000))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CLIP_OUT_OF_RANGE');
        $this->assertSame([], $this->ffmpegCommands);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidBodyProvider(): array
    {
        return [
            'negative start' => [['startMs' => -1], 'startMs'],
            'zero duration' => [['durationMs' => 0], 'durationMs'],
            'missing size' => [['expectedSize' => null], 'expectedSize'],
            'bad language' => [['language' => 'English!'], 'language'],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidBodyProvider')]
    public function testServerFileTranscriptionValidatesTheBody(array $override, string $field): void
    {
        $this->fakeWhisper();

        $this->postJson($this->serverFileUrl(), array_merge($this->serverFileBody(), $override))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    public function testServerFileTranscriptionIsUnavailableWithoutFfmpeg(): void
    {
        $this->fakeWhisper();
        $this->fakeFfmpeg(available: false);

        $this->postJson($this->serverFileUrl(), $this->serverFileBody())
            ->assertStatus(503)
            ->assertJsonPath('code', 'TRANSCRIPTION_UNAVAILABLE');
    }

    public function testServerFileTranscriptionReportsAClipFfmpegCannotCut(): void
    {
        $this->fakeWhisper();
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            if (in_array('-version', $command, true)) {
                return Process::result(output: 'ffmpeg version 6.1');
            }
            if ($command[0] === 'ffprobe') {
                return Process::result(output: self::FILE_DURATION_SECONDS . '.0');
            }
            $this->extractedClipPaths[] = (string) end($command);

            return Process::result(errorOutput: 'Invalid data found when processing input', exitCode: 1);
        });

        $this->postJson($this->serverFileUrl(), $this->serverFileBody())
            ->assertStatus(500)
            ->assertJson(['code' => 'CLIP_EXTRACTION_FAILED']);
        $this->assertFileDoesNotExist($this->extractedClipPaths[0]);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/audio'));
    }

    public function testServerFileTranscriptionIsDeniedToUsersWithoutLibraryAccess(): void
    {
        $this->fakeWhisper();
        $this->actingAsUser(User::factory()->create(['role' => 'unverified']));

        $this->postJson($this->serverFileUrl(), $this->serverFileBody())->assertForbidden();
        $this->assertSame([], $this->ffmpegCommands);
    }

    public function testUploadTranscriptionReturnsWords(): void
    {
        $this->fakeWhisper();

        $response = $this->postUpload($this->wavBytes(2.5), 'clip.wav', ['language' => 'en']);

        $response->assertOk()->assertExactJson([
            'words' => $this->expectedWords(),
            'language' => 'en',
            'model' => 'base.en',
            'durationMs' => 2500,
            'source' => 'upload',
        ]);
        $this->assertSame([], $this->ffmpegCommands);
    }

    public function testUploadTranscriptionAcceptsFlac(): void
    {
        $this->fakeWhisper();
        $sampleRate = 16000;
        $totalSamples = 16000 * 3;
        $packed = ($sampleRate << 44) | (0 << 41) | (15 << 36) | $totalSamples;
        $flac = 'fLaC' . chr(0x80) . "\x00\x00\x22" . str_repeat("\0", 10) . pack('J', $packed) . str_repeat("\0", 16);

        $this->postUpload($flac, 'clip.flac')->assertOk()->assertJsonPath('durationMs', 3000);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedUploadProvider(): array
    {
        return [
            'longer than the maximum clip' => ['long', 'CLIP_TOO_LONG'],
            'larger than the maximum upload' => ['large', 'AUDIO_TOO_LARGE'],
            'not wav or flac' => ['mp3', 'UNSUPPORTED_AUDIO'],
        ];
    }

    #[DataProvider('rejectedUploadProvider')]
    public function testUploadTranscriptionRejectsUnusableAudio(string $kind, string $code): void
    {
        $this->fakeWhisper();
        $bytes = match ($kind) {
            'long' => $this->wavBytes(30.5),
            'large' => $this->wavBytes(17, 32000),
            default => 'ID3' . str_repeat("\0", 1000),
        };

        $this->postUpload($bytes)->assertStatus(422)->assertJsonPath('code', $code);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/audio'));
    }

    public function testUploadTranscriptionRequiresAudio(): void
    {
        $this->fakeWhisper();

        $this->postJson('/api/v1/transcriptions', [])->assertStatus(422)->assertJsonValidationErrors(['audio']);
    }

    public function testUploadTranscriptionIsUnavailableWhenWhisperIsNotConfigured(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['transcription.whisper_url' => null]);

        $this->postUpload($this->wavBytes(1))
            ->assertStatus(503)
            ->assertJson(['code' => 'TRANSCRIPTION_UNAVAILABLE']);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sidecarDownProvider(): array
    {
        return [
            'connection refused' => ['refused'],
            'server error' => ['error'],
        ];
    }

    #[DataProvider('sidecarDownProvider')]
    public function testTranscriptionIsUnavailableWhenWhisperIsDown(string $failure): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::WHISPER_URL . '/v1/audio/transcriptions' => $failure === 'refused'
                ? Http::failedConnection('cURL error 7: Failed to connect to whisper.test port 80')
                : Http::response(['error' => 'boom'], 500),
        ]);

        $this->postUpload($this->wavBytes(1))
            ->assertStatus(503)
            ->assertJson(['code' => 'TRANSCRIPTION_UNAVAILABLE']);
        $this->postJson($this->serverFileUrl(), $this->serverFileBody())
            ->assertStatus(503)
            ->assertJson(['code' => 'TRANSCRIPTION_UNAVAILABLE']);
        $this->assertFileDoesNotExist($this->extractedClipPaths[0]);
    }

    public function testTranscriptionTimesOut(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::WHISPER_URL . '/v1/audio/transcriptions' => Http::failedConnection(
                'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'
            ),
        ]);

        $this->postUpload($this->wavBytes(1))
            ->assertStatus(504)
            ->assertJson(['code' => 'TRANSCRIPTION_TIMEOUT']);
        $this->postJson($this->serverFileUrl(), $this->serverFileBody())
            ->assertStatus(504)
            ->assertJson(['code' => 'TRANSCRIPTION_TIMEOUT']);
        $this->assertFileDoesNotExist($this->extractedClipPaths[0]);
    }

    public function testTranscriptionRoutesRequireAuthentication(): void
    {
        $this->fakeWhisper();
        $this->withHeader('Authorization', '');

        $this->postUpload($this->wavBytes(1))->assertUnauthorized();
        $this->postJson($this->serverFileUrl(), $this->serverFileBody())->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function testTranscriptionRoutesShareOneRateLimit(): void
    {
        $this->fakeWhisper();
        config(['transcription.rate_limit_per_minute' => 2]);

        $this->postUpload($this->wavBytes(1))->assertOk();
        $this->postJson($this->serverFileUrl(), $this->serverFileBody())->assertOk();
        $this->postUpload($this->wavBytes(1))->assertStatus(429);
        $this->postJson($this->serverFileUrl(), $this->serverFileBody())->assertStatus(429);
    }

    /**
     * @return array<string, array{0: ?string, 1: bool, 2: bool, 3: list<string>}>
     */
    public static function capabilityProvider(): array
    {
        return [
            'whisper not configured' => [null, true, true, []],
            'whisper healthy with ffmpeg' => [self::WHISPER_URL, true, true, ['TRANSCRIPTION', 'TRANSCRIPTION_SERVER_FILES']],
            'whisper healthy without ffmpeg' => [self::WHISPER_URL, true, false, ['TRANSCRIPTION']],
            'whisper down' => [self::WHISPER_URL, false, true, []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('capabilityProvider')]
    public function testCapabilitiesAdvertiseTranscriptionOnlyWhenUsable(
        ?string $whisperUrl,
        bool $whisperHealthy,
        bool $ffmpegAvailable,
        array $expected
    ): void {
        config(['transcription.whisper_url' => $whisperUrl]);
        $this->fakeFfmpeg($ffmpegAvailable);
        Http::preventStrayRequests();
        Http::fake([
            self::WHISPER_URL . '/health' => $whisperHealthy
                ? Http::response('OK')
                : Http::failedConnection('cURL error 7: Failed to connect'),
        ]);

        $capabilities = $this->getJson('/api/v1/health/capabilities')->assertOk()->json('capabilities');

        $this->assertSame(
            $expected,
            array_values(array_intersect($capabilities, ['TRANSCRIPTION', 'TRANSCRIPTION_SERVER_FILES']))
        );
    }

    public function testCapabilitiesCacheTheWhisperHealthCheck(): void
    {
        $this->fakeWhisper();

        $this->getJson('/api/v1/health/capabilities')->assertOk();
        $this->getJson('/api/v1/health/capabilities')->assertOk();

        Http::assertSentCount(1);
    }
}
