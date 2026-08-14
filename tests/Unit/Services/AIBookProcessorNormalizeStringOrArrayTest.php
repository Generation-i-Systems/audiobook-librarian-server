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
    public function normalizeStringOrArraySplitsMultipleNamesOnSlashAmpersandAndAndOnlyWhenRequested(): void
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite');

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('normalizeStringOrArray');
        $method->setAccessible(true);

        $this->assertSame(
            ['Dorje Swallow', 'Sofia Lette'],
            $method->invoke($processor, 'Dorje Swallow/Sofia Lette', true)
        );
        $this->assertSame(
            ['Jane Doe', 'John Smith'],
            $method->invoke($processor, 'Jane Doe & John Smith', true)
        );
        $this->assertSame(
            ['Jane Doe', 'John Smith'],
            $method->invoke($processor, 'Jane Doe and John Smith', true)
        );
        $this->assertSame(
            ['Sandra Andrews'],
            $method->invoke($processor, 'Sandra Andrews', true),
            '"and" must only split on a whole word, not inside names like "Sandra"/"Andrews"'
        );

        // Without splitMultipleNames, '&' and '/' stay intact — e.g. a genre like
        // "Science Fiction & Fantasy" must not be split into two genres.
        $this->assertSame(
            ['Science Fiction & Fantasy'],
            $method->invoke($processor, 'Science Fiction & Fantasy')
        );
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeMetadataExtractsTagsArray(): void
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite');

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('normalizeMetadata');
        $method->setAccessible(true);

        $normalized = $method->invoke($processor, [
            'title' => 'Test Book',
            'tags' => ['spicy', 'litrpg'],
            'confidence' => 90,
        ]);

        $this->assertSame(['spicy', 'litrpg'], $normalized['tags']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeMetadataSplitsMultiAuthorAndNarratorStringsOnSlashAndAmpersand(): void
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite');

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('normalizeMetadata');
        $method->setAccessible(true);

        $normalized = $method->invoke($processor, [
            'title' => 'Test Book',
            'author' => 'Dorje Swallow/Sofia Lette',
            'narrator' => 'Dorje Swallow/Sofia Lette',
            'confidence' => 90,
        ]);

        $this->assertSame(['Dorje Swallow', 'Sofia Lette'], $normalized['author']);
        $this->assertSame(['Dorje Swallow', 'Sofia Lette'], $normalized['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeMetadataDefaultsTagsToEmptyArrayWhenAbsent(): void
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite');

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('normalizeMetadata');
        $method->setAccessible(true);

        $normalized = $method->invoke($processor, [
            'title' => 'Test Book',
            'confidence' => 90,
        ]);

        $this->assertSame([], $normalized['tags']);
    }
}
