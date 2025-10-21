<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use Tests\TestCase;

class AuthorNormalizationTest extends TestCase
{
    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BookImportService::class);
    }

    /** @test */
    public function it_extracts_author_from_graphic_audio_bracket_pattern()
    {
        $result = $this->invokeMethod($this->service, 'normalizeAuthorName', ['Graphic Audio [Alex Archer]']);

        $this->assertEquals('Alex Archer', $result);
    }

    /** @test */
    public function it_extracts_author_from_graphicaudio_bracket_pattern()
    {
        $result = $this->invokeMethod($this->service, 'normalizeAuthorName', ['GraphicAudio [John Smith]']);

        $this->assertEquals('John Smith', $result);
    }

    /** @test */
    public function it_rejects_graphic_audio_as_author()
    {
        $result = $this->invokeMethod($this->service, 'normalizeAuthorName', ['Graphic Audio']);

        $this->assertEquals('', $result);
    }

    /** @test */
    public function it_rejects_graphicaudio_as_author()
    {
        $result = $this->invokeMethod($this->service, 'normalizeAuthorName', ['GraphicAudio']);

        $this->assertEquals('', $result);
    }

    /** @test */
    public function it_rejects_any_name_containing_graphic_and_audio()
    {
        $result = $this->invokeMethod($this->service, 'normalizeAuthorName', ['Graphic Audio Productions']);

        $this->assertEquals('', $result);
    }

    /** @test */
    public function it_rejects_full_cast_as_author()
    {
        $result = $this->invokeMethod($this->service, 'normalizeAuthorName', ['Full Cast']);

        $this->assertEquals('', $result);
    }

    /** @test */
    public function it_preserves_normal_author_names()
    {
        $result = $this->invokeMethod($this->service, 'normalizeAuthorName', ['Shannon Mayer']);

        $this->assertEquals('Shannon Mayer', $result);
    }

    /** @test */
    public function it_normalizes_initials()
    {
        $result = $this->invokeMethod($this->service, 'normalizeAuthorName', ['J K Rowling']);

        $this->assertEquals('J.K. Rowling', $result);
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
