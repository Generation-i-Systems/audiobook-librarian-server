@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Create New Book</h1>

        <ul class="nav nav-tabs" id="bookCreateTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="regular-tab" data-bs-toggle="tab" data-bs-target="#regular" type="button" role="tab" aria-controls="regular" aria-selected="true">Regular Form</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="import-tab" data-bs-toggle="tab" data-bs-target="#import" type="button" role="tab" aria-controls="import" aria-selected="false">Import from Directory</button>
            </li>
        </ul>

        <div class="tab-content" id="bookCreateTabsContent">
            <div class="tab-pane fade show active" id="regular" role="tabpanel" aria-labelledby="regular-tab">
                <!-- Regular Form (unchanged) -->
                <form action="{{ route('admin.books.store') }}" method="POST" enctype="multipart/form-data" class="mt-3">
                    @csrf

                    <div class="form-group">
                        <label for="title">Title:</label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ old('title') }}" required>
                        @error('title')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="author_id">Author:</label>
                        <select class="form-control" id="author_id" name="author_id" required>
                            <option value="">Select Author</option>
                            @foreach($authors as $author)
                            <option value="{{ $author->id }}" @if(old('author_id') == $author->id) selected @endif>{{ $author->name }}</option>
                            @endforeach
                        </select>
                        @error('author_id')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="series">Series (Optional):</label>
                        <input type="text" class="form-control" id="series" name="series" value="{{ old('series') }}">
                         @error('series')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="genre_id">Genre:</label>
                        <select class="form-control" id="genre_id" name="genre_id" required>
                            <option value="">Select Genre</option>
                            @foreach($genres as $genre)
                            <option value="{{ $genre->id }}" @if(old('genre_id') == $genre->id) selected @endif>{{ $genre->name }}</option>
                        @endforeach
                        </select>
                         @error('genre_id')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="cover_image">Cover Image (Optional):</label>
                        <input type="file" class="form-control-file" id="cover_image" name="cover_image">
                          @error('cover_image')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="description">Description (Optional):</label>
                        <textarea class="form-control" id="description" name="description" rows="3">{{ old('description') }}</textarea>
                          @error('description')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                     <div class="form-group">
                        <label for="publication_date">Publication Date (Optional):</label>
                        <input type="date" class="form-control" id="publication_date" name="publication_date" value="{{ old('publication_date') }}">
                          @error('publication_date')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                     <div class="form-group">
                        <label for="book_files">Book Files:</label>
                        <input type="file" class="form-control-file" id="book_files" name="book_files[]" multiple required>
                          @error('book_files')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>

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
                    <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
                </form>
            </div>

            <div class="tab-pane fade" id="import" role="tabpanel" aria-labelledby="import-tab">
                <!-- Import from Directory -->
                <div class="mt-3">
                    @if(session('error'))
                    <div class="alert alert-danger">
                         {{ session('error') }}
                        </div>
                     @endif
                    <p id="current-path"></p>
                  <p id="path-history"></p>
                    <a href="#" id="back-link">Previous</a>
                </div>

                <div id="directory-browser">
                    <p>Loading directory...</p>
                </div>

                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

                <script>
                    $(document).ready(function() {
                        var storagePath = "{{ env('BOOK_STORAGE_PATH') }}";

                        function escapeHtml(text) {
                            var map = {
                                '&': '&amp;',
                                '<': '&lt;',
                                '>': '&gt;',
                                '"': '&quot;',
                                "'": '&#039;'
                            };
                            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
                        }

                           function updatePathDisplay(path) {
                            let relativePath = path.replace(storagePath, '').replace(/^\/|\/$/g, '');
                            let pathParts = relativePath.split('/');
                            let html = '';
                            let currentPath = storagePath;

                            for (let i = 0; i < pathParts.length; i++) {
                                if(pathParts[i] === "") continue; //Skip if it does not exist
                                currentPath += '/' + pathParts[i];
                                let escapedPart = escapeHtml(pathParts[i]);

                                html += '<a href="#" class="path-link" data-path="' + escapeHtml(currentPath) + '">' + escapedPart + ' /</a> ';
                            }
                             $('#current-path').text("Current Path: " + path);
                            $('#path-history').html(html);
                        }


                    function loadDirectory(path) {
                        updatePathDisplay(path);

                        $.ajax({
                            url: '{{ route('admin.directoryBrowser') }}',
                            type: 'GET',
                            data: { path: path },
                            success: function(data) {
                                var html = '<ul>';
                                $.each(data, function(index, item) {
                                    if (item.name.startsWith('.')) {
                                        return true;  // Skip hidden files/directories
                                    }
                                    if (item.type === 'directory') {
                                        let encodedPath = encodeURIComponent(item.path);  // Ensure path is URL-safe
                                        let addBookUrl = '{{ route("admin.books.create") }}?path=' + encodedPath; // Create the encoded URL

                                            html += '<li><a href="#" class="directory-link" data-path="' + item.path + '">' + item.name + '</a>';
                                             html += '<a href="' + addBookUrl + '" style="float: right; margin-left: 10px;">Add Book</a></li>';

                                    } else {
                                        html += '<li>' + item.name + '</li>';
                                    }
                                });
                                html += '</ul>';
                                $('#directory-browser').html(html);
                            attachDirectoryLinkHandlers();

                            },
                            error: function(xhr, status, error) {
                                $('#directory-browser').html('<p>Error loading directory: ' + error + '</p>');
                            }
                        });
                    }

                     function attachDirectoryLinkHandlers() {
                             $('.path-link').off('click').on('click', function(e) {
                                e.preventDefault();
                                 var newPath = $(this).data('path');
                                 loadDirectory(newPath);
                            });

                        $('#back-link').off('click').on('click', function(e) {
                            e.preventDefault();
                             let currentPath = $('#current-path').text().replace("Current Path: ", "");
                            let parts = currentPath.split('/');
                                    parts.pop(); // Remove the last part of the path
                            let newPath = parts.join('/');

                             if (!newPath) newPath = storagePath;
                             loadDirectory(newPath);
                        });
                    }
                  loadDirectory(storagePath);
                });
                </script>
            </div>
        </div>
    </div>
@endsection
