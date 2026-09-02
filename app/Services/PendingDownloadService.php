<?php

namespace App\Services;

use App\Models\Book;
use App\Models\PendingDownload;
use App\Models\PendingDownloadBook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PendingDownloadService
{
    /**
     * Extract the BTIH infohash from a magnet URI, if present.
     */
    public function extractInfohash(?string $magnetUri): ?string
    {
        if (empty($magnetUri)) {
            return null;
        }

        if (preg_match('/xt=urn:btih:([A-Za-z0-9]+)/', $magnetUri, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Build a normalized, filesystem-agnostic hint used to fuzzy-match a
     * downloaded folder name back to the release that was registered.
     */
    public function normalizeNameHint(string $name): string
    {
        $normalized = Str::lower($name);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return trim($normalized);
    }

    /**
     * Create a pending download and its child book rows from a validated
     * request payload, keeping the create-parent-then-create-children
     * transaction out of the controller.
     *
     * @param array<string, mixed> $data Validated request data (magnet_uri, abb_url, abb_category, release_name, books).
     * @param array<string, mixed> $rawRequestData Full raw request payload, stored verbatim for debugging.
     */
    public function createFromRequest(array $data, array $rawRequestData, ?int $userId): PendingDownload
    {
        $infohash = $this->extractInfohash($data['magnet_uri']);
        $releaseName = $data['release_name'] ?? $data['books'][0]['title'];

        return DB::transaction(function () use ($data, $rawRequestData, $infohash, $releaseName, $userId) {
            $pending = PendingDownload::create([
                'magnet_infohash' => $infohash,
                'release_name' => $releaseName,
                'torrent_name_hint' => $this->normalizeNameHint($releaseName),
                'abb_url' => $data['abb_url'],
                'abb_category' => $data['abb_category'] ?? null,
                'magnet_uri' => $data['magnet_uri'],
                'book_count' => count($data['books']),
                'status' => 'pending',
                'created_by_user_id' => $userId,
                'expires_at' => now()->addDays(14),
                'metadata' => $rawRequestData,
            ]);

            foreach ($data['books'] as $index => $book) {
                $pending->books()->create([
                    'sort_order' => $index,
                    'title' => $book['title'],
                    'authors' => $book['authors'] ?? [],
                    'genre' => $book['genre'] ?? null,
                    'tags' => $book['tags'] ?? [],
                    'description' => $book['description'] ?? null,
                    'cover_url' => $book['cover_url'] ?? null,
                    'series_name' => $book['series_name'] ?? null,
                    'series_number' => $book['series_number'] ?? null,
                    'abb_url' => $book['abb_url'] ?? $data['abb_url'],
                    'narrator' => $book['narrator'] ?? null,
                ]);
            }

            return $pending;
        });
    }

    /**
     * Find a pending-download record matching a newly-scanned directory.
     */
    public function findMatch(string $directoryPath, array $metadata = []): ?PendingDownload
    {
        $baseName = basename(rtrim($directoryPath, '/'));
        $nameHint = $this->normalizeNameHint($baseName);

        if ($nameHint !== '') {
            $match = PendingDownload::where('status', 'pending')
                ->where(function ($query) use ($nameHint) {
                    $query->where('torrent_name_hint', $nameHint)
                        ->orWhere('release_name', $nameHint);
                })
                ->first();

            if ($match) {
                return $match;
            }
        }

        if (!empty($metadata['title'])) {
            $title = trim((string) $metadata['title']);
            $author = is_array($metadata['author'] ?? null)
                ? ($metadata['author'][0] ?? '')
                : ($metadata['author'] ?? '');
            $author = trim((string) $author);

            $match = PendingDownload::where('status', 'pending')
                ->whereHas('books', function ($query) use ($title, $author) {
                    $query->where('title', $title);
                    if ($author !== '') {
                        $query->whereJsonContains('authors', $author);
                    }
                })
                ->first();

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Overlay pending-download metadata onto scanned metadata, filling only
     * fields the scanner didn't already populate.
     */
    public function mergeIntoMetadata(PendingDownload $pending, array $metadata): array
    {
        $book = $pending->books->first();
        if (!$book) {
            return $metadata;
        }

        $fieldMap = [
            'title' => $book->title,
            'author' => $book->authors,
            'genre' => $book->genre,
            'description' => $book->description,
            'series' => $book->series_name,
            'series_number' => $book->series_number,
            'narrator' => $book->narrator,
        ];

        foreach ($fieldMap as $key => $value) {
            if (empty($metadata[$key]) && !empty($value)) {
                $metadata[$key] = $value;
            }
        }

        if (empty($metadata['cover_url']) && !empty($book->cover_url)) {
            $metadata['cover_url'] = $book->cover_url;
        }

        if (empty($metadata['file_tags']) && !empty($book->tags)) {
            $metadata['file_tags'] = $book->tags;
        }

        return $metadata;
    }

    /**
     * Mark a pending download consumed, recording which real Book each
     * pending child book was matched to.
     *
     * @param array<int, array{pending_download_book_id: int, book_id: int}> $matches
     */
    public function consumeForBooks(PendingDownload $pending, array $matches, ?string $matchedDirectory = null): void
    {
        foreach ($matches as $match) {
            PendingDownloadBook::where('id', $match['pending_download_book_id'])
                ->where('pending_download_id', $pending->id)
                ->update(['matched_book_id' => $match['book_id']]);
        }

        if ($matchedDirectory !== null) {
            $pending->matched_book_directory = $matchedDirectory;
        }

        $pending->markConsumed();
    }
}
