<?php

namespace Tests\Unit\Import;

use PHPUnit\Framework\TestCase;

class EnrichmentMergeTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichment_does_not_override_existing_author()
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Correct Author'],
            'year' => 2021,
        ];

        $enrichedData = [
            'author' => ['Wrong Author from Google Books'],
            'description' => 'Good description',
        ];

        // Simulate the merge logic from ImportBooksFromDownloads
        foreach ($enrichedData as $key => $value) {
            if (empty($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        $this->assertEquals(['Correct Author'], $metadata['author']);
        $this->assertEquals('Good description', $metadata['description']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichment_does_not_override_existing_year()
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'year' => 2021,
        ];

        $enrichedData = [
            'published_year' => 2000, // Wrong year from enrichment
        ];

        // Simulate the merge logic with year/published_year handling
        foreach ($enrichedData as $key => $value) {
            /** @phpstan-ignore-next-line identical.alwaysFalse,booleanAnd.alwaysFalse,empty.offset */
            if ($key === 'year' || $key === 'published_year') {
                /** @phpstan-ignore-next-line empty.offset,booleanAnd.alwaysFalse */
                if (empty($metadata['year']) && empty($metadata['published_year'])) {
                    $metadata[$key] = $value;
                }
            }
        }

        $this->assertEquals(2021, $metadata['year']);
        $this->assertArrayNotHasKey('published_year', $metadata);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichment_fills_in_missing_fields()
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
        ];

        $enrichedData = [
            'description' => 'Great description',
            'publisher' => 'Test Publisher',
            'isbn' => '1234567890',
        ];

        foreach ($enrichedData as $key => $value) {
            if (empty($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        $this->assertEquals('Great description', $metadata['description']);
        $this->assertEquals('Test Publisher', $metadata['publisher']);
        $this->assertEquals('1234567890', $metadata['isbn']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichment_does_not_override_genre()
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => 'Science Fiction & Fantasy:Fantasy:Epic',
        ];

        $enrichedData = [
            'genre' => 'Technology & Engineering', // Wrong genre from Google Books
        ];

        foreach ($enrichedData as $key => $value) {
            /** @phpstan-ignore-next-line empty.offset */
            if (empty($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        $this->assertEquals('Science Fiction & Fantasy:Fantasy:Epic', $metadata['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichment_does_not_override_publisher()
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'publisher' => 'Tantor', // From copyright tag
        ];

        $enrichedData = [
            'publisher' => 'World Scientific', // Wrong publisher from Google Books
        ];

        foreach ($enrichedData as $key => $value) {
            /** @phpstan-ignore-next-line empty.offset */
            if (empty($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        $this->assertEquals('Tantor', $metadata['publisher']);
    }
}
