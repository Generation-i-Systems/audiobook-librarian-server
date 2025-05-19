@extends('layouts.app')

@php
    // Helper function to format array values
    function formatValue($value) {
        if (is_array($value)) {
            // If it's an associative array with a 'name' key
            if (isset($value['name'])) {
                return $value['name'];
            }
            // If it's an array of values, join them with commas
            return implode(', ', array_filter($value, 'is_string'));
        }
        return $value;
    }

    // Helper function to format series with their numbers
    function formatSeries($series) {
        if (empty($series)) {
            return 'N/A';
        }
        
        $result = [];
        
        // Handle the new format: ['Series Name' => number, ...]
        if (is_array($series)) {
            foreach ($series as $name => $number) {
                if (is_string($name)) {
                    $result[] = $name . ($number !== null ? " (#$number)" : '');
                }
            }
            return !empty($result) ? implode(', ', $result) : 'N/A';
        }
        
        return 'N/A';
    }
@endphp

@section('content')
<div class="container">
    @if(isset($book['cover_image']) && $book['cover_image'])
        <div class="mb-3">
            <img src="{{ url('cover/' . (is_array($book['cover_image']) ? ($book['cover_image']['path'] ?? '') : $book['cover_image'])) }}" alt="Book Cover" style="max-height: 200px; border:1px solid #ccc;">
        </div>
    @endif
    <h1>{{ is_array($book['title'] ?? null) ? ($book['title'][0] ?? 'No Title') : ($book['title'] ?? 'No Title') }}</h1>
    <ul class="list-group mb-4">
        <li class="list-group-item">
            <strong>Author:</strong> {{ formatValue($book['author_name'] ?? $book['author'] ?? 'N/A') }}
        </li>
        <li class="list-group-item">
            <strong>Genre:</strong> {{ formatValue($book['genre'] ?? 'N/A') }}
        </li>
        <li class="list-group-item">
            <strong>Series:</strong> {!! formatSeries($book['series'] ?? []) !!}
        </li>
        <li class="list-group-item">
            <strong>Description:</strong> {{ is_array($book['description'] ?? null) ? nl2br(e(implode("\n", $book['description']))) : e($book['description'] ?? 'No description available') }}
        </li>
        <li class="list-group-item">
            <strong>Published Year:</strong> {{ $book['published_year'] ?? 'N/A' }}
        </li>
        <li class="list-group-item">
            <strong>Date Added:</strong> {{ is_array($book['created_at'] ?? null) ? ($book['created_at']['date'] ?? 'N/A') : ($book['created_at'] ?? 'N/A') }}
        </li>
        <li class="list-group-item">
            <strong>Directory Path:</strong> {{ $book['directory_path'] ?? 'N/A' }}
        </li>
    </ul>
    <div class="mt-3">
        <a href="{{ route('admin.books.edit', $book['id'] ?? 0) }}" class="btn btn-primary">Edit</a>
        <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Back to List</a>
    </div>
</div>
@endsection
