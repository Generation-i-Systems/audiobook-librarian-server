<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImportQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessQueuedImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    public $maxExceptions = 1;

    public $timeout = 600;

    protected string $importId;

    public function __construct(string $importId)
    {
        $this->importId = $importId;
    }

    public function handle(ImportQueueService $queueService): void
    {
        Log::info('[ImportQueue] Processing queued import', [
            'import_id' => $this->importId,
        ]);

        try {
            $result = $queueService->processImport($this->importId, false);

            if ($result['success']) {
                Log::info('[ImportQueue] Import succeeded', [
                    'import_id' => $this->importId,
                    'book_id' => $result['book_id'] ?? null,
                    'title' => $result['title'] ?? 'Unknown',
                ]);
            } else {
                Log::error('[ImportQueue] Import failed', [
                    'import_id' => $this->importId,
                    'error' => $result['error'] ?? 'Unknown error',
                    'validation_errors' => $result['validation_errors'] ?? [],
                ]);

                if (!empty($result['validation_errors'])) {
                    $this->fail(new \RuntimeException(
                        'Validation failed: ' . implode(', ', $result['validation_errors'])
                    ));
                }
            }
        } catch (\Exception $e) {
            Log::error('[ImportQueue] Job exception', [
                'import_id' => $this->importId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function tags(): array
    {
        return ['import', 'import-queue', 'import:' . $this->importId];
    }

    public function uniqueId(): string
    {
        return $this->importId;
    }
}
