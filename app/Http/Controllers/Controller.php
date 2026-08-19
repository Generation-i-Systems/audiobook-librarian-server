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
     * Parse id-based tokens (authorId:N, genreId:N, seriesId:N, bookId:N) and name-based
     * tokens (author:Name, genre:Name, series:Name, tag:Name) out of a raw search string.
     *
     * Returns an array with keys:
     *   - 'author_id', 'genre_id', 'series_id', 'book_id' (int|null)
     *   - 'author_name', 'genre_name', 'series_name', 'tag' (string|null)
     *   - 'search' (remaining free-text, trimmed)
     *
     * Name-token values may be a quoted string (author:"Brandon Sanderson") to allow spaces,
     * or a bare non-whitespace word (genre:Fantasy).
     *
     * Example: "genreId:12 Mistborn" → ['genre_id' => 12, 'search' => 'Mistborn', ...]
     * Compound: "authorId:5 genreId:12 Mistborn" → both id keys set
     */
    protected function parseSearchTokens(?string $raw): array
    {
        $result = [
            'author_id' => null,
            'genre_id' => null,
            'series_id' => null,
            'book_id' => null,
            'author_name' => null,
            'genre_name' => null,
            'series_name' => null,
            'tag' => null,
            'search' => '',
        ];

        $idTokenMap = [
            'authorId' => 'author_id',
            'genreId' => 'genre_id',
            'seriesId' => 'series_id',
            'bookId' => 'book_id',
        ];
        $nameTokenMap = [
            'author' => 'author_name',
            'genre' => 'genre_name',
            'series' => 'series_name',
            'tag' => 'tag',
        ];
        $raw = $raw ?? '';

        $remaining = preg_replace_callback(
            '/\b(authorId|genreId|seriesId|bookId):(\d+)\b/',
            function (array $m) use (&$result, $idTokenMap): string {
                $result[$idTokenMap[$m[1]]] = (int) $m[2];
                return '';
            },
            $raw
        );

        $remaining = preg_replace_callback(
            '/\b(author|genre|series|tag):(?:"([^"]*)"|(\S+))/',
            function (array $m) use (&$result, $nameTokenMap): string {
                $value = trim($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
                if ($value !== '') {
                    $result[$nameTokenMap[$m[1]]] = $value;
                }
                return '';
            },
            (string) $remaining
        );

        $result['search'] = trim((string) preg_replace('/\s+/', ' ', (string) $remaining));

        return $result;
    }
}
