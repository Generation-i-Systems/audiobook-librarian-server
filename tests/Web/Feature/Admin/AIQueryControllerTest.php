<?php

declare(strict_types=1);

namespace Tests\Web\Feature\Admin;

use App\Models\User;
use App\Services\AIQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Mockery;
use Tests\TestCase;

class AIQueryControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function processCastsFormStringContextIdsToIntegers(): void
    {
        $contextQueryId = 71;
        $contextLimit = 25;
        $newQueryId = 72;
        $prompt = 'Find books without narrators';

        $service = Mockery::mock(AIQueryService::class);
        $service->shouldReceive('processQuery')
            ->once()
            ->with($prompt, $this->user->id, $contextQueryId, $contextLimit)
            ->andReturn(['success' => true, 'query_id' => $newQueryId]);
        $service->shouldReceive('executeQuery')
            ->once()
            ->with($newQueryId)
            ->andReturn(['success' => true]);
        $this->app->bind(AIQueryService::class, fn () => $service);

        $response = $this->actingAs($this->user)->post(route('admin.ai-query.process'), [
            'prompt' => $prompt,
            'context_query_id' => (string) $contextQueryId,
            'context_limit' => (string) $contextLimit,
        ]);

        $response->assertRedirect(route('admin.ai-query.results', ['queryId' => $contextQueryId]));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function processPassesNullContextAndDefaultLimitWhenOmitted(): void
    {
        $newQueryId = 5;
        $prompt = 'List all fantasy books';

        $service = Mockery::mock(AIQueryService::class);
        $service->shouldReceive('processQuery')
            ->once()
            ->with($prompt, $this->user->id, null, 50)
            ->andReturn(['success' => true, 'query_id' => $newQueryId]);
        $service->shouldReceive('executeQuery')
            ->once()
            ->with($newQueryId)
            ->andReturn(['success' => true]);
        $this->app->bind(AIQueryService::class, fn () => $service);

        $response = $this->actingAs($this->user)->post(route('admin.ai-query.process'), [
            'prompt' => $prompt,
        ]);

        $response->assertRedirect(route('admin.ai-query.results', ['queryId' => $newQueryId]));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyBulkUpdateCastsFormStringQueryIdToInteger(): void
    {
        $queryId = 9;
        $selectedIds = ['3', '4'];

        $service = Mockery::mock(AIQueryService::class);
        $service->shouldReceive('applyBulkUpdate')
            ->once()
            ->with($queryId, $selectedIds)
            ->andReturn(['success' => true, 'applied_count' => count($selectedIds)]);
        $service->shouldReceive('executeQuery')
            ->once()
            ->with($queryId)
            ->andReturn(['success' => true]);
        $this->app->bind(AIQueryService::class, fn () => $service);

        $response = $this->actingAs($this->user)->post(route('admin.ai-query.apply-bulk-update'), [
            'query_id' => (string) $queryId,
            'selected_ids' => $selectedIds,
        ]);

        $response->assertRedirect(route('admin.ai-query.results', ['queryId' => $queryId]));
    }
}
