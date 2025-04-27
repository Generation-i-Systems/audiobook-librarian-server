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
            'authorList' => $authorList,
            'seriesList' => $seriesList,
            'genreList' => $genreList,
            'coverCandidates' => $coverCandidates,
            'coverAuto' => $coverAuto,
            'directory_path' => $book->directory_path,
            'isModal' => $isModal ?? false
        ])

    </div>

@endsection
