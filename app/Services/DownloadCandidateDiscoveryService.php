<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\DownloadCandidate;
use App\Models\PendingDownloadBook;
use App\Models\Series;
use Illuminate\Support\Collection;

class DownloadCandidateDiscoveryService
{
    private const SIMILARITY_THRESHOLD = 0.85;

    public function __construct(
        protected AudiobookBayApiService $apiService
    ) {
    }

    /**
     * @return Collection<int, DownloadCandidate>
     */
    public function discoverForAuthor(Author $author): Collection
    {
        $results = $this->apiService->getAudiobooksByAuthor($author->name);

        return $this->storeCandidates($results, ['author_id' => $author->id]);
    }

    /**
     * @return Collection<int, DownloadCandidate>
     */
    public function discoverForSeries(Series $series): Collection
    {
        $results = $this->apiService->searchAudiobooks($series->name) ?? [];

        return $this->storeCandidates($results, ['series_id' => $series->id]);
    }

    /**
     * @return array{authors: int, series: int, candidates_created: int}
     */
    public function discoverAll(): array
    {
        $candidatesCreated = 0;
        $authors = Author::whereHas('favoritedByUsers')->get();
        foreach ($authors as $author) {
            $candidatesCreated += $this->discoverForAuthor($author)->count();
        }

        $seriesList = Series::whereHas('favoritedByUsers')->get();
        foreach ($seriesList as $series) {
            $candidatesCreated += $this->discoverForSeries($series)->count();
        }

        return [
            'authors' => $authors->count(),
            'series' => $seriesList->count(),
            'candidates_created' => $candidatesCreated,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return Collection<int, DownloadCandidate>
     */
    protected function storeCandidates(array $results, array $ownerAttributes): Collection
    {
        $created = collect();

        foreach ($results as $result) {
            $title = trim((string) ($result['title'] ?? ''));
            $url = (string) ($result['url'] ?? '');
            if ($title === '' || $url === '') {
                continue;
            }

            $abbId = basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));
            if ($abbId === '' || DownloadCandidate::where('abb_id', $abbId)->exists()) {
                continue;
            }

            if ($this->isAlreadyOwned($title)) {
                continue;
            }

            $details = $this->apiService->getAudiobookDetails($url) ?? [];

            $candidate = DownloadCandidate::create(array_merge($ownerAttributes, [
                'title' => $title,
                'abb_url' => $url,
                'magnet_uri' => $details['magnetUri'] ?? null,
                'cover_url' => $details['coverImageUrl'] ?? ($result['coverImageUrl'] ?? null),
                'description' => $details['description'] ?? ($result['description'] ?? null),
                'genre' => $details['metadata']['categories'][0] ?? null,
                'abb_id' => $abbId,
                'status' => 'pending',
                'discovered_at' => now(),
            ]));

            $created->push($candidate);
        }

        return $created;
    }

    protected function isAlreadyOwned(string $title): bool
    {
        $existingTitles = Book::pluck('title');
        foreach ($existingTitles as $existingTitle) {
            if (AudiobookBayApiService::calculateSimilarity($title, $existingTitle) >= self::SIMILARITY_THRESHOLD) {
                return true;
            }
        }

        $pendingTitles = PendingDownloadBook::pluck('title');
        foreach ($pendingTitles as $pendingTitle) {
            if (AudiobookBayApiService::calculateSimilarity($title, $pendingTitle) >= self::SIMILARITY_THRESHOLD) {
                return true;
            }
        }

        return false;
    }
}
