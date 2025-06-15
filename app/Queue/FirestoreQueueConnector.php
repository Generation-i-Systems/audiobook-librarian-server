<?php

namespace App\Queue;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Queue\Connectors\ConnectorInterface;

class FirestoreQueueConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        $firestore = app(\App\Contracts\DocumentStoreServiceInterface::class);
        $collection = $config['queue'] ?? 'queue';

        return new FirestoreQueue($firestore, $collection);
    }
}
