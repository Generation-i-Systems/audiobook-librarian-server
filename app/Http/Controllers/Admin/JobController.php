<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class JobController extends Controller
{
    protected FirestoreService $firestore;

    public function __construct(FirestoreService $firestore)
    {
        $this->firestore = $firestore;
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

        // Get jobs from Firestore
        $jobs = $this->firestore->listJobs($type, $status, 1000);

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
        $job = $this->firestore->getJobStatus($id);

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
        $job = $this->firestore->getJobStatus($id);

        if (!$job) {
            abort(404);
        }

        // Update job status to queued
        $this->firestore->updateJobStatus(
            $id,
            $job['type'],
            'queued',
            $job['data'] ?? [],
            'Job requeued for retry',
            null,
            [
                [
                    'timestamp' => now()->toDateTimeString(),
                    'level' => 'info',
                    'message' => 'Job requeued for retry by ' . (Auth::check() ? Auth::user()->name : 'System'),
                ],
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
        $job = $this->firestore->getJobStatus($id);

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
        $this->firestore->updateJobStatus(
            $id,
            $job['type'],
            'cancelled',
            $job['data'] ?? [],
            'Job was cancelled by ' . (Auth::check() ? Auth::user()->name : 'System'),
            null,
            [
                [
                    'timestamp' => now()->toDateTimeString(),
                    'level' => 'warning',
                    'message' => 'Job was cancelled by ' . (Auth::check() ? Auth::user()->name : 'System'),
                ],
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
        $job = $this->firestore->getJobStatus($id);

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

        $jobs = $this->firestore->listJobs($type, $status, $limit);

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
        $deleted = $this->firestore->cleanupOldJobs($daysOld);

        return redirect()
            ->route('admin.jobs.index')
            ->with('status', "Successfully cleaned up $deleted old jobs.");
    }
}
