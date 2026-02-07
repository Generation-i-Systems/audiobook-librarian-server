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

    if ($response->getStatusCode() !== 200) {
        echo "Response Body: " . $response->getContent() . "\n";
    }

    echo "Book Count: " . count($data['books'] ?? []) . "\n";
    if (!empty($data['books'])) {
        echo "Pagination: " . json_encode($data['pagination']) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
