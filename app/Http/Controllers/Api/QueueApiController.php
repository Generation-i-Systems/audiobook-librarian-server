<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QueueApiController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $firestore = new FirestoreService();
        $queue = $firestore->getBookQueue($user->id);
        return response()->json($queue);
    }
}
