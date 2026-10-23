<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AIBookProcessor;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * When the AI call fails (e.g. a 403 from an empty API key) AIBookProcessor returns a
 * directory-name-only fallback. The import must still read author/series/title from the
 * audio filename instead of presenting the bare directory name as the title.
 */
class BookImportServiceAiFailureFilenameFallbackTest extends TestCase
{
    private BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService(
            $this->app->make(GenreMappingService::class),
            $this->app->make(SourceTrashService::class)
        );
    }

    public static function filenameProvider(): array
    {
        return [
            'author, bare series number, title' => [
                'C T Phipps, Michael Suttkus - Space Academy 06 - Space Academy Graduates.m4b',
                [
                    'author' => ['C T Phipps', 'Michael Suttkus'],
                    'series' => 'Space Academy',
                    'series_number' => 6,
                    'title' => 'Space Academy Graduates',
                ],
            ],
            'last-name-first author is not split' => [
                'Sanderson, Brandon - Stormlight Archive #1 - The Way of Kings.m4b',
                [
                    'author' => ['Sanderson, Brandon'],
                    'series' => 'Stormlight Archive',
                    'series_number' => 1,
                    'title' => 'The Way of Kings',
                ],
            ],
            'ampersand co-authors' => [
                'Ilona Andrews & Jeff Salyards - Iron and Magic #1 - Iron and Magic.mp3',
                [
                    'author' => ['Ilona Andrews', 'Jeff Salyards'],
                    'series' => 'Iron and Magic',
                    'series_number' => 1,
                    'title' => 'Iron and Magic',
                ],
            ],
        ];
    }

    #[Test]
    #[DataProvider('filenameProvider')]
    public function parseFilenameForMetadataExtractsAuthorSeriesAndTitle(string $filename, array $expected): void
    {
        $this->assertSame($expected, $this->service->parseFilenameForMetadata($filename));
    }

    #[Test]
    public function processWithAIUsesFilenameWhenAiCallFails(): void
    {
        $directory = '/media/unsorted/Cassius Lange';
        $file = $directory . '/Zenith Academy/Zenith Academy [B09LRJMRJS]/Cassius Lange - Zenith Academy.m4b';

        $result = $this->service->processWithAI(
            $this->observedAudiobook($directory, $file),
            $this->aiProcessorReturning($this->fallbackResult('Cassius Lange'))
        );

        $this->assertSame(
            ['title' => 'Zenith Academy', 'author' => ['Cassius Lange'], 'ai_failed' => true],
            ['title' => $result['title'], 'author' => $result['author'], 'ai_failed' => $result['_ai_failed']]
        );
    }

    #[Test]
    public function processWithAIKeepsTagTitleOverFilenameWhenAiCallFails(): void
    {
        $directory = '/media/unsorted/Some Folder';
        $file = $directory . '/Cassius Lange - Zenith Academy.m4b';
        $tagTitle = 'Tagged Zenith Title';

        $audiobook = $this->observedAudiobook($directory, $file);
        $audiobook['observed_file_tags'] = [$file => ['title' => $tagTitle]];

        $result = $this->service->processWithAI(
            $audiobook,
            $this->aiProcessorReturning($this->fallbackResult($tagTitle))
        );

        $this->assertSame(
            ['title' => $tagTitle, 'author' => ['Cassius Lange']],
            ['title' => $result['title'], 'author' => $result['author']]
        );
    }

    #[Test]
    public function processWithAIDoesNotApplyFilenameWhenAiSucceeds(): void
    {
        $directory = '/media/unsorted/Cassius Lange';
        $file = $directory . '/Cassius Lange - Zenith Academy.m4b';
        $aiTitle = 'Zenith Academy (AI)';
        $aiAuthor = ['AI Author'];

        $aiResult = $this->fallbackResult($aiTitle);
        $aiResult['author'] = $aiAuthor;
        $aiResult['ai_processed'] = true;
        $aiResult['confidence'] = 90;

        $result = $this->service->processWithAI(
            $this->observedAudiobook($directory, $file),
            $this->aiProcessorReturning($aiResult)
        );

        $this->assertSame(
            ['title' => $aiTitle, 'author' => $aiAuthor, 'ai_failed' => false],
            ['title' => $result['title'], 'author' => $result['author'], 'ai_failed' => $result['_ai_failed'] ?? false]
        );
    }

    private function observedAudiobook(string $directory, string $file): array
    {
        return [
            'name' => basename($directory),
            'path' => $directory,
            'files' => [$file],
            'observed_file_tags' => [],
        ];
    }

    private function aiProcessorReturning(array $result): AIBookProcessor
    {
        $aiProcessor = $this->createMock(AIBookProcessor::class);
        $aiProcessor->method('processBookDirectory')->willReturn($result);

        return $aiProcessor;
    }

    /**
     * Shape returned by AIBookProcessor::getFallbackMetadata().
     */
    private function fallbackResult(string $title): array
    {
        return [
            'title' => $title,
            'author' => [],
            'narrator' => [],
            'genre' => [],
            'year' => null,
            'confidence' => 25,
            'series' => null,
            'series_number' => null,
            'ai_processed' => false,
        ];
    }
}
