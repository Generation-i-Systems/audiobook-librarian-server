<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Parse id-based tokens (authorId:N, genreId:N, seriesId:N) out of a raw search string.
     *
     * Returns an array with keys:
     *   - 'author_id', 'genre_id', 'series_id' (int|null)
     *   - 'search' (remaining free-text, trimmed)
     *
     * Example: "genreId:12 Mistborn" → ['genre_id' => 12, 'search' => 'Mistborn', ...]
     * Compound: "authorId:5 genreId:12 Mistborn" → all three keys set
     */
    protected function parseSearchTokens(?string $raw): array
    {
        $result = ['author_id' => null, 'genre_id' => null, 'series_id' => null, 'search' => ''];

        $tokenMap = ['authorId' => 'author_id', 'genreId' => 'genre_id', 'seriesId' => 'series_id'];
        $raw = $raw ?? '';

        $remaining = preg_replace_callback(
            '/\b(authorId|genreId|seriesId):(\d+)\b/',
            function (array $m) use (&$result, $tokenMap): string {
                $result[$tokenMap[$m[1]]] = (int) $m[2];
                return '';
            },
            $raw
        );

        $result['search'] = trim((string) $remaining);

        return $result;
    }
}
