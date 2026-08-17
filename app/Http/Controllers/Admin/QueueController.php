<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Jobs\CreateImportJobsForDirectory;
use App\Jobs\ImportBookFromDirectoryJob;
use App\Traits\BookImportTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class QueueController extends Controller
{
    use BookImportTrait;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function index()
    {
        return view('admin.queue.index');
    }

    public function list(Request $request): JsonResponse
    {
        $typeFilter = $request->query('type');
        $allJobs = $this->documentStoreService->getJobs();

        $jobTypeCounts = [];
        $jobTypes = [];
        $jobs = collect();

        foreach ($allJobs as $job) {
            $jobType = $job['type'] ?? 'Unknown';
            $dir = $job['data']['directoryPath'] ?? null;

            $jobTypeCounts[$jobType] = ($jobTypeCounts[$jobType] ?? 0) + 1;
            if (!in_array($jobType, $jobTypes, true)) {
                $jobTypes[] = $jobType;
            }

            $jobs->push([
                'id' => $job['id'],
                'type' => $jobType,
                'directory' => $dir,
                'status' => $job['status'] ?? '',
                'attempts' => $job['data']['attempts'] ?? 0,
                'available_at' => $job['startedAt'] ?? '',
                'created_at' => $job['startedAt'] ?? '',
                'message' => $job['data']['message'] ?? '',
            ]);
        }

        if ($typeFilter) {
            $jobs = $jobs->where('type', $typeFilter)->values();
        }

        return response()->json([
            'jobs' => $jobs,
            'job_type_counts' => $jobTypeCounts,
            'job_types' => $jobTypes,
            'selected_type' => $typeFilter,
        ]);
    }

    public function remove(string|int $id): JsonResponse
    {
        $job = $this->documentStoreService->getJob((string) $id);
        if ($job) {
            $this->documentStoreService->updateJob((string) $id, ['status' => 'deleted']);
        }

        return response()->json(['success' => true]);
    }

    public function retry(string|int $id): JsonResponse
    {
        $job = $this->documentStoreService->getJob((string) $id);
        if ($job) {
            $this->documentStoreService->updateJob((string) $id, [
                'status' => 'pending',
                'data' => array_merge($job['data'] ?? [], ['attempts' => 0]),
            ]);
        }

        return response()->json(['success' => true]);
    }

    private function getHorizonStatus(): string
    {
        try {
            Artisan::call('horizon:status');
            $output = Artisan::output();
            if (str_contains($output, 'running')) {
                return 'running';
            }
            if (str_contains($output, 'paused')) {
                return 'paused';
            }
        } catch (Throwable $e) {
            Log::debug('Failed to get Horizon status', ['error' => $e->getMessage()]);
        }

        return 'inactive';
    }

    public function status(): JsonResponse
    {
        $horizonStatus = $this->getHorizonStatus();
        $workerRunning = $horizonStatus === 'running' || ($horizonStatus !== 'inactive' && (bool) Cache::get('queue_worker_heartbeat'));
        $pendingJobs = $this->documentStoreService->getJobCount();

        $embeddingsCount = 0;
        $defaultCount = 0;
        $recommendationsCount = 0;

        try {
            $embeddingsCount = (int) Redis::llen('queues:embeddings');
            $defaultCount = (int) Redis::llen('queues:default');
            $recommendationsCount = (int) Redis::llen('queues:recommendations');
        } catch (Throwable $e) {
            Log::debug('Redis queue inspection skipped', ['error' => $e->getMessage()]);
        }

        $failedJobs = 0;
        try {
            $failedJobs = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            Log::debug('Failed jobs table check skipped', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'status' => $horizonStatus,
            'workerRunning' => $workerRunning,
            'worker_running' => $workerRunning,
            'pendingJobs' => $pendingJobs,
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'queues' => [
                'embeddings' => $embeddingsCount,
                'default' => $defaultCount,
                'recommendations' => $recommendationsCount,
            ],
        ]);
    }

    public function startWorker(): JsonResponse
    {
        try {
            Artisan::call('horizon:continue');
        } catch (Throwable $e) {
            Log::debug('Horizon continue command failed', ['error' => $e->getMessage()]);
        }

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        exec("nohup {$php} {$artisan} horizon > /dev/null 2>&1 &");
        Cache::put('queue_worker_heartbeat', true, 60);

        usleep(300000);
        $status = $this->getHorizonStatus();

        return response()->json([
            'started' => true,
            'status' => $status,
            'message' => $status === 'running' ? 'Horizon worker started successfully.' : 'Horizon worker start initiated.',
        ]);
    }

    public function stopWorker(): JsonResponse
    {
        try {
            Artisan::call('horizon:pause');
            Artisan::call('horizon:terminate');
        } catch (Throwable $e) {
            Log::warning('Failed to pause/terminate Horizon worker', ['error' => $e->getMessage()]);
        }

        Cache::forget('queue_worker_heartbeat');

        return response()->json(['stopped' => true, 'message' => 'Horizon worker paused and terminated.']);
    }

    public function pauseQueue(Request $request): JsonResponse
    {
        $supervisor = $request->input('supervisor');

        try {
            if ($supervisor) {
                Artisan::call('horizon:pause-supervisor', ['name' => $supervisor]);
            } else {
                Artisan::call('horizon:pause');
            }
            return response()->json(['success' => true, 'message' => 'Queue paused successfully.']);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function resumeQueue(Request $request): JsonResponse
    {
        $supervisor = $request->input('supervisor');

        try {
            if ($supervisor) {
                Artisan::call('horizon:continue-supervisor', ['name' => $supervisor]);
            } else {
                Artisan::call('horizon:continue');
            }
            return response()->json(['success' => true, 'message' => 'Queue resumed successfully.']);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function retryFailed(Request $request, string|int|null $id = null): JsonResponse
    {
        try {
            if ($id) {
                Artisan::call('queue:retry', ['id' => [$id]]);
            } else {
                Artisan::call('queue:retry', ['id' => ['all']]);
            }
            return response()->json(['success' => true, 'message' => 'Failed jobs queued for retry.']);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function clear(Request $request): JsonResponse
    {
        if (!$request->boolean('confirm')) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation is required to clear jobs.',
            ], 422);
        }

        $queue = $request->input('queue');
        if ($queue) {
            try {
                Artisan::call('horizon:clear', ['--queue' => $queue]);
            } catch (Throwable $e) {
                try {
                    Redis::del('queues:' . $queue);
                } catch (Throwable $re) {
                    Log::warning('Failed to clear queue via Redis', ['queue' => $queue, 'error' => $re->getMessage()]);
                }
            }
        } else {
            $this->documentStoreService->clearJobs(true);
            try {
                Redis::del('queues:embeddings');
                Redis::del('queues:default');
                Redis::del('queues:recommendations');
            } catch (Throwable $e) {
                Log::warning('Failed to clear Redis queues', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Queue cleared successfully.']);
    }

    public function bulkImportBooks(Request $request): JsonResponse
    {
        $root = $request->input('dir');
        $storagePath = config('filesystems.disks.books.root') ?? config('app.book_root');
        $absRoot = rtrim((string) $storagePath, '/') . '/' . ltrim((string) $root, '/');
        if (!is_dir($absRoot)) {
            return response()->json([
                'error' => 'Invalid directory path.',
            ], 422);
        }

        $bookDirs = $this->findBookDirectories($absRoot);
        $queued = [];

        foreach ($bookDirs as $dir) {
            $relDir = ltrim(str_replace((string) $storagePath, '', $dir), '/');

            $alreadyQueued = $this->documentStoreService->jobExistsByDirectoryPath($relDir);
            $bookExists = $this->documentStoreService->bookExistsByDirectoryPath($relDir);

            if ($bookExists) {
                $alreadyQueued = true;
            }

            if (!$alreadyQueued) {
                ImportBookFromDirectoryJob::dispatch($relDir);
                $queued[] = $relDir;
            }
        }

        return response()->json(
            [
                'message' => 'Queued ' . count($queued) . ' book directories for import.',
                'skipped' => count($bookDirs) - count($queued),
                'queued_dirs' => $queued,
            ],
            200
        );
    }

    public function bulkImportBooksFromDir(Request $request): JsonResponse
    {
        $dir = $request->input('dir');

        Log::info("Bulk importing books from directory: $dir");

        $out = CreateImportJobsForDirectory::dispatch($dir, $this->documentStoreService);
        Log::info('Bulk import job dispatched: ' . json_encode($out));

        return response()->json(
            [
                'message' => 'Queued job to scan and import all book directories.',
            ],
            200
        );
    }
}
