<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Jobs\CreateImportJobsForDirectory;
use App\Traits\BookImportTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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


    public function list(Request $request)
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
            if (!in_array($jobType, $jobTypes)) {
                $jobTypes[] = $jobType;
            }

            $jobs->push([
                'id' => $job['id'],
                'type' => $jobType,
                'directory' => $dir,
                'status' => $job['status'] ?? '',
                'attempts' => $job['data']['attempts'] ?? 0,
                'availableAt' => $job['startedAt'] ?? '',
                'createdAt' => $job['startedAt'] ?? '',
                'message' => $job['data']['message'] ?? '',
            ]);
        }

        if ($typeFilter) {
            $jobs = $jobs->where('type', $typeFilter)->values();
        }

        return response()->json([
            'jobs' => $jobs,
            'jobTypeCounts' => $jobTypeCounts,
            'jobTypes' => $jobTypes,
            'selectedType' => $typeFilter,
        ]);
    }


    public function remove($id)
    {
        $job = $this->documentStoreService->getJob($id);
        if ($job) {
            $this->documentStoreService->updateJob($id, ['status' => 'deleted']);
        }

        return response()->json(['success' => true]);
    }


    public function retry($id)
    {
        $job = $this->documentStoreService->getJob($id);
        if ($job) {
            // Reset job status to pending for retry
            $this->documentStoreService->updateJob($id, [
                'status' => 'pending',
                'data' => array_merge($job['data'] ?? [], ['attempts' => 0]),
            ]);
        }

        return response()->json(['success' => true]);
    }


    public function status()
    {
        // Check for running worker (simple: look for process, or use a cache heartbeat)
        $running = Cache::get('queue_worker_heartbeat') ? true : false;
        $pending = $this->documentStoreService->getJobCount();

        return response()->json(['workerRunning' => $running, 'pendingJobs' => $pending]);
    }


    public function startWorker()
    {
        // Start worker in background (simple, naive approach)
        $output = null;
        $result = null;
        exec('php artisan queue:work --daemon > /dev/null 2>&1 &', $output, $result);
        // Optionally set a cache heartbeat
        Cache::put('queue_worker_heartbeat', true, 60);

        return response()->json(['started' => true]);
    }


    public function clear(Request $request)
    {
        if (! $request->boolean('confirm')) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation is required to clear jobs.',
            ], 422);
        }

        $success = $this->documentStoreService->clearJobs(true);

        return response()->json(['success' => $success]);
    }


    /**
     * Bulk import all book directories under a given path, using jobs collection for deduplication.
     */
    public function bulkImportBooks(Request $request)
    {
        $root = $request->input('dir');
        $storagePath = config('filesystems.disks.books.root') ?? config('app.book_root');
        $absRoot = rtrim($storagePath, '/') . '/' . ltrim($root, '/');
        if (!is_dir($absRoot)) {
            return response()->json([
                'error' => 'Invalid directory path.',
            ], 422);
        }

        // Use BookImportTrait's findBookDirectories
        $bookDirs = $this->findBookDirectories($absRoot);
        $queued = [];

        // Get all jobs for checking duplicates
        $allJobs = $this->documentStoreService->getJobs();

        foreach ($bookDirs as $dir) {
            $relDir = ltrim(str_replace($storagePath, '', $dir), '/');

            // Check if job already exists for this directory
            $alreadyQueued = $this->documentStoreService->jobExistsByDirectoryPath($relDir);

            // Check if book already exists with this directory path
            $bookExists = $this->documentStoreService->bookExistsByDirectoryPath($relDir);

            if ($bookExists) {
                $alreadyQueued = true;
            }

            if (!$alreadyQueued) {
                \App\Jobs\ImportBookFromDirectoryJob::dispatch($relDir);
                $queued[] = $relDir;
            }
        }

        return response()->json(
            [
                'message' => 'Queued ' . count($queued) . ' book directories for import.',
                'skipped' => count($bookDirs) - count($queued),
                'queued_dirs' => $queued,
            ],
            200,
        );
    }


    /**
     * Bulk import all book directories from a specific directory (recursive, queued)
     */
    public function bulkImportBooksFromDir(Request $request)
    {
        $dir = $request->input('dir');
        // Dispatch a single job that will queue all the import jobs

        Log::info("Bulk importing books from directory: $dir");

        $out = CreateImportJobsForDirectory::dispatch($dir, $this->documentStoreService);
        Log::info('Bulk import job dispatched: ' . json_encode($out));

        return response()->json(
            [
                'message' => 'Queued job to scan and import all book directories.',
            ],
            200,
        );
    }
}
