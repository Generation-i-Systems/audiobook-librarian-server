<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookFilterRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function testSeriesFilterWithAmpersandIsNotHtmlEscapedInAjaxParameters(): void
    {
        $author = 'Linus Torvald';
        $series = 'Linus Torvalds & David Diamond';
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get(route('books.index', compact('author', 'series')));

        $response->assertOk();
        $response->assertDontSee('"series": \'Linus Torvalds &amp; David Diamond\'', false);
    }
}
