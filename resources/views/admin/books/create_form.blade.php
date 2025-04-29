@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
    <div class="container">
        @include('admin.books.form', [
            'authorList' => $authorList,
            'seriesList' => $seriesList,
            'genreList' => $genreList,
            'coverCandidates' => $coverCandidates,
            'coverAuto' => $coverAuto,
            'directory_path' => $directory_path,
            'initial' => $initial,
            'isModal' => $isModal ?? false
        ])
    </div>
@endsection
