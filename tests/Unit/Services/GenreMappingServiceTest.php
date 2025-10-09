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

    /** @test */
    public function it_maps_science_fiction_and_fantasy_to_science_fiction()
    {
        $result = $this->service->mapToPrimaryGenre('Science Fiction & Fantasy:Fantasy:Dragons');
        $this->assertEquals('Science Fiction', $result);
    }

    /** @test */
    public function it_maps_fantasy_to_fantasy()
    {
        $result = $this->service->mapToPrimaryGenre('Fantasy:Epic:Dragons & Mythical Creatures');
        $this->assertEquals('Fantasy', $result);
    }

    /** @test */
    public function it_maps_litrpg_variants()
    {
        $this->assertEquals('LitRPG', $this->service->mapToPrimaryGenre('LitRPG:GameLit'));
        $this->assertEquals('LitRPG', $this->service->mapToPrimaryGenre('Lit RPG:Adventure'));
    }

    /** @test */
    public function it_maps_romance_variants()
    {
        $this->assertEquals('Romance', $this->service->mapToPrimaryGenre('Romance:Contemporary'));
        $this->assertEquals('Romance', $this->service->mapToPrimaryGenre('Romantic:Fiction'));
    }

    /** @test */
    public function it_maps_history_variants()
    {
        $this->assertEquals('History', $this->service->mapToPrimaryGenre('History:World War II'));
        $this->assertEquals('Historical Fiction', $this->service->mapToPrimaryGenre('Historical:Fiction'));
        $this->assertEquals('Historical Fiction', $this->service->mapToPrimaryGenre('Historical Fiction'));
    }

    /** @test */
    public function it_maps_non_fiction_variants()
    {
        $this->assertEquals('Non Fiction', $this->service->mapToPrimaryGenre('Non-Fiction:Biography'));
        $this->assertEquals('Non Fiction', $this->service->mapToPrimaryGenre('Nonfiction:Memoir'));
        $this->assertEquals('Non Fiction', $this->service->mapToPrimaryGenre('Biography:Historical'));
    }

    /** @test */
    public function it_maps_religious_content()
    {
        $this->assertEquals('Religion', $this->service->mapToPrimaryGenre('Religion:Christianity'));
        $this->assertEquals('Church', $this->service->mapToPrimaryGenre('Christian:Fiction'));
        $this->assertEquals('Religion', $this->service->mapToPrimaryGenre('Spirituality:Self-Help'));
    }

    /** @test */
    public function it_maps_kids_content()
    {
        $this->assertEquals('Kids', $this->service->mapToPrimaryGenre('Children:Fiction'));
        $this->assertEquals('Kids', $this->service->mapToPrimaryGenre('Kids:Adventure'));
        $this->assertEquals('Kids', $this->service->mapToPrimaryGenre('Young Adult:Fantasy'));
        $this->assertEquals('Kids', $this->service->mapToPrimaryGenre('Juvenile:Fiction'));
    }

    /** @test */
    public function it_maps_action_and_thriller()
    {
        $this->assertEquals('Action', $this->service->mapToPrimaryGenre('Action:Adventure'));
        $this->assertEquals('Action', $this->service->mapToPrimaryGenre('Thriller:Mystery'));
        $this->assertEquals('Action', $this->service->mapToPrimaryGenre('Adventure:Fiction'));
        $this->assertEquals('Action', $this->service->mapToPrimaryGenre('Suspense:Thriller'));
    }

    /** @test */
    public function it_maps_classics()
    {
        $this->assertEquals('Classic', $this->service->mapToPrimaryGenre('Classic:Literature'));
        $this->assertEquals('Classic', $this->service->mapToPrimaryGenre('Classics:Fiction'));
        $this->assertEquals('Classic', $this->service->mapToPrimaryGenre('Literature:Classic'));
    }

    /** @test */
    public function it_defaults_to_general_fiction()
    {
        $this->assertEquals('General Fiction', $this->service->mapToPrimaryGenre('Unknown Genre'));
        $this->assertEquals('General Fiction', $this->service->mapToPrimaryGenre('Fiction'));
        $this->assertEquals('General Fiction', $this->service->mapToPrimaryGenre('Contemporary:Fiction'));
    }

    /** @test */
    public function it_extracts_all_genres_from_hierarchy()
    {
        $genres = $this->service->extractAllGenres('Science Fiction & Fantasy:Fantasy:Dragons & Mythical Creatures');
        
        $this->assertCount(3, $genres);
        $this->assertEquals('Science Fiction & Fantasy', $genres[0]);
        $this->assertEquals('Fantasy', $genres[1]);
        $this->assertEquals('Dragons & Mythical Creatures', $genres[2]);
    }

    /** @test */
    public function it_handles_single_genre()
    {
        $genres = $this->service->extractAllGenres('Fantasy');
        
        $this->assertCount(1, $genres);
        $this->assertEquals('Fantasy', $genres[0]);
    }

    /** @test */
    public function it_handles_empty_genre()
    {
        $genres = $this->service->extractAllGenres('');
        $this->assertCount(0, $genres);
    }

    /** @test */
    public function it_trims_whitespace_from_genres()
    {
        $genres = $this->service->extractAllGenres(' Fantasy : Epic : Dragons ');
        
        $this->assertEquals('Fantasy', $genres[0]);
        $this->assertEquals('Epic', $genres[1]);
        $this->assertEquals('Dragons', $genres[2]);
    }

    /** @test */
    public function it_handles_case_insensitive_mapping()
    {
        $this->assertEquals('Science Fiction', $this->service->mapToPrimaryGenre('SCIENCE FICTION'));
        $this->assertEquals('Fantasy', $this->service->mapToPrimaryGenre('fantasy'));
        $this->assertEquals('LitRPG', $this->service->mapToPrimaryGenre('LitRPG'));
    }
}
