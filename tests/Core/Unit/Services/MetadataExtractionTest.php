<?php

namespace Tests\Core\Unit\Services;

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
    public function test_placeholder(): void
    {
        $this->assertTrue(true);
    }
}
