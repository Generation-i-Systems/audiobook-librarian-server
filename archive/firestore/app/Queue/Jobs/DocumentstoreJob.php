<?php

namespace App\Queue\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

class DocumentstoreJob extends Job implements JobContract
{
    protected $documentStoreQueue;

    protected $documentStoreDoc;

    protected $queue;

    protected $rawBody;

    public function __construct(Container $container, $documentStoreQueue, $documentStoreDoc, $connectionName, $queue)
    {
        $this->container = $container;
        $this->documentStoreQueue = $documentStoreQueue;
        $this->documentStoreDoc = $documentStoreDoc;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->rawBody = $documentStoreDoc['payload'];
    }

    public function getJobId()
    {
        return $this->documentStoreDoc['id'] ?? $this->documentStoreDoc->id();
    }

    public function attempts()
    {
        return $this->documentStoreDoc['attempts'] ?? 0;
    }

    public function delete()
    {
        parent::delete();
        $this->documentStoreDoc->reference()->delete();
    }

    public function release($delay = 0)
    {
        parent::release($delay);
        $availableAt = now()->addSeconds($delay)->timestamp;
        $this->documentStoreDoc->reference()->update([
            ['path' => 'reserved_at', 'value' => null],
            ['path' => 'available_at', 'value' => $availableAt],
        ]);
    }

    public function documentStoreDoc()
    {
        return $this->documentStoreDoc;
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
