<?php

declare(strict_types=1);

namespace Tests\Web\Feature\Admin;

use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class BookEditCoverUrlFormTest extends TestCase
{
    public function testCoverImageUrlFieldIsSubmittedWithoutJavaScript(): void
    {
        $html = view('admin.books.edit', [
            'book' => [
                'id' => '14469',
                'title' => 'Brando',
                'directoryPath' => 'Romance/Iris T. Cannon/The Gatti Brothers/02 Brando',
                'author' => ['Iris T. Cannon'],
                'genre' => ['Romance'],
            ],
            'genreList' => ['Romance'],
            'genres' => ['Romance'],
            'coverCandidates' => [],
            'coverAuto' => null,
            'directoryPath' => 'Romance/Iris T. Cannon/The Gatti Brothers/02 Brando',
            'isModal' => false,
            'returnUrl' => null,
            'errors' => new ViewErrorBag(),
        ])->render();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="coverImageUrl"[^>]*name="coverImageUrl"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="coverImageUrlText"[^>]*name="coverImageUrlText"/s',
            $html
        );
    }
}
