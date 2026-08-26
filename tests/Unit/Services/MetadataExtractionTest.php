<?php

namespace Tests\Unit\Services;

use App\Services\MetadataProcessingService;
use App\Services\AIBookProcessor;
use PHPUnit\Framework\TestCase;

class MetadataExtractionTest extends TestCase
{
    protected MetadataProcessingService $service;
    protected $aiBookProcessorMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiBookProcessorMock = $this->createStub(AIBookProcessor::class);

        $this->service = new class ($this->aiBookProcessorMock) extends MetadataProcessingService {
            protected ?AIBookProcessor $aiProcessor;

            public function __construct($aiProcessor)
            {
                $this->aiProcessor = $aiProcessor;
            }

            public function getAuthorPreferredGenre($authors): ?string
            {
                return null;
            }
        };
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_extracts_author_from_artist_tag(): void
    {
        $this->aiBookProcessorMock->method('extractFileTags')
            ->willReturn([
                'artist' => 'Brandon Sanderson',
                'title' => 'The Way of Kings',
            ]);

        $audiobook = [
            'name' => 'The Way of Kings',
            'path' => '/test/path',
            'files' => ['/test/path/audio.mp3'],
        ];

        $result = $this->service->processWithoutAI($audiobook);

        $this->assertNotNull($result);
        $this->assertContains('Brandon Sanderson', $result['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_extracts_narrator_from_read_by_comment_tag(): void
    {
        $this->aiBookProcessorMock->method('extractFileTags')
            ->willReturn([
                'artist' => 'Greg Egan',
                'title' => 'Reasons to Be Cheerful',
                'comment' => 'Read by Richard Hauenstein',
            ]);

        $audiobook = [
            'name' => 'Reasons to Be Cheerful',
            'path' => '/test/path',
            'files' => ['/test/path/audio.mp3'],
        ];

        $result = $this->service->processWithoutAI($audiobook);

        $this->assertNotNull($result);
        $this->assertContains('Richard Hauenstein', $result['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_extracts_narrator_from_narrated_by_comment_tag(): void
    {
        $this->aiBookProcessorMock->method('extractFileTags')
            ->willReturn([
                'artist' => 'Greg Egan',
                'title' => 'Reasons to Be Cheerful',
                'comment' => 'Narrated by Richard Hauenstein.',
            ]);

        $audiobook = [
            'name' => 'Reasons to Be Cheerful',
            'path' => '/test/path',
            'files' => ['/test/path/audio.mp3'],
        ];

        $result = $this->service->processWithoutAI($audiobook);

        $this->assertNotNull($result);
        $this->assertContains('Richard Hauenstein', $result['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prefers_explicit_narrator_tag_over_comment_tag(): void
    {
        $this->aiBookProcessorMock->method('extractFileTags')
            ->willReturn([
                'artist' => 'Greg Egan',
                'title' => 'Reasons to Be Cheerful',
                'narrator' => 'Explicit Narrator Tag',
                'comment' => 'Read by Richard Hauenstein',
            ]);

        $audiobook = [
            'name' => 'Reasons to Be Cheerful',
            'path' => '/test/path',
            'files' => ['/test/path/audio.mp3'],
        ];

        $result = $this->service->processWithoutAI($audiobook);

        $this->assertNotNull($result);
        $this->assertContains('Explicit Narrator Tag', $result['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_placeholder(): void
    {
        $this->expectNotToPerformAssertions();
    }
}
