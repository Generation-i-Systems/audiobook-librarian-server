<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\ListeningEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventTimelineController extends Controller
{
    public function index(Request $request, User $user): View
    {
        $query = ListeningEvent::where('user_id', $user->id)
            ->orderBy('timestamp_ms', 'desc');

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->input('device_id'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('book_id')) {
            $query->where('book_id', $request->input('book_id'));
        }

        $events = $query->with('book')->paginate(50);

        $devices = Device::where('user_id', $user->id)
            ->get()
            ->keyBy('device_id');

        $eventTypes = ListeningEvent::where('user_id', $user->id)
            ->distinct('event_type')
            ->pluck('event_type')
            ->sort()
            ->values();

        $books = ListeningEvent::where('user_id', $user->id)
            ->distinct('book_id')
            ->with('book:id,title')
            ->get()
            ->pluck('book')
            ->filter()
            ->unique('id')
            ->sortBy('title')
            ->values();

        return view('admin.events.timeline', compact(
            'user',
            'events',
            'devices',
            'eventTypes',
            'books',
        ));
    }
}
