<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class JobController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    /**
     * Display a listing of the jobs.
     */
    public function index(Request $request): View
    {
        $perPage = 20;
        $page = $request->input('page', 1);
        $type = $request->input('type');
        $status = $request->input('status');

        // Get jobs from document store
        $jobs = $this->documentStoreService->listJobs($type, $status, 1000);

        // Convert to collection for pagination
        $jobsCollection = collect($jobs);

        // Create a paginator
        $jobs = new LengthAwarePaginator(
            $jobsCollection->forPage($page, $perPage),
            $jobsCollection->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.jobs.index', compact('jobs'));
    }


    /**
     * Display the specified job.
     *
     * @param  string  $id
     * @return \Inertia\Response
     */


    /**
     * Display the specified job.
     *
     * @param  string  $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $job = $this->documentStoreService->getJob($id);

        if (!$job) {
            abort(404);
        }

        // Ensure the ID is included in the job data
        $job['id'] = $id;

        return view('admin.jobs.show', compact('job'));
    }


    /**
     * Retry a failed or queued job.
     *
     * @param  string  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function retry($id)
    {
        $job = $this->documentStoreService->getJob($id);

        if (!$job) {
            abort(404);
        }

        // Update job status to queued
        $this->documentStoreService->updateJob(
            $id,
            [
                'status' => 'queued',
                'message' => 'Job requeued for retry',
                'log' => array_merge($job['log'] ?? [], [
                    [
                        'timestamp' => now()->toDateTimeString(),
                        'level' => 'info',
                        'message' => 'Job requeued for retry by ' . (Auth::check() ? Auth::user()->name : 'System'),
                    ],
                ]),
            ]
        );

        // TODO: Dispatch the job based on its type

        return redirect()
            ->route('admin.jobs.show', $id)
            ->with('status', 'Job has been requeued for processing.');
    }


    /**
     * Cancel a queued or processing job.
     *
     * @param  string  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancel($id)
    {
        $job = $this->documentStoreService->getJob($id);

        if (!$job) {
            abort(404);
        }

        // Only allow cancelling queued or processing jobs
        if (!in_array($job['status'] ?? null, ['queued', 'processing'])) {
            return redirect()
                ->route('admin.jobs.show', $id)
                ->with('error', 'Only queued or processing jobs can be cancelled.');
        }

        // Update job status to cancelled
        $this->documentStoreService->updateJob(
            $id,
            [
                'status' => 'cancelled',
                'message' => 'Job was cancelled by ' . (Auth::check() ? Auth::user()->name : 'System'),
                'log' => array_merge($job['log'] ?? [], [
                    [
                        'timestamp' => now()->toDateTimeString(),
                        'level' => 'warning',
                        'message' => 'Job was cancelled by ' . (Auth::check() ? Auth::user()->name : 'System'),
                    ],
                ]),
            ]
        );

        // TODO: Cancel the actual job if it's still running

        return redirect()
            ->route('admin.jobs.show', $id)
            ->with('status', 'Job has been cancelled.');
    }


    /**
     * Get job status via AJAX.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */


    /**
     * Get the status of a specific job.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function status($id)
    {
        $job = $this->documentStoreService->getJob($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $job,
        ]);
    }


    /**
     * Get job logs.
     *
     * @return \Illuminate\Http\JsonResponse
     */


    /**
     * Get job logs with filtering.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logs(Request $request)
    {
        $request->validate([
            'type' => 'nullable|string',
            'status' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $type = $request->input('type');
        $status = $request->input('status');
        $limit = $request->input('limit', 50);

        $jobs = $this->documentStoreService->listJobs($type, $status, $limit);

        return response()->json([
            'success' => true,
            'data' => $jobs,
        ]);
    }


    /**
     * Clean up old completed/failed jobs.
     *
     * @param  int  $daysOld
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cleanup($daysOld = 30)
    {
        $deleted = $this->documentStoreService->cleanupOldJobs($daysOld);

        return redirect()
            ->route('admin.jobs.index')
            ->with('status', "Successfully cleaned up $deleted old jobs.");
    }
}
