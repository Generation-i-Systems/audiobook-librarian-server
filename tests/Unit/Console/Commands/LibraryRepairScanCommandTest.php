<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Services\LibraryRepairService;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LibraryRepairScanCommandTest extends TestCase
{
    /** @var \Mockery\MockInterface */
    private MockInterface $repairService;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var \Mockery\MockInterface $repairService */
        $repairService = Mockery::mock(LibraryRepairService::class);
        $this->repairService = $repairService;
        $this->app->instance(LibraryRepairService::class, $this->repairService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_outputs_summary_table_by_default(): void
    {
        $summary = [
            'missing_directory' => ['created' => 2, 'resolved' => 1, 'autoResolved' => 0],
            'nested_audio' => ['created' => 0, 'resolved' => 0, 'autoResolved' => 3],
        ];

        $this->repairService->shouldReceive('scan')
            ->once()
            ->with(true, [])
            ->andReturn($summary)
        ;

        $this->artisan('library:repair-scan')
            ->expectsOutput('Library Repair Summary')
            ->expectsTable(
                ['Issue', 'Created', 'Resolved', 'Auto-resolved'],
                [
                    ['Issue' => 'missing_directory', 'Created' => 2, 'Resolved' => 1, 'Auto-resolved' => 0],
                    ['Issue' => 'nested_audio', 'Created' => 0, 'Resolved' => 0, 'Auto-resolved' => 3],
                ],
            )
            ->expectsOutput('Attempted fixes: enabled')
            ->assertExitCode(0)
        ;
    }

    #[Test]
    public function it_supports_json_output_and_issue_filters(): void
    {
        $summary = [
            'missing_directory' => ['created' => 1, 'resolved' => 0, 'autoResolved' => 0],
        ];

        $this->repairService->shouldReceive('scan')
            ->once()
            ->with(false, ['missing_directory'])
            ->andReturn($summary)
        ;

        $payload = json_encode([
            'attemptFixes' => false,
            'issues' => $summary,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->artisan('library:repair-scan', [
            '--json' => true,
            '--no-attempt-fixes' => true,
            '--issue' => ['missing_directory'],
        ])
            ->expectsOutput($payload)
            ->assertExitCode(0)
        ;
    }

    #[Test]
    public function it_notifies_when_no_issue_types_are_selected(): void
    {
        $this->repairService->shouldReceive('scan')
            ->once()
            ->with(true, [])
            ->andReturn([])
        ;

        $this->artisan('library:repair-scan')
            ->doesntExpectOutput('Library Repair Summary')
            ->expectsOutput('No issue types were selected. Nothing to scan.')
            ->assertExitCode(0)
        ;
    }

    #[Test]
    public function it_reports_failures(): void
    {
        $this->repairService->shouldReceive('scan')
            ->once()
            ->with(true, [])
            ->andThrow(new \RuntimeException('Filesystem unavailable'))
        ;

        $this->artisan('library:repair-scan')
            ->expectsOutput('Library repair scan failed: Filesystem unavailable')
            ->assertExitCode(1);
    }
}
