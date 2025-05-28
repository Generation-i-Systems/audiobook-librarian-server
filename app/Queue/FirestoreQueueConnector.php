<?php

namespace App\Queue;

use Illuminate\Queue\Connectors\ConnectorInterface;
use App\Services\FirestoreService;

class FirestoreQueueConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        $firestore = app(FirestoreService::class);
        $collection = $config['queue'] ?? 'queue';
        return new FirestoreQueue($firestore, $collection);
    }
}
