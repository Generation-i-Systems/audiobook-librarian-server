@extends('layouts.app')
@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">Admin Social Activity Dashboard</h1>

    <div class="mb-8">
        <h2 class="text-2xl font-semibold mb-4">Pending Recommendations ({{ $pendingRecommendations->count() }})</h2>
        @if ($pendingRecommendations->isEmpty())
            <p>No new pending recommendations.</p>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($pendingRecommendations as $rec)
                    <div class="bg-yellow-50 p-3 rounded shadow-sm text-sm">
                        <p class="font-medium">Book: <a href="{{ route('admin.books.show', $rec->book->id) }}" class="text-blue-600 hover:underline">{{ $rec->book->title }}</a></p>
                        <p>From: {{ $rec->sender->name }} | To: {{ $rec->recipient->name }}</p>
                        @if ($rec->message)
                            <p class="mt-1 italic text-gray-700">Message: "{{ $rec->message }}"</p>
                        @endif
                        <p class="text-xs text-gray-500 mt-1">Sent: {{ $rec->created_at->diffForHumans() }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="mb-8">
        <h2 class="text-2xl font-semibold mb-4">Recently Completed Books ({{ $recentlyCompleted->count() }})</h2>
        @if ($recentlyCompleted->isEmpty())
            <p>No books recently marked completed.</p>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($recentlyCompleted as $status)
                    <div class="bg-green-50 p-3 rounded shadow-sm text-sm">
                        <p class="font-medium">Book: <a href="{{ route('admin.books.show', $status->book->id) }}" class="text-blue-600 hover:underline">{{ $status->book->title }}</a></p>
                        <p>User: {{ $status->user->name }}</p>
                        <p class="text-xs text-gray-500 mt-1">Completed: {{ $status->updated_at->diffForHumans() }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
