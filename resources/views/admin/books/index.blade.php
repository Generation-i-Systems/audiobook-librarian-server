@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Manage Books</h1>

        <form action="{{ route('admin.books.index') }}" method="GET">
            <div class="input-group mb-3">
                <input type="text" class="form-control" placeholder="Search title, author, or series" name="search"
                    value="{{ request('search') }}">
                <select name="sort" class="form-select ms-2" onchange="this.form.submit()">
                    <option value="recent_desc" {{ (request('sort', 'recent_desc') == 'recent_desc') ? 'selected' : '' }}>Most
                        Recent</option>
                    <option value="recent_asc" {{ (request('sort') == 'recent_asc') ? 'selected' : '' }}>Oldest</option>
                    <option value="author_asc" {{ (request('sort') == 'author_asc') ? 'selected' : '' }}>Author A-Z</option>
                    <option value="author_desc" {{ (request('sort') == 'author_desc') ? 'selected' : '' }}>Author Z-A</option>
                    <option value="title_asc" {{ (request('sort') == 'title_asc') ? 'selected' : '' }}>Title A-Z</option>
                    <option value="title_desc" {{ (request('sort') == 'title_desc') ? 'selected' : '' }}>Title Z-A</option>
                    <option value="year_asc" {{ (request('sort') == 'year_asc') ? 'selected' : '' }}>Year Asc</option>
                    <option value="year_desc" {{ (request('sort') == 'year_desc') ? 'selected' : '' }}>Year Desc</option>
                </select>
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </form>

        <div class="mb-3">
            <a href="{{ route('admin.books.create') }}" class="btn btn-primary">Add New Book</a>
            <a href="{{ route('admin.books.import') }}" class="btn btn-info">Import Book(s)</a>
            <a href="{{ route('admin.books.importFile') }}" class="btn btn-warning ms-2">Import from File/Audio</a>
            <a href="{{ route('admin.authors.index') }}" class="btn btn-outline-secondary ms-2">Manage Authors</a>
            <a href="{{ route('admin.genres.index') }}" class="btn btn-outline-secondary ms-2">Manage Genres</a>
        </div>
        @php
            $genreCounts = [];
            $totalBooks = count($books);
            foreach ($books as $book) {
                $genres = [];
                if (!empty($book['genre'])) {
                    if (is_array($book['genre'])) {
                        foreach ($book['genre'] as $g) {
                            $genres[] = is_array($g) ? ($g['name'] ?? $g[0] ?? $g) : $g;
                        }
                    } else {
                        $genres[] = $book['genre'];
                    }
                }
                foreach ($genres as $g) {
                    $g = is_string($g) ? trim($g) : $g;
                    if ($g === '')
                        continue;
                    $genreCounts[$g] = ($genreCounts[$g] ?? 0) + 1;
                }
            }
        @endphp
        <div class="mb-2 text-muted small">
            <span>Total books: <strong>{{ $totalBooks }}</strong></span>
            @if(count($genreCounts))
                    <span class="ms-3">Genres:
                        {!! collect($genreCounts)->map(function ($count, $genre) {
                    return e($genre) . ' (' . $count . ')';
                })->implode(', ') !!}
                    </span>
            @endif
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th style="width: 56px;"></th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Series</th>
                    <th>Genre</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($books as $book)
                    <tr class="{{ $loop->iteration % 2 == 0 ? 'table-secondary' : '' }}">
                        <td style="vertical-align: middle; text-align: center;">
                            @php
                                $coverImage = $book['coverImage'] ?? null;
                                $coverProxyUrl = (is_string($coverImage) && !empty(trim($coverImage)))
                                    ? route('cover.proxy', ['path' => rawurlencode($coverImage)])
                                    : asset('images/placeholder.png');
                            @endphp
                            <img src="{{ $coverProxyUrl }}" alt="cover"
                                style="height: 48px; width: auto; object-fit: contain; border-radius: 3px; box-shadow: 0 1px 2px rgba(0,0,0,.07); background: #f8f8f8;"
                                loading="lazy">
                        </td>
                        <td>{{ $book['title'] ?? 'Untitled' }}</td>
                        <td>
                            @if(!empty($book['author']))
                                @if(is_array($book['author']))
                                    @foreach($book['author'] as $author)
                                        @if(is_array($author) && isset($author['name']))
                                            {{ $author['name'] }}@if(!$loop->last)<br>@endif
                                        @else
                                            {{ is_string($author) ? $author : 'Unknown' }}@if(!$loop->last)<br>@endif
                                        @endif
                                    @endforeach
                                @else
                                    {{ $book['author'] }}
                                @endif
                            @else
                                Unknown
                            @endif
                        </td>
                        <td>
                            @if(!empty($book['series']))
                                @php
                                    $series = $book['series'] ?? [];
                                @endphp
                                @if(is_array($series))
                                    @foreach($series as $key => $item)
                                        @if(is_array($item) && (isset($item['seriesName']) || isset($item['name'])))
                                            {{-- Canonical: [{seriesName, number}] or [{name, number}] --}}
                                            {{ $item['seriesName'] ?? $item['name'] }}{{ !empty($item['number']) ? ' (#' . $item['number'] . ')' : '' }}
                                        @elseif(is_string($key) && (is_scalar($item) || is_null($item)))
                                            {{-- Assoc: ['Name' => number] --}}
                                            {{ $key }}{{ $item ? ' (#' . $item . ')' : '' }}
                                        @elseif(is_string($item))
                                            {{-- Legacy: ['Name', ...] --}}
                                            {{ $item }}
                                        @endif
                                        @if(!$loop->last)<br>@endif
                                    @endforeach
                                @elseif(is_string($series))
                                    {{ $series }}
                                @endif
                            @endif
                        </td>
                        <td>
                            @if(!empty($book['genre']))
                                @if(is_array($book['genre']))
                                    @if(isset($book['genre']['name']))
                                        {{ $book['genre']['name'] }}
                                    @else
                                        @foreach($book['genre'] as $genre)
                                            {{ is_array($genre) ? ($genre['name'] ?? '') : $genre }}@if(!$loop->last), @endif
                                        @endforeach
                                    @endif
                                @else
                                    {{ $book['genre'] }}
                                @endif
                            @else
                                Unknown
                            @endif
                        </td>
                        <td>
                            @php
                                $bookId = $book['id'] ?? ($book['documentId'] ?? 0);
                            @endphp
                            <a href="{{ route('admin.books.edit', array_merge([$bookId], request()->query())) }}" class="btn btn-sm btn-outline-primary"
                                title="Edit"><i class="fas fa-pencil-alt"></i></a>
                            <form action="{{ route('admin.books.destroy', $bookId) }}" method="POST" style="display: inline;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                    onclick="return confirm('Are you sure?')"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="pagination">
            {{ $books->links() }}
        </div>

    </div>
@endsection
