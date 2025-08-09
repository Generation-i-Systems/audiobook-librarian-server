<?php

// Test basic database operations
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Test</h1>";
echo "<p>Initial Memory: " . number_format(memory_get_usage()) . " bytes</p>";

try {
    require_once '../vendor/autoload.php';
    echo "<p>After autoload: " . number_format(memory_get_usage()) . " bytes</p>";

    $app = require_once '../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::create('/', 'GET');
    $kernel->bootstrap();
    echo "<p>After Laravel: " . number_format(memory_get_usage()) . " bytes</p>";

    // Test basic count
    $count = \App\Models\Book::count();
    echo "<p>Total books: {$count}</p>";
    echo "<p>After count: " . number_format(memory_get_usage()) . " bytes</p>";

    // Test minimal select
    $books = \App\Models\Book::select('id', 'title')->limit(5)->get();
    echo "<p>Selected 5 books</p>";
    echo "<p>After select: " . number_format(memory_get_usage()) . " bytes</p>";

    foreach ($books as $book) {
        echo "<p>Book: {$book->id} - {$book->title}</p>";
    }
    echo "<p>After loop: " . number_format(memory_get_usage()) . " bytes</p>";

    echo "<h2>SUCCESS!</h2>";
} catch (Exception $e) {
    echo "<h2 style='color: red;'>ERROR: " . $e->getMessage() . "</h2>";
    echo "<p>Memory at error: " . number_format(memory_get_usage()) . " bytes</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<p>Peak Memory: " . number_format(memory_get_peak_usage()) . " bytes</p>";
