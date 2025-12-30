<?php

namespace Tests\Unit\Services;

use App\Services\AIBookProcessor;
use Tests\TestCase;

class AIBookProcessorNormalizeStringOrArrayTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeStringOrArrayTrimsStringsSplitsCommaSeparatedAndFiltersNonScalars(): void
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite');

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('normalizeStringOrArray');
        $method->setAccessible(true);

        $this->assertSame(['A', 'B'], $method->invoke($processor, ' A, B '));
        $this->assertSame(['Single'], $method->invoke($processor, 'Single'));

        $result = $method->invoke($processor, ['  Foo  ', 0, null, '', ['nested'], 12.5]);
        $this->assertSame(['Foo', '0', '12.5'], $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeMetadataAcceptsPluralKeysForAuthorsNarratorsAndGenres(): void
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite');

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('normalizeMetadata');
        $method->setAccessible(true);

        $normalized = $method->invoke($processor, [
            'title' => 'Test Book',
            'authors' => ['Author One'],
            'narrators' => ['Narrator One', 'Narrator Two'],
            'genres' => 'Fantasy, Action',
            'confidence' => 90,
        ]);

        $this->assertSame(['Author One'], $normalized['author']);
        $this->assertSame(['Narrator One', 'Narrator Two'], $normalized['narrator']);
        $this->assertSame(['Fantasy', 'Action'], $normalized['genre']);
        $this->assertSame(90, $normalized['confidence']);
    }
}
