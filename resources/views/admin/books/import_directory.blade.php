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

        <div class="mb-3 d-flex align-items-center gap-3">
            <div>
                <label>Filter by first letter:</label>
                <div id="letter-filter" class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary" data-letter="">All</button>
                    @foreach(range('A','Z') as $letter)
                        <button type="button" class="btn btn-outline-secondary" data-letter="{{ $letter }}">{{ $letter }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <input type="text" id="search-filter" class="form-control form-control-sm" placeholder="Search directories..." style="width: 200px; display: inline-block;" />
            </div>
        </div>

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

            .btn-primary { background-color: #007bff !important; color: #fff !important; }
            .btn-success { background-color: #28a745 !important; color: #fff !important; }
            .btn-danger { background-color: #dc3545 !important; color: #fff !important; }
            .open-add-book-modal { background-color: #28a745 !important; color: #fff !important; border: none !important; }
            .open-edit-book-modal { background-color: #007bff !important; color: #fff !important; border: none !important; }
        </style>

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
                let filterLetter = '';
                let searchString = '';

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
                        let current = '';
                        for (let i = 0; i < pathParts.length; i++) {
                            if (pathParts[i] === '') continue;
                            current += '/' + pathParts[i];
                            html += '<li class="breadcrumb-item"><a href="#" class="breadcrumb-link" data-path="' + current + '">' + escapeHtml(pathParts[i]) + '</a></li>';
                        }
                    } else {
                        html += '<li class="breadcrumb-item active">Root</li>';
                    }
                    html += '</ul>';
                    $('#directory-path-breadcrumbs').html(html);
                }

                function renderDirectoryBrowser(data, path) {
                    let html = '';
                    // Previous row: go up one directory
                    if (path !== '') {
                        let parentPath = path.split('/').slice(0, -1).join('/');
                        if (parentPath === undefined) parentPath = '';
                        html += '<tr><td colspan="3"><a href="#" class="previous-link" data-path="' + escapeHtml(parentPath) + '"><i class="fas fa-arrow-left"></i> Previous</a></td></tr>';
                    }
                    data.forEach(function (item) {
                        html += '<tr>';
                        // Directory name: clickable for directories
                        if (item.type === 'directory') {
                            html += '<td><a href="#" class="directory-link" data-path="' + escapeHtml(item.path) + '">' + escapeHtml(item.name) + '</a></td>';
                        } else {
                            html += '<td>' + escapeHtml(item.name) + '</td>';
                        }
                        // Type column
                        if (item.type === 'book') {
                            html += '<td>Book</td>';
                        } else if (item.type === 'directory') {
                            html += '<td>Directory</td>';
                        } else {
                            html += '<td>' + escapeHtml(item.type.charAt(0).toUpperCase() + item.type.slice(1)) + '</td>';
                        }
                        // Actions column
                        html += '<td>';
                        if (item.type === 'book') {
                            html += '<button class="btn btn-sm btn-primary me-1 open-edit-book-modal" data-url="' + item.edit + '"><i class="fas fa-edit"></i> Edit</button>';
                        } else if (item.type === 'directory') {
                            if (item.create) {
                                html += '<button class="btn btn-sm btn-success me-1 open-add-book-modal" data-url="' + item.create + '"><i class="fas fa-plus"></i> Create</button>';
                            }
                            // Inline rename field
                            html += '<button class="btn btn-sm btn-secondary rename-dir-btn me-1" data-path="' + escapeHtml(item.path) + '" data-name="' + escapeHtml(item.name) + '"><i class="fas fa-edit"></i> Rename</button>';
                            html += '<span class="rename-inline-field d-none ms-1"><input type="text" class="form-control form-control-sm d-inline-block rename-input" style="width:120px;" value="' + escapeHtml(item.name) + '"><button class="btn btn-sm btn-success confirm-rename-btn ms-1"><i class="fas fa-check"></i></button><button class="btn btn-sm btn-danger cancel-rename-btn ms-1"><i class="fas fa-times"></i></button></span>';
                        }
                        if (item.bulk_import) {
                            html += '<button class="btn btn-sm btn-warning bulk-import-dir-btn ms-1" data-dir="' + escapeHtml(item.path) + '"><i class="fas fa-cloud-upload-alt"></i> Bulk Import</button>';
                        }
                        html += '</td>';
                        html += '</tr>';
                    });
                    directoryBrowser.html(html);
                    updateBreadcrumbs(path);
                }

                // Previous link click: go up one directory
                $(document).on('click', '.previous-link', function (e) {
                    e.preventDefault();
                    const parentPath = $(this).data('path') ?? '';
                    loadDirectory(parentPath);
                });

                // Breadcrumb navigation
                $(document).on('click', '.breadcrumb-link', function(e) {
                    e.preventDefault();
                    const path = $(this).data('path') ?? '';
                    loadDirectory(path);
                });

                // Directory click navigation
                $(document).on('click', '.directory-link', function (e) {
                    e.preventDefault();
                    const path = $(this).data('path');
                    loadDirectory(path);
                });

                // Inline rename button event
                $(document).on('click', '.rename-dir-btn', function(e) {
                    e.preventDefault();
                    const $row = $(this).closest('tr');
                    $row.find('.rename-dir-btn').addClass('d-none');
                    $row.find('.rename-inline-field').removeClass('d-none');
                    $row.find('.rename-input').focus().select();
                });
                // Confirm inline rename
                $(document).on('click', '.confirm-rename-btn', function(e) {
                    e.preventDefault();
                    const $row = $(this).closest('tr');
                    const path = $row.find('.rename-dir-btn').data('path');
                    const oldName = $row.find('.rename-dir-btn').data('name');
                    const newName = $row.find('.rename-input').val();
                    if (newName && newName !== oldName) {
                        $.ajax({
                            url: '{{ route("admin.import.rename") }}',
                            type: 'POST',
                            data: { path: path, new_name: newName, _token: '{{ csrf_token() }}' },
                            success: function (resp) {
                                loadDirectory(currentPath);
                            },
                            error: function (xhr) {
                                alert('Rename failed: ' + (xhr.responseJSON?.error || xhr.statusText));
                                loadDirectory(currentPath);
                            }
                        });
                    } else {
                        $row.find('.rename-dir-btn').removeClass('d-none');
                        $row.find('.rename-inline-field').addClass('d-none');
                    }
                });
                // Cancel inline rename
                $(document).on('click', '.cancel-rename-btn', function(e) {
                    e.preventDefault();
                    const $row = $(this).closest('tr');
                    $row.find('.rename-dir-btn').removeClass('d-none');
                    $row.find('.rename-inline-field').addClass('d-none');
                });

                function loadDirectory(path) {
                    currentPath = path;
                    // Update the URL so reload stays on the same directory
                    if (window.location.pathname + window.location.search !== updateUrlForPath(path)) {
                        window.history.replaceState({ path: path }, '', updateUrlForPath(path));
                    }
                    $.ajax({
                        url: '{{ route("admin.directoryBrowser") }}',
                        type: 'GET',
                        data: {
                            path: path,
                            filter_letter: filterLetter,
                            search: searchString
                        },
                        success: function (data) {
                            renderDirectoryBrowser(data, path);
                        }
                    });
                }

                // Fix: define updateUrlForPath if missing
                function updateUrlForPath(path) {
                    // Retain filter/search params if present
                    const url = new URL(window.location.href);
                    url.searchParams.set('path', path);
                    return url.pathname + url.search;
                }

                // Modal dialog for Create/Edit
                $(document).on('click', '.open-add-book-modal, .open-edit-book-modal', function(e) {
                    e.preventDefault();
                    const url = $(this).data('url');
                    const isEdit = $(this).hasClass('open-edit-book-modal');
                    const modal = isEdit ? $('#editBookModal') : $('#addBookModal');
                    const body = isEdit ? $('#edit-book-modal-body') : $('#addBookModalBody');
                    body.html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>');
                    modal.modal('show');
                    $.get(url, function(html) {
                        body.html(html);
                    });
                });

                // Filter UI events
                $('#letter-filter').on('click', 'button', function () {
                    filterLetter = $(this).data('letter');
                    $('#letter-filter button').removeClass('active');
                    $(this).addClass('active');
                    loadDirectory(currentPath);
                });
                $('#search-filter').on('input', function () {
                    searchString = $(this).val();
                    loadDirectory(currentPath);
                });

                // Letter filter: start with All active
                $('#letter-filter button[data-letter=""]').addClass('active');

                // Bulk import for filtered list
                $('#bulk-import-btn').off('click').on('click', function () {
                    // Get visible directories
                    $.ajax({
                        url: '{{ route("admin.directoryBrowser") }}',
                        type: 'GET',
                        data: {
                            path: currentPath,
                            filter_letter: filterLetter,
                            search: searchString
                        },
                        success: function (data) {
                            let dirs = data.filter(item => item.type === 'directory' || item.type === 'book');
                            if (dirs.length === 0) {
                                $('#bulk-import-status').text('No directories to import.');
                                return;
                            }
                            $('#bulk-import-status').text('Starting bulk import...');
                            $.ajax({
                                url: '{{ route("admin.books.bulkImport") }}',
                                type: 'POST',
                                data: { dirs: dirs.map(d => d.path), _token: '{{ csrf_token() }}' },
                                success: function (data) {
                                    $('#bulk-import-status').text(data.message);
                                },
                                error: function (xhr) {
                                    let msg = 'Error: ' + (xhr.responseJSON?.error || xhr.statusText);
                                    $('#bulk-import-status').text(msg);
                                }
                            });
                        }
                    });
                });

                // Per-directory bulk import
                $(document).on('click', '.bulk-import-dir-btn', function () {
                    const dir = $(this).data('dir');
                    $('#bulk-import-status').text('Starting bulk import for ' + dir + ' ...');
                    $.ajax({
                        url: '{{ route("admin.books.bulkImportDir") }}',
                        type: 'POST',
                        data: { dir: dir, _token: '{{ csrf_token() }}' },
                        success: function (data) {
                            $('#bulk-import-status').text(data.message);
                        },
                        error: function (xhr) {
                            let msg = 'Error: ' + (xhr.responseJSON?.error || xhr.statusText);
                            $('#bulk-import-status').text(msg);
                        }
                    });
                });

                // Initial Load
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
