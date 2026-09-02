<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\DownloadCandidate;
use App\Services\AudiobookBayApiService;
use App\Services\DownloadCandidateDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DownloadCandidateDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function discoverForAuthorCreatesACandidateForANewResult(): void
    {
        $author = Author::factory()->create(['name' => 'Jane Author']);

        $apiServiceMock = Mockery::mock(AudiobookBayApiService::class);
        $apiServiceMock->shouldReceive('getAudiobooksByAuthor')
            ->with('Jane Author')
            ->andReturn([
                [
                    'title' => 'A Brand New Book',
                    'url' => 'https://audiobookbay.test/abss/a-brand-new-book/',
                    'coverImageUrl' => 'https://audiobookbay.test/cover.jpg',
                    'description' => 'A summary.',
                ],
            ]);
        $apiServiceMock->shouldReceive('getAudiobookDetails')
            ->with('https://audiobookbay.test/abss/a-brand-new-book/')
            ->andReturn([
                'magnetUri' => 'magnet:?xt=urn:btih:ABCDEF',
                'coverImageUrl' => 'https://audiobookbay.test/cover.jpg',
                'description' => 'A summary.',
                'metadata' => ['categories' => ['Fantasy']],
            ]);

        $service = new DownloadCandidateDiscoveryService($apiServiceMock);
        $created = $service->discoverForAuthor($author);

        $this->assertCount(1, $created);
        $this->assertDatabaseHas('download_candidates', [
            'title' => 'A Brand New Book',
            'author_id' => $author->id,
            'magnet_uri' => 'magnet:?xt=urn:btih:ABCDEF',
            'abb_id' => 'a-brand-new-book',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function discoverForAuthorSkipsResultsMatchingAnOwnedBook(): void
    {
        $author = Author::factory()->create(['name' => 'Jane Author']);
        Book::factory()->create(['title' => 'Already Owned Book']);

        $apiServiceMock = Mockery::mock(AudiobookBayApiService::class);
        $apiServiceMock->shouldReceive('getAudiobooksByAuthor')
            ->andReturn([
                [
                    'title' => 'Already Owned Book',
                    'url' => 'https://audiobookbay.test/abss/already-owned-book/',
                ],
            ]);
        $apiServiceMock->shouldNotReceive('getAudiobookDetails');

        $service = new DownloadCandidateDiscoveryService($apiServiceMock);
        $created = $service->discoverForAuthor($author);

        $this->assertCount(0, $created);
        $this->assertDatabaseCount('download_candidates', 0);
    }

    #[Test]
    public function discoverForAuthorSkipsResultsAlreadyStagedByAbbId(): void
    {
        $author = Author::factory()->create(['name' => 'Jane Author']);
        DownloadCandidate::create([
            'author_id' => $author->id,
            'title' => 'Previously Rejected Book',
            'abb_url' => 'https://audiobookbay.test/abss/previously-rejected-book/',
            'abb_id' => 'previously-rejected-book',
            'status' => 'rejected',
            'discovered_at' => now(),
        ]);

        $apiServiceMock = Mockery::mock(AudiobookBayApiService::class);
        $apiServiceMock->shouldReceive('getAudiobooksByAuthor')
            ->andReturn([
                [
                    'title' => 'Previously Rejected Book',
                    'url' => 'https://audiobookbay.test/abss/previously-rejected-book/',
                ],
            ]);
        $apiServiceMock->shouldNotReceive('getAudiobookDetails');

        $service = new DownloadCandidateDiscoveryService($apiServiceMock);
        $created = $service->discoverForAuthor($author);

        $this->assertCount(0, $created);
        $this->assertDatabaseCount('download_candidates', 1);
    }
}
