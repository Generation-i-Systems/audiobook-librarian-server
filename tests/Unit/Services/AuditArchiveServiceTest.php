<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Audit;
use App\Services\AuditArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuditArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/audit-archive-' . uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function makeAudit(int $daysOld, string $name = 'Ünïcode "title"'): Audit
    {
        return Audit::create([
            'user_id' => 7,
            'auditable_type' => 'App\\Models\\Book',
            'auditable_id' => 42,
            'event' => 'updated',
            'old_values' => ['title' => $name],
            'new_values' => ['title' => $name . ' 2', 'path' => 'a/b'],
            'url' => 'https://example.test/x',
            'tags' => null,
            'created_at' => now()->subDays($daysOld),
            'updated_at' => now()->subDays($daysOld),
        ]);
    }

    public function testArchivesAndDeletesOnlyRowsOlderThanTheCutoffAcrossBatches(): void
    {
        $oldIds = [];
        foreach ([40, 45, 60, 90, 31] as $days) {
            $oldIds[] = $this->makeAudit($days)->id;
        }
        $recentIds = [$this->makeAudit(29)->id, $this->makeAudit(1)->id];

        $summary = (new AuditArchiveService($this->dir, 2))->archive(now()->subDays(30));

        $this->assertSame(['batches' => 3, 'rows' => 5], ['batches' => $summary['batches'], 'rows' => $summary['rows']]);
        $this->assertSame($recentIds, Audit::query()->orderBy('id')->pluck('id')->all());
        $archived = [];
        foreach ($summary['files'] as $file) {
            $this->assertFileExists($file . '.sha256');
            foreach (explode("\n", trim((string) gzdecode((string) file_get_contents($file)))) as $line) {
                $archived[] = json_decode($line, true)['id'];
            }
        }
        $this->assertSame($oldIds, $archived);
    }

    public function testRestoreReproducesTheOriginalRowsAndSkipsExistingIds(): void
    {
        $original = $this->makeAudit(50);
        $expected = Audit::query()->toBase()->where('id', $original->id)->first();
        $summary = (new AuditArchiveService($this->dir))->archive(now()->subDays(30));
        $this->assertSame(0, Audit::count());

        $service = new AuditArchiveService($this->dir);
        $this->assertSame(1, $service->restore($summary['files'][0]));
        $this->assertEquals($expected, Audit::query()->toBase()->where('id', $original->id)->first());
        $this->assertSame(0, $service->restore($summary['files'][0]));
        $this->assertSame(1, Audit::count());
    }

    public function testNothingIsDeletedWhenTheArchiveCannotBeWritten(): void
    {
        $this->makeAudit(50);
        File::ensureDirectoryExists($this->dir);
        // A directory squatting on the target name makes the final publish step fail.
        $first = Audit::query()->first();
        mkdir($this->dir . '/audits_' . $first->id . '_' . $first->id . '.ndjson.gz');

        try {
            (new AuditArchiveService($this->dir))->archive(now()->subDays(30));
            $this->fail('Expected archive to fail');
        } catch (\RuntimeException) {
            $this->assertSame(1, Audit::count());
            $this->assertSame([], glob($this->dir . '/*.part') ?: []);
        }
    }

    public function testNothingToArchiveCreatesNoFiles(): void
    {
        $this->makeAudit(5);

        $summary = (new AuditArchiveService($this->dir))->archive(now()->subDays(30));

        $this->assertSame(['batches' => 0, 'rows' => 0, 'files' => []], $summary);
        $this->assertSame(1, Audit::count());
    }
}
