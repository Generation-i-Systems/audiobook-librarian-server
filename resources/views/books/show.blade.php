@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ $book['title'] }}</h1>
        <p><strong>Path:</strong>
            {{ isset($book['directoryPath']) ? $book['directoryPath'] : (isset($book['path']) ? $book['path'] : 'N/A') }}
        </p>

        <div class="row">
            <div class="col-md-4">
                @php
                    $cover = isset($book['coverImage']) && $book['coverImage'] ? url('/cover/' . $book['coverImage']) : url('/images/placeholder.png');
                @endphp
                <img src="{{ $cover }}" alt="{{ $book['title'] }}" class="img-fluid">
            </div>
            <div class="col-md-8">
                <p><strong>Author:</strong>
                    @if(isset($book['authors']) && is_array($book['authors']) && !empty($book['authors']))
                        {{ implode(', ', $book['authors']) }}
                    @else
                        Unknown
                    @endif
                </p>
                @if(!empty($book['series']))
                    <p><strong>Series:</strong>
                        @foreach($book['series'] as $series)
                            {{ $series['seriesName'] }}@if(isset($series['number'])) (Book {{ $series['number'] }})@endif
                        @endforeach
                    </p>
                @endif
                <p><strong>Genre:</strong>
                    @if(isset($book['genres']) && is_array($book['genres']) && !empty($book['genres']))
                        {{ implode(', ', $book['genres']) }}
                    @else
                        Unknown
                    @endif
                </p>
                <p>{{ isset($book['description']) ? $book['description'] : 'No description available.' }}</p>

                <a href="{{ route('books.download', $book['id']) }}" class="btn btn-primary">Download</a>

                @auth
    @if(Auth::user()->is_admin)
        <a href="{{ route('admin.books.edit', $book['id']) }}" class="btn btn-sm btn-primary float-end ms-2">
            <i class="bi bi-pencil"></i> Edit
        </a>
    @endif
@endauth
                <hr>

                @if(!empty($relatedBooks))
                    <h2>Related Books</h2>
                    <div class="row">
                        @foreach($relatedBooks as $relatedBook)
                            <div class="col-md-4 mb-3">
                                <div class="card h-100">
                                    @php
                                        $relatedCover = isset($relatedBook['coverImage']) && $relatedBook['coverImage'] ?
                                            url('/cover/' . $relatedBook['coverImage']) :
                                            url('/images/placeholder.png');
                                    @endphp
                                    <img src="{{ $relatedCover }}" class="card-img-top" alt="{{ $relatedBook['title'] }}"
                                        style="height: 150px; object-fit: contain;">
                                    <div class="card-body">
                                        @if(auth()->check() && (auth()->user()->is_admin ?? false))
                                            <a href="{{ route('admin.books.edit', $book['id']) }}"
                                                class="btn btn-sm btn-primary float-end ms-2">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                        @endif
                                        <h5 class="card-title">{{ $relatedBook['title'] }}</h5>
                                        <a href="{{ route('books.show', $relatedBook['id']) }}"
                                            class="btn btn-sm btn-primary">View</a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <hr>
                @endif

                <h2>Reviews</h2>
                @if(isset($book['reviews']) && is_array($book['reviews']))
                    @foreach($book['reviews'] as $review)
                        <div class="card mb-3">
                            <div class="card-body">
                                <p>{{ isset($review['comment']) ? $review['comment'] : '' }}</p>
                                <p><strong>Age Rating:</strong> {{ isset($review['age_rating']) ? $review['age_rating'] : 'N/A' }}
                                </p>
                                <p><strong>Content Rating:</strong>
                                    {{ isset($review['content_rating']) ? $review['content_rating'] : 'N/A' }}</p>
                            </div>
                        </div>
                    @endforeach
                @else
                    <p>No reviews available.</p>
                @endif

                <form action="{{ route('reviews.store', $book['id']) }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <label for="comment">Add a Review:</label>
                        <textarea class="form-control" id="comment" name="comment" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="age_rating">Age Rating:</label>
                        <select class="form-control" id="age_rating" name="age_rating">
                            <option value="1">1 (Very Young)</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5 (Older)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="content_rating">Content Rating:</label>
                        <select class="form-control" id="content_rating" name="content_rating">
                            <option value="G">G</option>
                            <option value="PG">PG</option>
                            <option value="PG-13">PG-13</option>
                            <option value="R">R</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit Review</button>
                </form>

            </div>
        </div>
    </div>
@endsection
