<?php

include "vendor/autoload.php";
$app = include "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\BookController;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

$user = User::first();
if (!$user) {
    echo "No user found\n";
    exit;
}
Auth::login($user);

$request = Request::create('/api/books/json', 'GET', ['page' => 1, 'per_page' => 5]);
$controller = $app->make(BookController::class);

try {
    $response = $controller->jsonIndex($request);
    echo "Status Code: " . $response->getStatusCode() . "\n";
    $data = json_decode($response->getContent(), true);

    // Check for errors in the response content if status is not 200
    if ($response->getStatusCode() !== 200) {
        echo "Response: " . $response->getContent() . "\n";
    }

    echo "Book Count: " . count($data['books'] ?? []) . "\n";
    if (!empty($data['books'])) {
        echo "First Book Title: " . $data['books'][0]['title'] . "\n";
        echo "First Book Authors (plural): " . json_encode($data['books'][0]['authors']) . "\n";
        echo "First Book Author (singular): " . json_encode($data['books'][0]['author'] ?? 'NOT SET') . "\n";
    } else {
        echo "No data returned in 'books' key\n";
        echo "Full API response keys: " . implode(', ', array_keys($data)) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
