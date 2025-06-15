<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Auth;

class QueueApiController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $firestore = app(\App\Contracts\DocumentStoreServiceInterface::class);
        $queue = $firestore->getBookQueue($user->id);

        return response()->json($queue);
    }
}
