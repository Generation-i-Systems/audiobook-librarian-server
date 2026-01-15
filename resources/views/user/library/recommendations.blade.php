@extends('layouts.app')
@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">Recommendations Inbox</h1>
    @if ($recommendations->isEmpty())
        <p>Your recommendations inbox is empty. Nobody has recommended a book to you yet.</p>
    @else
        <div class="space-y-4">
            @foreach ($recommendations as $recommendation)
                <div class="bg-white shadow-lg rounded-lg p-4 flex items-start space-x-4">
                    <img src="{{ $recommendation->book->coverUrl ?? asset('images/default-cover.png') }}" alt="{{ $recommendation->book->title }}" class="w-16 h-16 object-cover rounded">
                    <div>
                        <p class="text-lg font-semibold">{{ $recommendation->book->title }}</p>
                        <p class="text-sm text-gray-600">From: {{ $recommendation->sender->name }}</p>
                        @if ($recommendation->message)
                            <p class="mt-1 text-gray-700 italic">"{{ $recommendation->message }}"</p>
                        @endif
                        <p class="mt-2 text-xs text-gray-500">Received: {{ $recommendation->created_at->diffForHumans() }}</p>
                        @if ($recommendation->acknowledged_at)
                            <span class="text-green-500 text-sm font-medium">Acknowledged</span>
                        @else
                            <button class="mt-2 text-sm text-blue-600 hover:underline">Acknowledge</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
