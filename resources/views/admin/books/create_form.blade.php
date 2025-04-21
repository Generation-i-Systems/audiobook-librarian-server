@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
    <div class="container">
        @if(empty($isModal))
            <h1>Create New Book</h1>
        @endif

        <button type="button" class="btn btn-info mb-3" id="autofill-btn">Autofill from Google Books</button>
        <form action="{{ route('admin.books.store') }}" method="POST" enctype="multipart/form-data" id="book-form" class="mt-3">
            @csrf
            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" class="form-control" id="title" name="title" value="{{ old('title', $initial['title']) }}" required>
                @error('title')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label for="author_id">Author:</label>
                <select class="form-control" id="author_id" name="author_id" required>
                    <option value="">Select Author</option>
                    @foreach($authorList as $author)
                        <option value="{{ $author->id }}" @if(old('author_id', request('author_id', $initial->author_id)) == $author->id) selected @endif>{{ $author->name }}</option>
                    @endforeach
                </select>
                @error('author_id')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="series_id">Series (Optional):</label>
                <select class="form-control" id="series_id" name="series_id">
                    <option value="">Select Series</option>
                    @foreach($seriesList as $series)
                        <option value="{{ $series->id }}" @if(old('series_id', $initial->series_id) == $series->id) selected @endif>{{ $series->name }}</option>
                    @endforeach
                </select>
                @error('series_id')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="series_number">Series Number (Optional):</label>
                <input type="number" class="form-control" id="series_number" name="series_number" value="{{ old('series_number', $initial->seriesNumber) }}">
                @error('series_number')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="genre_id">Genre:</label>
                <select class="form-control" id="genre_id" name="genre_id" required>
                    <option value="">Select Genre</option>
                    @foreach($genreList as $genre)
                    <option value="{{ $genre->id }}" @if(old('genre_id') == $genre->id || $initial->genre_id == $genre->id) selected @endif>{{ $genre->name }}</option>
                    @endforeach
                </select>
                @error('genre_id')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            @if (!empty($coverAuto))
                <label>Current Cover:</label><br>
                <img src="{{ route('image.proxy', ['dir' => $directory_path, 'file' => $coverAuto]) }}" alt="Current Cover"
                    style="max-height: 120px; border:1px solid #ccc; margin-bottom: 10px;">
            @endif

            @if(empty(old('cover_image')) && !empty($coverCandidates) && empty($coverAuto))
                <div class="mb-3">
                    <label class="form-label">Select Cover Image:</label>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach($coverCandidates as $candidate)
                            <div class="text-center">
                                <label>
                                    <input type="radio" name="cover_image_candidate" value="{{ $candidate }}">
                                    <br>
                                    <img src="{{ route('image.proxy', ['dir' => $directory_path, 'file' => $candidate]) }}" alt="{{ $candidate }}" style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">
                                </label>
                                <div style="font-size:12px;word-break:break-all;">{{ $candidate }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="form-group">
                <label for="cover_image">Cover Image (Optional):</label>
                <input type="file" class="form-control-file" id="cover_image" name="cover_image">
                @error('cover_image')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="description">Description (Optional):</label>
                <textarea class="form-control" id="description" name="description" rows="3">{{ old('description', $initial->description) }}</textarea>
                @error('description')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="published_year">Published Year (Optional):</label>
                <input type="number" class="form-control" id="published_year" name="published_year" min="1000" max="9999" value="{{ old('published_year', $initial->published_year) }}">
                @error('published_year')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="book_files">Directory Path:</label>
                <input type="text" class="form-control" id="directory_path" name="directory_path" value="{{ old('directory_path', $initial->directory_path) }}">
                @error('directory_path')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            {{-- <div class="form-group">
                <label for="book_files">Book Files:</label>
                <input type="file" class="form-control-file" id="book_files" name="book_files[]" multiple required>
                @error('book_files')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div> --}}

            <div class="form-group">
                <label>Type:</label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="type" id="ebook" value="ebook" @if(old('type') == 'ebook') checked @endif>
                    <label class="form-check-label" for="ebook">Ebook</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="type" id="audiobook" value="audiobook" @if(old('type') == null || old('type') == 'audiobook') checked @endif required>
                    <label class="form-check-label" for="audiobook">Audiobook</label>
                </div>
                @error('type')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary">Create</button>
            @if(!empty($isModal))
                <button type="button" class="btn btn-secondary" id="modal-cancel-btn">Cancel</button>
            @else
                <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
            @endif
        </form>
    </div>
@endsection

@section('styles')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#author_id').select2({
                placeholder: 'Select Author',
                allowClear: true,
                width: '100%'
            });
            $('#series_id').select2({
                placeholder: 'Select Series',
                allowClear: true,
                width: '100%'
            });
            // If in modal, wire up cancel button to close the modal
            if (typeof window.bootstrap !== 'undefined' && $('#modal-cancel-btn').length) {
                $('#modal-cancel-btn').on('click', function() {
                    var modalEl = document.getElementById('addBookModal');
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) bsModal.hide();
                });
            }
        });
    </script>
    <script>
        $(document).ready(function() {
            $('#autofill-btn').on('click', function() {
                const title = $('#title').val();
                const authorSelect = $('#author_id');
                const authorName = authorSelect.length ? authorSelect.find('option:selected').text() : '';
                if (!title || !authorName || authorName === 'Select Author') {
                    alert('Please enter both title and author to autofill.');
                    return;
                }
                fetch("{{ route('admin.books.autofill') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                    },
                    body: JSON.stringify({ title: title, author: authorName })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                    } else {
                        if (data.published_year) {
                            $('#published_year').val(data.published_year);
                        }
                        if (data.description) {
                            $('#description').val(data.description);
                        }
                        if (data.cover_image_url) {
                            $('#cover-preview-img').attr('src', data.cover_image_url);
                            $('#cover-preview-group').show();
                        }
                    }
                })
                .catch(() => alert('Failed to fetch book info.'));
            });
        });
    </script>
@endsection
