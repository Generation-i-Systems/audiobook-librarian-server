<?php

namespace App\Queue;

use Illuminate\Queue\Queue;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\FirestoreService;
use App\Queue\Jobs\FirestoreJob;

/**
 * @method int getSeconds(mixed $delay) Inherited from Illuminate\Queue\Queue
 */
class FirestoreQueue extends Queue implements QueueContract
{
    protected $firestore;
    protected $collection;

    public function __construct(FirestoreService $firestore, $collection)
    {
        $this->firestore = $firestore;
        $this->collection = $collection;
    }

    public function size($queue = null)
    {
        return $this->firestore->getQueueCollection($this->collection)->documents()->size();
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $queue = $queue ?: $this->collection;
        $id = (string) Str::uuid();
        $now = Carbon::now()->timestamp;
        $this->firestore->getQueueCollection($queue)->add([
            'id' => $id,
            'payload' => $payload,
            'available_at' => $now,
            'reserved_at' => null,
            'attempts' => 0,
        ]);
        return $id;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $queue = $queue ?: $this->collection;
        $id = (string) Str::uuid();
        // getSeconds() is inherited from Illuminate\Queue\Queue
        $availableAt = Carbon::now()->addSeconds($this->getSeconds($delay))->timestamp;
        $this->firestore->getQueueCollection($queue)->add([
            'id' => $id,
            'payload' => $this->createPayload($job, $data),
            'available_at' => $availableAt,
            'reserved_at' => null,
            'attempts' => 0,
        ]);
        return $id;
    }

    public function pop($queue = null)
    {
        $queue = $queue ?: $this->collection;
        $now = Carbon::now()->timestamp;
        $documents = $this->firestore->getQueueCollection($queue)
            ->where('reserved_at', '=', null)
            ->where('available_at', '<=', $now)
            ->orderBy('available_at')
            ->limit(1)
            ->documents();

        foreach ($documents as $document) {
            // Mark as reserved
            $document->reference()->update([
                ['path' => 'reserved_at', 'value' => $now],
                ['path' => 'attempts', 'value' => ($document['attempts'] ?? 0) + 1],
            ]);
            return new FirestoreJob(
                $this->container, $this, $document, $this->connectionName, $queue
            );
        }
        return null;
    }

    public function deleteReserved($queue, $job)
    {
        $job->delete();
    }

    public function release($queue, $job, $delay)
    {
        // getSeconds() is inherited from Illuminate\Queue\Queue
        $availableAt = Carbon::now()->addSeconds($this->getSeconds($delay))->timestamp;
        $job->firestoreDoc()->reference()->update([
            ['path' => 'reserved_at', 'value' => null],
            ['path' => 'available_at', 'value' => $availableAt],
        ]);
    }
}
