@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">My Tags</h1>

    <h2 class="text-xl font-bold mb-3">My Tags</h2>
    @if (empty($tags['user']))
        <p class="text-gray-500 mb-6">You haven't tagged any books yet. Add a tag from a book's page.</p>
    @else
        <ul class="mb-6">
            @foreach ($tags['user'] as $entry)
                <li class="mb-2">
                    <strong>{{ $entry['tag'] }}</strong> —
                    @foreach ($entry['books'] as $book)
                        <a href="{{ route('books.show', $book->id) }}">{{ $book->title }}</a>@if(!$loop->last), @endif
                    @endforeach
                </li>
            @endforeach
        </ul>
    @endif

    @foreach ($tags['groups'] as $group)
        <h2 class="text-xl font-bold mb-3">{{ $group['groupName'] }} Tags</h2>
        @if (empty($group['tags']))
            <p class="text-gray-500 mb-6">No group tags yet.</p>
        @else
            <ul class="mb-6">
                @foreach ($group['tags'] as $entry)
                    <li class="mb-2">
                        <strong>{{ $entry['tag'] }}</strong> —
                        @foreach ($entry['books'] as $book)
                            <a href="{{ route('books.show', $book->id) }}">{{ $book->title }}</a>@if(!$loop->last), @endif
                        @endforeach
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach
</div>
@endsection
