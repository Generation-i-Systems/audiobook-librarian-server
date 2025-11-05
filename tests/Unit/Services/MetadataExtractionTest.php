<?php

namespace Tests\Unit\Services;

use App\Services\MetadataProcessingService;
use App\Services\AIBookProcessor;
use Tests\TestCase;

class MetadataExtractionTest extends TestCase
{
    protected MetadataProcessingService $service;
    protected $aiBookProcessorMock;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a mock of AIBookProcessor using Laravel's mock helper
        $this->aiBookProcessorMock = $this->mock(AIBookProcessor::class);
        
        // Create the service - it will use our mocked AIBookProcessor
        $this->service = new MetadataProcessingService();
    }

    /** @test */
    public function it_extracts_author_from_artist_tag()
    {
        // Create a test audiobook with file tags
        $audiobook = [
            'path' => '/test/path',
            'name' => 'Test Book',
            'files' => [
                '/test/path/test.mp3'
            ],
            'openaudible_metadata' => []
        ];

        // Mock the AIBookProcessor to return our test tags
        $this->aiBookProcessorMock
            ->shouldReceive('extractFileTags')
            ->with('/test/path/test.mp3')
            ->andReturn([
                'artist' => 'Shannon Mayer',
                'title' => 'Tracker'
            ]);

        // Process the audiobook
        $result = $this->service->processWithoutAI($audiobook);

        // Assert that the author was extracted from the artist tag
        $this->assertEquals(['Shannon Mayer'], $result['author']);
        $this->assertEquals('Tracker', $result['title']);
    }

    /** @test */
    public function it_never_uses_graphic_audio_as_author()
    {
        $fileTags = [
            'artist' => 'Graphic Audio',
            'title' => 'Test Book',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        // Should not set Graphic Audio as author
        $this->assertArrayNotHasKey('author', $result);
    }

    /** @test */
    public function it_extracts_author_from_bracket_pattern()
    {
        $fileTags = [
            'artist' => 'Graphic Audio [Alex Archer]',
            'title' => 'Test Book',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        // The normalization happens in BookImportService, not here
        // But the artist tag should still be set
        $this->assertEquals(['Graphic Audio [Alex Archer]'], $result['author']);
    }

    /** @test */
    public function it_extracts_narrator_from_composer_tag()
    {
        $fileTags = [
            'composer' => 'Patrick Lawlor',
            'artist' => 'Robert Coram',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        $this->assertEquals('Patrick Lawlor', $result['narrator']);
    }

    /** @test */
    public function it_extracts_year_from_date_tag()
    {
        $fileTags = [
            'date' => '2021-02-19',
            'artist' => 'Test Author',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        $this->assertEquals(2021, $result['year']);
    }

    /** @test */
    public function it_extracts_genre_from_tag()
    {
        $fileTags = [
            'genre' => 'Science Fiction & Fantasy:Fantasy:Epic',
            'artist' => 'Test Author',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        $this->assertEquals('Science Fiction & Fantasy:Fantasy:Epic', $result['genre']);
    }

    /** @test */
    public function it_extracts_publisher_from_copyright()
    {
        $fileTags = [
            'copyright' => '©2013 Shannon Mayer;(P)2021 GraphicAudio',
            'artist' => 'Shannon Mayer',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        $this->assertEquals('GraphicAudio', $result['publisher']);
    }

    /** @test */
    public function it_parses_title_with_series_and_number()
    {
        $fileTags = [
            'title' => 'Tracker (Dramatized Adaptation) - Rylee Adamson 6',
            'artist' => 'Shannon Mayer',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        $this->assertEquals('Tracker', $result['title']);
        $this->assertEquals('Rylee Adamson', $result['series']);
        $this->assertEquals(6, $result['series_number']);
    }

    /** @test */
    public function it_uses_full_title_when_no_series_number()
    {
        $fileTags = [
            'title' => 'Boyd - The Fighter Pilot Who Changed the Art of War',
            'artist' => 'Robert Coram',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        $this->assertEquals('Boyd - The Fighter Pilot Who Changed the Art of War', $result['title']);
        $this->assertArrayNotHasKey('series', $result);
    }

    /** @test */
    public function it_extracts_description_from_tags()
    {
        $fileTags = [
            'description' => '<p>This is a test description</p>',
            'artist' => 'Test Author',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        $this->assertEquals('This is a test description', $result['description']);
    }

    /** @test */
    public function file_tags_override_ai_results()
    {
        $fileTags = [
            'artist' => 'Correct Author',
            'date' => '2021-05-15',
        ];

        $result = [
            'author' => ['Wrong Author'],
            'year' => 2000,
        ];

        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        // File tags should override
        $this->assertEquals(['Correct Author'], $result['author']);
        $this->assertEquals(2021, $result['year']);
    }

    /** @test */
    public function file_tag_series_never_overridden_by_ai()
    {
        // REGRESSION TEST: File tags extracted "The Doomed Earth" but AI changed it to "Jack"
        $fileTags = [
            'title' => 'In Our Stars - The Doomed Earth, Book 1',
            'artist' => 'Jack Campbell',
        ];

        $result = [
            'author' => ['Jack Campbell'],
            'series' => 'Jack', // AI incorrectly extracted from author name
        ];

        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        // File tags should override AI's incorrect series
        $this->assertEquals('In Our Stars', $result['title']);
        $this->assertEquals('The Doomed Earth', $result['series']);
        $this->assertEquals(1, $result['series_number']);
    }

    /** @test */
    public function parses_title_with_comma_book_format()
    {
        // Test the new pattern: "Title - Series, Book N"
        $fileTags = [
            'title' => 'In Our Stars - The Doomed Earth, Book 1',
            'artist' => 'Jack Campbell',
        ];

        $result = [];
        $this->invokeMethod($this->service, 'applyId3TagMappings', [&$result, $fileTags]);

        $this->assertEquals('In Our Stars', $result['title']);
        $this->assertEquals('The Doomed Earth', $result['series']);
        $this->assertEquals(1, $result['series_number']);
    }

    /**
     * Invoke protected/private method of a class
     */
    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
