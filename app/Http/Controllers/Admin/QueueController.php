<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class QueueController extends Controller
{
    public function index()
    {
        return view('queue.index');
    }

    public function list()
    {
        $jobs = DB::table('jobs')->orderBy('id')->get();
        $jobs = $jobs->map(function ($job) {
            $payload = json_decode($job->payload, true);
            $dir = null;
            if (isset($payload['data']['command'])) {
                $command = $payload['data']['command'];
                // Extract directoryPath from the serialized string
                if (preg_match('/directoryPath";s:\\d+:"([^"]+)"/', $command, $matches)) {
                    $dir = $matches[1];
                }


            }
            return [
                'id' => $job->id,
                'queue' => $job->queue,
                'attempts' => $job->attempts,
                'available_at' => date('Y-m-d H:i:s', $job->available_at),
                'created_at' => date('Y-m-d H:i:s', $job->created_at),
                'directory' => $dir,
                'payload' => $payload,
            ];
        });
        return response()->json(['jobs' => $jobs]);
    }

    public function remove($id)
    {
        DB::table('jobs')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    public function status()
    {
        // Check for running worker (simple: look for process, or use a cache heartbeat)
        $running = Cache::get('queue_worker_heartbeat') ? true : false;
        $pending = DB::table('jobs')->count();
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
}
