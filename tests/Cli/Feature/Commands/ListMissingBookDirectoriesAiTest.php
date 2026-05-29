<?php

declare(strict_types=1);

namespace Tests\Cli\Feature\Commands;

use App\Models\Book;
use App\Services\AIBookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListMissingBookDirectoriesAiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function testAiSuggestIncludesSuggestionsInJsonAndOptionalFile(): void
    {
        Storage::fake('books');

        // Prepare storage: one existing referenced directory, and two unreferenced with audio
        Storage::disk('books')->makeDirectory('existing/dir');
        Storage::disk('books')->put('unref/bookA/track1.mp3', 'A');
        Storage::disk('books')->put('unref/bookB/track1.m4b', 'B');

        // DB references: one exists, two are missing
        Book::factory()->create(['directory_path' => 'existing/dir']);
        Book::factory()->create(['directory_path' => 'missing/bookA']);
        Book::factory()->create(['directory_path' => 'missing/bookB']);

        // Mock AI processor
        $mock = $this->createStub(AIBookProcessor::class);
        $mock->method('complete')->willReturn([
            'success' => true,
            'data' => "```json\n" . json_encode([
                'mappings' => [
                    [
                        'db_directory' => 'missing/bookA',
                        'unreferenced_directory' => 'unref/bookA',
                        'confidence' => 0.92,
                        'reason' => 'Name similarity and series numbering match',
                    ],
                    [
                        'db_directory' => 'missing/bookB',
                        'unreferenced_directory' => 'unref/bookB',
                        'confidence' => 0.88,
                        'reason' => 'High name similarity',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES) . "\n```",
        ]);
        $this->app->instance(AIBookProcessor::class, $mock);

        $mainOut = tempnam(sys_get_temp_dir(), 'ai-main-');
        $aiOut = tempnam(sys_get_temp_dir(), 'ai-out-');

        $this->artisan('books:list-missing-directories', [
            '--disk' => 'books',
            '--format' => 'json',
            '--output' => $mainOut,
            '--include-unreferenced' => true,
            '--ai-suggest' => true,
            '--ai-output' => $aiOut,
        ])->assertExitCode(0);

        $main = file_get_contents($mainOut);
        $this->assertIsString($main);
        $this->assertStringContainsString('"ai_suggestions"', $main);
        $this->assertStringContainsString('missing/bookA', $main);
        $this->assertStringContainsString('unref/bookA', $main);

        $ai = file_get_contents($aiOut);
        $this->assertIsString($ai);
        // The ai-output now writes ONLY the raw AI response text
        $this->assertStringContainsString('```json', $ai);
        $this->assertStringContainsString('unref/bookB', $ai);
        $this->assertNull(json_decode((string) $ai, true));
    }

    #[Test]
    public function testAiSuggestParsesJsonPrefixWithoutFences(): void
    {
        Storage::fake('books');

        // Prepare storage
        Storage::disk('books')->put('unref/bookC/track1.mp3', 'C');
        Book::factory()->create(['directory_path' => 'missing/bookC']);

        // Mock AI processor returning a plain 'json\n' prefix without fences
        $mock = $this->createStub(AIBookProcessor::class);
        $mock->method('complete')->willReturn([
            'success' => true,
            'data' => "json\n" . json_encode([
                'mappings' => [
                    [
                        'db_directory' => 'missing/bookC',
                        'unreferenced_directory' => 'unref/bookC',
                        'confidence' => 0.77,
                        'reason' => 'Prefix json followed by valid object',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $this->app->instance(AIBookProcessor::class, $mock);

        $out = tempnam(sys_get_temp_dir(), 'ai-jsonprefix-');
        $this->artisan('books:list-missing-directories', [
            '--disk' => 'books',
            '--format' => 'json',
            '--output' => $out,
            '--include-unreferenced' => true,
            '--ai-suggest' => true,
        ])->assertExitCode(0);

        $contents = file_get_contents($out);
        $this->assertIsString($contents);
        $json = json_decode((string) $contents, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('ai_suggestions', $json);
        $this->assertArrayHasKey('mappings', $json['ai_suggestions']);
        $mappings = (array) $json['ai_suggestions']['mappings'];
        $unrefDirs = array_column($mappings, 'unreferenced_directory');
        $this->assertContains('unref/bookC', $unrefDirs);
    }
}
