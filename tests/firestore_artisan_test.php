<?php

namespace Tests;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;

class FirestoreTest extends TestCase
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Test Firestore integration.
     */
    public function testFirestoreIntegration()
    {
        // Create a test book record
        $book = [
            'title' => 'Test Book via Artisan',
            'author' => ['Test Author'],
            'directoryPath' => '/tmp/test_artisan_path',
            'seriesName' => 'Test Series',
            'seriesNumber' => '1',
            'duration' => 3600,
            'durationFormatted' => '01:00:00',
            'audioFileCount' => 5,
        ];

        // Initialize FirestoreService
        $firestoreService = app(\App\Contracts\DocumentStoreServiceInterface::class);

        // Test creating a book
        echo "Creating test book...\n";
        $result = $firestoreService->createBook($book);
        echo 'Create result: ' . ($result ? 'Success' : 'Failed') . "\n";

        // Test finding the book by directoryPath
        echo "Finding book by directoryPath...\n";
        $foundBook = $firestoreService->findBookByDirectoryPath('/tmp/test_artisan_path');
        echo 'Found book: ' . ($foundBook ? 'Yes' : 'No') . "\n";
        if ($foundBook) {
            echo 'Book ID: ' . $foundBook['id'] . "\n";
            echo 'Book title: ' . $foundBook['title'] . "\n";
        }

        // Test updating the book
        if ($foundBook) {
            echo "Updating book...\n";
            $book['title'] = 'Updated Test Book';
            $result = $firestoreService->updateBook($foundBook['id'], $book);
            echo 'Update result: ' . ($result ? 'Success' : 'Failed') . "\n";

            // Verify update
            $updatedBook = $firestoreService->findBookByDirectoryPath('/tmp/test_artisan_path');
            echo 'Updated book title: ' . ($updatedBook ? $updatedBook['title'] : 'Not found') . "\n";
        }
    }
}

// Run the test
$test = new FirestoreTest();
$test->createApplication();
$test->testFirestoreIntegration();
