<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\DownloadCandidate;

class DownloadCandidateApiTest extends ApiTestCase
{
    public function testIndexListsPendingCandidates(): void
    {
        $author = Author::factory()->create();
        DownloadCandidate::create([
            'author_id' => $author->id,
            'title' => 'A New Release',
            'abb_url' => 'https://audiobookbay.example/post/a-new-release',
            'abb_id' => 'a-new-release',
            'status' => 'pending',
            'discovered_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/download-candidates');

        $response->assertOk()->assertJsonPath('data.data.0.title', 'A New Release');
    }

    public function testApproveCreatesPendingDownloadAndReturnsMagnet(): void
    {
        $candidate = DownloadCandidate::create([
            'title' => 'A New Release',
            'abb_url' => 'https://audiobookbay.example/post/a-new-release',
            'abb_id' => 'a-new-release',
            'magnet_uri' => 'magnet:?xt=urn:btih:ABCDEF1234567890ABCDEF1234567890ABCDEF12',
            'status' => 'pending',
            'discovered_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/download-candidates/{$candidate->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.magnet_uri', 'magnet:?xt=urn:btih:ABCDEF1234567890ABCDEF1234567890ABCDEF12');

        $this->assertDatabaseHas('download_candidates', [
            'id' => $candidate->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('pending_downloads', [
            'release_name' => 'A New Release',
        ]);
    }

    public function testRejectMarksCandidateRejected(): void
    {
        $candidate = DownloadCandidate::create([
            'title' => 'A New Release',
            'abb_url' => 'https://audiobookbay.example/post/a-new-release',
            'abb_id' => 'a-new-release',
            'status' => 'pending',
            'discovered_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/download-candidates/{$candidate->id}/reject");

        $response->assertOk();
        $this->assertDatabaseHas('download_candidates', [
            'id' => $candidate->id,
            'status' => 'rejected',
        ]);
    }

    public function testMarkSentRequiresApprovedStatus(): void
    {
        $candidate = DownloadCandidate::create([
            'title' => 'A New Release',
            'abb_url' => 'https://audiobookbay.example/post/a-new-release',
            'abb_id' => 'a-new-release',
            'status' => 'pending',
            'discovered_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/download-candidates/{$candidate->id}/mark-sent");

        $response->assertStatus(422);
    }
}
