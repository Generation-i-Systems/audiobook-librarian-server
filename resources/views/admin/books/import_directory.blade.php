@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Import from Directory</h1>
        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <div id="directory-path-breadcrumbs"></div>

        <div id="directory-browser" class="mt-3">
            <p>Loading directory...</p>
        </div>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
            integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
            crossorigin="anonymous" referrerpolicy="no-referrer" />

        <script>
            $(document).ready(function () {
                const directoryBrowser = $('#directory-browser');

                function escapeHtml(text) {
                    var map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    };
                    return text.replace(/[&<>"']/g, function (m) { return map[m]; });
                }

                function updateBreadcrumbs(path) {
                    let pathParts = path.split('/');
                    var html = '';

                    if (path !== '') {
                        html += '<li class="breadcrumb-item"><a href="#" class="breadcrumb-link" data-path="">Root</a></li>';
                    }

                    for (let i = 0; i < pathParts.length; i++) {
                        if (pathParts[i] === "" || pathParts[i] === null || pathParts[i] === undefined || pathParts[i] == '/') continue;
                        let crumbPath = '';

                        for (let j = 0; j <= i; j++) {
                            if (pathParts[j] === "" || pathParts[j] === null || pathParts[j] === undefined || pathParts[j] == '/') continue;
                            crumbPath += "/" + pathParts[j];
                        }

                        html += '<li class="breadcrumb-item"><a href="#" class="breadcrumb-link" data-path="' + crumbPath + '">' + pathParts[i] + '</a></li>';
                    }
                    $('#directory-path-breadcrumbs').html(html);

                }
                function loadDirectory(path) {
                    $.ajax({
                        url: '{{ route("admin.directoryBrowser") }}',
                        type: 'GET',
                        data: { path: path },
                        success: function (data) {
                            let html = '<ul>';
                            if (path !== '') {
                                html += '<li><a href="#" onclick="history.back()">Previous</a></li>';
                            }
                            $.each(data, function (index, item) {
                                if (item.type === 'directory') {
                                    let encodedPath = encodeURIComponent(item.path);
                                    let addBookUrl = '{{ route("admin.books.create") }}?path=' + encodedPath;

                                    html += '<li><a href="#" class="directory-link" data-path="' + item.path + '">' + item.name + '</a>';
                                    if (item.edit) {
                                        html += '<a href="' + item.edit + '" style="float: right; margin-left: 10px;">Edit Book</a></li>';
                                    } else if (item.create) {
                                        html += '<a href="' + addBookUrl + '" style="float: right; margin-left: 10px;">Add Book</a></li>';
                                    }
                                } else {
                                    html += '<li>' + item.name + '</li>';
                                }
                            });
                            html += '</ul>';
                            updateBreadcrumbs(path);
                            $('#directory-browser').html(html);
                            directoryBrowser.html(html);
                            attachDirectoryLinkHandlers(path)
                        },
                        error: function (xhr, status, error) {
                            $('#directory-browser').html('<p>Error loading directory: ' + error + '</p>');
                        }
                    });
                }
                function attachDirectoryLinkHandlers(path) {
                    $(".directory-link").on("click", function (e) {
                        e.preventDefault();
                        const newPath = $(this).data('path');
                        history.pushState({ path: newPath }, null, null);
                        loadDirectory(newPath);
                    });
                    $(".breadcrumb-link").on("click", function (e) {
                        e.preventDefault();
                        const newPath = $(this).data('path');
                        history.pushState({ path: newPath }, null, null);
                        loadDirectory(newPath);
                    });
                }

                // Initial Load
                loadDirectory('');

                window.addEventListener('popstate', function (event) {
                    loadDirectory(event.state ? event.state.path : '');
                });
            });

        </script>
    </div>
@endsection
