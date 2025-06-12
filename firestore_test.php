<?php

// Set up Laravel environment
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Now we can use Laravel's container
use App\Services\FirestoreService;

// Get FirestoreService from container
$firestoreService = app(FirestoreService::class);

// Create a test book record
$book = [
    'title' => 'Test Book via Script',
    'author' => ['Test Author'],
    'directoryPath' => '/tmp/test_path',
    'seriesName' => 'Test Series',
    'seriesNumber' => '1',
    'duration' => 3600,
    'durationFormatted' => '01:00:00',
    'audioFileCount' => 5,
];

// Test creating a book
echo "Creating test book...\n";
$result = $firestoreService->createBook($book);
echo 'Create result: ' . ($result ? 'Success' : 'Failed') . "\n";

// Test finding the book by directoryPath
echo "Finding book by directoryPath...\n";
$foundBook = $firestoreService->findBookByDirectoryPath('/tmp/test_path');
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
    $updatedBook = $firestoreService->findBookByDirectoryPath('/tmp/test_path');
    echo 'Updated book title: ' . ($updatedBook ? $updatedBook['title'] : 'Not found') . "\n";
}
