<?php

namespace App\Queue\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

class FirestoreJob extends Job implements JobContract
{
    protected $firestoreQueue;

    protected $firestoreDoc;

    protected $queue;

    protected $rawBody;

    public function __construct(Container $container, $firestoreQueue, $firestoreDoc, $connectionName, $queue)
    {
        $this->container = $container;
        $this->firestoreQueue = $firestoreQueue;
        $this->firestoreDoc = $firestoreDoc;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->rawBody = $firestoreDoc['payload'];
    }

    public function getJobId()
    {
        return $this->firestoreDoc['id'] ?? $this->firestoreDoc->id();
    }

    public function attempts()
    {
        return $this->firestoreDoc['attempts'] ?? 0;
    }

    public function delete()
    {
        parent::delete();
        $this->firestoreDoc->reference()->delete();
    }

    public function release($delay = 0)
    {
        parent::release($delay);
        $availableAt = now()->addSeconds($delay)->timestamp;
        $this->firestoreDoc->reference()->update([
            ['path' => 'reserved_at', 'value' => null],
            ['path' => 'available_at', 'value' => $availableAt],
        ]);
    }

    public function firestoreDoc()
    {
        return $this->firestoreDoc;
    }

    /**
     * Get the raw underlying job payload string.
     *
     * @return string
     */
    public function getRawBody()
    {
        return $this->rawBody;
    }
}
