<?php

// Simple memory debugging without Laravel overhead
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Memory Debug Test</h1>";
echo "<p>Initial Memory: " . number_format(memory_get_usage()) . " bytes</p>";
echo "<p>Memory Limit: " . ini_get('memory_limit') . "</p>";

try {
    // Load minimal Laravel bootstrap
    require_once '../vendor/autoload.php';
    echo "<p>After autoload: " . number_format(memory_get_usage()) . " bytes</p>";

    $app = require_once '../bootstrap/app.php';
    echo "<p>After Laravel bootstrap: " . number_format(memory_get_usage()) . " bytes</p>";

    // Test database connection
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::create('/', 'GET');
    $kernel->bootstrap();
    echo "<p>After kernel bootstrap: " . number_format(memory_get_usage()) . " bytes</p>";

    // Test basic query
    $mysql = $app->make(\App\Services\MySqlService::class);
    echo "<p>After service creation: " . number_format(memory_get_usage()) . " bytes</p>";

    // Test the problematic query
    $result = $mysql->listBooks(1, 5, ['search' => 'Jane austen'], true, 'created_at', 'desc');
    echo "<p>After query: " . number_format(memory_get_usage()) . " bytes</p>";
    echo "<p>Books returned: " . count($result['data']) . "</p>";
    echo "<p>Total books found: " . $result['total'] . "</p>";

    echo "<h2>SUCCESS - No memory error!</h2>";
} catch (Throwable $e) {
    echo "<h2 style='color: red;'>ERROR: " . $e->getMessage() . "</h2>";
    echo "<p>Memory at error: " . number_format(memory_get_usage()) . " bytes</p>";
    echo "<p>Peak memory: " . number_format(memory_get_peak_usage()) . " bytes</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<p>Peak Memory Used: " . number_format(memory_get_peak_usage()) . " bytes</p>";
