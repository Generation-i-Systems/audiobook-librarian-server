<?php

declare(strict_types=1);

use Google\Cloud\Firestore\FirestoreClient;
use MongoDB\Client as MongoClient;

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env manually if present
if (file_exists(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
}

if (!function_exists('custom_base_path')) {
    function custom_base_path($path = '')
    {
        return rtrim(dirname(__DIR__), '/') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

$FIREBASE_PROJECT_ID = getenv('FIREBASE_PROJECT_ID') ?: ($_ENV['FIREBASE_PROJECT_ID'] ?? '');
$FIREBASE_CREDENTIALS = getenv('FIREBASE_CREDENTIALS') ?: ($_ENV['FIREBASE_CREDENTIALS'] ?? '');
$MONGODB_URI = getenv('MONGODB_URI') ?: ($_ENV['MONGODB_URI'] ?? 'mongodb://localhost:27017');
$MONGODB_DB = getenv('MONGODB_DB') ?: ($_ENV['MONGODB_DB'] ?? 'ab_librarian');

if (!$FIREBASE_PROJECT_ID || !$FIREBASE_CREDENTIALS) {
    fwrite(STDERR, "FIREBASE_PROJECT_ID or FIREBASE_CREDENTIALS not set.\n");
    exit(1);
}

$firestore = new FirestoreClient([
    'projectId' => $FIREBASE_PROJECT_ID,
    'keyFilePath' => custom_base_path($FIREBASE_CREDENTIALS),
]);
$mongo = (new MongoClient($MONGODB_URI))->selectDatabase($MONGODB_DB);

$exclude = ['books'];
$collections = [
    'users',
    'genres',
    'series',
    'authors',
    'messages',
    'jobs',
    'reading_progress',
    // add more as needed
];

foreach ($collections as $collection) {
    if (in_array($collection, $exclude, true)) {
        echo "Skipping $collection\n";

        continue;
    }
    echo "Migrating $collection... ";
    $docs = $firestore->collection($collection)->documents();
    $count = 0;
    foreach ($docs as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        $data = $doc->data();
        $data['id'] = $doc->id();
        // Use Firestore's id as _id in Mongo if not already present
        if (!isset($data['_id'])) {
            $data['_id'] = $doc->id();
        }
        $mongo->selectCollection($collection)->replaceOne(['_id' => $data['_id']], $data, ['upsert' => true]);
        $count++;
    }
    echo "done ($count docs)\n";
}
echo "Migration complete.\n";
