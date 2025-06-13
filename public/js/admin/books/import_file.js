// import_file.js - File/Audio Import Browser for Book Import
// PSR12/camelCase, debug logging, no global leaks

(function ($) {
    'use strict';
    window.initImportFileBrowser = function (rootSelector, options = {}) {
        const $root = $(rootSelector);
        if ($root.length === 0) {
            console.warn('[ImportFile] Root not found:', rootSelector);
            return;
        }
        // Options
        const ajaxRootsUrl = options.ajaxRootsUrl || '/admin/import/roots';
        const ajaxListUrl = options.ajaxListUrl || '/admin/import/list';
        let currentRoot = null;
        let currentPath = '';
        // Initialize UI
        function loadRoots() {
            $.getJSON(ajaxRootsUrl, function (roots) {
                const $select = $root.find('#import-root-select');
                $select.empty();
                for (const r of roots) {
                    $select.append($('<option>').val(r.value).text(r.label));
                }
                if (roots.length > 0) {
                    currentRoot = roots[0].value;
                    $select.val(currentRoot);
                    loadDirectory('');
                }
            });
        }
        function loadDirectory(path) {
            currentPath = path;
            $root.find('#import-path-input').val(path);
            $root.find('#import-directory-list').html('<div class="text-muted">Loading...</div>');
            $.getJSON(ajaxListUrl, {root: currentRoot, path: path}, function (data) {
                renderDirectoryList(data);
            }).fail(function (xhr) {
                $root.find('#import-directory-list').html('<div class="text-danger">Failed to load directory: ' + xhr.statusText + '</div>');
            });
        }
        function renderDirectoryList(data) {
            const $list = $('<ul class="list-group"></ul>');
            if (data.parent) {
                $list.append('<li class="list-group-item list-group-item-action" data-type="parent" style="cursor:pointer"><i class="fas fa-level-up-alt"></i> .. (up)</li>');
            }
            for (const item of data.items) {
                let icon = item.type === 'dir' ? 'fa-folder' : 'fa-file-audio';
                let cls = item.type === 'dir' ? 'list-group-item-info' : 'list-group-item-secondary';
                $list.append('<li class="list-group-item ' + cls + ' list-group-item-action" data-type="' + item.type + '" data-name="' + item.name + '" style="cursor:pointer"><i class="fas ' + icon + '"></i> ' + item.name + '</li>');
            }
            $root.find('#import-directory-list').empty().append($list);
            $root.find('#import-select-btn').prop('disabled', true);
        }
        // Event handlers
        $root.on('change', '#import-root-select', function () {
            currentRoot = $(this).val();
            loadDirectory('');
        });
        $root.on('click', '.list-group-item-action', function () {
            const $item = $(this);
            const type = $item.data('type');
            const name = $item.data('name');
            if (type === 'parent') {
                let up = currentPath.split('/').slice(0, -1).join('/');
                loadDirectory(up);
            } else if (type === 'dir') {
                let next = currentPath ? currentPath + '/' + name : name;
                loadDirectory(next);
            } else if (type === 'file') {
                $root.find('.list-group-item').removeClass('active');
                $item.addClass('active');
                $root.find('#import-select-btn').prop('disabled', false);
            }
        });
        $root.on('click', '#import-select-btn', function () {
            const selected = $root.find('.list-group-item.active').data('name');
            if (!selected) return;
            const type = $root.find('.list-group-item.active').data('type');
            let relPath = currentPath ? currentPath + '/' + selected : selected;
            let payload = {root: currentRoot, path: relPath, type: type};
            $root.find('#import-metadata-summary').remove();
            $root.append('<div id="import-metadata-summary" class="my-3"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div> <span class="ms-2">Extracting metadata...</span></div>');
            $.ajax({
                url: '/admin/import/extract',
                type: 'POST',
                data: payload,
                headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')},
                success: function (data) {
                    $root.find('#import-metadata-summary').html(renderMetadataSummary(data));
                },
                error: function (xhr) {
                    $root.find('#import-metadata-summary').html('<div class="text-danger">Failed: ' + xhr.statusText + '</div>');
                }
            });
        });
        function renderMetadataSummary(data) {
            if (!data.success) {
                return '<div class="text-danger">' + (data.message || 'Metadata extraction failed.') + '</div>';
            }
            let html = '<div class="card card-body bg-light">';
            html += '<h5>Extracted Metadata</h5>';
            html += '<ul class="mb-2">';
            html += '<li><strong>Title:</strong> ' + (data.title || '-') + '</li>';
            html += '<li><strong>Author:</strong> ' + (data.author || '-') + '</li>';
            html += '<li><strong>Series:</strong> ' + (data.series || '-') + '</li>';
            html += '<li><strong>Genre:</strong> ' + (data.genre || '-') + '</li>';
            html += '</ul>';
            html += '<button id="import-move-btn" class="btn btn-success me-2">Move to Library</button>';
            html += '<button id="import-prefill-btn" class="btn btn-primary" disabled>Import and Prefill Book Form</button>';
            html += '</div>';
            return html;
        }
        $root.on('click', '#import-move-btn', function () {
            const $summary = $root.find('#import-metadata-summary');
            let meta = {};
            $summary.find('li').each(function () {
                let label = $(this).find('strong').text().replace(':', '').toLowerCase();
                let value = $(this).contents().filter(function () { return this.nodeType === 3; }).text().trim();
                if (value && value !== '-') {
                    meta[label] = value;
                }
            });
            // Add backend fields
            meta['root'] = currentRoot;
            meta['path'] = $root.find('.list-group-item.active').data('name');
            meta['type'] = $root.find('.list-group-item.active').data('type');
            meta['directoryPath'] = $summary.data('directory-path') || '';
            $summary.html('<div class="spinner-border" role="status"><span class="visually-hidden">Moving...</span></div> <span class="ms-2">Moving file/directory...</span>');
            $.ajax({
                url: '/admin/import/move',
                type: 'POST',
                data: meta,
                headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')},
                success: function (data) {
                    if (data.success) {
                        $summary.html('<div class="alert alert-success">Moved to: <code>' + (data.newPath || '-') + '</code></div>');
                        $root.find('#import-prefill-btn').prop('disabled', false);
                        // Autofill API integration
                        let meta = {};
                        $summary.siblings('ul').find('li').each(function () {
                            let label = $(this).find('strong').text().replace(':', '').toLowerCase();
                            let value = $(this).contents().filter(function () { return this.nodeType === 3; }).text().trim();
                            if (value && value !== '-') {
                                meta[label] = value;
                            }
                        });
                        // Google Books
                        $.get(window.BOOK_FORM_ROUTES.googleBooks, {
                            title: meta.title || '',
                            author: meta.author || ''
                        }, function (resp) {
                            if (resp && resp.items && resp.items.length) {
                                let best = resp.items[0].volumeInfo;
                                $summary.append('<div class="mt-2"><strong>Google Books:</strong> ' +
                                    '<span>' + (best.title || '-') + ' by ' + (best.authors ? best.authors.join(', ') : '-') + '</span>' +
                                    (best.imageLinks && best.imageLinks.thumbnail ? '<br><img src="' + best.imageLinks.thumbnail + '" style="max-height:80px;">' : '') +
                                    '</div>');
                            } else {
                                $summary.append('<div class="mt-2 text-muted">No Google Books match found.</div>');
                            }
                        });
                        // Goodreads search button
                        let goodreadsUrl = 'https://www.goodreads.com/search?q=' + encodeURIComponent((meta.title || '') + ' ' + (meta.author || ''));
                        $summary.append('<a href="' + goodreadsUrl + '" target="_blank" class="btn btn-secondary btn-sm mt-2">Search Goodreads</a>');
                        // TODO: Add Audible/genre guess as needed
                    } else {
                        $summary.html('<div class="alert alert-danger">Move failed: ' + (data.message || 'Unknown error') + '</div>');
                    }
                },
                error: function (xhr) {
                    $summary.html('<div class="alert alert-danger">Move failed: ' + xhr.statusText + '</div>');
                }
            });
        });
        $root.on('click', '#import-prefill-btn', function () {
            const $summary = $root.find('#import-metadata-summary');
            let meta = {};
            $summary.find('li').each(function () {
                let label = $(this).find('strong').text().replace(':', '').toLowerCase();
                let value = $(this).contents().filter(function () { return this.nodeType === 3; }).text().trim();
                if (value && value !== '-') {
                    meta[label] = value;
                }
            });
            meta['import_path'] = $root.find('.list-group-item.active').data('name');
            meta['import_root'] = currentRoot;
            meta['import_type'] = $root.find('.list-group-item.active').data('type');
            let params = Object.keys(meta)
                .map(k => encodeURIComponent(k) + '=' + encodeURIComponent(meta[k]))
                .join('&');
            let url = window.BOOK_FORM_ROUTES && window.BOOK_FORM_ROUTES.create ? window.BOOK_FORM_ROUTES.create : '/admin/books/create';
            url += (url.indexOf('?') === -1 ? '?' : '&') + params;
            console.debug('[ImportFile] Redirecting to:', url);
            window.location.href = url;
        });
        // Init
        loadRoots();
    };
})(jQuery);
