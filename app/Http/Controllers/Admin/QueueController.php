<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateImportJobsForDirectory;
use App\Contracts\DocumentStoreServiceInterface;
use App\Traits\BookImportTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class QueueController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }
    use BookImportTrait;

    public function index()
    {
        return view('admin.queue.index');
    }

    public function list(Request $request)
    {
        $typeFilter = $request->query('type');
        $jobsDocs = $this->documentStoreService->getClient()->collection('jobs')->documents();

        $jobTypeCounts = [];
        $jobTypes = [];
        $jobs = collect();

        foreach ($jobsDocs as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $job = $doc->data();
            $job['id'] = $doc->id(); // Ensure ID is present

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
        $this->documentStoreService->getClient()->collection('jobs')->document($id)->delete();

        return response()->json(['success' => true]);
    }

    public function retry($id)
    {
        $this->documentStoreService->getClient()->collection('jobs')->document($id)->delete();

        return response()->json(['success' => true]);
    }

    public function status()
    {
        // Check for running worker (simple: look for process, or use a cache heartbeat)
        $running = Cache::get('queueWorkerHeartbeat') ? true : false;
        $pending = $this->documentStoreService->getClient()->collection('jobs')->count();

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

    public function clear()
    {
        $docs = $this->documentStoreService->getClient()->collection('jobs')->documents();
        foreach ($docs as $doc) {
            if ($doc->exists()) {
                $doc->reference()->delete();
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Bulk import all book directories under a given path, using Firestore jobs collection for deduplication.
     */
    public function bulkImportBooks(Request $request)
    {
        $root = $request->input('dir');
        $storagePath = env('BOOK_STORAGE_PATH');
        $absRoot = rtrim($storagePath, '/') . '/' . ltrim($root, '/');
        if (!is_dir($absRoot)) {
            return response()->json([
                'error' => 'Invalid Google Books API response.',
            ], 422);
        }
        // Use BookImportTrait's findBookDirectories
        $bookDirs = $this->findBookDirectories($absRoot);
        $queued = [];
        $jobsCollection = $this->documentStoreService->getClient()->collection('jobs');
        $jobsDocs = $jobsCollection->documents();
        $pendingJobs = collect($jobsDocs)->map(function ($doc) {
            return $doc->exists() ? $doc->data() : null;
        })->filter();
        foreach ($bookDirs as $dir) {
            $relDir = ltrim(str_replace($storagePath, '', $dir), '/');
            $alreadyQueued = false;
            // Check Firestore jobs collection for queued jobs with this directory
            foreach ($pendingJobs as $job) {
                $payload = json_decode($job['payload'] ?? '', true);
                if (
                    isset($payload['data']['command']) &&
                    preg_match('/directoryPath";s:\\d+:"([^"]+)"/', $payload['data']['command'], $matches) &&
                    $matches[1] === $relDir
                ) {
                    $alreadyQueued = true;
                    break;
                }
            }
            // Check for existing book record in Firestore
            $bookExists = false;
            $booksCollection = $this->documentStoreService->getClient()->collection('books');
            $bookDocs = $booksCollection->where('directoryPath', '=', $relDir)->documents();
            foreach ($bookDocs as $doc) {
                if ($doc->exists()) {
                    $bookExists = true;
                    break;
                }
            }
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

        $out = CreateImportJobsForDirectory::dispatch($dir);
        Log::info('Bulk import job dispatched: ' . print_r($out, true));

        return response()->json(
            [
                'message' => 'Queued job to scan and import all book directories.',
            ],
            200,
        );
    }
}
