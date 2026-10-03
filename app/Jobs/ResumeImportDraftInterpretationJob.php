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
 * Continues interpretation of a draft that was waiting for an audio sample: after the client answered, and
 * again (delayed) when the request may have expired. A run before the request is due is a no-op.
 */
class ResumeImportDraftInterpretationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

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
        $interpretation->resume($this->draftId);
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
