@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ __('Authors in Genre') }}</h1>

        <div class="mb-3">
            <h2 class="h4 mb-1">{{ $hierarchy['genre']['name'] ?? '' }}</h2>
        </div>

        <form action="{{ route('genres.authors', $hierarchy['genre']['id']) }}" method="GET" class="mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="sort" class="form-label">{{ __('Sort by') }}</label>
                    <select name="sort" id="sort" class="form-select">
                        <option value="name" {{ ($sort ?? 'name') === 'name' ? 'selected' : '' }}>{{ __('Name') }}</option>
                        <option value="books" {{ ($sort ?? 'name') === 'books' ? 'selected' : '' }}>{{ __('Book count') }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="direction" class="form-label">{{ __('Direction') }}</label>
                    <select name="direction" id="direction" class="form-select">
                        <option value="asc" {{ ($direction ?? 'asc') === 'asc' ? 'selected' : '' }}>{{ __('Ascending') }}</option>
                        <option value="desc" {{ ($direction ?? 'asc') === 'desc' ? 'selected' : '' }}>{{ __('Descending') }}</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-outline-secondary" type="submit">{{ __('Apply') }}</button>
                </div>
            </div>
        </form>

        @if(!empty($hierarchy['authors']))
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Books') }}</th>
                        <th>{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($hierarchy['authors'] as $author)
                        <tr>
                            <td>{{ $author['name'] }}</td>
                            <td>{{ $author['bookCount'] ?? 0 }}</td>
                            <td>
                                <a href="{{ route('authors.browse', [$author['id'], 'genre_id' => $hierarchy['genre']['id'] ?? null]) }}"
                                    class="btn btn-sm btn-outline-secondary">{{ __('Browse') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-muted">{{ __('No authors found for this genre.') }}</p>
        @endif

        <a href="{{ route('genres.index') }}" class="btn btn-secondary">{{ __('Back to Genres') }}</a>
    </div>
@endsection
