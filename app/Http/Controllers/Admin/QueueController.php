<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class QueueController extends Controller
{
    public function index()
    {
        return view('admin.queue.index');
    }

    public function list(Request $request)
    {
        $typeFilter = $request->query('type');
        $jobs = (new \App\Services\FirestoreService())->db->collection('jobs')->orderBy('id')->get();
        $jobTypeCounts = [];
        $jobTypes = [];
        $jobs = $jobs->map(function ($job) use (&$jobTypeCounts, &$jobTypes) {
            $payload = json_decode($job->payload, true);
            $dir = null;
            $jobType = 'Unknown';
            if (isset($payload['data']['command'])) {
                $command = $payload['data']['command'];
                // Extract job class name
                if (preg_match('/O:(\\d+):\"([^\"]+)\"/', $command, $matches)) {
                    $jobType = class_basename(str_replace('\\', '\\', $matches[2]));
                }
                // Extract directory for ImportBookFromDirectoryJob
                if ($jobType === 'ImportBookFromDirectoryJob') {
                    if (preg_match('/directoryPath";s:\\d+:"([^"]+)"/', $command, $matches)) {
                        $dir = $matches[1];
                    }
                }
                // Extract dir for CreateImportJobsForDirectory
                elseif ($jobType === 'CreateImportJobsForDirectory') {
                    if (preg_match('/dir";s:\\d+:"([^"]+)"/', $command, $matches)) {
                        $dir = $matches[1];
                    }
                }
            }
            $jobTypeCounts[$jobType] = ($jobTypeCounts[$jobType] ?? 0) + 1;
            if (!in_array($jobType, $jobTypes)) {
                $jobTypes[] = $jobType;
            }
            return [
                'id' => $job->id,
                'queue' => $job->queue,
                'attempts' => $job->attempts,
                'available_at' => date('Y-m-d H:i:s', $job->available_at),
                'created_at' => date('Y-m-d H:i:s', $job->created_at),
                'directory' => $dir,
                'payload' => $payload,
                'type' => $jobType,
            ];
        });
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

    public function remove($id)
    {
        (new \App\Services\FirestoreService())->db->collection('jobs')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    public function status()
    {
        // Check for running worker (simple: look for process, or use a cache heartbeat)
        $running = Cache::get('queue_worker_heartbeat') ? true : false;
        $pending = (new \App\Services\FirestoreService())->db->collection('jobs')->count();
        return response()->json(['worker_running' => $running, 'pending_jobs' => $pending]);
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
        (new \App\Services\FirestoreService())->db->collection('jobs')->documents()->each(function($doc) { $doc->reference()->delete(); });
        return response()->json(['success' => true]);
    }
}
