// Global settings/variables
window.googleBooksMatchLimit = 10;
window.googleBooksMoreMatches = false;
window.googleBooksMatches = [];

// Function to update the + button position to always be on the last row
function updateAddRowButtons(groupSelector, rowSelector, buttonClass) {
    const group = document.querySelector(groupSelector);
    if (!group) return;
    const rows = group.querySelectorAll(rowSelector);

    // Hide all add buttons first
    group.querySelectorAll(buttonClass).forEach(btn => {
        btn.style.display = 'none';
    });

    // Show add button only on the last row
    if (rows.length > 0) {
        const lastRow = rows[rows.length - 1];
        const addButton = lastRow.querySelector(buttonClass);
        if (addButton) {
            addButton.style.display = 'flex';
        }
    }
}

function initTomSelect(selector, ajaxUrl, createUrl, initialOptions = [],genreOptions = []) {
    let el = document.querySelector(selector);
    if (!el) return;

    let options = {
        create: false,
        persist: false,
        valueField: 'id',
        labelField: 'name',
        searchField: 'name',
        maxOptions: 20,
        loadThrottle: 300,
        onFocus: function () {
            if (!this.isOpen) this.open();
            this.refreshOptions(false);
        },
        onInitialize: function () {
            initialOptions.forEach(opt => {
                this.addOption(opt);
            });
            if (initialOptions.length > 0) {
                this.setValue(initialOptions.map(opt => opt.id), true);
            }
        }
    };

    if (ajaxUrl) {
        options.load = function (query, callback) {
            let url = ajaxUrl + '?q=' + encodeURIComponent(query || '');
            fetch(url)
                .then(response => response.json())
                .then(json => {
                    callback(json.data || []);
                })
                .catch(() => {
                    callback();
                });
        };
    }

    if (createUrl) {
        options.create = function (input, callback) {
            fetch(createUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({ name: input })
            })
            .then(response => response.json())
            .then(data => {
                if (data.id && data.name) {
                    callback({ id: data.id, name: data.name });
                }
            })
            .catch(() => callback());
        };
    }
    
    if (selector === '#genre-select-tom') { // Special handling for static genre list
        options.options = genreOptions;
        delete options.load; // No dynamic loading for genres if static list is provided
        options.create = false; // No creating genres from TomSelect by default
    }

    new TomSelect(el, options);
}
window.initTomSelect = initTomSelect;

// Function to add a new author row
function addAuthorRow(authorName = '') {
    const group = document.getElementById('authors-group');
    if (!group) return;
    const div = document.createElement('div');
    div.className = 'input-group author-row align-items-start mb-3';
    div.innerHTML = `
        <input type="text" name="author[]" class="form-control w-auto author-autocomplete" value="${authorName}" style="max-width:300px; height:32px;" required>
        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-author p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-author-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
        </div>`;
    group.appendChild(div);
    // Re-initialize autocomplete for the new element if needed, or rely on delegation
    // $(div).find('.author-autocomplete').autocomplete({ source: window.BOOK_FORM_ROUTES.authorsAutocomplete }); 
    updateAddRowButtons('#authors-group', '.author-row', '.add-author-row');
}
window.addAuthorRow = addAuthorRow;

// Function to add a new series row
function addSeriesRow(seriesName = '', seriesNumber = '') {
    const group = document.getElementById('series-group');
    if (!group) return;
    const div = document.createElement('div');
    div.className = 'input-group series-row align-items-start mb-3';
    div.innerHTML = `
        <input type="text" name="series[]" class="form-control w-auto series-autocomplete" style="max-width:200px; height:32px;" placeholder="Series Name" value="${seriesName}">
        <input type="number" name="series_number[]" class="form-control w-auto" style="max-width:100px; height:32px;" placeholder="Number" value="${seriesNumber}" min="1" step="any">
        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-series p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-series-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
        </div>`;
    group.appendChild(div);
    // Re-initialize autocomplete for the new element if needed, or rely on delegation
    // $(div).find('.series-autocomplete').autocomplete({ source: window.BOOK_FORM_ROUTES.seriesAutocomplete });
    updateAddRowButtons('#series-group', '.series-row', '.add-series-row');
}
window.addSeriesRow = addSeriesRow;

// Function to add a new genre row
function addGenreRow(selectedGenre = '') {
    const group = document.getElementById('genres-group');
    if (!group) return;
    const div = document.createElement('div');
    div.className = 'input-group genre-row align-items-start mb-3';
    let optionsHtml = '<option value="">Select a genre</option>';
    (window.GENRE_OPTIONS || []).forEach(g => {
        optionsHtml += `<option value="${g}" ${selectedGenre === g ? 'selected' : ''}>${g}</option>`;
    });
    div.innerHTML = `
        <select name="genre[]" class="form-select w-auto" style="max-width:200px; height:32px;" required>
            ${optionsHtml}
        </select>
        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-genre p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-genre-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
        </div>`;
    group.appendChild(div);
    updateAddRowButtons('#genres-group', '.genre-row', '.add-genre-row');
}
window.addGenreRow = addGenreRow;

// Helper function to initialize jQuery UI Autocomplete
function initializeAutocomplete(selector, sourceUrl) {
    // Use event delegation on a static parent for dynamically added elements
    $('body').on('focus', selector, function() {
        if (!$(this).data('autocomplete-initialized')) {
            $(this).autocomplete({
                source: sourceUrl,
                minLength: 2, // Minimum characters to trigger autocomplete
                select: function(event, ui) {
                    $(this).val(ui.item.value); // Set the input value
                    // You can add more logic here if needed when an item is selected
                }
            }).data('autocomplete-initialized', true);
        }
    });
}

// Autofill handler - ensure it is always bound after TomSelect
function bindAutofillBtn() {
    $('#autofill-btn').off('click').on('click', function () {
        const title = $('#title').val().trim();
        const authorInputs = $('input[name="author[]"]').filter(function() {
            return $(this).val().trim() !== '';
        });

        if (!title) {
            $('#title').addClass('is-invalid').next('.invalid-feedback').remove();
            $('#title').after('<span class="invalid-feedback d-block">Title is required.</span>');
            return;
        }
        if (authorInputs.length === 0) {
            const firstAuthorInput = $('input[name="author[]"]').first();
            firstAuthorInput.addClass('is-invalid').parent().find('.invalid-feedback').remove();
            firstAuthorInput.closest('.input-group').after('<span class="invalid-feedback d-block">At least one author is required.</span>');
            return;
        }

        const authorName = $(authorInputs[0]).val().trim();
        const series = $('#series-select option:selected').text() || ''; // Assuming TomSelect for series, otherwise adjust
        const seriesNumber = $('input[name="series_number[]"]').first().val() || ''; // Assuming first series number
        $('#autofill-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Searching...');
        
        const googleBooksUrl = window.BOOK_FORM_ROUTES.googleBooks || '/admin/books/google-books'; // Fallback if not defined

        fetch(`${googleBooksUrl}?title=${encodeURIComponent(title)}&author=${encodeURIComponent(authorName)}&series=${encodeURIComponent(series)}&series_number=${encodeURIComponent(seriesNumber)}&limit=${window.googleBooksMatchLimit}${window.googleBooksMoreMatches ? '&more=1' : ''}`)
            .then(response => response.json())
            .then(data => {
                $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                if (data.match_type === 'close') {
                    if (data.published_year) $('#published_year').val(data.published_year);
                    if (data.description) $('#description').val(data.description);
                    if (data.cover_image_url) {
                        try {
                            const proxiedUrl = googleBooksProxyUrl(data.cover_image_url);
                            $('#cover-preview-img').attr('src', proxiedUrl);
                            $('#cover-preview-group').show();
                        } catch (error) {
                            console.error('Error processing cover image URL:', error);
                        }
                    }
                    let hidden = $('#cover_image_url');
                    if (!hidden.length) {
                        hidden = $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-form');
                    }
                    hidden.val(data.cover_image_url || '');
                    $('#google-books-matches-table-wrapper').hide();
                    $('#autofill-btn').html('<i class="fas fa-search"></i> Get More Matches');
                    window.googleBooksMoreMatches = true;
                    window.googleBooksMatchLimit = 10;
                } else if (data.match_type === 'list' && data.matches && data.matches.length > 0) {
                    const $tbody = $('#google-books-matches-table tbody');
                    $tbody.empty();
                    data.matches.forEach((match, idx) => {
                        const row = `<tr>
                            <td><input type="radio" name="google_books_match_select" value="${idx}"></td>
                            <td>${match.title || ''}</td>
                            <td>${match.authors || ''}</td>
                            <td>${match.published_year || ''}</td>
                            <td>${match.cover_image_url ? `<img src='${googleBooksProxyUrl(match.cover_image_url)}' style='max-height:60px;'>` : ''}</td>
                        </tr>`;
                        $tbody.append(row);
                    });
                    $('#google-books-matches-table-wrapper').show();
                    $('input[name="google_books_match_select"]').off('change').on('change', function () {
                        const idx = $(this).val();
                        const match = data.matches[idx];
                        if (match.published_year) $('#published_year').val(match.published_year);
                        if (match.description) $('#description').val(match.description);
                        if (match.authors) {
                            $('.author-row').remove(); // Clear all authors
                            const authorDelimiters = /,|\s+and\s+|\s*&\s*/i;
                            const authors = match.authors.split(authorDelimiters)
                                .map(author => author.trim())
                                .filter(author => author.length > 0);
                            authors.forEach(author => addAuthorRow(author));
                            if (authors.length === 0) addAuthorRow(); // Ensure at least one empty row
                        }
                        if (match.cover_image_url) {
                            const proxiedUrl = googleBooksProxyUrl(match.cover_image_url);
                            $('#google-books-candidate').remove();
                            var candidateHtml = `<div class="text-center" id="google-books-candidate">
                                <label>
                                <input type="radio" name="cover_image_candidate" value="${match.cover_image_url}" checked>
                                <br>
                                <img src="${proxiedUrl}" alt="Google Books Cover" style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">
                                </label>
                                <div style="font-size:12px;word-break:break-all;">Google Books</div>
                                </div>`;
                            if ($('#cover-candidates-list').length) {
                                $('#cover-candidates-list').append(candidateHtml);
                            } else {
                                $('#cover-preview-img').attr('src', proxiedUrl);
                                $('#cover-preview-group').show();
                            }
                        }
                        let hidden = $('#cover_image_url');
                        if (!hidden.length) {
                           hidden = $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-form');
                        }
                        hidden.val(match.cover_image_url || '');
                    });
                    if (data.maxed || window.googleBooksMatchLimit >= 40) {
                        $('#autofill-btn').prop('disabled', true).html('<i class="fas fa-check"></i> All Results Shown');
                    } else {
                        $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Get More Matches');
                        window.googleBooksMoreMatches = true;
                        window.googleBooksMatchLimit += 10;
                    }
                } else {
                    $('#google-books-matches-table-wrapper').hide();
                    $('#autofill-btn').html('<i class="fas fa-search"></i> Autofill from Google Books');
                    window.googleBooksMoreMatches = false;
                    window.googleBooksMatchLimit = 10;
                }
            })
            .catch(() => {
                $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                $('#title').addClass('is-invalid').next('.invalid-feedback').remove();
                $('#title').after('<span class="invalid-feedback d-block">Failed to fetch book info.</span>');
            });
    });

    // Initialize jQuery UI Autocomplete
    if (window.BOOK_FORM_ROUTES && window.BOOK_FORM_ROUTES.authorsAutocomplete) {
        initializeAutocomplete('.author-autocomplete', window.BOOK_FORM_ROUTES.authorsAutocomplete);
    }
    if (window.BOOK_FORM_ROUTES && window.BOOK_FORM_ROUTES.seriesAutocomplete) {
        initializeAutocomplete('.series-autocomplete', window.BOOK_FORM_ROUTES.seriesAutocomplete);
    }
}

// Helper for proxying Google Books cover images
function googleBooksProxyUrl(url) {
    if (url && url.match(/^https?:\/\/books\.google\.com\//)) {
        try {
            const encodedUrl = typeof btoa === 'function'
                ? btoa(encodeURIComponent(url))
                : Buffer.from(url).toString('base64'); // Node.js fallback, not for browser
            return (window.APP_URL || '') + '/google-books-cover/' + encodedUrl;
        } catch (e) {
            console.error('Error encoding URL:', e);
            return url;
        }
    }
    return url;
}
window.googleBooksProxyUrl = googleBooksProxyUrl;

function loadDirectoryFiles() {
    const dirPath = $("#directory_path").val() || $("input[name='directory_path']").val() || $("input[name='original_directory_path']").val();
    const filesList = $('#directory-files-list');
    const linkText = $('#show-files-link div');

    if (!dirPath) {
        filesList.html('<div class="p-3 text-danger">Please select a directory first.</div>').show();
        return;
    }
    const originalText = linkText.html();
    linkText.html('<i class="fas fa-spinner fa-spin me-2"></i>Loading files...');
    filesList.html('<div class="text-center p-3"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading files...</div>').show();
    
    const filesAjaxUrl = window.BOOK_FORM_ROUTES.filesAjax || '/admin/books/files-ajax'; // Fallback

    $.ajax({
        url: filesAjaxUrl,
        method: 'GET',
        data: { directory: dirPath },
        dataType: 'json',
        success: function(response) {
            linkText.html(originalText);
            let html = '';
            let files = [];
            if (typeof response === 'string') try { response = JSON.parse(response); } catch (e) { console.error('Error parsing response:', e); }
            if (response && response.files && Array.isArray(response.files)) files = response.files;
            else if (response && Array.isArray(response)) files = response;
            else if (response && response.data && Array.isArray(response.data)) files = response.data;

            if (files && files.length > 0) {
                html = '<div class="list-group list-group-flush">';
                files.forEach(function(file) {
                    if (!file) return;
                    const filename = typeof file === 'string' ? file : (file.name || file.filename || '');
                    if (!filename) return;
                    const isImage = /(\.(jpg|jpeg|png|gif|webp))$/i.test(filename);
                    const isAudio = /(\.(mp3|m4b|m4a|ogg|wav|flac))$/i.test(filename);
                    let icon = '📄';
                    if (isImage) icon = '🖼️';
                    else if (isAudio) icon = '🔊';
                    html += `<div class="list-group-item p-2"><div class="d-flex align-items-center"><span class="me-2">${icon}</span><span class="text-truncate">${filename}</span></div></div>`;
                });
                html += '</div>';
            } else {
                html = '<div class="p-3 text-muted text-center">No files found in this directory.</div>';
            }
            filesList.html(html).show().css('display', 'block');
        },
        error: function(xhr, status, error) {
            linkText.html(originalText);
            filesList.html('<div class="p-3 text-danger">Error loading files. Please check the console.</div>');
        }
    });
}
window.loadDirectoryFiles = loadDirectoryFiles; // Expose if called by onclick


document.addEventListener('DOMContentLoaded', function () {
    // Initialize dynamic row buttons
    updateAddRowButtons('#authors-group', '.author-row', '.add-author-row');
    updateAddRowButtons('#series-group', '.series-row', '.add-series-row');
    updateAddRowButtons('#genres-group', '.genre-row', '.add-genre-row');

    // Event delegation for remove/add buttons
    document.body.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-author')) {
            e.target.closest('.author-row').remove();
            updateAddRowButtons('#authors-group', '.author-row', '.add-author-row');
        }
        if (e.target.classList.contains('add-author-row')) {
            addAuthorRow();
        }
        if (e.target.classList.contains('remove-series')) {
            e.target.closest('.series-row').remove();
            updateAddRowButtons('#series-group', '.series-row', '.add-series-row');
        }
        if (e.target.classList.contains('add-series-row')) {
            addSeriesRow();
        }
        if (e.target.classList.contains('remove-genre')) {
            e.target.closest('.genre-row').remove();
            updateAddRowButtons('#genres-group', '.genre-row', '.add-genre-row');
        }
        if (e.target.classList.contains('add-genre-row')) {
            addGenreRow();
        }
    });

    // Bind autofill button
    bindAutofillBtn();

    // Toggle files list on link click
    $('#show-files-link').on('click', function(e) {
        e.preventDefault();
        const filesList = $('#directory-files-list');
        const linkDiv = $(this).find('div');
        if (filesList.is(':visible')) {
            filesList.slideUp();
            linkDiv.html('<i class="fas fa-chevron-down me-2"></i>Show Files in Directory');
        } else {
            loadDirectoryFiles(); // Load files when opening
            filesList.slideDown();
            linkDiv.html('<i class="fas fa-chevron-up me-2"></i>Hide Files in Directory');
        }
    });

    // Form validation
    const form = document.getElementById('book-form'); // Ensure your form has id="book-form"
    if (form) {
        form.addEventListener('submit', function (e) {
            // Clear previous validation
            form.querySelectorAll('.is-invalid').forEach(field => field.classList.remove('is-invalid'));
            form.querySelectorAll('.invalid-feedback.d-block').forEach(msg => msg.remove());

            let hasError = false;

            const dirPathInput = form.querySelector('input[name="directory_path"]');
            if (dirPathInput && dirPathInput.value) {
                dirPathInput.value = dirPathInput.value.replace(/^\/+/, '');
            }

            const titleInput = form.querySelector('input[name="title"]');
            if (!titleInput || !titleInput.value.trim()) {
                titleInput.classList.add('is-invalid');
                $(titleInput).after('<span class="invalid-feedback d-block">Title is required.</span>');
                hasError = true;
            }

            const authorInputs = form.querySelectorAll('input[name="author[]"]');
            let hasAuthor = false;
            authorInputs.forEach(input => { if (input.value.trim()) hasAuthor = true; });
            if (!hasAuthor && authorInputs.length > 0) {
                authorInputs[0].classList.add('is-invalid');
                $(authorInputs[0].closest('.input-group')).after('<span class="invalid-feedback d-block">At least one author is required.</span>');
                hasError = true;
            } else if (authorInputs.length === 0) { // No author input fields at all
                 const authorsGroup = document.getElementById('authors-group');
                 $(authorsGroup).after('<span class="invalid-feedback d-block">At least one author is required.</span>');
                 hasError = true;
            }


            const genreSelects = form.querySelectorAll('select[name="genre[]"]');
            let hasGenre = false;
            genreSelects.forEach(select => { if (select.value) hasGenre = true; });
            if (!hasGenre && genreSelects.length > 0) {
                genreSelects[0].classList.add('is-invalid');
                 $(genreSelects[0].closest('.input-group')).after('<span class="invalid-feedback d-block">At least one genre is required.</span>');
                hasError = true;
            } else if (genreSelects.length === 0) {
                const genresGroup = document.getElementById('genres-group');
                $(genresGroup).after('<span class="invalid-feedback d-block">At least one genre is required.</span>');
                hasError = true;
            }

            if (hasError) {
                e.preventDefault();
                const firstError = form.querySelector('.is-invalid');
                if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            // Handle AJAX form submission if in modal
            var $form = $(this);
            var $modal = $form.closest('.modal');
            if ($modal.length) {
                e.preventDefault();
                var url = $form.attr('action');
                var method = $form.find('input[name="_method"]').val() || 'POST';
                var formData = new FormData(this);
                
                const submitButton = form.querySelector('button[type="submit"]');
                const originalButtonText = submitButton ? submitButton.innerHTML : '';
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                }

                $.ajax({
                    url: url,
                    type: method,
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (data) {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.innerHTML = originalButtonText;
                        }
                        var modalEl = $modal[0];
                        var bsModal = bootstrap.Modal.getInstance(modalEl);
                        if (bsModal) bsModal.hide();
                        $(document).trigger('book:updated', data); // Custom event for other parts of app to listen to
                        if (window.BOOK_FORM_ROUTES && window.BOOK_FORM_ROUTES.index) {
                           // Optionally redirect or refresh part of the page
                           // Example: if (data.redirect_url) window.location.href = data.redirect_url;
                        }
                    },
                    error: function (xhr) {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.innerHTML = originalButtonText;
                        }
                        let msg = 'Failed to save book.';
                        if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                        if (xhr.responseJSON && xhr.responseJSON.errors) {
                            // Display validation errors
                            $.each(xhr.responseJSON.errors, function(key, value) {
                                const inputField = form.querySelector(`[name^="${key}"]`);
                                if (inputField) {
                                    inputField.classList.add('is-invalid');
                                    $(inputField.closest('.input-group') || inputField).after('<span class="invalid-feedback d-block">' + value[0] + '</span>');
                                }
                            });
                        } else {
                            $form.find('#title').addClass('is-invalid').next('.invalid-feedback.d-block').remove();
                            $form.find('#title').after('<span class="invalid-feedback d-block">' + msg + '</span>');
                        }
                         const firstErrorField = form.querySelector('.is-invalid');
                        if (firstErrorField) firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
                return false;
            }
        });
    }

    // Bootstrap modal cancel button specific handler if needed
    if (typeof window.bootstrap !== 'undefined') {
        document.querySelectorAll('.modal .btn-close, .modal [data-bs-dismiss="modal"]').forEach(btn => {
            btn.addEventListener('click', function() {
                const modalEl = this.closest('.modal');
                if (modalEl) {
                    var bsModal = bootstrap.Modal.getInstance(modalEl);
                    if (bsModal && bsModal._isShown) {
                        // bsModal.hide(); // Already handled by data-bs-dismiss
                    }
                }
            });
        });
    }
    
    // Initialize TomSelect for series if the element exists
    // This part needs the route for fetching series, passed from Blade
    // Example: initTomSelect('#series-select-tom', window.BOOK_FORM_ROUTES.seriesAjax, window.BOOK_FORM_ROUTES.seriesStore, window.INITIAL_SERIES_OPTIONS);
    
    // Initialize TomSelect for genres if the element exists and window.GENRE_OPTIONS is populated
    // Example: initTomSelect('#genre-select-tom', null, null, [], window.GENRE_OPTIONS.map(g => ({id: g, name: g}) ));
});
