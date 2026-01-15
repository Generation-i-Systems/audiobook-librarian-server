@extends('layouts.app')
@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">Reading Queue</h1>
    @if ($queuedBooks->isEmpty())
        <p>Your reading queue is empty. Add some books to your queue!</p>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($queuedBooks as $status)
                <div class="bg-white shadow-lg rounded-lg overflow-hidden">
                    <div class="p-4">
                        <h2 class="text-xl font-semibold">{{ $status->book->title }}</h2>
                        <p class="text-gray-600">Order: {{ $status->order }}</p>
                        <p class="text-gray-600">Added: {{ $status->created_at->diffForHumans() }}</p>
                        @if ($status->statusDetail)
                            <p class="text-sm text-gray-500 mt-2">Notes: {{ json_encode($status->statusDetail) }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
