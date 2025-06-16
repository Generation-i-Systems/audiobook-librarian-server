// Global settings/variables
window.googleBooksMatchLimit = 10;
window.googleBooksMoreMatches = false;
window.googleBooksMatches = [];

// Function to update the + button position to always be on the last row
function updateAddRowButtons(
    $container,
    groupSelector,
    rowSelector,
    buttonClass,
) {
    console.log("updateAddRowButtons called. Container:", $container);
    const group = $container.find(groupSelector)[0];
    if (!group) return;
    const rows = group.querySelectorAll(rowSelector);

    // Hide all add buttons first
    group.querySelectorAll(buttonClass).forEach((btn) => {
        btn.style.display = "none";
    });

    // Show add button only on the last row
    if (rows.length > 0) {
        const lastRow = rows[rows.length - 1];
        const addButton = lastRow.querySelector(buttonClass);
        if (addButton) {
            addButton.style.display = "flex";
        }
    }
}

// Function to add a new author row
function addAuthorRow($container, authorName = "") {
    console.log("addAuthorRow called. Container:", $container);
    const group = $container.find("#authors-group")[0];
    if (!group) return;
    const div = document.createElement("div");
    div.className = "input-group author-row align-items-start mb-3";
    div.innerHTML = `
        <input type="text" name="author[]" class="form-control w-auto author-autocomplete" value="${authorName}" style="max-width:300px; height:32px;" required>
        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-author p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-author-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
        </div>`;
    group.appendChild(div);
    // Re-initialize autocomplete for the new element
    if (typeof initializeAutocomplete === "function") {
        initializeAutocomplete(
            $(div),
            ".author-autocomplete",
            window.BOOK_FORM_ROUTES.authorsAutocomplete,
        );
    }
    updateAddRowButtons(
        $container,
        "#authors-group",
        ".author-row",
        ".add-author-row",
    );
}
window.addAuthorRow = addAuthorRow;

// Function to add a new series row
function addSeriesRow($container, seriesName = "", seriesNumber = "") {
    console.log("addSeriesRow called. Container:", $container);
    const group = $container.find("#series-group")[0];
    if (!group) return;
    const div = document.createElement("div");
    div.className = "input-group series-row align-items-start mb-3";
    div.innerHTML = `
        <input type="text" name="series[]" class="form-control w-auto series-autocomplete" style="max-width:200px; height:32px;" placeholder="Series Name" value="${seriesName}">
        <input type="number" name="series_number[]" class="form-control w-auto" style="max-width:100px; height:32px;" placeholder="Number" value="${seriesNumber}" min="1" step="any">
        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-series p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-series-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
        </div>`;
    group.appendChild(div);
    // Re-initialize autocomplete for the new element
    if (typeof initializeAutocomplete === "function") {
        initializeAutocomplete(
            $(div),
            ".series-autocomplete",
            window.BOOK_FORM_ROUTES.seriesAutocomplete,
        );
    }
    updateAddRowButtons(
        $container,
        "#series-group",
        ".series-row",
        ".add-series-row",
    );
}
window.addSeriesRow = addSeriesRow;

// Function to add a new genre row
function addGenreRow($container, selectedGenre = "") {
    console.log("addGenreRow called. Container:", $container);
    const group = $container.find("#genres-group")[0];
    if (!group) return;
    const div = document.createElement("div");
    div.className = "input-group genre-row align-items-start mb-3";
    let optionsHtml = '<option value="">Select a genre</option>';
    (window.GENRE_OPTIONS || []).forEach((g) => {
        optionsHtml += `<option value="${g}" ${selectedGenre === g ? "selected" : ""}>${g}</option>`;
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
    updateAddRowButtons(
        $container,
        "#genres-group",
        ".genre-row",
        ".add-genre-row",
    );
}
window.addGenreRow = addGenreRow;

// Raw JSON Edit Modal logic
$(function() {
    var $rawJsonBtn = $('#raw-json-edit-btn');
    if ($rawJsonBtn.length) {
        var bookId = $rawJsonBtn.closest('form').attr('action').match(/books\/(\w+)/);
        bookId = bookId ? bookId[1] : null;
        $rawJsonBtn.on('click', function() {
            if (!bookId) return;
            $('#raw-json-error').hide();
            $.get('/admin/books/' + bookId + '/raw-json', function(data) {
                $('#raw-json-textarea').val(JSON.stringify(data, null, 2));
                $('#rawJsonModal').modal('show');
            }).fail(function(xhr) {
                $('#raw-json-textarea').val('');
                $('#raw-json-error').text('Failed to load JSON: ' + xhr.status).show();
                $('#rawJsonModal').modal('show');
            });
        });
        $('#save-raw-json-btn').on('click', function() {
            var json;
            try {
                json = JSON.parse($('#raw-json-textarea').val());
            } catch (e) {
                $('#raw-json-error').text('Invalid JSON: ' + e.message).show();
                return;
            }
            $.ajax({
                url: '/admin/books/' + bookId + '/raw-json',
                type: 'POST',
                data: { json: JSON.stringify(json) },
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function() {
                    window.location.reload();
                },
                error: function(xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Failed to save JSON.';
                    $('#raw-json-error').text(msg).show();
                }
            });
        });
    }
});

// Helper function to initialize jQuery UI Autocomplete
function initializeAutocomplete($container, selector, sourceUrl) {
    console.log("initializeAutocomplete called. Container:", $container);
    $container.on("focus", selector, function () {
        console.log("Input field focused. Selector:", selector);
        const $inputField = $(this); // Capture 'this' to use in callbacks

        // Check if autocomplete has already been initialized on this element
        if (!$inputField.data("autocomplete-initialized")) {
            console.log("Initializing autocomplete for selector:", selector);
            $inputField.autocomplete({
                minLength: 2,
                source: function (request, responseCallback) {
                    console.log(
                        "AJAX request for autocomplete. URL:",
                        sourceUrl,
                    );
                    // request.term is the current value in the input field
                    $.ajax({
                        url: sourceUrl,
                        dataType: "json",
                        data: {
                            term: request.term, // Pass the search term to the server
                        },
                        async: true, // Explicitly ensure the request is asynchronous
                        success: function (data) {
                            responseCallback(data); // Provide the data to jQuery UI
                        },
                        error: function (jqXHR, textStatus, errorThrown) {
                            responseCallback([]); // Call with empty array on error
                        },
                    });
                },
                select: function (event, ui) {
                    $inputField.val(ui.item.value); // Set the input field's value to the selected item
                    // You might want to trigger a 'change' event if other parts of your code listen for it
                    // $inputField.trigger('change');
                    return false; // Prevent the default behavior of setting the value, as we did it manually
                },
                // Optional: Customize how items are rendered in the dropdown
                create: function () {
                    // $(this).data('ui-autocomplete')._renderItem = function (ul, item) {
                    //     return $('<li>')
                    //         .append('<div>' + item.label + '</div>') // Adjust 'item.label' based on your server response
                    //         .appendTo(ul);
                    // };
                },
            });
            $inputField.data("autocomplete-initialized", true); // Mark as initialized
        }
    });
}

/*
        const authorInputs = $container.find('input[name="author[]"]');
        const authorName = $(authorInputs[0]).val().trim();
        const series = $container.find('#series-select option:selected').text() || ''; // Assuming TomSelect for series, otherwise adjust
        const seriesNumber = $container.find('input[name="series_number[]"]').first().val() || ''; // Assuming first series number
        $container.find('#autofill-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Searching...');

        const googleBooksUrl = window.BOOK_FORM_ROUTES.googleBooks || '/admin/books/google-books'; // Fallback if not defined

        fetch(`${googleBooksUrl}?title=${encodeURIComponent(title)}&author=${encodeURIComponent(authorName)}&series=${encodeURIComponent(series)}&series_number=${encodeURIComponent(seriesNumber)}&limit=${window.googleBooksMatchLimit}${window.googleBooksMoreMatches ? '&more=1' : ''}`)
            .then(response => response.json())
            .then(data => {
                $container.find('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                if (data.match_type === 'close') {
                    if (data.published_year) $container.find('#published_year').val(data.published_year);
                    if (data.description) $container.find('#description').val(data.description);
                    if (data.cover_image_url) {
                        try {
                            const proxiedUrl = googleBooksProxyUrl(data.cover_image_url);
                            $container.find('#cover-preview-img').attr('src', proxiedUrl);
                            $container.find('#cover-preview-group').show();
                        } catch (error) {
                            console.error('Error processing cover image URL:', error);
                        }
                    }
                    let hidden = $container.find('#cover_image_url');
                    if (!hidden.length) {
                        hidden = $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo($container.find('#book-form'));
                    }
                    hidden.val(data.cover_image_url || '');
                    $container.find('#google-books-matches-table-wrapper').hide();
                    $container.find('#autofill-btn').html('<i class="fas fa-search"></i> Get More Matches');
                    window.googleBooksMoreMatches = true;
                    window.googleBooksMatchLimit = 10;
                } else if (data.match_type === 'list' && data.matches && data.matches.length > 0) {
                    const $tbody = $container.find('#google-books-matches-table tbody');
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
                    $container.find('#google-books-matches-table-wrapper').show();
                    $container.find('input[name="google_books_match_select"]').off('change').on('change', function () {
                        const idx = $(this).val();
                        const match = data.matches[idx];
                        if (match.published_year) $container.find('#published_year').val(match.published_year);
                        if (match.description) $container.find('#description').val(match.description);
                        // Update authors
                        $container.find('#authors-group').empty();
                        (data.authors || ['']).forEach(function(author) {
                            addAuthorRow($container, author);
                        });
                        updateAddRowButtons($container, '#authors-group', '.author-row', '.add-author-row');
                        // Update series
                        $container.find('#series-group').empty();
                        (data.series || []).forEach(function(item) {
                            addSeriesRow($container, item.name, item.number);
                        });
                        updateAddRowButtons($container, '#series-group', '.series-row', '.add-series-row');
                        // Re-initialize autocomplete for new fields
                        if (typeof initializeAutocomplete === 'function') {
                            initializeAutocomplete($container, '.author-autocomplete', window.BOOK_FORM_ROUTES.authorsAutocomplete);
                            initializeAutocomplete($container, '.series-autocomplete', window.BOOK_FORM_ROUTES.seriesAutocomplete);
                        }
                        if (match.cover_image_url) {
                            const proxiedUrl = googleBooksProxyUrl(match.cover_image_url);
                            $container.find('#google-books-candidate').remove();
                            var candidateHtml = `<div class="text-center" id="google-books-candidate">
                                <label>
                                <input type="radio" name="cover_image_candidate" value="${match.cover_image_url}" checked>
                                <br>
                                <img src="${proxiedUrl}" alt="Google Books Cover" style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">
                                </label>
                                <div style="font-size:12px;word-break:break-all;">Google Books</div>
                                </div>`;
                            if ($container.find('#cover-candidates-list').length) {
                                $container.find('#cover-candidates-list').append(candidateHtml);
                            } else {
                                $container.find('#cover-preview-img').attr('src', proxiedUrl);
                                $container.find('#cover-preview-group').show();
                            }
                        }
                        let hidden = $container.find('#cover_image_url');
                        if (!hidden.length) {
                           hidden = $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo($container.find('#book-form'));
                        }
                        hidden.val(match.cover_image_url || '');
                    });
                    if (data.maxed || window.googleBooksMatchLimit >= 40) {
                        $container.find('#autofill-btn').prop('disabled', true).html('<i class="fas fa-check"></i> All Results Shown');
                    } else {
                        $container.find('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Get More Matches');
                        window.googleBooksMoreMatches = true;
                        window.googleBooksMatchLimit += 10;
                    }
                } else {
                    $container.find('#google-books-matches-table-wrapper').hide();
                    $container.find('#autofill-btn').html('<i class="fas fa-search"></i> Autofill from Google Books');
                    window.googleBooksMoreMatches = false;
                    window.googleBooksMatchLimit = 10;
                }
            })
            .catch(() => {
                $container.find('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                $container.find('#title').addClass('is-invalid').next('.invalid-feedback').remove();
                $container.find('#title').after('<span class="invalid-feedback d-block">Failed to fetch book info.</span>');
            });
*/
// All AJAX autofill modal logic is now inside the event handler where $container is defined.
// No orphaned $container code remains here. Lint errors fixed.

// Helper for proxying Google Books cover images
function googleBooksProxyUrl(url) {
    if (url && url.match(/^https?:\/\/books\.google\.com\//)) {
        try {
            const encodedUrl =
                typeof btoa === "function"
                    ? btoa(encodeURIComponent(url))
                    : Buffer.from(url).toString("base64"); // Node.js fallback, not for browser
            return (window.APP_URL || "") + "/google-books-cover/" + encodedUrl;
        } catch (e) {
            console.error("Error encoding URL:", e);
            return url;
        }
    }
    return url;
}
window.googleBooksProxyUrl = googleBooksProxyUrl;

function loadDirectoryFiles($container) {
    console.log("loadDirectoryFiles called. Container:", $container);
    const filesList = $container.find("#directory-files-list");
    if (!filesList.length) {
        console.error('[DEBUG] #directory-files-list not found in container');
        return;
    }
    let $viewFilesBtn = $container.find("#show-files-link");
    if (!$viewFilesBtn.length) {
        console.error('[DEBUG] #show-files-link not found in container');
        return;
    }
    // Store original button HTML if not already stored
    if (!$viewFilesBtn.data('originalHtml')) {
        $viewFilesBtn.data('originalHtml', $viewFilesBtn.html());
    }
    // Toggle: If visible, hide and return
    if (filesList.is(":visible")) {
        filesList.slideUp();
        let origHtml = $viewFilesBtn.data('originalHtml');
        if (!origHtml) origHtml = '<i class="fas fa-folder"></i> View Directory Files';
        $viewFilesBtn.html(origHtml);
        console.log('[DEBUG] Directory files box hidden (toggle off), button text reset');
        return;
    }
    // Show: set button text to Hide Directory Files
    $viewFilesBtn.html('<i class="fas fa-folder-open"></i> Hide Directory Files');
    // Debug: Print all inputs with id directoryPath in container
    const $dirInput = $container.find("#directoryPath");
    console.log('[DEBUG] loadDirectoryFiles: #directoryPath inputs found:', $dirInput.length);
    if ($dirInput.length > 0) {
        console.log('[DEBUG] loadDirectoryFiles: #directoryPath value:', $dirInput.val());
    } else {
        console.warn('[DEBUG] loadDirectoryFiles: #directoryPath not found in container');
    }
    const dirPath = $dirInput.val();

    if (!dirPath) {
        filesList
            .html(
                '<div class="p-3 text-danger">Please select a directory first.</div>',
            )
            .show(); // Or slideDown()
        return;
    }
    const originalBtnHtml = $viewFilesBtn.html();
    $viewFilesBtn
        .prop("disabled", true)
        .html('<i class="fas fa-spinner fa-spin me-1"></i>Loading...');
    filesList
        .html(
            '<div class="text-center p-3"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading files...</div>',
        )
        .show(); // Or slideDown()

    const filesAjaxUrl =
        window.BOOK_FORM_ROUTES.filesAjax || "/admin/books/files-ajax"; // Fallback

    $.ajax({
        url: filesAjaxUrl,
        method: "GET",
        data: { directory: dirPath },
        dataType: "json",
        success: function (response) {
            $viewFilesBtn.prop("disabled", false).html(originalBtnHtml);
            let html = "";
            let files = [];
            if (typeof response === "string")
                try {
                    response = JSON.parse(response);
                } catch (e) {
                    console.error("Error parsing response:", e);
                }
            if (response && response.files && Array.isArray(response.files))
                files = response.files;
            else if (response && Array.isArray(response)) files = response;
            else if (response && response.data && Array.isArray(response.data))
                files = response.data;

            if (files.length > 0) {
                html = '<div class="list-group list-group-flush">';
                files.forEach(function (file) {
                    if (!file) return;
                    const filename =
                        typeof file === "string"
                            ? file
                            : file.name || file.filename || "";
                    if (!filename) return;
                    const isImage = /(\.(jpg|jpeg|png|gif|webp))$/i.test(
                        filename,
                    );
                    const isAudio = /(\.(mp3|m4b|m4a|ogg|wav|flac))$/i.test(
                        filename,
                    );
                    let icon = "📄";
                    if (isImage) icon = "🖼️";
                    else if (isAudio) icon = "🔊";
                    html += `<div class="list-group-item p-2"><div class="d-flex align-items-center"><span class="me-2">${icon}</span><span class="text-truncate">${filename}</span></div></div>`;
                });
                html += "</div>";
            } else {
                html =
                    '<div class="p-3 text-muted text-center">No files found in this directory.</div>';
            }
            filesList.html(html).show(); // Or slideDown()
        },
        error: function (xhr, status, error) {
            $viewFilesBtn.prop("disabled", false).html(originalBtnHtml);
            filesList
                .html(
                    '<div class="p-3 text-danger">Error loading files. Please check the console.</div>',
                )
                .show(); // Or slideDown()
        },
    });
}
window.loadDirectoryFiles = loadDirectoryFiles; // Expose if called by onclick


window.initBookForm = function (formContainerSelector) {
    console.log("initBookForm - BOOK_FORM_ROUTES:", window.BOOK_FORM_ROUTES);
    const $container = $(formContainerSelector); // Scope all operations to this container

    // Log DOM structure for debug
    console.log(
        "initBookForm: #authors-group children:",
        $container.find("#authors-group").html(),
    );
    console.log(
        "initBookForm: #series-group children:",
        $container.find("#series-group").html(),
    );
    console.log(
        "initBookForm: #genres-group children:",
        $container.find("#genres-group").html(),
    );

    // Initialize dynamic row buttons
    updateAddRowButtons(
        $container,
        "#authors-group",
        ".author-row",
        ".add-author-row",
    );
    updateAddRowButtons(
        $container,
        "#series-group",
        ".series-row",
        ".add-series-row",
    );
    updateAddRowButtons(
        $container,
        "#genres-group",
        ".genre-row",
        ".add-genre-row",
    );

    // Initialize autocomplete for all author and series fields on page load
    if (typeof initializeAutocomplete === "function") {
        initializeAutocomplete(
            $container,
            ".author-autocomplete",
            window.BOOK_FORM_ROUTES.authorsAutocomplete,
        );
        initializeAutocomplete(
            $container,
            ".series-autocomplete",
            window.BOOK_FORM_ROUTES.seriesAutocomplete,
        );
    }

    // Event delegation for add row buttons
    $container
        .off("click", ".add-author-row")
        .on("click", ".add-author-row", function () {
            console.log("add-author-row clicked");
            addAuthorRow($container);
        });
    $container
        .off("click", ".add-series-row")
        .on("click", ".add-series-row", function () {
            console.log("add-series-row clicked");
            addSeriesRow($container);
        });
    $container
        .off("click", ".add-genre-row")
        .on("click", ".add-genre-row", function () {
            console.log("add-genre-row clicked");
            addGenreRow($container);
        });

    // Event delegation for remove row buttons
    $container
        .off("click", ".remove-author")
        .on("click", ".remove-author", function () {
            console.log("remove-author clicked");
            $(this).closest(".author-row").remove();
            updateAddRowButtons(
                $container,
                "#authors-group",
                ".author-row",
                ".add-author-row",
            );
        });
    $container
        .off("click", ".remove-series")
        .on("click", ".remove-series", function () {
            console.log("remove-series clicked");
            $(this).closest(".series-row").remove();
            updateAddRowButtons(
                $container,
                "#series-group",
                ".series-row",
                ".add-series-row",
            );
        });
    $container
        .off("click", ".remove-genre")
        .on("click", ".remove-genre", function () {
            console.log("remove-genre clicked");
            $(this).closest(".genre-row").remove();
            updateAddRowButtons(
                $container,
                "#genres-group",
                ".genre-row",
                ".add-genre-row",
            );
        });

    // Event listener for viewing directory files
    $container
        .off("click", "#show-files-link")
        .on("click", "#show-files-link", function (e) {
            e.preventDefault(); // Prevent the default anchor behavior
            console.log('[DEBUG] #show-files-link clicked');
            loadDirectoryFiles($container);
        }); // Confirm handler attached

    // Attach event handler for Autofill Modal button
    $container
        .off('click', '#autofill-modal-btn')
        .on('click', '#autofill-modal-btn', function (e) {
            e.preventDefault();
            console.log('[DEBUG] #autofill-modal-btn clicked');
            if (typeof bootstrap !== 'undefined') {
                var modalEl = document.getElementById('autofillModal');
                if (modalEl) {
                    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    bsModal.show();
                    console.log('[DEBUG] Autofill modal shown');
                } else {
                    console.error('[DEBUG] #autofillModal element not found');
                }
            } else {
                console.error('[DEBUG] Bootstrap JS not loaded');
            }
        });
};

document.addEventListener("DOMContentLoaded", function () {
    // Always bind autofill modal button globally after DOM is ready

    const $bookForm = $("#book-form");
    if ($bookForm.length) {
        // Check if it's part of a modal
        if (!$bookForm.closest(".modal").length) {
            console.log(
                "DOMContentLoaded: Initializing #book-form (not in a modal).",
            );
            initBookForm($bookForm); // Pass the jQuery object for #book-form
        } else {
            console.log(
                "DOMContentLoaded: #book-form found, but it is inside a modal. Initialization will be handled by modal events.",
            );
            // For modals, initBookForm is typically called when the modal is shown.
            // Ensure that logic exists elsewhere if this form can also be in a modal.
        }
    } else {
        console.log("DOMContentLoaded: #book-form not found.");
    }

    // Form validation
    const form = document.getElementById("book-form"); // Ensure your form has id="book-form"
    if (form) {
        form.addEventListener("submit", function (e) {
            // Clear previous validation
            form.querySelectorAll(".is-invalid").forEach((field) =>
                field.classList.remove("is-invalid"),
            );
            form.querySelectorAll(".invalid-feedback.d-block").forEach((msg) =>
                msg.remove(),
            );

            let hasError = false;

            const dirPathInput = form.querySelector(
                'input[name="directoryPath"]',
            );
            if (dirPathInput && dirPathInput.value) {
                dirPathInput.value = dirPathInput.value.replace(/^\/+/, "");
            }

            const titleInput = form.querySelector('input[name="title"]');
            if (!titleInput || !titleInput.value.trim()) {
                titleInput.classList.add("is-invalid");
                $(titleInput).after(
                    '<span class="invalid-feedback d-block">Title is required.</span>',
                );
                hasError = true;
            }

            const authorInputs = form.querySelectorAll(
                'input[name="author[]"]',
            );
            let hasAuthor = false;
            authorInputs.forEach((input) => {
                if (input.value.trim()) hasAuthor = true;
            });
            if (!hasAuthor && authorInputs.length > 0) {
                authorInputs[0].classList.add("is-invalid");
                $(authorInputs[0].closest(".input-group")).after(
                    '<span class="invalid-feedback d-block">At least one author is required.</span>',
                );
                hasError = true;
            } else if (authorInputs.length === 0) {
                // No author input fields at all
                const authorsGroup = document.getElementById("authors-group");
                $(authorsGroup).after(
                    '<span class="invalid-feedback d-block">At least one author is required.</span>',
                );
                hasError = true;
            }

            const genreSelects = form.querySelectorAll(
                'select[name="genre[]"]',
            );
            let hasGenre = false;
            genreSelects.forEach((select) => {
                if (select.value) hasGenre = true;
            });
            if (!hasGenre && genreSelects.length > 0) {
                genreSelects[0].classList.add("is-invalid");
                $(genreSelects[0].closest(".input-group")).after(
                    '<span class="invalid-feedback d-block">At least one genre is required.</span>',
                );
                hasError = true;
            } else if (genreSelects.length === 0) {
                const genresGroup = document.getElementById("genres-group");
                $(genresGroup).after(
                    '<span class="invalid-feedback d-block">At least one genre is required.</span>',
                );
                hasError = true;
            }

            console.log('[DEBUG] book-form submit event fired');
            if (hasError) {
                console.log('[DEBUG] Validation failed, preventing submit');
                e.preventDefault();
                const firstError = form.querySelector(".is-invalid");
                if (firstError)
                    firstError.scrollIntoView({
                        behavior: "smooth",
                        block: "center",
                    });
                console.log('[DEBUG] Form will not submit due to validation errors');
                return false;
            }

            // Handle AJAX form submission if in modal
            var $form = $(this);
            var $modal = $form.closest(".modal");

            if ($modal.length) {
                e.preventDefault();
                var url = $form.attr("action");
                var method =
                    $form.find('input[name="_method"]').val() || "POST";
                var formData = new FormData(this);

                const submitButton = form.querySelector(
                    'button[type="submit"]',
                );
                const originalButtonText = submitButton
                    ? submitButton.innerHTML
                    : "";
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML =
                        '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                }

                $.ajax({
                    url: url,
                    type: method,
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: {
                        "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                            "content",
                        ),
                    },
                    success: function (data) {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.innerHTML = originalButtonText;
                        }
                        var modalEl = $modal[0];
                        var bsModal = bootstrap.Modal.getInstance(modalEl);
                        if (bsModal) bsModal.hide();
                        $(document).trigger("book:updated", data); // Custom event for other parts of app to listen to
                        if (
                            window.BOOK_FORM_ROUTES &&
                            window.BOOK_FORM_ROUTES.index
                        ) {
                            // Optionally redirect or refresh part of the page
                            // Example: if (data.redirect_url) window.location.href = data.redirect_url;
                        }
                    },
                    error: function (xhr) {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.innerHTML = originalButtonText;
                        }
                        let msg = "Failed to save book.";
                        if (xhr.responseJSON && xhr.responseJSON.message)
                            msg = xhr.responseJSON.message;
                        if (xhr.responseJSON && xhr.responseJSON.errors) {
                            // Display validation errors
                            $.each(
                                xhr.responseJSON.errors,
                                function (key, value) {
                                    const inputField = form.querySelector(
                                        `[name^="${key}"]`,
                                    );
                                    if (inputField) {
                                        inputField.classList.add("is-invalid");
                                        $(
                                            inputField.closest(
                                                ".input-group",
                                            ) || inputField,
                                        ).after(
                                            '<span class="invalid-feedback d-block">' +
                                                value[0] +
                                                "</span>",
                                        );
                                    }
                                },
                            );
                        } else {
                            $form
                                .find("#title")
                                .addClass("is-invalid")
                                .next(".invalid-feedback.d-block")
                                .remove();
                            $form
                                .find("#title")
                                .after(
                                    '<span class="invalid-feedback d-block">' +
                                        msg +
                                        "</span>",
                                );
                        }
                        const firstErrorField =
                            form.querySelector(".is-invalid");
                        if (firstErrorField)
                            firstErrorField.scrollIntoView({
                                behavior: "smooth",
                                block: "center",
                            });
                    },
                });
                console.log('[DEBUG] Modal AJAX submit, returning false');
                return false;
            }
            // Non-modal: allow default submit
            console.log('[DEBUG] No errors, not in modal, allowing normal form submit');
        });
    }

    // Bootstrap modal cancel button specific handler if needed
    if (typeof window.bootstrap !== "undefined") {
        document
            .querySelectorAll(
                '.modal .btn-close, .modal [data-bs-dismiss="modal"]',
            )
            .forEach((btn) => {
                btn.addEventListener("click", function () {
                    const modalEl = this.closest(".modal");
                    if (modalEl) {
                        var bsModal = bootstrap.Modal.getInstance(modalEl);
                        if (bsModal && bsModal._isShown) {
                            // bsModal.hide(); // Already handled by data-bs-dismiss
                        }
                    }
                });
            });
    }
});
console.log("Form JS loaded 5");
