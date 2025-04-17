@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ $book->title }}</h1>

        <div class="row">
            <div class="col-md-4">
                <img src="{{ Storage::url($book->cover_image) }}" alt="{{ $book->title }}" class="img-fluid">
            </div>
            <div class="col-md-8">
                <p><strong>Author:</strong> {{ $book->author->name }}</p>
                <p><strong>Series:</strong> {{ $book->series ?? 'N/A' }}</p>
                <p><strong>Genre:</strong> {{ $book->genre->name }}</p>
                <p>{{ $book->description }}</p>

                <a href="{{ route('books.download', $book) }}" class="btn btn-primary">Download</a>

                <form action="{{ route('queue.add', $book) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-success">Add to Queue</button>
                </form>

                <hr>

                <h2>Reviews</h2>
                @foreach($book->reviews as $review)
                    <div class="card mb-3">
                        <div class="card-body">
                            <p>{{ $review->comment }}</p>
                            <p><strong>Age Rating:</strong> {{ $review->age_rating }}</p>
                            <p><strong>Content Rating:</strong> {{ $review->content_rating }}</p>
                        </div>
                    </div>
                @endforeach

                <form action="{{ route('reviews.store', $book) }}" method="POST">
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
