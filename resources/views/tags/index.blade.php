@extends('layouts.app')

@section('content')
<div class="container py-3">
    <h1>{{ __('System Tags') }}</h1>
    <p class="text-muted">{{ __('Browse shared tags and the books that carry them. Personal and group tags are managed separately.') }}</p>
    @if(session('success')) <div class="alert alert-success" role="status">{{ session('success') }}</div> @endif
    @if ($errors->any()) <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div> @endif

    <div class="row g-4">
        <aside class="col-lg-3">
            <h2 class="h5">{{ __('Tags') }}</h2>
            <div class="list-group mb-3" style="max-height: 32rem; overflow-y: auto;">
                <a class="list-group-item list-group-item-action {{ $tag === '' ? 'active' : '' }}" href="{{ route('tags.index', array_filter(['search' => $search, 'genre' => $genre])) }}">{{ __('All books') }}</a>
                @foreach($tags as $entry)
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ $tag === $entry['name'] ? 'active' : '' }}" href="{{ route('tags.index', array_filter(['tag' => $entry['name'], 'search' => $search, 'genre' => $genre])) }}">
                        <span>{{ $entry['name'] }}</span><span class="badge rounded-pill {{ $tag === $entry['name'] ? 'bg-light text-dark' : 'bg-secondary' }}">{{ $entry['count'] }}</span>
                    </a>
                @endforeach
            </div>
            @can('manage-tags')
                @if($tag !== '')
                    <a href="{{ route('tags.edit', $tag) }}" class="btn btn-sm btn-outline-primary">{{ __('Rename tag') }}</a>
                    <form action="{{ route('tags.destroy', $tag) }}" method="POST" class="d-inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Delete this tag from all books?')) }});">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger">{{ __('Delete tag') }}</button>
                    </form>
                @endif
            @endcan
        </aside>
        <main class="col-lg-9">
            <h2 class="h4">{{ $tag !== '' ? $tag : __('All books') }} <span class="text-muted fs-6">({{ $books->total() }})</span></h2>
            <form method="GET" action="{{ route('tags.index') }}" class="d-flex flex-wrap gap-2 mb-3" role="search">
                @if($tag !== '') <input type="hidden" name="tag" value="{{ $tag }}"> @endif
                <label class="visually-hidden" for="book-search">{{ __('Search books or authors') }}</label>
                <input id="book-search" class="form-control" type="search" name="search" value="{{ $search }}" placeholder="{{ __('Search books or authors') }}">
                <label class="visually-hidden" for="genre-filter">{{ __('Genre') }}</label>
                <select id="genre-filter" name="genre" class="form-select" style="max-width: 14rem">
                    <option value="">{{ __('All genres') }}</option>
                    @foreach($genres as $item)<option value="{{ $item->id }}" @selected($genre === $item->id)>{{ $item->name }}</option>@endforeach
                </select>
                <button class="btn btn-primary">{{ __('Search') }}</button>
            </form>
            <form method="POST" action="{{ route('tags.bulk') }}">
                @csrf
                @can('manage-tags')
                    <div class="border rounded p-3 mb-3 bg-light">
                        <p class="mb-2">{{ __('Select books on this page, then add or remove a system tag.') }}</p>
                        <div class="d-flex flex-wrap gap-2">
                            <label class="visually-hidden" for="bulk-tag">{{ __('Tag') }}</label>
                            <input id="bulk-tag" name="tag" class="form-control" style="max-width: 18rem" maxlength="64" value="{{ $tag }}" list="system-tag-options" placeholder="{{ __('Tag name') }}" required>
                            <datalist id="system-tag-options">@foreach($tags as $entry)<option value="{{ $entry['name'] }}">@endforeach</datalist>
                            <button name="action" value="add" class="btn btn-primary">{{ __('Add tag') }}</button>
                            <button name="action" value="remove" class="btn btn-outline-danger">{{ __('Remove tag') }}</button>
                        </div>
                    </div>
                @endcan
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr>
                            @can('manage-tags') <th scope="col"><input type="checkbox" id="select-page" aria-label="{{ __('Select all books on this page') }}"></th> @endcan
                            <th scope="col">{{ __('Book') }}</th><th scope="col">{{ __('Genres') }}</th><th scope="col">{{ __('System tags') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse($books as $book)
                            <tr>
                                @can('manage-tags') <td><input type="checkbox" name="book_ids[]" value="{{ $book->id }}" aria-label="{{ __('Select') }} {{ $book->title }}"></td> @endcan
                                <td><a href="{{ route('books.show', $book->id) }}">{{ $book->title }}</a><div class="small text-muted">{{ $book->authors->pluck('name')->join(', ') }}</div></td>
                                <td>{{ $book->genres->pluck('name')->join(', ') }}</td>
                                <td>{{ collect($book->userTags->first()?->tags ?? [])->join(', ') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">{{ __('No books found. Try another search or tag.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </form>
            {{ $books->links() }}
        </main>
    </div>
</div>
<script>
    document.getElementById('select-page')?.addEventListener('change', function () {
        document.querySelectorAll('input[name="book_ids[]"]').forEach(input => { input.checked = this.checked; });
    });
</script>
@endsection
