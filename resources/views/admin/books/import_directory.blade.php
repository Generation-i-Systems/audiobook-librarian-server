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
                    @foreach(range('A', 'Z') as $letter)
                        <button type="button" class="btn btn-outline-secondary"
                            data-letter="{{ $letter }}">{{ $letter }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <input type="text" id="search-filter" class="form-control form-control-sm"
                    placeholder="Search directories..." style="width: 200px; display: inline-block;" />
            </div>
        </div>

        <div class="mb-3">
            <button id="bulk-import-btn" class="btn btn-primary">
                <i class="fas fa-cloud-upload-alt"></i> Bulk Import Books Here
            </button>
            <span id="bulk-import-status" class="ms-3"></span>
        </div>

        <div class="table-responsive">
            <div id="directory-loading-spinner" class="text-center" style="display:none;">
                <div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>
            </div>
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

            .btn-primary {
                background-color: #007bff !important;
                color: #fff !important;
            }

            .btn-success {
                background-color: #28a745 !important;
                color: #fff !important;
            }

            .btn-danger {
                background-color: #dc3545 !important;
                color: #fff !important;
            }

            .open-add-book-modal {
                background-color: #28a745 !important;
                color: #fff !important;
                border: none !important;
            }

            .open-edit-book-modal {
                background-color: #007bff !important;
                color: #fff !important;
                border: none !important;
            }
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

                $('#bulk-import-btn').on('click', function (e) {
                    e.preventDefault();
                    $('#bulk-import-status').text('Starting bulk import...');
                    $.ajax({
                        url: '{{ route("admin.books.bulkImportDir") }}',
                        type: 'POST',
                        data: {
                            dir: currentPath,
                            _token: '{{ csrf_token() }}'
                        },
                        success: function (data) {
                            $('#bulk-import-status').text(data.message || 'Bulk import started!');
                        },
                        error: function (xhr) {
                            let msg = 'Error: ' + (xhr.responseJSON?.error || xhr.statusText);
                            $('#bulk-import-status').text(msg);
                        }
                    });
                });

                // Handle browser back/forward buttons
                window.onpopstate = function (event) {
                    if (event.state && event.state.path !== undefined) {
                        loadDirectory(event.state.path, false, false);
                    } else {
                        loadDirectory('', false, false);
                    }
                };

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
                        // Add data-book-id attribute for book rows
                        if (item.type === 'book' && item.id) {
                            html += '<tr data-book-id="' + escapeHtml(item.id) + '">';
                        } else {
                            html += '<tr>';
                        }
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
                    $('#directory-loading-spinner').show();
                    const parentPath = $(this).data('path') ?? '';
                    loadDirectory(parentPath);
                });

                // Breadcrumb navigation
                $(document).on('click', '.breadcrumb-link', function (e) {
                    e.preventDefault();
                    $('#directory-loading-spinner').show();
                    const path = $(this).data('path') ?? '';
                    loadDirectory(path);
                });

                // Directory click navigation
                $(document).on('click', '.directory-link', function (e) {
                    e.preventDefault();
                    $('#directory-loading-spinner').show();
                    const path = $(this).data('path');
                    loadDirectory(path);
                });

                // Inline rename button event
                $(document).on('click', '.rename-dir-btn', function (e) {
                    e.preventDefault();
                    const $row = $(this).closest('tr');
                    $row.find('.rename-dir-btn').addClass('d-none');
                    $row.find('.rename-inline-field').removeClass('d-none');
                    $row.find('.rename-input').focus().select();
                });
                // Confirm inline rename
                $(document).on('click', '.confirm-rename-btn', function (e) {
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
                $(document).on('click', '.cancel-rename-btn', function (e) {
                    e.preventDefault();
                    const $row = $(this).closest('tr');
                    $row.find('.rename-dir-btn').removeClass('d-none');
                    $row.find('.rename-inline-field').addClass('d-none');
                });

                function loadDirectory(path, updateFilter = true, updateHistory = true, callback) {
                    currentPath = path || '';
                    if (updateFilter) {
                        filterLetter = '';
                        $('.btn-letter-filter').removeClass('active');
                        $('#search-filter').val('');
                    }
                    updateBreadcrumbs(path);

                    // Update browser history if needed
                    if (updateHistory) {
                        const url = new URL(window.location);
                        url.searchParams.set('path', path);
                        window.history.pushState({ path: path }, '', url);
                    }

                    // Show loading indicator
                    $('#directory-loading-spinner').show();
                    const $directoryContents = $('#directory-contents');
                    const $loading = $('<div class="text-center p-5"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
                    $directoryContents.html($loading);

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
                            $('#directory-loading-spinner').hide();
                            // Call the callback if provided
                            if (typeof callback === 'function') {
                                callback();
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error('Error loading directory:', status, error);
                            $directoryContents.html('<div class="alert alert-danger">Error loading directory: ' + error + '</div>');
                            $('#directory-loading-spinner').hide();
                            // Still call the callback if there was an error
                            if (typeof callback === 'function') {
                                callback();
                            }
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
                $(document).on('click', '.open-add-book-modal, .open-edit-book-modal', function (e) {
                    e.preventDefault();
                    const url = $(this).data('url');
                    const isEdit = $(this).hasClass('open-edit-book-modal');
                    const modal = isEdit ? $('#editBookModal') : $('#addBookModal');
                    const body = isEdit ? $('#edit-book-modal-body') : $('#addBookModalBody');
                    body.html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>');
                    modal.modal('show');
                    $.get(url, function (html) {
                        body.html(html); // Inject the HTML response

                        // IMPORTANT: Explicitly load and execute scripts after HTML injection.
                        const formContainerId = body.attr('id'); // Get the ID of the container where HTML was injected
                        const formJsPath = "{{ asset('js/admin/books/form.js') }}";

                        $.getScript(formJsPath)
                            .done(function (script, textStatus) {
                                // Now that form.js is loaded and executed, initBookForm should be defined.
                                if (typeof window.initBookForm === 'function') {
                                    window.initBookForm('#' + formContainerId);
                                } else {
                                    console.error('CRITICAL: initBookForm is not defined after loading form.js via $.getScript.');
                                }
                            })
                            .fail(function (jqxhr, settings, exception) {
                                console.error(`CRITICAL: Failed to load ${formJsPath}. Exception: `, exception, jqxhr.responseText);
                            });
                    });
                });

                // Clean up any stale form state when modal is closed
                $(document).on('hidden.bs.modal', '#addBookModal, #editBookModal', function () {
                    // Remove any pending form submission state
                    $(document).off('submit', '#book-form');
                    // Re-attach the form submission handler
                    setupBookFormSubmit();
                });

                // Separate function for form submission to allow re-attachment
                function setupBookFormSubmit() {
                    $(document).on('submit', '#book-form', function (e) {
                        e.preventDefault();
                        const $form = $(this);
                        const $modal = $form.closest('.modal');
                        const $submitBtn = $form.find('button[type="submit"]');
                        const originalBtnText = $submitBtn.html();
                        const directoryPath = $form.find('input[name="directoryPath"]').val() || '';

                        // Show loading state
                        $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

                        // Submit form via AJAX
                        $.ajax({
                            url: $form.attr('action'),
                            method: $form.attr('method'),
                            data: $form.serialize(),
                            dataType: 'json',
                            success: function (response) {
                                if (response.success) {
                                    // Close the modal
                                    $modal.modal('hide');

                                    // If we have row HTML, replace the existing row
                                    if (response.row_html) {
                                        // Find the row to replace (either by book ID or path)
                                        let $existingRow = $(`tr[data-book-id="${response.book_id}"]`);
                                        const path = response.directoryPath || directoryPath;

                                        if (!$existingRow.length && path) {
                                            // Try to find by path if not found by ID
                                            $existingRow = $(`tr a[data-path*="${path}"]`).closest('tr');
                                        }

                                        if ($existingRow.length) {
                                            // Replace the existing row with the new one
                                            $existingRow.replaceWith(response.row_html);

                                            // Show success message
                                            showAlert('Book saved successfully!', 'success');
                                        } else {
                                            // If we can't find the row, reload the directory
                                            loadDirectory(currentPath, false, false, function () {
                                                showAlert('Book saved successfully!', 'success');
                                            });
                                        }
                                    } else if (typeof updateBookAction === 'function') {
                                        // Fallback to the old update method if no row HTML
                                        updateBookAction(response.book_id, response.edit_url, response.directoryPath || directoryPath);
                                    }
                                } else {
                                    showAlert(response.message || 'An error occurred while saving the book.', 'danger');
                                    $submitBtn.prop('disabled', false).html(originalBtnText);
                                }
                            },
                            error: function (xhr) {
                                const response = xhr.responseJSON || {};
                                showAlert(response.message || 'An error occurred while saving the book.', 'danger');
                                $submitBtn.prop('disabled', false).html(originalBtnText);
                            }
                        });
                    });
                }

                // Initialize the form submission handler
                setupBookFormSubmit();

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

                // Initial load - check for path in URL
                const urlParams = new URLSearchParams(window.location.search);
                const initialPath = urlParams.get('path') ? decodeURIComponent(urlParams.get('path')) : '';
                loadDirectory(initialPath, true, false);

                // Function to update book action links after creation/update
                window.updateBookAction = function (bookId, editUrl, directoryPath) {
                    console.log('Updating book action for ID:', bookId, 'with URL:', editUrl, 'and path:', directoryPath);

                    // First, try to find the row by book ID
                    let row = $(`tr[data-book-id="${bookId}"]`);

                    // If not found by ID, try to find by directory path
                    if (!row.length && directoryPath) {
                        row = $(`tr a[data-path*="${directoryPath}"]`).closest('tr');
                    }

                    // If we found the row, update it
                    if (row.length) {
                        updateBookRow(row, bookId, editUrl);
                    } else {
                        // If we can't find the row, refresh the directory and try again
                        loadDirectory(currentPath, true, false, function () {
                            // After refresh, try to find the row again
                            let row = $(`tr[data-book-id="${bookId}"]`);
                            if (!row.length && directoryPath) {
                                row = $(`tr a[data-path*="${directoryPath}"]`).closest('tr');
                            }

                            if (row.length) {
                                updateBookRow(row, bookId, editUrl);
                            } else {
                                console.warn('Book row not found after refresh for ID:', bookId, 'with path:', directoryPath);
                                // Show an error message
                                const errorAlert = $('<div class="alert alert-warning alert-dismissible fade show" role="alert">' +
                                    'Could not update book action. Please refresh the page to see changes.' +
                                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                                    '</div>');
                                $('#alerts-container').append(errorAlert);

                                setTimeout(() => {
                                    errorAlert.alert('close');
                                }, 5000);
                            }
                        });
                    }

                    // Helper function to update a book row
                    function updateBookRow(row, bookId, editUrl) {
                        // Update the row's data-book-id if it wasn't set
                        if (!row.data('book-id')) {
                            row.attr('data-book-id', bookId);
                        }

                        // Update the action cell
                        const actionCell = row.find('td:last');
                        actionCell.html(`
                                                                            <button class="btn btn-sm btn-primary me-1 open-edit-book-modal" data-url="${editUrl}">
                                                                                <i class="fas fa-edit"></i> Edit
                                                                            </button>
                                                                        `);


                        // Show a success message
                        const successAlert = $('<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                            'Book action updated successfully.' +
                            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                            '</div>');
                        $('#alerts-container').append(successAlert);

                        // Auto-dismiss the alert after 3 seconds
                        setTimeout(() => {
                            successAlert.alert('close');
                        }, 3000);

                        // Ensure the row is visible in case it was in a collapsed section
                        row[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                };
            });
        </script>
    </div>
@endsection
