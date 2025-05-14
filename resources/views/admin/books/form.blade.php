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
            @if(isset($book) && $book->directory_path)
                <input type="hidden" name="original_directory_path" value="{{ $book->directory_path }}">
            @elseif(old('directory_path'))
                <input type="hidden" name="original_directory_path" value="{{ old('directory_path') }}">
            @endif
            <button type="button" class="btn btn-info mb-3" id="autofill-btn"><i class="fas fa-search"></i> Autofill from Google Books</button>
            <div class="mb-3">
                <label for="title">Title:</label>
                <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', isset($book) ? $book->title : ($initial['title'] ?? null)) }}" required>
                @error('title')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
            <div class="mb-3">
                <label for="author-select" class="form-label">Author</label>
                <select id="author-select" name="author_id" class="form-control" data-url="{{ route('admin.authors.ajax') }}" data-selected="{{ old('author_id', $book->author_id ?? ($initial['author_id'] ?? '')) }}" data-selected-name="{{ old('author_name', $book->author->name ?? ($initial['author_name'] ?? '')) }}">
                    @if(old('author_id', $book->author_id ?? ($initial['author_id'] ?? '')))
                        <option value="{{ old('author_id', $book->author_id ?? ($initial['author_id'] ?? '')) }}" selected>{{ old('author_name', $book->author->name ?? ($initial['author_name'] ?? '')) }}</option>
                    @endif
                </select>
            </div>
            <div class="mb-3">
                <label for="series-select" class="form-label">Series</label>
                <select id="series-select" name="series_id" class="form-control" data-url="{{ route('admin.series.ajax') }}" data-selected="{{ old('series_id', $book->series_id ?? ($initial['series_id'] ?? '')) }}" data-selected-name="{{ old('series_name', $book->series->name ?? ($initial['series_name'] ?? '')) }}">
                    @if(old('series_id', $book->series_id ?? ($initial['series_id'] ?? '')))
                        <option value="{{ old('series_id', $book->series_id ?? ($initial['series_id'] ?? '')) }}" selected>{{ old('series_name', $book->series->name ?? ($initial['series_name'] ?? '')) }}</option>
                    @endif
                </select>
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
                        <option value="{{ $genre->id }}" @if(old('genre_id', isset($book) ? $book->genre_id : ($initial['genre_id'] ?? null)) == $genre->id) selected @endif>{{ $genre->name }}</option>
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
            <div class="form-group mb-3">
                <label>Directory Files</label>
                <div class="d-flex align-items-center mb-2">
                    <input type="checkbox" id="show-all-files-checkbox" class="form-check-input me-2">
                    <label for="show-all-files-checkbox" class="form-check-label me-3">Show all files</label>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="show-files-btn">Show Files</button>
                </div>
                <div id="directory-files-list" style="max-width:400px; max-height:220px; overflow-y:auto; border:1px solid #ccc; border-radius:4px; background:#fafbfc; padding:8px; display:none;">
                    <span class="text-muted">No files loaded yet.</span>
                </div>
            </div>
            @php
                $dirPath = isset($book) ? $book->directory_path : ($directory_path ?? $initial->directory_path ?? null);
                $coverImg = isset($book) ? $book->cover_image : ($initial->cover_image ?? null);
                $coverAuto = $coverAuto ?? null;
                $coverCandidates = $coverCandidates ?? [];
                $coverOptions = [];
                $addedCovers = [];
                // Add current cover if available
                if (!empty($coverImg) && !in_array($coverImg, $addedCovers)) {
                    $coverOptions[] = [
                        'type' => 'current',
                        'value' => $coverImg,
                        'src' => route('image.proxy', ['dir' => '.', 'file' => $coverImg]),
                        'label' => 'Current Cover',
                    ];
                    $addedCovers[] = $coverImg;
                }
                // Add Google Books cover if available
                if (!empty($coverAuto) && !in_array($coverAuto, $addedCovers)) {
                    $coverOptions[] = [
                        'type' => 'google',
                        'value' => $coverAuto,
                        'src' => route('image.proxy', ['dir' => $dirPath, 'file' => $coverAuto]),
                        'label' => 'Google Books',
                    ];
                    $addedCovers[] = $coverAuto;
                }
                // Add other candidates
                if (!empty($coverCandidates)) {
                    foreach ($coverCandidates as $candidate) {
                        if (!in_array($candidate, $addedCovers)) {
                            $coverOptions[] = [
                                'type' => 'candidate',
                                'value' => $candidate,
                                'src' => route('image.proxy', ['dir' => $dirPath, 'file' => $candidate]),
                                'label' => 'Candidate',
                            ];
                            $addedCovers[] = $candidate;
                        }
                    }
                }
            @endphp

            @if (!empty($coverOptions))
                <div class="mb-3" id="cover-candidates-group">
                    <label class="form-label">Select Cover Image:</label>
                    <div class="d-flex flex-wrap gap-3" id="cover-candidates-list">
                        @foreach($coverOptions as $option)
                            <div class="text-center">
                                <label>
                                    <input type="radio" name="cover_image_candidate" value="{{ $option['value'] }}"
                                        @if((isset($biggestCover) && $biggestCover === $option['value']) || (empty($biggestCover) && isset($coverImg) && $coverImg === $option['value'])) checked @endif>
                                    <br>
                                    <img src="{{ $option['src'] }}"
                                        alt="{{ $option['label'] }}"
                                        style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">
                                </label>
                                <div style="font-size:12px;word-break:break-all;">{{ $option['label'] }}<br>{{ $option['value'] }}</div>
                            </div>
                        @endforeach
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

            <div class="form-group">
                <label for="cover_image">Cover Image (Optional):</label>
                <input type="file" class="form-control-file @error('cover_image') is-invalid @enderror" id="cover_image" name="cover_image">
                @error('cover_image')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
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
            <button type="submit" class="btn btn-primary" id="modal-{{ isset($book) ? 'update' : 'create' }}-btn">{{ isset($book) ? 'Update' : 'Create' }}</button>
            @if(!empty($isModal))
                <button type="button" class="btn btn-secondary" id="modal-cancel-btn">Cancel</button>
            @else
                <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
            @endif
        </form>
    </div>
    <script>
        (function() {
        window.googleBooksMoreMatches = false;
        window.googleBooksMatchLimit = 10;

        function initTomSelect(selector) {
            let el = document.querySelector(selector);
            if (!el) return;
            let ajaxUrl = el.getAttribute('data-url');
            let selected = el.getAttribute('data-selected');
            let selectedName = el.getAttribute('data-selected-name');
            new TomSelect(selector, {
                create: true,
                persist: false,
                valueField: 'id',
                labelField: 'name',
                searchField: 'name',
                maxOptions: 20,
                loadThrottle: 300,
                load: function(query, callback) {
                    let url = ajaxUrl + '?q=' + encodeURIComponent(query || '');
                    fetch(url)
                        .then(response => response.json())
                        .then(json => {
                            callback(json.data || []);
                        })
                        .catch(() => {
                            callback();
                        });
                },
                onFocus: function() {
                    this.refreshOptions(false);
                },
                onInitialize: function() {
                    if (selected && selectedName) {
                        this.addOption({ id: selected, name: selectedName });
                        this.setValue(selected, true);
                    }
                }
            });
        }
        initTomSelect('#author-select');
        initTomSelect('#series-select');

        // Autofill handler - ensure it is always bound after TomSelect
        function bindAutofillBtn() {
            $('#autofill-btn').off('click').on('click', function () {
                const title = $('#title').val();
                // Robust author name detection: TomSelect or fallback
                let authorName = '';
                const authorSelectEl = $('#author-select')[0];
                if (authorSelectEl && authorSelectEl.tomselect) {
                    const authorId = authorSelectEl.tomselect.getValue();
                    // Try to get the label from TomSelect's options
                    const tomSelect = authorSelectEl.tomselect;
                    let option = tomSelect.options[authorId];
                    authorName = option ? option.name : authorId;
                } else {
                    authorName = $('#author-select option:selected').text() || '';
                }
                const series = $('#series-select option:selected').text() || '';
                const seriesNumber = $('#series_number').val() || '';
                if (!title || !authorName || authorName === '') {
                    $('#title').addClass('is-invalid');
                    $('#title').after('<span class="invalid-feedback d-block">Title and author are required.</span>');
                    return;
                }
                $('#autofill-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Searching...');
                fetch(`{{ route('admin.books.googleBooks') }}?title=${encodeURIComponent(title)}&author=${encodeURIComponent(authorName)}&series=${encodeURIComponent(series)}&series_number=${encodeURIComponent(seriesNumber)}&limit=${window.googleBooksMatchLimit}${window.googleBooksMoreMatches ? '&more=1' : ''}`)
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
                                const proxiedUrl = googleBooksProxyUrl(data.cover_image_url);
                                $('#cover-preview-img').attr('src', proxiedUrl);
                                $('#cover-preview-group').show();
                            }
                            let hidden = $('#cover_image_url');
                            if (!hidden.length) {
                                $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-{{ isset($book) ? 'edit' : 'form' }}');
                                hidden = $('#cover_image_url');
                            }
                            hidden.val(data.cover_image_url || '');
                            $('#google-books-matches-table-wrapper').hide();
                            // Change button to get more matches
                            $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Get More Matches');
                            window.googleBooksMoreMatches = true;
                            window.googleBooksMatchLimit = 10;
                        } else if (data.match_type === 'list' && data.matches && data.matches.length > 0) {
                            // Show table of matches
                            const $tbody = $('#google-books-matches-table tbody');
                            $tbody.empty();
                            data.matches.forEach((match, idx) => {
                                const row = `<tr>
                                    <td><input type="radio" name="google_books_match_select" value="${idx}"></td>
                                    <td>${match.title}</td>
                                    <td>${match.authors}</td>
                                    <td>${match.published_year}</td>
                                    <td>${match.cover_image_url ? `<img src='${googleBooksProxyUrl(match.cover_image_url)}' style='max-height:60px;'>` : ''}</td>
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
                                    const proxiedUrl = googleBooksProxyUrl(match.cover_image_url);
                                    $('#google-books-candidate').remove();
                                    var candidateHtml = '<div class="text-center" id="google-books-candidate">' +
                                        '<label>' +
                                            '<input type="radio" name="cover_image_candidate" value="' + match.cover_image_url + '">' +
                                            '<br>' +
                                            '<img src="' + proxiedUrl + '" alt="Google Books Cover" style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">' +
                                        '</label>' +
                                        '<div style="font-size:12px;word-break:break-all;">Google Books</div>' +
                                    '</div>';

                                    if ($('#cover-candidates-list').length) {
                                        $('#cover-candidates-list').append(candidateHtml);
                                    } else {
                                        $('#cover-preview-img').attr('src', proxiedUrl);
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
                            // Handle button for more matches
                            if (data.maxed || window.googleBooksMatchLimit >= 40) {
                                $('#autofill-btn').prop('disabled', true).html('<i class="fas fa-check"></i> All Results Shown');
                            } else {
                                $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Get More Matches');
                                window.googleBooksMoreMatches = true;
                                window.googleBooksMatchLimit += 10;
                            }
                        } else {
                            $('#google-books-matches-table-wrapper').hide();
                            $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                            window.googleBooksMoreMatches = false;
                            window.googleBooksMatchLimit = 10;
                        }
                    })
                    .catch(() => {
                        $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                        $('#title').addClass('is-invalid');
                        $('#title').after('<span class="invalid-feedback d-block">Failed to fetch book info.</span>');
                    });
            });
        }
        // Bind after TomSelect initialized
        bindAutofillBtn();
        // If TomSelect is re-initialized dynamically, you must re-bind this as well.

        $(document).off('submit.bookModalAjax').on('submit.bookModalAjax', 'form[id^="book-"]', function(e) {
            var $form = $(this);
            var $modal = $form.closest('.modal');
            if ($modal.length) {
                e.preventDefault();
                var url = $form.attr('action');
                var method = 'POST';
                var formData = new FormData(this);
                formData.set('_method', 'POST');
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
                { id: '#author-select', name: 'author_id', label: 'Author' },
                { id: '#genre_id', name: 'genre_id', label: 'Genre' },
            ];
            requiredFields.forEach(field => {
                const $el = $(field.id);
                let value = $el.val();
                if (!value || value === '' || value === 'Select ' + field.label) {
                    $el.addClass('is-invalid');
                    $el.after('<span class="invalid-feedback d-block">' + field.label + ' is required.</span>');
                    hasError = true;
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

        $(document).on('change', 'input[name="google_books_match_select"]', function() {
            const idx = $(this).val();
            let matches = window.googleBooksMatches || [];
            if (!matches.length && window.lastGoogleBooksAjaxData && window.lastGoogleBooksAjaxData.matches) {
                matches = window.lastGoogleBooksAjaxData.matches;
            }
            const match = matches[idx];
            if (match) {
                if (match.title) {
                    $('#title').val(match.title);
                }
                if (match.authors) {
                    let authorSelect = $('#author-select')[0].tomselect;
                    if (authorSelect) {
                        let authorName = match.authors.split(',')[0].trim();
                        authorSelect.addOption({ id: authorName, name: authorName });
                        authorSelect.setValue(authorName, true);
                    } else {
                        $('#author-select').val(match.authors.split(',')[0].trim());
                    }
                }
            }
        });

        function setGoogleBooksMatches(matches) {
            window.googleBooksMatches = matches;
        }

        function fetchDirectoryFiles() {
            const dirPath = $("#directory_path").val() || $("input[name='directory_path']").val() || $("input[name='original_directory_path']").val();
            const showAll = $("#show-all-files-checkbox").is(":checked") ? 1 : 0;
            if (!dirPath) {
                $('#directory-files-list').html('<span class="text-muted">-- No directory selected --</span>');
                return;
            }
            $('#show-files-btn').prop('disabled', true).text('Loading...');
            $.ajax({
                url: "{{ route('admin.books.filesAjax') }}",
                data: {directory: dirPath, show_all: showAll},
                success: function(res) {
                    let html = '';
                    if (res.files && res.files.length) {
                        html += '<ul class="list-unstyled mb-0">';
                        res.files.forEach(function(file) {
                            html += `<li style=\"word-break:break-all;padding:2px 0;\">${file}</li>`;
                        });
                        html += '</ul>';
                    } else {
                        html = '<span class="text-muted">(No files found)</span>';
                    }
                    $('#directory-files-list').html(html);
                },
                complete: function() {
                    $('#show-files-btn').prop('disabled', false).text('Hide Files');
                }
            });
        }
        let filesBoxVisible = false;
        $('#show-files-btn').on('click', function() {
            filesBoxVisible = !filesBoxVisible;
            if (filesBoxVisible) {
                $('#directory-files-list').slideDown(120);
                $(this).text('Hide Files');
                fetchDirectoryFiles();
            } else {
                $('#directory-files-list').slideUp(120);
                $(this).text('Show Files');
            }
        });
        $('#show-all-files-checkbox').on('change', function() {
            if (filesBoxVisible) fetchDirectoryFiles();
        });
        $('#directory_path, input[name="directory_path"], input[name="original_directory_path"]').on('change', function() {
            if (filesBoxVisible) fetchDirectoryFiles();
        });

        // Helper for proxying Google Books cover images
        function googleBooksProxyUrl(url) {
            if (url && url.match(/^https?:\/\/books\.google\.com\//)) {
                return '/google-books-cover/' + btoa(url);
            }
            return url;
        }
    })();
    </script>
@endsection
