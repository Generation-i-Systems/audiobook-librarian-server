<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Str;

trait ProcessesBookData
{
    /**
     * Ensure all required fields are present in a book array.
     */
    protected function ensureBookFields(array $book): array
    {
        $defaults = [
            'id' => '',
            'title' => (string) ($book['title'] ?? 'Unknown Title'),
            'authors' => [],
            'genres' => [],
            'coverImage' => '/images/placeholder.png',
            'description' => 'No description available.',
            'createdAt' => date('Y-m-d H:i:s'),
            'duration' => '00:00:00',
            'narrators' => [],
            'series' => [],
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($book[$key]) || empty($book[$key])) {
                $book[$key] = $value;
            }
        }

        foreach (['authors', 'genres', 'narrators'] as $key) {
            if (isset($book[$key]) && is_array($book[$key])) {
                $book[$key] = array_map(function ($item) {
                    return is_array($item) && isset($item['name']) ? $item['name'] : (string) $item;
                }, $book[$key]);
            } else {
                $book[$key] = [];
            }
        }

        if (isset($book['series']) && is_array($book['series'])) {
            $book['series'] = collect($book['series'])->map(function ($seriesItem) {
                if (isset($seriesItem['seriesName']) && isset($seriesItem['number'])) {
                    return $seriesItem;
                }
                if (isset($seriesItem['name']) && isset($seriesItem['pivot']['series_number'])) {
                    return [
                        'seriesName' => $seriesItem['name'],
                        'number' => (int) $seriesItem['pivot']['series_number'],
                    ];
                }
                if (is_string($seriesItem)) {
                    return [
                        'seriesName' => $seriesItem,
                        'number' => 1,
                    ];
                }
                return null;
            })->filter()->values()->all();
        } else {
            $book['series'] = [];
        }

        $book['coverImage'] = $this->processCoverImage($book['coverImage'] ?? null, $book['directoryPath'] ?? null);

        return $book;
    }

    /**
     * Process cover image URL efficiently with directory path handling.
     */
    protected function processCoverImage(?string $coverImage, ?string $directoryPath = null): string
    {
        if (empty($coverImage)) {
            return asset('images/placeholder.png');
        }

        if (Str::startsWith($coverImage, ['http://', 'https://'])) {
            return $coverImage;
        }

        if (Str::startsWith($coverImage, '/')) {
            return url($coverImage);
        }

        $coverFilename = basename($coverImage);
        $finalCoverPath = $coverFilename;
        if (!empty($directoryPath)) {
            $directoryPath = trim((string) $directoryPath, '/');
            if ($directoryPath !== '') {
                if (Str::contains($coverImage, $directoryPath . '/')) {
                    $finalCoverPath = trim($coverImage, '/');
                } else {
                    $finalCoverPath = $directoryPath . '/' . $coverFilename;
                }
            }
        }

        $encodedPath = str_replace(['%2F'], ['/'], rawurlencode($finalCoverPath));
        return url('/cover/' . $encodedPath);
    }
}
