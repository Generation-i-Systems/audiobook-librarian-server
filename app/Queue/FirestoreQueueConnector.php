<?php

namespace App\Queue;

use App\Services\FirestoreService;
use Illuminate\Queue\Connectors\ConnectorInterface;

class FirestoreQueueConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        $firestore = app(FirestoreService::class);
        $collection = $config['queue'] ?? 'queue';

        return new FirestoreQueue($firestore, $collection);
    }
}
