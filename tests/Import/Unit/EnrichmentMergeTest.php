<?php

namespace Tests\Import\Unit;

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
        /** @var array<string, mixed> $metadata */
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'year' => 2021,
        ];

        /** @var array<string, mixed> $enrichedData */
        $enrichedData = [
            'published_year' => 2000, // Wrong year from enrichment
            'description' => 'Good description',
        ];

        // Simulate the merge logic with year/published_year handling
        foreach ($enrichedData as $key => $value) {
            if ($key === 'year' || $key === 'published_year') {
                if (empty($metadata['year']) && empty($metadata['published_year'])) {
                    $metadata[$key] = $value;
                }
            } elseif (empty($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        $this->assertEquals(2021, $metadata['year']);
        $this->assertArrayNotHasKey('published_year', $metadata);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichment_fills_in_missing_fields()
    {
        /** @var array<string, mixed> $metadata */
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
        /** @var array<string, mixed> $metadata */
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => 'Science Fiction & Fantasy:Fantasy:Epic',
        ];

        $enrichedData = [
            'genre' => 'Technology & Engineering', // Wrong genre from Google Books
        ];

        foreach ($enrichedData as $key => $value) {
            if (empty($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        $this->assertEquals('Science Fiction & Fantasy:Fantasy:Epic', $metadata['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichment_does_not_override_publisher()
    {
        /** @var array<string, mixed> $metadata */
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'publisher' => 'Tantor', // From copyright tag
        ];

        $enrichedData = [
            'publisher' => 'World Scientific', // Wrong publisher from Google Books
        ];

        foreach ($enrichedData as $key => $value) {
            if (empty($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        $this->assertEquals('Tantor', $metadata['publisher']);
    }
}
