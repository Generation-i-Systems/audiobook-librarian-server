<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\InterpretImportDraftJob;
use App\Services\Imports\ImportInterpretationService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InterpretImportDraftJobTest extends TestCase
{
    #[DataProvider('profileProvider')]
    public function testHandleReappliesLibraryProfileActiveAtDispatch(string $profile, string $bookRoot): void
    {
        config([
            'library_profiles.profiles.' . $profile . '.book_storage_path' => $bookRoot,
            'library_profiles.profiles.' . $profile . '.database_connection' => null,
            'library_profiles.active_profile' => $profile,
        ]);
        $job = new InterpretImportDraftJob(7);
        config(['library_profiles.active_profile' => null, 'app.book_root' => '/somewhere/else']);

        $service = Mockery::mock(ImportInterpretationService::class);
        $service->shouldReceive('run')->once()->with(7)->andReturnUsing(function () use ($bookRoot, $profile): void {
            $this->assertSame($bookRoot, config('app.book_root'));
            $this->assertSame($profile, config('library_profiles.active_profile'));
        });

        $job->handle($service);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function profileProvider(): array
    {
        return [
            'hybrid profile' => ['hybrid', '/tmp/import-job-profile-hybrid'],
            'librivox profile' => ['librivox', '/tmp/import-job-profile-librivox'],
        ];
    }

    public function testJobWithoutActiveProfileLeavesConfigurationAlone(): void
    {
        config(['library_profiles.active_profile' => null, 'app.book_root' => '/kept']);
        $job = new InterpretImportDraftJob(3);

        $service = Mockery::mock(ImportInterpretationService::class);
        $service->shouldReceive('run')->once()->with(3);
        $job->handle($service);

        $this->assertNull($job->libraryProfile);
        $this->assertSame('/kept', config('app.book_root'));
    }
}
