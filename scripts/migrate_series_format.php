<?php

/**
 * Script to normalize the `series` field for all books in both MongoDB and Firestore.
 * - Converts legacy formats to canonical: array of objects [{seriesName, number}]
 * - If `seriesNumber` and `seriesName` are set but `series` is blank, fills `series` accordingly
 *
 * Usage: php scripts/migrate_series_format.php
 */

use Google\Cloud\Firestore\FirestoreClient;
use MongoDB\Client as MongoClient;

require_once __DIR__ . '/../vendor/autoload.php';

function canonicalizeSeries($series, $seriesName = null, $seriesNumber = null)
{
    // Already canonical
    if (is_array($series) && isset($series[0]['seriesName'])) {
        return $series;
    }
    // Legacy: [{"Buryoku": "9"}]
    if (is_array($series) && isset($series[0]) && is_array($series[0]) && count($series[0]) === 1) {
        $out = [];
        foreach ($series as $item) {
            foreach ($item as $name => $number) {
                $out[] = ['seriesName' => $name, 'number' => (string) $number];
            }
        }

        return $out;
    }
    // Legacy: key-value object
    if (is_array($series) && count(array_filter(array_keys($series), 'is_string'))) {
        $out = [];
        foreach ($series as $name => $number) {
            $out[] = ['seriesName' => $name, 'number' => (string) $number];
        }

        return $out;
    }
    // Single string
    if (is_string($series) && $series !== '') {
        return [['seriesName' => $series, 'number' => $seriesNumber ?? '']];
    }
    // If seriesName/seriesNumber set but series blank
    if ($seriesName && $seriesNumber) {
        return [['seriesName' => $seriesName, 'number' => $seriesNumber]];
    }
    if ($seriesName) {
        return [['seriesName' => $seriesName, 'number' => $seriesNumber ?? '']];
    }

    return [];
}

function updateMongoDB()
{
    $uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
    $dbName = getenv('MONGODB_DB') ?: 'ab_librarian';
    $client = new MongoClient($uri);
    $db = $client->$dbName;
    $books = $db->books;
    $cursor = $books->find();
    $count = 0;
    foreach ($cursor as $doc) {
        $id = $doc['_id'];
        $series = $doc['series'] ?? null;
        $seriesName = $doc['seriesName'] ?? null;
        $seriesNumber = $doc['seriesNumber'] ?? null;
        $canonical = canonicalizeSeries($series, $seriesName, $seriesNumber);
        if ($series !== $canonical || (empty($series) && ($seriesName || $seriesNumber))) {
            $update = ['series' => $canonical];
            // Optionally remove legacy fields
            if (isset($doc['seriesName'])) {
                $update['seriesName'] = null;
            }
            if (isset($doc['seriesNumber'])) {
                $update['seriesNumber'] = null;
            }
            $books->updateOne(['_id' => $id], ['$set' => $update, '$unset' => ['seriesName' => '', 'seriesNumber' => '']]);
            $count++;
            echo "[MongoDB] Updated book {$id}\n";
        }
    }
    echo "[MongoDB] Migration complete. Updated {$count} records.\n";
}

function updateFirestore()
{
    $projectId = getenv('FIREBASE_PROJECT_ID') ?: 'your-project-id';
    $firestore = new FirestoreClient(['projectId' => $projectId]);
    $books = $firestore->collection('books');
    $documents = $books->documents();
    $count = 0;
    foreach ($documents as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        $data = $doc->data();
        $series = $data['series'] ?? null;
        $seriesName = $data['seriesName'] ?? null;
        $seriesNumber = $data['seriesNumber'] ?? null;
        $canonical = canonicalizeSeries($series, $seriesName, $seriesNumber);
        if ($series !== $canonical || (empty($series) && ($seriesName || $seriesNumber))) {
            $update = ['series' => $canonical];
            // Optionally remove legacy fields
            if (isset($data['seriesName'])) {
                $update['seriesName'] = firestoreDeleteField();
            }
            if (isset($data['seriesNumber'])) {
                $update['seriesNumber'] = firestoreDeleteField();
            }
            $doc->reference()->update($update);
            $count++;
            echo "[Firestore] Updated book {$doc->id()}\n";
        }
    }
    echo "[Firestore] Migration complete. Updated {$count} records.\n";
}

function firestoreDeleteField()
{
    // Helper for Firestore field deletion
    return new Google\Cloud\Firestore\FieldValue(['deleteField' => true]);
}

updateMongoDB();
updateFirestore();
