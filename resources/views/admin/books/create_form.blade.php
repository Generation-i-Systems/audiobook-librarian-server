@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
    <div class="container">
        @include('admin.books.form', [
            'genreList' => $genreList,
            'coverCandidates' => $coverCandidates,
            'coverAuto' => $coverAuto,
            'directoryPath' => $directoryPath,
            'initial' => $initial,
            'isModal' => $isModal ?? false
        ])
            </div>
@endsection
