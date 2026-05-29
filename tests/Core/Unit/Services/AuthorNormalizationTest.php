<?php

namespace Tests\Core\Unit\Services;

use App\Services\BookImportService;
use PHPUnit\Framework\TestCase;

class AuthorNormalizationTest extends TestCase
{
    protected $service;
    protected $invokeMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the GenreMappingService and SourceTrashService
        $genreMappingService = $this->createStub(\App\Services\GenreMappingService::class);
        $sourceTrashService = $this->createStub(\App\Services\SourceTrashService::class);

        // Create a new instance of BookImportService with the mocks
        $this->service = new class ($genreMappingService, $sourceTrashService) extends BookImportService {
            // Override any methods that use facades or other Laravel features
            protected function methodThatUsesFacades()
            {
                // Mock the facade behavior here if needed
                return [];
            }
        };

        // Add reflection helper method
        $this->invokeMethod = function ($object, $method, array $parameters = []) {
            $reflection = new \ReflectionClass(get_class($object));
            $method = $reflection->getMethod($method);
            $method->setAccessible(true);
            return $method->invokeArgs($object, $parameters);
        };
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_extracts_author_from_graphic_audio_bracket_pattern()
    {
        $invoke = $this->invokeMethod;
        $result = $invoke($this->service, 'normalizeAuthorName', ['Graphic Audio [Alex Archer]']);
        $this->assertEquals('Alex Archer', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_extracts_author_from_graphicaudio_bracket_pattern()
    {
        $invoke = $this->invokeMethod;
        $result = $invoke($this->service, 'normalizeAuthorName', ['GraphicAudio [John Smith]']);
        $this->assertEquals('John Smith', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_graphic_audio_as_author()
    {
        $invoke = $this->invokeMethod;
        $result = $invoke($this->service, 'normalizeAuthorName', ['Graphic Audio']);
        $this->assertSame('', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_graphicaudio_as_author()
    {
        $invoke = $this->invokeMethod;
        $result = $invoke($this->service, 'normalizeAuthorName', ['GraphicAudio']);
        $this->assertSame('', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_any_name_containing_graphic_and_audio()
    {
        $invoke = $this->invokeMethod;
        $result = $invoke($this->service, 'normalizeAuthorName', ['Some Graphic and Audio Production']);
        $this->assertSame('', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_full_cast_as_author()
    {
        $invoke = $this->invokeMethod;
        $result = $invoke($this->service, 'normalizeAuthorName', ['Full Cast']);
        $this->assertSame('', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_preserves_normal_author_names()
    {
        $invoke = $this->invokeMethod;
        $result = $invoke($this->service, 'normalizeAuthorName', ['J.K. Rowling']);
        $this->assertEquals('J.K. Rowling', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_normalizes_initials()
    {
        $invoke = $this->invokeMethod;
        $result = $invoke($this->service, 'normalizeAuthorName', ['J. K. Rowling']);
        $this->assertEquals('J.K. Rowling', $result);
    }
}
