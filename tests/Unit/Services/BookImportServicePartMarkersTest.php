<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Tests\TestCase;

class BookImportServicePartMarkersTest extends TestCase
{
    private BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService(
            app(GenreMappingService::class),
            app(\App\Services\SourceTrashService::class)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function stripPartAndProductionMarkersRemovesPartOfPattern(): void
    {
        $result = $this->service->stripPartAndProductionMarkers(
            '01 The Warded Man (2 of 2) [Dramatized Adaptation] - Peter V. Brett'
        );

        $this->assertSame('01 The Warded Man - Peter V. Brett', $result['title']);
        $this->assertTrue($result['is_multi_part']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function stripPartAndProductionMarkersRemovesDramatizedAdaptation(): void
    {
        $result = $this->service->stripPartAndProductionMarkers(
            '05.6 Butter Cookies and Demon Claws [Dramatized Adaptation] - Peter V. Brett'
        );

        $this->assertSame('05.6 Butter Cookies and Demon Claws - Peter V. Brett', $result['title']);
        $this->assertFalse($result['is_multi_part']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function stripPartAndProductionMarkersRemovesBracketedGraphicAudio(): void
    {
        $result = $this->service->stripPartAndProductionMarkers(
            '05.6 Butter Cookies and Demon Claws [Graphic Audio] - Peter V. Brett'
        );

        $this->assertSame('05.6 Butter Cookies and Demon Claws - Peter V. Brett', $result['title']);
        $this->assertFalse($result['is_multi_part']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function stripPartAndProductionMarkersRemovesParenthesisGraphicAudio(): void
    {
        $result = $this->service->stripPartAndProductionMarkers(
            '05.6 Butter Cookies and Demon Claws (GraphicAudio) - Peter V. Brett'
        );

        $this->assertSame('05.6 Butter Cookies and Demon Claws - Peter V. Brett', $result['title']);
        $this->assertFalse($result['is_multi_part']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function stripPartAndProductionMarkersHandlesAllMarkersInCombination(): void
    {
        $result = $this->service->stripPartAndProductionMarkers(
            'The Eye of the World (1 of 2) [Dramatized Adaptation]'
        );

        $this->assertSame('The Eye of the World', $result['title']);
        $this->assertTrue($result['is_multi_part']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function stripPartAndProductionMarkersLeavesCleanTitlesUnchanged(): void
    {
        $result = $this->service->stripPartAndProductionMarkers('The Name of the Wind - Patrick Rothfuss');

        $this->assertSame('The Name of the Wind - Patrick Rothfuss', $result['title']);
        $this->assertFalse($result['is_multi_part']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAIResultSetsIsMultiPartFlagFromDirectoryName(): void
    {
        $aiResult = ['title' => 'The Warded Man', 'confidence' => 90];
        $audiobook = ['path' => '/downloads/01 The Warded Man (2 of 2) [Dramatized Adaptation] - Peter V. Brett'];

        $result = $this->service->postProcessAIResult($aiResult, $audiobook);

        $this->assertTrue($result['is_multi_part'] ?? false);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAIResultStripsPartMarkersFromAITitle(): void
    {
        $aiResult = ['title' => 'The Warded Man (2 of 2)', 'confidence' => 90];
        $audiobook = ['path' => '/downloads/SomeDir'];

        $result = $this->service->postProcessAIResult($aiResult, $audiobook);

        $this->assertStringNotContainsString('(2 of 2)', $result['title']);
        $this->assertTrue($result['is_multi_part'] ?? false);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resolveFileConflictReturnsNullWhenNoCallback(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('resolveFileConflict');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '/src/cover.jpg', '/dst/cover.jpg', 'non-audio', null);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resolveFileConflictReturnsNullForKeep(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('resolveFileConflict');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->service,
            '/src/cover.jpg',
            '/dst/cover.jpg',
            'non-audio',
            fn ($s, $t, $type) => 'keep'
        );

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resolveFileConflictReturnsTargetPathForReplace(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('resolveFileConflict');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->service,
            '/src/cover.jpg',
            '/dst/cover.jpg',
            'non-audio',
            fn ($s, $t, $type) => 'replace'
        );

        $this->assertSame('/dst/cover.jpg', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resolveFileConflictReturnsRenamedPathForRename(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('resolveFileConflict');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->service,
            '/src/cover.jpg',
            '/dst/cover.jpg',
            'non-audio',
            fn ($s, $t, $type) => 'rename:cover_new.jpg'
        );

        $this->assertSame('/dst/cover_new.jpg', $result);
    }
}
