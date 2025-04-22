@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
    <div class="container">
        @if(empty($isModal))
            <h1>{{ isset($book) ? 'Edit Book' : 'Create New Book' }}</h1>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if(session('errors') && session('errors')->has('general'))
            <div class="alert alert-danger">{{ session('errors')->first('general') }}</div>
        @endif
        @if ($errors && $errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ isset($book) ? route('admin.books.update', $book) : route('admin.books.store') }}" method="POST" enctype="multipart/form-data" id="book-{{ isset($book) ? 'edit' : 'form' }}" class="mt-3">
            @csrf
            @if(isset($book))
                @method('PUT')
            @endif
            <button type="button" class="btn btn-info mb-3" id="autofill-btn">Autofill from Google Books</button>
            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', isset($book) ? $book->title : ($initial['title'] ?? null)) }}" required>
                @error('title')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label for="author_id">Author:</label>
                <select class="form-control @error('author_id') is-invalid @enderror" id="author_id" name="author_id" required>
                    <option value="">Select Author</option>
                    @foreach($authorList as $author)
                        <option value="{{ $author->id }}" @if(old('author_id', isset($book) ? $book->author_id : (request('author_id', $initial->author_id ?? null))) == $author->id) selected @endif>{{ $author->name }}</option>
                    @endforeach
                </select>
                @error('author_id')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label for="series_id">Series (Optional):</label>
                <select class="form-control @error('series_id') is-invalid @enderror" id="series_id" name="series_id">
                    <option value="">Select Series</option>
                    @foreach($seriesList as $series)
                        <option value="{{ $series->id }}" @if(old('series_id', isset($book) ? $book->series_id : ($initial->series_id ?? null)) == $series->id) selected @endif>{{ $series->name }}</option>
                    @endforeach
                </select>
                @error('series_id')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label for="series_number">Series Number (Optional):</label>
                <input type="number" class="form-control @error('series_number') is-invalid @enderror" id="series_number" name="series_number" value="{{ old('series_number', isset($book) ? $book->series_number : ($initial->seriesNumber ?? null)) }}">
                @error('series_number')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label for="genre_id">Genre:</label>
                <select class="form-control @error('genre_id') is-invalid @enderror" id="genre_id" name="genre_id" required>
                    <option value="">Select Genre</option>
                    @foreach($genreList as $genre)
                        <option value="{{ $genre->id }}" @if(old('genre_id', isset($book) ? $book->genre_id : ($initial->genre_id ?? null)) == $genre->id) selected @endif>{{ $genre->name }}</option>
                    @endforeach
                </select>
                @error('genre_id')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label for="published_year">Published Year (Optional):</label>
                <input type="number" class="form-control @error('published_year') is-invalid @enderror" id="published_year" name="published_year" min="1000" max="9999" value="{{ old('published_year', isset($book) ? $book->published_year : ($initial->published_year ?? null)) }}">
                @error('published_year')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            @php
                $dirPath = isset($book) ? $book->directory_path : ($directory_path ?? $initial->directory_path ?? null);
                $coverImg = isset($book) ? $book->cover_image : ($initial->cover_image ?? null);
            @endphp
            @if (!empty($coverAuto))
                <label>Current Cover:</label><br>
                <img src="{{ route('image.proxy', ['dir' => $dirPath, 'file' => $coverAuto]) }}" alt="Current Cover"
                    style="max-height: 120px; border:1px solid #ccc; margin-bottom: 10px;">
            @endif
            @if(empty($coverImg) && !empty($coverCandidates) && empty($coverAuto))
                <div class="mb-3" id="cover-candidates-group">
                    <label class="form-label">Select Cover Image:</label>
                    <div class="d-flex flex-wrap gap-3" id="cover-candidates-list">
                        @foreach($coverCandidates as $candidate)
                            <div class="text-center">
                                <label>
                                    <input type="radio" name="cover_image_candidate" value="{{ $candidate }}" @if(isset($biggestCover) && $biggestCover === $candidate) checked @endif>
                                    <br>
                                    <img src="{{ route('image.proxy', ['dir' => $dirPath ?? $book->directory_path ?? '', 'file' => $candidate]) }}"
                                        alt="{{ $candidate }}"
                                        style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">
                                </label>
                                <div style="font-size:12px;word-break:break-all;">{{ $candidate }}</div>
                            </div>
                        @endforeach
                        <!-- Google Books cover candidate will be injected here by JS -->
                    </div>
                </div>
            @endif

            <div id="google-books-matches-table-wrapper" style="display:none;">
                <label>Google Books: Select a Match</label>
                <table class="table table-bordered table-sm" id="google-books-matches-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Title</th>
                            <th>Authors</th>
                            <th>Year</th>
                            <th>Cover</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            @if ($coverImg)
                <label>Current Cover:</label><br>
                <img src="{{ route('image.proxy', ['dir' => dirname($coverImg), 'file' => basename($coverImg)]) }}"
                    alt="Current Cover" style="max-height: 120px; border:1px solid #ccc; margin-bottom: 10px;">
                <input type="hidden" name="cover_image_path" value="{{ $coverImg }}">
            @endif
            <div class="form-group">
                <label for="cover_image">Cover Image (Optional):</label>
                <input type="file" class="form-control-file @error('cover_image') is-invalid @enderror" id="cover_image" name="cover_image">
                @error('cover_image')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div id="cover-preview-group" style="display:none;">
                <label>Google Books Cover Preview:</label><br>
                <img id="cover-preview-img" src="" alt="Google Books Cover" style="max-height:120px; border:1px solid #ccc; margin-bottom:10px;">
            </div>
            <div class="form-group">
                <label for="description">Description (Optional):</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="3">{{ old('description', isset($book) ? $book->description : ($initial->description ?? null)) }}</textarea>
                @error('description')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label for="book_files">Directory Path:</label>
                <input type="text" class="form-control @error('directory_path') is-invalid @enderror" id="directory_path" name="directory_path" value="{{ old('directory_path', $dirPath) }}">
                @error('directory_path')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label>Type:</label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input @error('type') is-invalid @enderror" type="radio" name="type" id="ebook" value="ebook" @if(old('type', isset($book) ? $book->type : null) == 'ebook') checked @endif>
                    <label class="form-check-label" for="ebook">Ebook</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input @error('type') is-invalid @enderror" type="radio" name="type" id="audiobook" value="audiobook" @if(old('type', isset($book) ? $book->type : null) == null || old('type', isset($book) ? $book->type : null) == 'audiobook') checked @endif required>
                    <label class="form-check-label" for="audiobook">Audiobook</label>
                </div>
                @error('type')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary" id="modal-{{ isset($book) ? 'update' : 'create' }}-btn">{{ isset($book) ? 'Update' : 'Create' }}</button>
            @if(!empty($isModal))
                <button type="button" class="btn btn-secondary" id="modal-cancel-btn">Cancel</button>
            @else
                <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
            @endif
        </form>
    </div>
@endsection

@section('scripts')
    <script>
        function initSelect2Dropdowns() {
            if ($.fn.select2) {
                if ($('#author_id').length) {
                    $('#author_id').select2({
                        placeholder: 'Select Author',
                        allowClear: true,
                        width: '100%'
                    });
                }
                if ($('#series_id').length) {
                    $('#series_id').select2({
                        placeholder: 'Select Series',
                        allowClear: true,
                        width: '100%'
                    });
                }
            }
        }
        $(document).off('submit.bookModalAjax').on('submit.bookModalAjax', 'form[id^="book-"]', function(e) {
            var $form = $(this);
            var $modal = $form.closest('.modal');
            if ($modal.length) {
                e.preventDefault();
                var url = $form.attr('action');
                var method = 'POST';
                var formData = new FormData(this);
                formData.set('_method', 'PUT');
                var csrfToken = $form.find('input[name="_token"]').val();
                if (csrfToken) formData.append('_token', csrfToken);
                $.ajax({
                    url: url,
                    type: method,
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    success: function (data) {
                        var modalEl = $modal[0];
                        var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                        if (bsModal) bsModal.hide();
                        $(document).trigger('book:updated', data);
                    },
                    error: function (xhr) {
                        let msg = 'Failed to save book.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }
                        $form.find('#title').addClass('is-invalid');
                        $form.find('#title').after('<span class="invalid-feedback d-block">' + msg + '</span>');
                    }
                });
                return false;
            }
        });
        $(document).off('shown.bs.modal.bookSelect2').on('shown.bs.modal.bookSelect2', '.modal', function() {
            initSelect2Dropdowns();
        });
        $(function() {
            initSelect2Dropdowns();
        });

        $('#autofill-btn').on('click', function () {
            const title = $('#title').val();
            const authorSelect = $('#author_id');
            const authorName = authorSelect.length ? authorSelect.find('option:selected').text() : '';
            const series = $('#series_id option:selected').text() || '';
            const seriesNumber = $('#series_number').val() || '';
            if (!title || !authorName || authorName === 'Select Author') {
                $('#title').addClass('is-invalid');
                $('#title').after('<span class="invalid-feedback d-block">Title and author are required.</span>');
                return;
            }
            fetch(`{{ route('admin.books.googleBooks') }}?title=${encodeURIComponent(title)}&author=${encodeURIComponent(authorName)}&series=${encodeURIComponent(series)}&series_number=${encodeURIComponent(seriesNumber)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.match_type === 'close') {
                        if (data.published_year) {
                            $('#published_year').val(data.published_year);
                        }
                        if (data.description) {
                            $('#description').val(data.description);
                        }
                        if (data.cover_image_url) {
                            $('#google-books-candidate').remove();
                            const candidateHtml = `
                                <div class="text-center" id="google-books-candidate">
                                    <label>
                                        <input type="radio" name="cover_image_candidate" value="${data.cover_image_url}">
                                        <br>
                                        <img src="${data.cover_image_url}"
                                            alt="Google Books Cover"
                                            style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">
                                    </label>
                                    <div style="font-size:12px;word-break:break-all;">Google Books</div>
                                </div>
                            `;
                            if ($('#cover-candidates-list').length) {
                                $('#cover-candidates-list').append(candidateHtml);
                            } else {
                                $('#cover-preview-img').attr('src', data.cover_image_url);
                                $('#cover-preview-group').show();
                            }
                        }
                        let hidden = $('#cover_image_url');
                        if (!hidden.length) {
                            $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-{{ isset($book) ? 'edit' : 'form' }}');
                            hidden = $('#cover_image_url');
                        }
                        hidden.val(data.cover_image_url || '');
                        $('#google-books-matches-table-wrapper').hide();
                    } else if (data.match_type === 'list' && data.matches && data.matches.length > 0) {
                        const $tbody = $('#google-books-matches-table tbody');
                        $tbody.empty();
                        data.matches.forEach((match, idx) => {
                            const row = `<tr>
                                <td><input type="radio" name="google_books_match_select" value="${idx}"></td>
                                <td>${match.title}</td>
                                <td>${match.authors}</td>
                                <td>${match.published_year}</td>
                                <td>${match.cover_image_url ? `<img src='${match.cover_image_url}' style='max-height:60px;'>` : ''}</td>
                            </tr>`;
                            $tbody.append(row);
                        });
                        $('#google-books-matches-table-wrapper').show();
                        $('input[name="google_books_match_select"]').off('change').on('change', function() {
                            const idx = $(this).val();
                            const match = data.matches[idx];
                            if (match.published_year) {
                                $('#published_year').val(match.published_year);
                            }
                            if (match.description) {
                                $('#description').val(match.description);
                            }
                            if (match.cover_image_url) {
                                $('#google-books-candidate').remove();
                                const candidateHtml = `
                                    <div class="text-center" id="google-books-candidate">
                                        <label>
                                            <input type="radio" name="cover_image_candidate" value="${match.cover_image_url}">
                                            <br>
                                            <img src="${match.cover_image_url}"
                                                alt="Google Books Cover"
                                                style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">
                                        </label>
                                        <div style="font-size:12px;word-break:break-all;">Google Books</div>
                                    </div>
                                `;
                                if ($('#cover-candidates-list').length) {
                                    $('#cover-candidates-list').append(candidateHtml);
                                } else {
                                    $('#cover-preview-img').attr('src', match.cover_image_url);
                                    $('#cover-preview-group').show();
                                }
                            }
                            let hidden = $('#cover_image_url');
                            if (!hidden.length) {
                                $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-{{ isset($book) ? 'edit' : 'form' }}');
                                hidden = $('#cover_image_url');
                            }
                            hidden.val(match.cover_image_url || '');
                        });
                    } else {
                        $('#google-books-matches-table-wrapper').hide();
                    }
                })
                .catch(() => {
                    $('#title').addClass('is-invalid');
                    $('#title').after('<span class="invalid-feedback d-block">Failed to fetch book info.</span>');
                });
        });

        if (typeof window.bootstrap !== 'undefined' && ($('#modal-cancel-btn').length)) {
            $('#modal-cancel-btn').on('click', function () {
                var modalEl = $(this).closest('.modal')[0];
                var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
            });
        }

        $('#book-{{ isset($book) ? 'edit' : 'form' }}').off('submit').on('submit', function(e) {
            $(this).find('.is-invalid').removeClass('is-invalid');
            $(this).find('.invalid-feedback').remove();
            let hasError = false;
            const requiredFields = [
                { id: '#title', name: 'title', label: 'Title' },
                { id: '#author_id', name: 'author_id', label: 'Author' },
                { id: '#genre_id', name: 'genre_id', label: 'Genre' },
                { name: 'type', label: 'Type', radio: true }
            ];
            requiredFields.forEach(field => {
                if (field.radio) {
                    if (!$('input[name="type"]:checked').length) {
                        $('input[name="type"]').addClass('is-invalid');
                        $('input[name="type"]').last().parent().after('<span class="invalid-feedback d-block">Type is required.</span>');
                        hasError = true;
                    }
                } else {
                    const $el = $(field.id);
                    let value = $el.val();
                    if (!value || value === '' || value === 'Select ' + field.label) {
                        $el.addClass('is-invalid');
                        $el.after('<span class="invalid-feedback d-block">' + field.label + ' is required.</span>');
                        hasError = true;
                    }
                }
            });
            if (hasError) {
                e.preventDefault();
                const $firstError = $(this).find('.is-invalid').first();
                if ($firstError.length) {
                    $('html, body').animate({ scrollTop: $firstError.offset().top - 100 }, 300);
                }
                return false;
            }
        });
    </script>
@endsection
