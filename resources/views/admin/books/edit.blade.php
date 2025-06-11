@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
    <div class="container">
        @if(empty($isModal))
            <h1>Edit Book</h1>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @include('admin.books.form', [
            'book' => $book,
            'genreList' => $genreList,
            'coverCandidates' => $coverCandidates,
            'coverAuto' => $coverAuto,
            'directoryPath' => $book['directoryPath'] ?? $book['directory_path'] ?? null,
            'isModal' => $isModal ?? false
        ])

        </div>

@endsection
