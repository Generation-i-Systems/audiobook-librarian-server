@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Create New Book</h1>

        <ul class="nav nav-tabs" id="bookCreateTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="regular-tab" data-bs-toggle="tab" data-bs-target="#regular"
                    type="button" role="tab" aria-controls="regular" aria-selected="true">Regular Form</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="import-tab" data-bs-toggle="tab" data-bs-target="#import" type="button"
                    role="tab" aria-controls="import" aria-selected="false">Import from Directory</button>
            </li>
        </ul>

        <div class="tab-content" id="bookCreateTabsContent">
            <div class="tab-pane fade show active" id="regular" role="tabpanel" aria-labelledby="regular-tab">
                @include('admin.books.form', [
                    'genreList' => $genreList ?? [],
                    'coverCandidates' => $coverCandidates ?? [],
                    'coverAuto' => $coverAuto ?? null,
                    'directoryPath' => $directoryPath ?? null,
                    'isModal' => $isModal ?? false
                ])
            </div>

            <div class="tab-pane fade" id="import" role="tabpanel" aria-labelledby="import-tab">
                <!-- Import from Directory -->
                @if(session('error'))
                    <div class="alert alert-danger">
                        {{ session('error') }}
                    </div>
                @endif

                <div id="directory-browser" class="mt-3">
                    <p>Loading directory...</p>
                </div>

                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
                    integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
                    crossorigin="anonymous" referrerpolicy="no-referrer" />

                <script>
                    $(document).ready(function () {
                        var storagePath = "{{ config('app.book_root') }}";

                        function escapeHtml(text) {
                            var map = {
                                '&': '&amp;',
                                '<': '&amp;',
                                '>': '&gt;',
                                '"': '&quot;',
                                "'": '&#039;'
                            };
                            return text.replace(/[&<>"']/g, function (m) { return map[m]; });
                        }

                        function updatePathDisplay(path) {
                            let relativePath = path.replace(storagePath, '').replace(/^\/|\/$/g, '');
                            let pathParts = relativePath.split('/');
                            let html = '';

                            for (let i = 0; i < pathParts.length; i++) {
                                if (pathParts[i] === "") continue;
                                let escapedPart = escapeHtml(pathParts[i]);
                            }
                        }

                        function loadDirectory(path) {
                            //This is the first section for code validation
                            if (path != "") {
                            } else {
                                alert("This is the first action that has failed. Please make it so that you are testing this");
                            }

                            $.ajax({
                                url: '{{ route("admin.directoryBrowser") }}',
                                type: 'GET',
                                data: {
                                    path: path
                                },
                                success: function (data) {
                                    var html = '<ul>';
                                    $.each(data, function (index, item) {
                                        if (item.type === 'directory') {
                                            html += '<li>';
                                            if (item.edit) {
                                                html += '<a href="' + item.edit + '" style="float: right; margin-left: 10px;" title="Edit"><i class="fas fa-pencil-alt"></i></a>';
                                            } else if (item.create) {
                                                html += '<a href="' + item.create + '" style="float: right; margin-left: 10px;">Add Book</a>';
                                            }
                                            html += '</li>';
                                        }
                                        else {
                                            html += '<li>' + item.name + '</li>';
                                        }
                                    });
                                    html += '</ul>';
                                    $('#directory-browser').html(html);

                                },
                                error: function (xhr, status, error) {
                                    $('#directory-browser').html('<p>Error loading directory: ' + error + '</p>');
                                }
                            });
                        }

                        loadDirectory(storagePath);
                    });

                    function attachDirectoryLinkHandlers(basepath) {
                        updatePathDisplay(basepath);

                        $('.path-link').off('click').on('click', function (e) {
                            e.preventDefault();
                            var newPath = $(this).data('path');
                            loadDirectory(newPath);
                        });
                    }
                </script>
            </div>
        </div>
    </div>

@vite(['resources/js/admin/book-create.js'])
@endsection
