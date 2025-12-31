<?php

namespace App\Queue;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Queue\Connectors\ConnectorInterface;

class DocumentstoreQueueConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        $documentStore = app(DocumentStoreServiceInterface::class);
        $collection = $config['queue'] ?? 'queue';

        return new DobumentstoreQueue($documentStore, $collection);
    }
}
