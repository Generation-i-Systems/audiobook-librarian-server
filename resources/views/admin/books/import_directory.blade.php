@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Import from Directory</h1>
        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <div id="directory-path-breadcrumbs" class="breadcrumb-container mb-3"></div>

        <div class="mb-3">
            <button id="bulk-import-btn" class="btn btn-primary">
                <i class="fas fa-cloud-upload-alt"></i> Bulk Import Books Here
            </button>
            <span id="bulk-import-status" class="ms-3"></span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle" id="directory-browser-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="directory-browser"></tbody>
            </table>
        </div>
        <style>
            .import-list ul {
                list-style-type: none;
                padding-left: 0;
            }

            .import-list li {
                padding: 2px 0;
                border-bottom: 1px solid #f0f0f0;
            }

            .import-list li:last-child {
                border-bottom: none;
            }

            .import-list a.directory-link {
                font-weight: 500;
            }

            .import-list .fa-arrow-left {
                margin-right: 5px;
            }

            .breadcrumb-container {
                padding: 8px 0 0 0;
            }

            .breadcrumb {
                display: flex;
                flex-wrap: nowrap;
                list-style: none;
                padding: 0;
                margin: 0;
                background: none;
            }

            .breadcrumb-item {
                white-space: nowrap;
                margin-right: 0.2rem;
            }

            .breadcrumb-item+.breadcrumb-item:before {
                content: "/";
                margin-right: 0.2rem;
                color: #888;
            }
        </style>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
            integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
            crossorigin="anonymous" referrerpolicy="no-referrer" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

        <!-- Add Book Modal -->
        <div class="modal fade" id="addBookModal" tabindex="-1" aria-labelledby="addBookModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addBookModalLabel">Add Book</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="addBookModalBody">
                        <div class="text-center">
                            <div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Book Modal -->
        <div class="modal fade" id="editBookModal" tabindex="-1" aria-labelledby="editBookModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editBookModalLabel">Edit Book</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0" id="edit-book-modal-body"></div>
                </div>
            </div>
        </div>

        <script>
            $(document).ready(function () {
                const directoryBrowser = $('#directory-browser');
                let currentPath = '';

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
                    var html = '<ul class="breadcrumb">';
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
                    html += '</ul>';
                    $('#directory-path-breadcrumbs').html(html);
                }
                function loadDirectory(path) {
                    currentPath = path;
                    // Update the URL so reload stays on the same directory
                    if (window.location.pathname + window.location.search !== updateUrlForPath(path)) {
                        window.history.replaceState({ path: path }, '', updateUrlForPath(path));
                    }
                    $.ajax({
                        url: '{{ route("admin.directoryBrowser") }}',
                        type: 'GET',
                        data: { path: path },
                        success: function (data) {
                            let html = '';
                            if (path !== '') {
                                html += '<tr><td colspan="3"><a href="#" class="previous-link"><i class="fas fa-arrow-left"></i> Previous</a></td></tr>';
                            }
                            $.each(data, function (index, item) {
                                html += '<tr>';
                                html += '<td>';
                                if (item.type === 'directory') {
                                    html += '<a href="#" class="directory-link" data-path="' + item.path + '">' + escapeHtml(item.name) + '</a>';
                                } else {
                                    html += escapeHtml(item.name);
                                }
                                html += '</td>';
                                html += '<td>' + escapeHtml(item.type.charAt(0).toUpperCase() + item.type.slice(1)) + '</td>';
                                html += '<td>';
                                if (item.type === 'directory') {
                                    let encodedPath = encodeURIComponent(item.path);
                                    let addBookUrl = '{{ route("admin.books.create") }}?path=' + encodedPath;
                                    if (item.edit) {
                                        html += '<a href="#" class="btn btn-sm btn-outline-primary me-1 open-edit-book-modal" data-url="' + item.edit + '?modal=1' + '">Edit Book</a>';
                                    } else if (item.create) {
                                        html += '<a href="#" class="btn btn-sm btn-outline-success open-add-book-modal" data-url="' + addBookUrl + '">Add Book</a>';
                                    }
                                }
                                html += '</td>';
                                html += '</tr>';
                            });
                            updateBreadcrumbs(path);
                            $('#directory-browser').html(html);
                            attachDirectoryLinkHandlers(path)
                        },
                        error: function (xhr, status, error) {
                            $('#directory-browser').html('<p>Error loading directory: ' + error + '</p>');
                        }
                    });
                }
                function updateUrlForPath(path) {
                    let url = window.location.pathname.split('?')[0];
                    if (path && path !== '') {
                        url += '?path=' + encodeURIComponent(path);
                    }
                    return url;
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
                    $(".previous-link").on("click", function (e) {
                        e.preventDefault();
                        history.back();
                    });
                    $(".open-add-book-modal").on("click", function (e) {
                        e.preventDefault();
                        const url = $(this).data('url');
                        const modal = new bootstrap.Modal(document.getElementById('addBookModal'));
                        $('#addBookModalBody').html('<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
                        $.get(url, function (response) {
                            $('#addBookModalBody').html(response);
                            // Unbind any previous submit handlers first, then bind
                            $('#addBookModalBody form').off('submit').on('submit', function (ev) {
                                ev.preventDefault();
                                var formData = new FormData(this);
                                var action = $(this).attr('action');
                                var method = $(this).find('input[name="_method"]').val() || $(this).attr('method');
                                $.ajax({
                                    url: action,
                                    type: method || 'POST',
                                    data: formData,
                                    processData: false,
                                    contentType: false,
                                    success: function (data) {
                                        var modalEl = document.getElementById('addBookModal');
                                        var bsModal = bootstrap.Modal.getInstance(modalEl);
                                        bsModal.hide();
                                        loadDirectory(path);
                                    },
                                    error: function (xhr) {
                                        $('#addBookModalBody').html(xhr.responseText);
                                    }
                                });
                            });
                        });
                        modal.show();
                    });
                    $(document).on('click', '.open-edit-book-modal', function(e) {
                        e.preventDefault();
                        var url = $(this).data('url');
                        $('#edit-book-modal-body').html('<div class="text-center p-4"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
                        var modal = new bootstrap.Modal(document.getElementById('editBookModal'));
                        modal.show();
                        $.get(url, function(data) {
                            $('#edit-book-modal-body').html(data);
                        });
                    });
                    $(document).on('click', '#bulk-import-btn', function(e) {
                        e.preventDefault();
                        $('#bulk-import-status').text('Starting bulk import...');
                        $.ajax({
                            url: '{{ route("admin.books.bulkImport") }}',
                            type: 'POST',
                            data: { dir: currentPath, _token: '{{ csrf_token() }}' },
                            success: function (data) {
                                $('#bulk-import-status').text(data.message);
                            },
                            error: function (xhr) {
                                let msg = 'Error: ' + (xhr.responseJSON?.error || xhr.statusText);
                                $('#bulk-import-status').text(msg);
                            }
                        });
                    });
                }

                // Initial Load
                // Check for ?path= in the URL
                const urlParams = new URLSearchParams(window.location.search);
                const initialPath = urlParams.get('path') || '';
                loadDirectory(initialPath);

                window.addEventListener('popstate', function (event) {
                    loadDirectory(event.state ? event.state.path : (new URLSearchParams(window.location.search).get('path') || ''));
                });
            });

        </script>
    </div>
@endsection
