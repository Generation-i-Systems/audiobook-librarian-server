<?php

namespace Tests;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class FirestoreTestCase extends BaseTestCase
{
    protected $firestore;

    protected $collections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->firestore = new FirestoreClient([
            'projectId' => config('firebase.project_id'),
            'keyFilePath' => config('firebase.credentials.file'),
        ]);

        // Clear test data before each test
        $this->clearTestData();
    }

    protected function tearDown(): void
    {
        $this->clearTestData();
        parent::tearDown();
    }

    protected function clearTestData()
    {
        foreach ($this->collections as $collectionName) {
            $documents = $this->firestore->collection($collectionName)
                ->where('__test__', '==', true)
                ->documents();

            foreach ($documents as $document) {
                $document->reference()->delete();
            }
        }
    }

    protected function markAsTestDocument($documentRef)
    {
        $documentRef->update([['path' => '__test__', 'value' => true]]);

        return $documentRef;
    }
}
