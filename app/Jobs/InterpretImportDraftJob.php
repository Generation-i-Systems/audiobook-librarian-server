<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Middleware\ResolveLibraryProfileFromHost;
use App\Services\Imports\ImportInterpretationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Interprets a newly created imports.v1 draft into a reviewable recommendation.
 * Runs once; failures are recorded on the draft instead of retried.
 */
class InterpretImportDraftJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * Library profile (database + book root) active when the draft was created, so
     * a worker interprets against the same library the request used.
     */
    public readonly ?string $libraryProfile;

    public function __construct(public readonly int $draftId)
    {
        $profile = config('library_profiles.active_profile');
        $this->libraryProfile = is_string($profile) && $profile !== '' ? $profile : null;

        $queue = config('import_drafts.interpretation_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(ImportInterpretationService $interpretation): void
    {
        $this->activateLibraryProfile();
        $interpretation->run($this->draftId);
    }

    public function failed(?Throwable $exception): void
    {
        $this->activateLibraryProfile();
        app(ImportInterpretationService::class)->markFailed($this->draftId);
    }

    private function activateLibraryProfile(): void
    {
        if ($this->libraryProfile !== null && config('library_profiles.active_profile') !== $this->libraryProfile) {
            app(ResolveLibraryProfileFromHost::class)->activateProfile($this->libraryProfile);
        }
    }
}
