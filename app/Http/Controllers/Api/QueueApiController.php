<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QueueApiController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $queue = BookQueue::where('user_id', $user->id)
            ->orderBy('order')
            ->with('book')
            ->get();

        return response()->json($queue);
    }
}
