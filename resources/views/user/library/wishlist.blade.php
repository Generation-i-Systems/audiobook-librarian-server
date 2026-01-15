@extends('layouts.app')
@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">Wishlist</h1>
    @if ($wishlist->isEmpty())
        <p>Your wishlist is empty. Time to find some new audiobooks!</p>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($wishlist as $status)
                <div class="bg-white shadow-lg rounded-lg overflow-hidden">
                    <div class="p-4">
                        <h2 class="text-xl font-semibold">{{ $status->book->title }}</h2>
                        <p class="text-gray-600">Added: {{ $status->created_at->diffForHumans() }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
