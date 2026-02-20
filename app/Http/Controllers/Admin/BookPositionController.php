<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookPosition;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookPositionController extends Controller
{
    public function index(Request $request, User $user): View
    {
        $query = BookPosition::where('user_id', $user->id)
            ->with(['book:id,title', 'device', 'lastEvent:id,metadata'])
            ->orderBy('last_event_timestamp_ms', 'desc');

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->input('device_id'));
        }

        if ($request->filled('completed')) {
            $query->where('completed', $request->boolean('completed'));
        }

        $positions = $query->paginate(50);

        $devices = Device::where('user_id', $user->id)
            ->get()
            ->keyBy('device_id');

        return view('admin.books.positions', compact('user', 'positions', 'devices'));
    }
}
