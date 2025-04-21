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

        <form action="{{ route('admin.books.update', $book) }}" method="POST" enctype="multipart/form-data"
            id="book-edit-form">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" class="form-control" id="title" name="title" value="{{ $book->title }}" required>
            </div>

            <div class="form-group">
                <label for="author_id">Author:</label>
                <select class="form-control" id="author_id" name="author_id" required>
                    @foreach($authorList as $author)
                        <option value="{{ $author->id }}" {{ $book->author_id == $author->id ? 'selected' : '' }}>
                            {{ $author->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="series_id">Series (Optional):</label>
                <select class="form-control" id="series_id" name="series_id">
                    <option value="">Select Series</option>
                    @foreach($seriesList as $series)
                        <option value="{{ $series->id }}" {{ $book->series_id == $series->id ? 'selected' : '' }}>
                            {{ $series->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="series_number">Series Number (Optional):</label>
                <input type="number" class="form-control" id="series_number" name="series_number"
                    value="{{ $book->series_number }}">
            </div>

            <div class="form-group">
                <label for="genre_id">Genre:</label>
                <select class="form-control" id="genre_id" name="genre_id">
                    @foreach($genreList as $genre)
                        <option value="{{ $genre->id }}" {{ $book->genre_id == $genre->id ? 'selected' : '' }}>{{ $genre->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="published_year">Published Year (Optional):</label>
                <input type="number" class="form-control" id="published_year" name="published_year" min="1000" max="9999"
                    value="{{ $book->published_year }}">
            </div>

            @if(empty($book->cover_image) && !empty($coverCandidates) && empty($coverAuto))
                <div class="mb-3">
                    <label class="form-label">Select Cover Image:</label>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach($coverCandidates as $candidate)
                            <div class="text-center">
                                <label>
                                    <input type="radio" name="cover_image_candidate" value="{{ $candidate }}">
                                    <br>
                                    <img src="{{ route('image.proxy', ['dir' => $book->directory_path, 'file' => $candidate]) }}"
                                        alt="{{ $candidate }}"
                                        style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">
                                </label>
                                <div style="font-size:12px;word-break:break-all;">{{ $candidate }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($book->cover_image)
                <label>Current Cover:</label> [[ {{ $book->cover_image }} ]]<br>
                <img src="{{ route('image.proxy', ['dir' => dirname($book->cover_image), 'file' => basename($book->cover_image)]) }}"
                    alt="Current Cover" style="max-height: 120px; border:1px solid #ccc; margin-bottom: 10px;">
                <input type="hidden" name="cover_image_path" value="{{ $book->cover_image }}">
            @endif
            <label for="cover_image">Cover Image (Optional):</label>
            <input type="file" class="form-control-file" id="cover_image" name="cover_image">

            <button type="button" class="btn btn-info mb-3" id="autofill-btn">Autofill from Google Books</button>

            <div class="form-group" id="cover-preview-group" style="display:none;">
                <label>Cover Preview:</label><br>
                <img id="cover-preview-img" src="" alt="Cover Preview"
                    style="max-height: 120px; border:1px solid #ccc; margin-bottom: 10px;">
            </div>

            <div class="form-group">
                <label for="description">Description (Optional):</label>
                <textarea class="form-control" id="description" name="description"
                    rows="3">{{ $book->description }}</textarea>
            </div>

            <div class="form-group">
                <label for="type">Type:</label>
                <select class="form-control" id="type" name="type">
                    <option value="ebook" {{ $book->type == 'ebook' ? 'selected' : '' }}>Ebook</option>
                    <option value="audiobook" {{ $book->type == 'audiobook' ? 'selected' : '' }}>Audiobook</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Update</button>
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#autofill-btn').on('click', function () {
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
                            // Set a hidden field for cover_image_url so it is submitted
                            let hidden = $('#cover_image_url');
                            if (!hidden.length) {
                                $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-edit-form');
                                hidden = $('#cover_image_url');
                            }
                            hidden.val(data.cover_image_url || '');
                        }
                    })
                    .catch(() => alert('Failed to fetch book info.'));
            });
            if ($.fn.select2) {
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
            }
            if (typeof window.bootstrap !== 'undefined' && $('#modal-cancel-btn').length) {
                $('#modal-cancel-btn').on('click', function () {
                    var modalEl = document.getElementById('addBookModal');
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) bsModal.hide();
                });
            }
        });
    </script>
@endsection
