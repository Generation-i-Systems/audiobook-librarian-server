<?php

declare(strict_types=1);

namespace Tests\Web\Feature\Admin;

use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookAutocompleteSeriesTest extends TestCase
{
    use RefreshDatabase;

    public function testQueryParameterReturnsSeriesNames(): void
    {
        $matching = ['Hunter Legends', 'The Last Hunter'];
        foreach ([...$matching, 'Mistrunner'] as $name) {
            Series::create(['name' => $name]);
        }

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('admin.books.autocomplete.series', ['query' => 'Hunt']));

        $response->assertOk();
        $names = $response->json('data');
        sort($names);
        $this->assertSame($matching, $names);
    }
}
