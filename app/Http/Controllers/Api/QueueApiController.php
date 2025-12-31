<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class QueueApiController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);
        $queue = $documentStore->getBookQueue($user->id);

        return response()->json($queue);
    }
}
