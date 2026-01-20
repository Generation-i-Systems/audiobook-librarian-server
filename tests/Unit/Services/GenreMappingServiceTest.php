<?php

namespace Tests\Unit\Services;

use App\Services\GenreMappingService;
use Tests\TestCase;

class GenreMappingServiceTest extends TestCase
{
    private GenreMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GenreMappingService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_science_fiction_and_fantasy_using_most_specific_part()
    {
        // Should find 'Fantasy' because it's in the remainder and high priority
        $this->assertEquals('Fantasy', $this->service->mapToPrimaryGenre('Science Fiction & Fantasy:Fantasy:Action & Adventure'));

        // Should find 'Science Fiction' because it's in the remainder
        $this->assertEquals('Science Fiction', $this->service->mapToPrimaryGenre('Science Fiction & Fantasy:Science Fiction:Adventure'));

        // Should default to 'Science Fiction' for the broad category if nothing else found
        $this->assertEquals('Science Fiction', $this->service->mapToPrimaryGenre('Science Fiction & Fantasy'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_fantasy_to_fantasy()
    {
        $result = $this->service->mapToPrimaryGenre('Fantasy:Epic:Dragons & Mythical Creatures');
        $this->assertEquals('Fantasy', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_litrpg_variants()
    {
        $this->assertEquals('LitRPG', $this->service->mapToPrimaryGenre('LitRPG'));
        $this->assertEquals('LitRPG', $this->service->mapToPrimaryGenre('Lit RPG'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_romance_variants()
    {
        $this->assertEquals('Romance', $this->service->mapToPrimaryGenre('Romance'));
        $this->assertEquals('Romance', $this->service->mapToPrimaryGenre('Romantic'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_history_variants()
    {
        $this->assertEquals('History', $this->service->mapToPrimaryGenre('History'));
        $this->assertEquals('Historical Fiction', $this->service->mapToPrimaryGenre('Historical Fiction'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_non_fiction_variants()
    {
        $this->assertEquals('Non Fiction', $this->service->mapToPrimaryGenre('Non-Fiction:Self-Help'));
        $this->assertEquals('Non Fiction', $this->service->mapToPrimaryGenre('Nonfiction:Memoir'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_biography_to_history()
    {
        $this->assertEquals('History', $this->service->mapToPrimaryGenre('Biography'));
        $this->assertEquals('History', $this->service->mapToPrimaryGenre('Biography & Autobiography'));
        $this->assertEquals('History', $this->service->mapToPrimaryGenre('Autobiography'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_religious_content()
    {
        $this->assertEquals('Religion', $this->service->mapToPrimaryGenre('Religion'));
        $this->assertEquals('Church', $this->service->mapToPrimaryGenre('Christian'));
        $this->assertEquals('Religion', $this->service->mapToPrimaryGenre('Spirituality'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_kids_content()
    {
        $this->assertEquals('Kids', $this->service->mapToPrimaryGenre('Children'));
        $this->assertEquals('Kids', $this->service->mapToPrimaryGenre('Kids'));
        $this->assertEquals('Kids', $this->service->mapToPrimaryGenre('Young Adult'));
        $this->assertEquals('Kids', $this->service->mapToPrimaryGenre('Juvenile'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_action_and_thriller()
    {
        $this->assertEquals('Action', $this->service->mapToPrimaryGenre('Action'));
        $this->assertEquals('Action', $this->service->mapToPrimaryGenre('Thriller'));
        $this->assertEquals('Action', $this->service->mapToPrimaryGenre('Adventure'));
        $this->assertEquals('Action', $this->service->mapToPrimaryGenre('Suspense'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_classics()
    {
        $this->assertEquals('Classic', $this->service->mapToPrimaryGenre('Classic'));
        $this->assertEquals('Classic', $this->service->mapToPrimaryGenre('Classics'));
        $this->assertEquals('Classic', $this->service->mapToPrimaryGenre('Literature'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_defaults_to_general_fiction()
    {
        $this->assertEquals('Other', $this->service->mapToPrimaryGenre('Unknown Genre'));
        // 'Fiction' now maps to 'General Fiction' which is a valid library genre
        $this->assertEquals('General Fiction', $this->service->mapToPrimaryGenre('Fiction'));
        $this->assertEquals('General Fiction', $this->service->mapToPrimaryGenre('Contemporary:Fiction'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_extracts_all_genres_from_hierarchy()
    {
        $genres = $this->service->extractAllGenres('Science Fiction & Fantasy:Fantasy:Dragons & Mythical Creatures');

        $this->assertCount(3, $genres);
        $this->assertEquals('Science Fiction & Fantasy', $genres[0]);
        $this->assertEquals('Fantasy', $genres[1]);
        $this->assertEquals('Dragons & Mythical Creatures', $genres[2]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_single_genre()
    {
        $genres = $this->service->extractAllGenres('Fantasy');

        $this->assertCount(1, $genres);
        $this->assertEquals('Fantasy', $genres[0]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_empty_genre()
    {
        $genres = $this->service->extractAllGenres('');
        $this->assertCount(0, $genres);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_trims_whitespace_from_genres()
    {
        $genres = $this->service->extractAllGenres(' Fantasy : Epic : Dragons ');

        $this->assertEquals('Fantasy', $genres[0]);
        $this->assertEquals('Epic', $genres[1]);
        $this->assertEquals('Dragons', $genres[2]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_case_insensitive_mapping()
    {
        $this->assertEquals('Science Fiction', $this->service->mapToPrimaryGenre('SCIENCE FICTION'));
        $this->assertEquals('Fantasy', $this->service->mapToPrimaryGenre('fantasy'));
        $this->assertEquals('LitRPG', $this->service->mapToPrimaryGenre('LitRPG'));
    }
}
