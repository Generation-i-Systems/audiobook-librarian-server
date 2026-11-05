<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LocalTransfer;
use Illuminate\Http\JsonResponse;

/**
 * Tells signed-in apps how to reach this server's LAN listener for book file downloads.
 * Kept off the unauthenticated capabilities response, which would publish the private address.
 */
class LocalTransferController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['localTransfer' => LocalTransfer::route()]);
    }
}
