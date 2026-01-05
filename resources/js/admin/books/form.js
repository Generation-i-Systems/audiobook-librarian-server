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
    const group = $container.find("#authors-group")[0];
    if (!group) return;
    const div = document.createElement("div");
    div.className = "d-flex align-items-start mb-2 author-row";
    div.innerHTML = `
        <input type="text" name="author[]" class="form-control author-autocomplete" value="${authorName}" style="height:32px; flex:1;" placeholder="Author Name" required>
        <div class="d-flex flex-column ms-2" style="gap:2px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-author" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-author-row" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">+</button>
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

// Function to add a new narrator row
function addNarratorRow($container, narratorName = "") {
    const group = $container.find("#narrators-group")[0];
    if (!group) return;
    const div = document.createElement("div");
    div.className = "d-flex align-items-start mb-2 narrator-row";
    div.innerHTML = `
        <input type="text" name="narrator[]" class="form-control narrator-autocomplete" value="${narratorName}" style="height:32px; flex:1;" placeholder="Narrator Name">
        <div class="d-flex flex-column ms-2" style="gap:2px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-row" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-narrator-row" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">+</button>
        </div>`;
    group.appendChild(div);
    if (
        typeof initializeAutocomplete === "function" &&
        window.BOOK_FORM_ROUTES.narratorsAutocomplete
    ) {
        initializeAutocomplete(
            $(div),
            ".narrator-autocomplete",
            window.BOOK_FORM_ROUTES.narratorsAutocomplete,
        );
    }
    updateAddRowButtons(
        $container,
        "#narrators-group",
        ".narrator-row",
        ".add-narrator-row",
    );
}
window.addNarratorRow = addNarratorRow;

// Function to add a new series row
function addSeriesRow($container, seriesName = "", seriesNumber = "") {
    const group = $container.find("#series-group")[0];
    if (!group) return;
    const idx = group.querySelectorAll(".series-row").length;
    const div = document.createElement("div");
    div.className = "d-flex align-items-start mb-2 series-row";
    div.innerHTML = `
        <input type="number" name="series[${idx}][number]" class="form-control" style="width:80px; height:32px; flex-shrink:0;" placeholder="#" value="${seriesNumber}" step="any">
        <input type="text" name="series[${idx}][seriesName]" class="form-control series-autocomplete ms-2" style="height:32px; flex:1;" placeholder="Series Name" value="${seriesName}">
        <div style="width:32px; height:32px; margin-left:0.5rem; flex-shrink:0;"></div>
        <div class="d-flex flex-column ms-2" style="gap:2px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-series" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-series-row" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">+</button>
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
    const group = $container.find("#genres-group")[0];
    if (!group) return;
    const div = document.createElement("div");
    div.className = "d-flex align-items-start mb-2 genre-row";
    let optionsHtml = '<option value="">Select a genre</option>';
    (window.GENRE_OPTIONS || []).forEach((g) => {
        optionsHtml += `<option value="${g}" ${selectedGenre === g ? "selected" : ""}>${g}</option>`;
    });
    div.innerHTML = `
        <select name="genre[]" class="form-select" style="height:32px; flex:1;" required>
            ${optionsHtml}
        </select>
        <div class="d-flex flex-column ms-2" style="gap:2px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-genre" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
            <button type="button" class="btn btn-primary btn-sm add-genre-row" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">+</button>
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
$(function () {
    var $rawJsonBtn = $("#raw-json-edit-btn");
    if ($rawJsonBtn.length) {
        // Button is outside form, so get book ID from form action or page URL
        var bookId = null;
        var $form = $("#book-form");
        if ($form.length) {
            var formAction = $form.attr("action");
            if (formAction) {
                var match = formAction.match(/books\/(\w+)/);
                bookId = match ? match[1] : null;
            }
        }
        // Fallback: try to get from URL
        if (!bookId) {
            var urlMatch = window.location.pathname.match(/books\/(\w+)/);
            bookId = urlMatch ? urlMatch[1] : null;
        }
        $rawJsonBtn.on("click", function () {
            if (!bookId) return;
            $("#raw-json-error").hide();
            $.get("/admin/books/" + bookId + "/raw-json", function (data) {
                $("#raw-json-textarea").val(JSON.stringify(data, null, 2));
                $("#rawJsonModal").modal("show");
            }).fail(function (xhr) {
                $("#raw-json-textarea").val("");
                $("#raw-json-error")
                    .text("Failed to load JSON: " + xhr.status)
                    .show();
                $("#rawJsonModal").modal("show");
            });
        });
        $("#save-raw-json-btn").on("click", function () {
            var json;
            try {
                json = JSON.parse($("#raw-json-textarea").val());
            } catch (e) {
                $("#raw-json-error")
                    .text("Invalid JSON: " + e.message)
                    .show();
                return;
            }
            $.ajax({
                url: "/admin/books/" + bookId + "/raw-json",
                type: "POST",
                data: { json: JSON.stringify(json) },
                headers: {
                    "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                        "content",
                    ),
                },
                success: function () {
                    window.location.reload();
                },
                error: function (xhr) {
                    var msg =
                        xhr.responseJSON && xhr.responseJSON.message
                            ? xhr.responseJSON.message
                            : "Failed to save JSON.";
                    $("#raw-json-error").text(msg).show();
                },
            });
        });
    }
});

// Helper function to initialize jQuery UI Autocomplete
function initializeAutocomplete($container, selector, sourceUrl) {
    console.log("[DEBUG] initializeAutocomplete called for selector:", selector, "with URL:", sourceUrl);

    // Check if jQuery UI autocomplete is available
    if (!$.fn.autocomplete) {
        console.error("[ERROR] jQuery UI autocomplete is not available!");
        return;
    }

    if (!sourceUrl) {
        console.error("[ERROR] No sourceUrl provided for selector:", selector);
        return;
    }

    $container.on("focus", selector, function () {
        const $inputField = $(this); // Capture 'this' to use in callbacks

        console.log("[DEBUG] Focus event on", selector, "- checking initialization");

        // Check if autocomplete has already been initialized on this element
        if (!$inputField.data("autocomplete-initialized")) {
            console.log("[DEBUG] Initializing autocomplete for field:", $inputField.attr('name'));

            try {
                $inputField.autocomplete({
                    minLength: 2,
                    source: function (request, responseCallback) {
                        console.log("[DEBUG] Autocomplete source called for term:", request.term);
                        $.ajax({
                            url: sourceUrl,
                            dataType: "json",
                            data: {
                                term: request.term,
                            },
                            async: true, // Explicitly ensure the request is asynchronous
                            success: function (data) {
                                console.log("[DEBUG] Autocomplete response:", data);

                                // Transform data to ensure it's in the correct format for jQuery UI
                                // jQuery UI expects either an array of strings or objects with {label, value}
                                let transformedData = data;

                                if (Array.isArray(data) && data.length > 0) {
                                    // Check if first item is an object (not a string)
                                    if (typeof data[0] === 'object' && data[0] !== null) {
                                        console.log("[DEBUG] Transforming object response to strings");
                                        // Extract the name/value from objects
                                        transformedData = data.map(item => {
                                            if (typeof item === 'string') {
                                                return item;
                                            }
                                            // Try common property names
                                            return item.name || item.value || item.label || item.title || JSON.stringify(item);
                                        });
                                        console.log("[DEBUG] Transformed data:", transformedData);
                                    }
                                }

                                responseCallback(transformedData); // Provide the data to jQuery UI
                            },
                            error: function (jqXHR, textStatus, errorThrown) {
                                console.error("[ERROR] Autocomplete AJAX failed:", textStatus, errorThrown);
                                responseCallback([]); // Call with empty array on error
                            },
                        });
                    },
                    select: function (event, ui) {
                        event.preventDefault(); // Prevent any default behavior that might trigger form submission
                        $inputField.val(ui.item.value); // Set the input field's value to the selected item
                        console.log("[DEBUG] Autocomplete item selected:", ui.item.value);
                        return false; // Prevent the default behavior of setting the value, as we did it manually
                    },
                });
                $inputField.data("autocomplete-initialized", true); // Mark as initialized
                console.log("[DEBUG] Autocomplete successfully initialized for", $inputField.attr('name'));
            } catch (error) {
                console.error("[ERROR] Failed to initialize autocomplete:", error);
            }
        } else {
            console.log("[DEBUG] Autocomplete already initialized for this field");
        }
    });
}

/*
        const authorInputs = $container.find('input[name="author[]"]');
        const authorName = $(authorInputs[0]).val().trim();
        const series = $container.find('#series-select option:selected').text() || ''; // Assuming TomSelect for series, otherwise adjust
        const seriesNumber = $container.find('#series-group input[name*="[number]"]').first().val() || ''; // Assuming first series number
        $container.find('#autofill-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Searching...');

        const googleBooksUrl = window.BOOK_FORM_ROUTES.googleBooks || '/admin/books/google-books'; // Fallback if not defined

        fetch(`${googleBooksUrl}?title=${encodeURIComponent(title)}&author=${encodeURIComponent(authorName)}&series=${encodeURIComponent(series)}&series_number=${encodeURIComponent(seriesNumber)}&limit=${window.googleBooksMatchLimit}${window.googleBooksMoreMatches ? '&more=1' : ''}`)
            .then(response => response.json())
            .then(data => {
                $container.find('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                if (data.match_type === 'close') {
                    if (data.published_year) {
                        // Convert year to date format
                        const year = data.published_year;
                        $container.find('#release_date').val(year + '-01-01');
                    }
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
                        if (match.published_year) {
                            // Convert year to date format
                            $container.find('#release_date').val(match.published_year + '-01-01');
                        }
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
                            addSeriesRow($container, item.seriesName || item.name, item.number);
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

// Helper function to format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function loadDirectoryFiles($container) {
    console.log("loadDirectoryFiles called. Container:", $container);
    const filesList = $container.find("#directory-files-list");
    if (!filesList.length) {
        console.error("[DEBUG] #directory-files-list not found in container");
        return;
    }
    let $viewFilesBtn = $container.find("#show-files-link");
    if (!$viewFilesBtn.length) {
        console.error("[DEBUG] #show-files-link not found in container");
        return;
    }
    // Store original button HTML if not already stored
    if (!$viewFilesBtn.data("originalHtml")) {
        $viewFilesBtn.data("originalHtml", $viewFilesBtn.html());
    }
    // Toggle: If visible, hide and return
    if (filesList.is(":visible")) {
        filesList.slideUp();
        let origHtml = $viewFilesBtn.data("originalHtml");
        if (!origHtml)
            origHtml = '<i class="fas fa-folder"></i> View Directory Files';
        $viewFilesBtn.html(origHtml);
        console.log(
            "[DEBUG] Directory files box hidden (toggle off), button text reset",
        );
        return;
    }
    // Show: set button text to Hide Directory Files
    $viewFilesBtn.html(
        '<i class="fas fa-folder-open"></i> Hide Directory Files',
    );
    // Debug: Print all inputs with id directoryPath in container
    const $dirInput = $container.find("#directoryPath");
    console.log(
        "[DEBUG] loadDirectoryFiles: #directoryPath inputs found:",
        $dirInput.length,
    );
    if ($dirInput.length > 0) {
        console.log(
            "[DEBUG] loadDirectoryFiles: #directoryPath value:",
            $dirInput.val(),
        );
    } else {
        console.warn(
            "[DEBUG] loadDirectoryFiles: #directoryPath not found in container",
        );
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

            // Check if directory exists
            if (response.exists === false) {
                html = '<div class="p-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Directory not found</div>';
                filesList.html(html);
                return;
            }

            let files = response.files || [];

            if (files.length > 0) {
                html = '<div class="list-group list-group-flush">';
                files.forEach(function (file) {
                    if (!file) return;
                    const filename = file.name || file.filename || "Unknown";
                    const size = file.size ? formatFileSize(file.size) : '';
                    const ext = file.extension || '';

                    // Choose icon based on file type
                    let icon = 'fa-file';
                    let isImage = false;
                    let isAudio = false;
                    if (['mp3', 'm4b', 'm4a', 'aac', 'flac', 'ogg', 'wav'].includes(ext.toLowerCase())) {
                        icon = 'fa-file-audio text-primary';
                        isAudio = true;
                    } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext.toLowerCase())) {
                        icon = 'fa-file-image text-success';
                        isImage = true;
                    } else if (['txt', 'nfo', 'md'].includes(ext.toLowerCase())) {
                        icon = 'fa-file-alt text-info';
                    } else if (['json', 'xml'].includes(ext.toLowerCase())) {
                        icon = 'fa-file-code text-warning';
                    }

                    const itemClass = isImage ? 'list-group-item-action cursor-pointer' : '';
                    const dataAttrs = isImage ? 'data-cover-file="' + filename + '"' : '';

                    html += '<div class="list-group-item py-1 px-2 small d-flex justify-content-between align-items-center ' + itemClass + '" ' + dataAttrs + '>';
                    html += '<span><i class="fas ' + icon + ' me-2"></i>' + filename;
                    if (isImage) {
                        html += ' <small class="text-muted">(click to set as cover)</small>';
                    }
                    html += '</span>';
                    html += '<span class="d-flex align-items-center gap-2">';
                    if (size) {
                        html += '<span class="text-muted">' + size + '</span>';
                    }
                    if (isAudio) {
                        html += '<a href="#" class="btn btn-sm btn-outline-primary view-metadata" data-file="' + dirPath + '/' + filename + '" title="View Metadata"><i class="fas fa-info-circle"></i></a>';
                    }
                    html += '</span>';
                    html += '</div>';
                });
                html += "</div>";
            } else {
                html = '<div class="p-3 text-muted">No files found in this directory.</div>';
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
    console.log("[DEBUG] initBookForm called with", formContainerSelector);
    console.log("[DEBUG] UPDATED VERSION - Dec 12 2025");
    const $container = $(formContainerSelector); // Scope all operations to this container

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

    // Initialize autocomplete for all author, narrator, and series fields on page load
    if (typeof initializeAutocomplete === "function") {
        console.log("[DEBUG] Initializing autocomplete with routes:", window.BOOK_FORM_ROUTES);
        console.log("[DEBUG] jQuery UI autocomplete available:", typeof $.fn.autocomplete);

        initializeAutocomplete(
            $container,
            ".author-autocomplete",
            window.BOOK_FORM_ROUTES.authorsAutocomplete,
        );
        initializeAutocomplete(
            $container,
            ".narrator-autocomplete",
            window.BOOK_FORM_ROUTES.narratorsAutocomplete,
        );
        initializeAutocomplete(
            $container,
            ".series-autocomplete",
            window.BOOK_FORM_ROUTES.seriesAutocomplete,
        );
    } else {
        console.error("[DEBUG] initializeAutocomplete function not found!");
    }

    // Event delegation for add row buttons
    $container
        .off("click", ".add-author-row")
        .on("click", ".add-author-row", function () {
            addAuthorRow($container);
        });
    $container
        .off("click", ".add-narrator-row")
        .on("click", ".add-narrator-row", function () {
            addNarratorRow($container);
        })
        .off("click", ".remove-row")
        .on("click", ".remove-row", function () {
            const group = $container.find("#narrators-group")[0];
            const rows = group.querySelectorAll(".narrator-row");
            const row = $(this).closest(".narrator-row")[0];
            if (rows.length > 1) {
                if (row) row.remove();
            } else if (row) {
                // Only one row: clear the input
                const input = row.querySelector('input[name="narrator[]"]');
                if (input) input.value = "";
            }
            updateAddRowButtons(
                $container,
                "#narrators-group",
                ".narrator-row",
                ".add-narrator-row",
            );
        });
    $container
        .off("click", ".add-series-row")
        .on("click", ".add-series-row", function () {
            addSeriesRow($container);
        });
    $container
        .off("click", ".add-genre-row")
        .on("click", ".add-genre-row", function () {
            addGenreRow($container);
        });

    // Event delegation for remove row buttons
    $container
        .off("click", ".remove-author")
        .on("click", ".remove-author", function () {
            const group = $container.find("#authors-group")[0];
            const rows = group.querySelectorAll(".author-row");
            const row = $(this).closest(".author-row")[0];
            const rowIndex = Array.from(rows).indexOf(row);

            if (rowIndex === 0) {
                // First row: just clear the value
                row.querySelector('input[name="author[]"]').value = "";
            } else {
                // Other rows: remove the row
                row.remove();
            }
            updateAddRowButtons(
                $container,
                "#authors-group",
                ".author-row",
                ".add-author-row",
            );
        })
        .off("click", ".remove-series")
        .on("click", ".remove-series", function () {
            const group = $container.find("#series-group")[0];
            const rows = group.querySelectorAll(".series-row");
            const row = $(this).closest(".series-row")[0];
            if (rows.length > 1) {
                if (row) row.remove();
            } else if (row) {
                // Only one row: clear the inputs
                const nameInput = row.querySelector(
                    'input[name^="series"][name$="[seriesName]"]',
                );
                const numberInput = row.querySelector(
                    'input[name^="series"][name$="[number]"]',
                );
                if (nameInput) nameInput.value = "";
                if (numberInput) numberInput.value = "";
            }
            updateAddRowButtons(
                $container,
                "#series-group",
                ".series-row",
                ".add-series-row",
            );
        });
    // Legacy: .remove-row handler for narrators
    $container
        .off("click", ".remove-row")
        .on("click", ".remove-row", function () {
            const group = $container.find("#narrators-group")[0];
            const rows = group.querySelectorAll(".narrator-row");
            const row = $(this).closest(".narrator-row")[0];
            const rowIndex = Array.from(rows).indexOf(row);

            if (rowIndex === 0) {
                // First row: just clear the value
                row.querySelector('input[name="narrator[]"]').value = "";
            } else {
                // Other rows: remove the row
                row.remove();
            }
            updateAddRowButtons(
                $container,
                "#narrators-group",
                ".narrator-row",
                ".add-narrator-row",
            );
        });
    $container
        .off("click", ".remove-genre")
        .on("click", ".remove-genre", function () {
            const group = $container.find("#genres-group")[0];
            const rows = group.querySelectorAll(".genre-row");
            const row = $(this).closest(".genre-row")[0];
            const rowIndex = Array.from(rows).indexOf(row);

            if (rowIndex === 0) {
                if (rows.length > 1) {
                    const secondRow = rows[1];
                    const secondSelect = secondRow.querySelector('select[name="genre[]"]');
                    const firstSelect = row.querySelector('select[name="genre[]"]');
                    firstSelect.value = secondSelect.value;
                    secondRow.remove();
                } else {
                    row.querySelector('select[name="genre[]"]').value = "";
                }
            } else {
                row.remove();
            }
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
            loadDirectoryFiles($container);
        }); // Confirm handler attached

    // Event listener for selecting cover image from directory files
    $container
        .off("click", "[data-cover-file]")
        .on("click", "[data-cover-file]", function (e) {
            e.preventDefault();
            const filename = $(this).data("cover-file");
            const dirPath = $container.find("#directoryPath").val();

            if (!filename || !dirPath) {
                alert("Unable to set cover - missing filename or directory path");
                return;
            }

            // Set the cover image field to the selected filename
            const $coverInput = $container.find("#coverImage");
            if ($coverInput.length) {
                $coverInput.val(filename);

                // Trigger change event to update any preview
                $coverInput.trigger("change");

                // Show success message
                $(this).addClass("bg-success text-white");
                setTimeout(() => {
                    $(this).removeClass("bg-success text-white");
                }, 1000);

                // If there's a cover preview, update it
                const coverUrl = window.BOOK_FORM_ROUTES?.filesAjax
                    ? "/cover/" + dirPath + "/" + filename
                    : "";
                if (coverUrl) {
                    $container.find(".cover-preview img").attr("src", coverUrl);
                }

                console.log("Cover image set to:", filename);
            } else {
                console.error("Cover image input field not found");
            }
        });

    // Event listener for directory path changes - reload cover image
    $container.find("#directoryPath").on("change", function () {
        const newPath = $(this).val();
        const $coverInput = $container.find("#coverImage");
        const coverFilename = $coverInput.val();

        if (newPath && coverFilename) {
            // Update cover preview with new path
            const coverUrl = "/cover/" + newPath + "/" + coverFilename;
            const $coverPreview = $container.find(".cover-preview img, img[alt='cover'], .cover-option img");

            if ($coverPreview.length) {
                $coverPreview.each(function() {
                    const $img = $(this);
                    // Only update if it's showing a cover from the directory (not external URLs)
                    const currentSrc = $img.attr("src");
                    if (currentSrc && currentSrc.includes("/cover/")) {
                        $img.attr("src", coverUrl);
                        console.log("Updated cover preview to:", coverUrl);
                    }
                });
            }
        }
    });

    // Auto-split authors on blur if multiple authors detected
    $container
        .off("blur", ".author-autocomplete")
        .on("blur", ".author-autocomplete", function () {
            const $input = $(this);
            const value = $input.val().trim();

            if (!value) return;

            // Check for any separators: &, ,, or "and"
            const hasSeparators = value.includes('&') || value.includes(',') || / and /i.test(value);

            if (hasSeparators) {
                // Replace all separator types with a uniform delimiter (|) for easy splitting
                // Handle word boundaries for "and" to avoid matching words like "Andrew" or "Brandon"
                let normalized = value
                    .replace(/\s*&\s*/g, '|')           // Replace & with |
                    .replace(/\s*,\s*/g, '|')           // Replace , with |
                    .replace(/\s+and\s+/gi, '|');       // Replace " and " with | (case insensitive)

                // Split by the delimiter and clean up
                const authors = normalized
                    .split('|')
                    .map(a => a.trim())
                    .filter(a => a.length > 0);

                // Only offer to split if we actually have multiple authors
                if (authors.length > 1) {
                    const authorList = authors.map((a, idx) => `${idx + 1}. ${a}`).join('\n');

                    if (confirm(`Split into ${authors.length} separate authors?\n\n${authorList}\n\nClick OK to split, or Cancel to keep as-is.`)) {
                        // Clear the current input
                        $input.val('');

                        // Get the current row
                        const $currentRow = $input.closest('.author-row');
                        const $authorsGroup = $container.find('#authors-group');

                        // Remove all existing author rows except the first one
                        $authorsGroup.find('.author-row:not(:first)').remove();

                        // Set first author in the first row
                        $authorsGroup.find('.author-row:first input[name="author[]"]').val(authors[0]);

                        // Add remaining authors
                        for (let i = 1; i < authors.length; i++) {
                            addAuthorRow($container, authors[i]);
                        }

                        // Update button positions
                        updateAddRowButtons(
                            $container,
                            "#authors-group",
                            ".author-row",
                            ".add-author-row"
                        );

                        console.log("[DEBUG] Split authors:", authors);
                    }
                }
            }
        });

};

// Attach event handler for Autofill Modal button (globally, outside form)
$(document).on("click", "#autofill-modal-btn", function (e) {
    e.preventDefault();
    console.log("[DEBUG] Autofill modal button clicked");
    let bootstrapRef = null;
    if (typeof bootstrap !== "undefined") {
        bootstrapRef = bootstrap;
    } else if (
        typeof window !== "undefined" &&
        typeof window.bootstrap !== "undefined"
    ) {
        bootstrapRef = window.bootstrap;
    } else {
        try {
            bootstrapRef = require("bootstrap");
        } catch (e) {
            bootstrapRef = null;
        }
    }
    if (bootstrapRef && typeof bootstrapRef.Modal !== "undefined") {
        var modalEl = document.getElementById("autofillModal");
        if (modalEl) {
            var bsModal =
                bootstrapRef.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
        } else {
            console.error("[DEBUG] #autofillModal element not found");
        }
    }
});

// Auto-search when autofill modal is shown
$("#autofillModal").on("shown.bs.modal", function () {
    console.log("[DEBUG] Autofill modal shown, auto-searching all sources");

    // Pre-populate search fields from the current form
    const currentTitle = $("#title").val();
    const currentAuthor = $("#authors-group").find('input[name="author[]"]').first().val();
    const currentSeries = $("#series-group").find('input[name="series[]"]').first().val();

    if (currentTitle) {
        $("#autofill-title").val(currentTitle);
    }
    if (currentAuthor) {
        $("#autofill-author").val(currentAuthor);
    }
    if (currentSeries) {
        $("#autofill-series").val(currentSeries);
    }

    // Automatically trigger search for all sources if any search criteria exists
    if (currentTitle || currentAuthor || currentSeries) {
        console.log("[DEBUG] Auto-triggering search with criteria:", { title: currentTitle, author: currentAuthor, series: currentSeries });
        performAutofillSearch(["audible", "google", "hardcover"], false);
    }
});

document.addEventListener("DOMContentLoaded", function () {
    console.log("[DEBUG] DOM ready event fired");
    // Always bind autofill modal button globally after DOM is ready

    const $bookForm = $("#book-form");
    if ($bookForm.length) {
        console.log("[DEBUG] Book form found, initializing");
        // Initialize all book forms on page load, regardless of whether they're in a modal or not
        initBookForm($bookForm);

        // Ensure a cover image radio button is selected if any are available
        ensureCoverImageSelected();

        // Log whether this is a modal or non-modal form for debugging
        if ($bookForm.closest(".modal").length) {
            console.log("[DEBUG] Form is in a modal");

            // Also ensure modal forms are initialized when shown
            const modalEl = $bookForm.closest(".modal")[0];
            if (modalEl) {
                modalEl.addEventListener("shown.bs.modal", function () {
                    console.log(
                        "[DEBUG] Modal shown event, reinitializing form",
                    );
                    initBookForm($bookForm);
                    ensureCoverImageSelected();
                });
            }
        } else {
            console.log("[DEBUG] Form is not in a modal");
        }
    }

    // Handle cover image radio button selection
    const coverRadios = document.querySelectorAll('input[name="coverImageCandidate"]');
    coverRadios.forEach((radio) => {
        radio.addEventListener("change", function () {
            console.log("Cover image selected: " + this.value);
            console.log("Cover source: " + this.dataset.source);

            // Set the hidden coverImageSource field
            const coverImageSourceField = document.getElementById('coverImageSource');
            if (coverImageSourceField) {
                coverImageSourceField.value = this.dataset.source || '';
                console.log("Set coverImageSource to: " + coverImageSourceField.value);
            }

            // Preserve cover URL in hidden fields for validation failure recovery
            const source = this.dataset.source || '';
            const coverUrl = this.value;
            if (source === 'audible') {
                const audibleUrlField = document.getElementById('audibleCoverImageUrl');
                if (audibleUrlField) {
                    audibleUrlField.value = coverUrl;
                    console.log("[DEBUG] Set audibleCoverImageUrl to:", coverUrl);
                }
            } else if (source === 'google') {
                const googleUrlField = document.getElementById('coverImageUrl');
                if (googleUrlField) {
                    googleUrlField.value = coverUrl;
                    console.log("[DEBUG] Set coverImageUrl to:", coverUrl);
                }
            }

            // Update the corner preview image to show the selected cover
            const $cornerPreview = $("#cover-preview-trigger img");
            if ($cornerPreview.length) {
                // Find the image associated with this radio button
                const $radioLabel = $(this).closest('label');
                const $coverImg = $radioLabel.find('img');
                if ($coverImg.length) {
                    const newSrc = $coverImg.attr('src');
                    $cornerPreview.attr('src', newSrc);
                    console.log("[DEBUG] Updated corner preview to show selected cover:", newSrc);
                }
            }
        });
    });

    // Set initial value for coverImageSource if a radio is already checked
    const checkedCoverRadio = document.querySelector('input[name="coverImageCandidate"]:checked');
    if (checkedCoverRadio) {
        const coverImageSourceField = document.getElementById('coverImageSource');
        if (coverImageSourceField) {
            // Ensure we're getting the correct source value
            const source = checkedCoverRadio.dataset.source || '';
            console.log("[DEBUG] Initial checked radio data-source:", source);
            console.log("[DEBUG] Initial checked radio value:", checkedCoverRadio.value);

            coverImageSourceField.value = source;
            console.log("Initial coverImageSource set to: " + coverImageSourceField.value);
        }
    }

    // Force update the coverImageSource field now to ensure it's set correctly
    setTimeout(() => {
        const checkedRadio = document.querySelector('input[name="coverImageCandidate"]:checked');
        if (checkedRadio) {
            const sourceField = document.getElementById('coverImageSource');
            if (sourceField) {
                sourceField.value = checkedRadio.dataset.source || '';
                console.log("[DEBUG] Forced coverImageSource update to:", sourceField.value);
            }
        }
    }, 100);

    // Form validation
    const form = document.getElementById("book-form"); // Ensure your form has id="book-form"
    if (form) {
        form.addEventListener("submit", function (e) {
            function removeValidationSummary() {
                const existingSummary = document.getElementById("book-form-validation-summary");
                if (existingSummary) {
                    existingSummary.remove();
                }
            }

            function setValidationSummary(messages) {
                removeValidationSummary();

                if (!Array.isArray(messages) || messages.length === 0) {
                    return;
                }

                const summary = document.createElement("div");
                summary.id = "book-form-validation-summary";
                summary.className = "alert alert-danger";
                summary.setAttribute("role", "alert");

                const heading = document.createElement("div");
                heading.className = "fw-bold";
                heading.textContent = "Cannot save because there are validation errors:";
                summary.appendChild(heading);

                const list = document.createElement("ul");
                list.className = "mb-0";
                messages.forEach((message) => {
                    const li = document.createElement("li");
                    li.textContent = String(message);
                    list.appendChild(li);
                });
                summary.appendChild(list);

                form.insertAdjacentElement("afterbegin", summary);
            }

            function addFieldError(field, message) {
                if (!field) {
                    return;
                }

                try {
                    field.classList.add("is-invalid");
                } catch (error) {
                    // ignore
                }

                const feedback = document.createElement("span");
                feedback.className = "invalid-feedback d-block";
                feedback.textContent = String(message);

                const container =
                    field.closest(".input-group") ||
                    field.closest(".form-group") ||
                    field.closest(".author-row") ||
                    field.closest(".narrator-row") ||
                    field.closest(".series-row") ||
                    field.closest(".genre-row") ||
                    field.closest(".mb-3");

                if (container && container !== field) {
                    container.insertAdjacentElement("afterend", feedback);
                    return;
                }

                field.insertAdjacentElement("afterend", feedback);
            }

            // CRITICAL: Update coverImageSource right before form submission
            const checkedRadioButton = document.querySelector('input[name="coverImageCandidate"]:checked');
            if (checkedRadioButton) {
                const coverSourceField = document.getElementById('coverImageSource');
                if (coverSourceField) {
                    const sourceValue = checkedRadioButton.dataset.source || '';
                    coverSourceField.value = sourceValue;
                    console.log("[SUBMIT] Setting coverImageSource to:", sourceValue);

                    // Force a data attribute to the form to ensure it's used
                    form.dataset.coverSource = sourceValue;
                }
            }

            // Clear previous validation
            form.querySelectorAll(".is-invalid").forEach((field) =>
                field.classList.remove("is-invalid"),
            );
            form.querySelectorAll(".invalid-feedback.d-block").forEach((msg) =>
                msg.remove(),
            );

            removeValidationSummary();

            // Disable submit button and show spinner
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

            let hasError = false;

            const dirPathInput = form.querySelector(
                'input[name="directoryPath"]',
            );
            if (dirPathInput && dirPathInput.value) {
                dirPathInput.value = dirPathInput.value.replace(/^\/+/, "");
            }

            // Check if an external cover image is selected
            const selectedCoverRadio = form.querySelector('input[name="coverImageCandidate"]:checked');
            console.log("[DEBUG] Selected cover radio data-source:", selectedCoverRadio ? selectedCoverRadio.dataset.source : "none");

            const validationMessages = [];

            if (selectedCoverRadio && (selectedCoverRadio.dataset.source === "audible" || selectedCoverRadio.dataset.source === "google")) {
                console.log("[DEBUG] External cover image selected: " + selectedCoverRadio.dataset.source);

                // Check if the cover has already been downloaded (filename starts with cover_audible_ or cover_google_)
                const coverValue = selectedCoverRadio.value;
                const normalizedCoverValue = (coverValue || '').toString();
                const isAlreadyDownloaded =
                    normalizedCoverValue !== '' &&
                    (
                        normalizedCoverValue.startsWith('cover_audible_') ||
                        // Current naming (ExternalCoverService::normalizeSourceName => googlebooks)
                        normalizedCoverValue.startsWith('cover_googlebooks_') ||
                        // Legacy naming used elsewhere in the UI
                        normalizedCoverValue.startsWith('cover_google_') ||
                        // Some older/current covers are stored as googlebooks_*.jpg (without cover_ prefix)
                        normalizedCoverValue.startsWith('googlebooks_')
                    );

                if (!isAlreadyDownloaded) {
                    // Only validate IDs and URLs if we need to download the cover
                    // Verify we have the corresponding ID for the external source
                    if (selectedCoverRadio.dataset.source === "audible") {
                        const audibleIdInput = form.querySelector('#audibleId');
                        if (!audibleIdInput || !audibleIdInput.value.trim()) {
                            addFieldError(audibleIdInput, "Audible ID is missing. Cannot download Audible cover image.");
                            hasError = true;
                            validationMessages.push("Audible ID is missing.");
                            console.log("[DEBUG] (error) Audible ID is missing");
                        }
                    } else if (selectedCoverRadio.dataset.source === "google") {
                        const googleBooksIdInput = form.querySelector('#googleBooksId');
                        if (!googleBooksIdInput || !googleBooksIdInput.value.trim()) {
                            addFieldError(googleBooksIdInput, "Google Books ID is missing. Cannot download Google Books cover image.");
                            hasError = true;
                            validationMessages.push("Google Books ID is missing.");
                            console.log("[DEBUG] (error) Google Books ID is missing");
                        }
                    }

                    // Verify we have a cover URL if using external cover
                    const coverUrlInput = form.querySelector('#coverImageUrl');
                    if (!coverUrlInput || !coverUrlInput.value.trim()) {
                        const coverGroup =
                            selectedCoverRadio.closest('.form-group') ||
                            document.getElementById('cover-candidates-group') ||
                            selectedCoverRadio;
                        addFieldError(coverGroup, "External cover image URL is missing. Cannot download cover image.");
                        hasError = true;
                        validationMessages.push("External cover image URL is missing.");
                        console.log("[DEBUG] (error) External cover image URL is missing");
                    }
                } else {
                    console.log("[DEBUG] Cover already downloaded, skipping validation");
                }
            }

            const titleInput = form.querySelector('input[name="title"]');
            if (!titleInput || !titleInput.value.trim()) {
                addFieldError(titleInput, "Title is required.");
                hasError = true;
                validationMessages.push("Title is required.");
                console.log("[DEBUG] (error) Title is required");
            }

            const authorInputs = form.querySelectorAll(
                'input[name="author[]"]',
            );
            let hasAuthor = false;
            authorInputs.forEach((input) => {
                if (input.value.trim()) hasAuthor = true;
            });
            if (!hasAuthor && authorInputs.length > 0) {
                addFieldError(authorInputs[0], "At least one author is required.");
                hasError = true;
                validationMessages.push("At least one author is required.");
                console.log("[DEBUG] (error) No author selected");
            } else if (authorInputs.length === 0) {
                // No author input fields at all
                const authorsGroup = document.getElementById("authors-group");
                addFieldError(authorsGroup, "At least one author is required.");
                hasError = true;
                validationMessages.push("At least one author is required.");
                console.log("[DEBUG] (error) No author input fields found");
            }

            const genreSelects = form.querySelectorAll(
                'select[name="genre[]"]',
            );
            let hasGenre = false;
            genreSelects.forEach((select) => {
                if (select.value) hasGenre = true;
            });
            if (!hasGenre && genreSelects.length > 0) {
                addFieldError(genreSelects[0], "At least one genre is required.");
                console.log("[DEBUG] (error) No genre selected");
                hasError = true;
                validationMessages.push("At least one genre is required.");
            } else if (genreSelects.length === 0) {
                const genresGroup = document.getElementById("genres-group");
                addFieldError(genresGroup, "At least one genre is required.");
                console.log("[DEBUG] (error) No genre select fields found");
                hasError = true;
                validationMessages.push("At least one genre is required.");
            }

            // Log the selected cover image value for debugging
            if (selectedCoverRadio) {
                console.log("[DEBUG] Selected cover image value before submission:", selectedCoverRadio.value);
                console.log("[DEBUG] Selected cover image data-source:", selectedCoverRadio.dataset.source);
            }

            if (hasError) {
                e.preventDefault();
                // Re-enable the submit button if there are validation errors
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;

                setValidationSummary(validationMessages);

                submitBtn.disabled = true;
                submitBtn.dataset.disabledByValidation = "1";

                if (!form.dataset.validationChangeHandlerAttached) {
                    form.dataset.validationChangeHandlerAttached = "1";

                    const handleUserChange = function () {
                        if (submitBtn.dataset.disabledByValidation === "1") {
                            submitBtn.disabled = false;
                            delete submitBtn.dataset.disabledByValidation;
                            removeValidationSummary();
                        }
                    };

                    form.addEventListener("input", handleUserChange, true);
                    form.addEventListener("change", handleUserChange, true);
                }

                const firstErrorField = form.querySelector(".is-invalid");
                if (firstErrorField) {
                    if (typeof firstErrorField.scrollIntoView === "function") {
                        firstErrorField.scrollIntoView({
                            behavior: "smooth",
                            block: "center",
                        });
                    }
                }
                console.log(
                    "[DEBUG] Form will not submit due to validation errors",
                );
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

                        // Check specifically for external cover image download errors
                        if (xhr.responseJSON && xhr.responseJSON.error_type === "external_cover_download_failed") {
                            console.log("[DEBUG] External cover download failed: " + msg);

                            // Find the selected cover image radio button
                            const selectedCoverRadio = form.querySelector('input[name="coverImageCandidate"]:checked');
                            if (selectedCoverRadio) {
                                const coverGroup = selectedCoverRadio.closest('.form-group') ||
                                                  document.getElementById('cover-candidates-group');

                                if (coverGroup) {
                                    $(coverGroup).addClass("is-invalid");
                                    $(coverGroup).after(
                                        '<span class="invalid-feedback d-block alert alert-danger">' +
                                        '<strong>Cover Image Error:</strong> ' + msg +
                                        '</span>'
                                    );

                                    // Scroll to the error
                                    coverGroup.scrollIntoView({
                                        behavior: "smooth",
                                        block: "center"
                                    });
                                    return;
                                }
                            }
                        }

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
                console.log("[DEBUG] Modal AJAX submit, returning false");
                return false;
            }
            // Non-modal: allow default submit
            console.log(
                "[DEBUG] No errors, not in modal, allowing normal form submit",
            );
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

// Autofill search function
function performAutofillSearch(sources, autoApplyFirst) {
    autoApplyFirst = autoApplyFirst || false;
    var $modal = $("#autofillModal");
    var $resultsTable = $modal.find("#autofill-results-table tbody");
    var $applyBtn = $modal.find("#autofill-apply-btn");
    $applyBtn.prop("disabled", true);

    // Show loading state
    $resultsTable.html(
        '<tr><td colspan="8" class="text-center text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Searching...</td></tr>',
    );

    // Gather search fields
    var title = $("#autofill-title").val();
    var author = $("#autofill-author").val();
    var series = $("#autofill-series").val();
    var apiId = $("#autofill-api-id").val();

    // Show results wrapper
    $("#autofill-results-wrapper").show();

    // Use the unified search endpoint
    var endpoint = window.BOOK_FORM_ROUTES.search || "/admin/books/search";

    // If searching all sources, we'll make multiple requests
    var sourcesToSearch = Array.isArray(sources) ? sources : [sources];
    var allResults = [];
    var completedRequests = 0;

    sourcesToSearch.forEach(function(source) {
        // Map the source value to the API source parameter
        var apiSource = "";
        if (source === "google") {
            apiSource = "googlebooks";
        } else if (source === "audible") {
            apiSource = "audible";
        } else if (source === "audiobookbay") {
            apiSource = "audiobookbay";
        } else if (source === "hardcover") {
            apiSource = "hardcover";
        } else {
            completedRequests++;
            return;
        }

        // Build query params
        var params = {
            source: apiSource,
            title: title,
            author: author,
            series: series,
            api_id: apiId,
        };

        console.log("[DEBUG] Autofill search parameters:", params);

        $.get(endpoint, params)
            .done(function (response) {
                var results = Array.isArray(response) ? response : [];
                // Add source to each result
                results.forEach(function(item) {
                    item.source = source.charAt(0).toUpperCase() + source.slice(1);
                });
                allResults = allResults.concat(results);
            })
            .fail(function (xhr) {
                console.error("[DEBUG] Search failed for " + source + ":", xhr);
            })
            .always(function() {
                completedRequests++;
                if (completedRequests === sourcesToSearch.length) {
                    displayAutofillResults(allResults, autoApplyFirst);
                }
            });
    });
}

function displayAutofillResults(results, autoApplyFirst) {
    var $modal = $("#autofillModal");
    var $resultsTable = $modal.find("#autofill-results-table tbody");
    var $applyBtn = $modal.find("#autofill-apply-btn");

    // Sort results by relevance/quality
    results.sort(function(a, b) {
        // Source priority scoring (higher is better)
        var sourcePriority = {
            'Audible': 100,
            'Hardcover': 80,
            'Google': 60,
            'Audiobookbay': 40
        };

        var aSourceScore = sourcePriority[a.source] || 0;
        var bSourceScore = sourcePriority[b.source] || 0;

        // Data completeness scoring
        var aDataScore = 0;
        var bDataScore = 0;

        // Check for key fields
        if (a.author || (a.authors && a.authors.length > 0)) aDataScore += 10;
        if (b.author || (b.authors && b.authors.length > 0)) bDataScore += 10;

        if (a.narrator || a.narrators || a.narratorsList) aDataScore += 5;
        if (b.narrator || b.narrators || b.narratorsList) bDataScore += 5;

        if (a.coverImageUrl || a.cover_image_url) aDataScore += 5;
        if (b.coverImageUrl || b.cover_image_url) bDataScore += 5;

        if (a.description) aDataScore += 3;
        if (b.description) bDataScore += 3;

        if (a.series) aDataScore += 2;
        if (b.series) bDataScore += 2;

        // Combine scores
        var aTotal = aSourceScore + aDataScore;
        var bTotal = bSourceScore + bDataScore;

        return bTotal - aTotal; // Sort descending (best first)
    });

    if (results.length > 0) {
        var rows = "";
        results.forEach(function (item, idx) {
            // Handle authors - prefer narratorsList (simple array), fall back to author array or authors (nested)
            var authors = "";
            if (item.author && Array.isArray(item.author)) {
                // Simple array: ["Eoin Colfer"]
                authors = item.author.join(", ");
            } else if (item.authors && Array.isArray(item.authors)) {
                // Nested array: [{author: {name: "Eoin Colfer", id: "..."}}]
                authors = item.authors
                    .map(function(a) {
                        if (a.author && a.author.name) {
                            return a.author.name;
                        }
                        return a.name || a;
                    })
                    .filter(function(a) { return a; })
                    .join(", ");
            } else if (item.author) {
                authors = item.author;
            }

            // Handle narrators - prefer narratorsList (simple array), fall back to narrator or narrators (nested)
            var narrators = "";
            if (item.narratorsList && Array.isArray(item.narratorsList)) {
                // Simple array: ["Nathaniel Parker"]
                narrators = item.narratorsList.join(", ");
            } else if (item.narrators && Array.isArray(item.narrators)) {
                // Nested array: [{narrator: {name: "Nathaniel Parker", id: "..."}}]
                narrators = item.narrators
                    .map(function(n) {
                        if (n.narrator && n.narrator.name) {
                            return n.narrator.name;
                        }
                        return n.name || n;
                    })
                    .filter(function(n) { return n; })
                    .join(", ");
            } else if (item.narratorList && Array.isArray(item.narratorList)) {
                // Alternative spelling
                narrators = item.narratorList.join(", ");
            } else if (item.narrator) {
                narrators = Array.isArray(item.narrator)
                    ? item.narrator.join(", ")
                    : item.narrator;
            }

            var coverUrl =
                item.coverImageUrl ||
                item.cover_image_url ||
                "";
            var publishedYear =
                item.publishedYear ||
                (item.published_date
                    ? item.published_date.substring(0, 4)
                    : "");

            rows +=
                "<tr>" +
                '<td><input type="radio" name="autofill_result_select" value="' +
                idx +
                '" ' + (idx === 0 ? 'checked' : '') + '></td>' +
                "<td>" +
                (coverUrl
                    ? '<img src="' +
                      coverUrl +
                      '" alt="Cover" style="height:48px;max-width:40px;">'
                    : "") +
                "</td>" +
                "<td>" +
                (item.title || "") +
                "</td>" +
                "<td>" +
                authors +
                "</td>" +
                "<td>" +
                (narrators || '<span class="text-muted">—</span>') +
                "</td>" +
                "<td>" +
                (item.series || "") +
                "</td>" +
                "<td>" +
                publishedYear +
                "</td>" +
                "<td>" +
                (item.source || "") +
                "</td>" +
                "</tr>";
        });
        $resultsTable.html(rows);

        // Store results for later use
        window.autofillMatches = results;

        // Enable the apply button for first result
        $applyBtn.prop("disabled", false);
        $applyBtn.data("selectedIdx", 0);

        // Enable selection logic
        $(document)
            .off("change.autofillResult")
            .on(
                "change.autofillResult",
                'input[name="autofill_result_select"]',
                function () {
                    var idx = $(this).val();
                    var item = window.autofillMatches[idx];
                    if (!item) return;
                    $("#autofill-apply-btn").prop(
                        "disabled",
                        false,
                    );
                    $("#autofill-apply-btn").data(
                        "selectedIdx",
                        idx,
                    );
                },
            );

        // Auto-apply first result if requested
        if (autoApplyFirst && results.length > 0) {
            console.log("[DEBUG] Auto-applying first result");
            applyAutofillResult(0);
            $modal.modal('hide');
        }
    } else {
        $resultsTable.html(
            '<tr><td colspan="8" class="text-center text-muted">No results found</td></tr>',
        );
    }
}

// Autofill Modal Search Intercept
$(function () {
    var $autofillForm = $("#autofill-search-form");
    if ($autofillForm.length) {
        // Remove old submit handler
        $autofillForm.off("submit");

        // Handle individual source buttons
        $("#search-audible-btn").on("click", function(e) {
            e.preventDefault();
            performAutofillSearch("audible", false);
        });

        $("#search-google-btn").on("click", function(e) {
            e.preventDefault();
            performAutofillSearch("google", false);
        });

        $("#search-audiobookbay-btn").on("click", function(e) {
            e.preventDefault();
            performAutofillSearch("audiobookbay", false);
        });

        $("#search-hardcover-btn").on("click", function(e) {
            e.preventDefault();
            performAutofillSearch("hardcover", false);
        });

        // Handle search all button (excludes AudiobookBay due to performance)
        $("#search-all-btn").on("click", function(e) {
            e.preventDefault();
            performAutofillSearch(["audible", "google", "hardcover"], false);
        });

        // Handle the apply button click
        $("#autofill-apply-btn")
            .off("click.autofillApply")
            .on("click.autofillApply", function () {
                var idx = $(this).data("selectedIdx");
                applyAutofillResult(idx);
                $("#autofillModal").modal("hide");
            });
    }
});

// Function to apply autofill result to form
function applyAutofillResult(idx) {
    var item = window.autofillMatches[idx];
    if (!item) return;

                                // Set title
                                $("#title").val(item.title || "");

                                // Set description
                                if (item.description) {
                                    $("#description").val(item.description);
                                }

                                // Authors - handle multiple formats
                                $("#authors-group").html("");
                                if (item.author && Array.isArray(item.author)) {
                                    // Simple array: ["Eoin Colfer"]
                                    item.author.forEach(function (author) {
                                        addAuthorRow($("#book-form"), author);
                                    });
                                } else if (item.authors && Array.isArray(item.authors)) {
                                    // Nested array: [{author: {name: "Eoin Colfer", id: "..."}}]
                                    item.authors.forEach(function (a) {
                                        var authorName = a.author && a.author.name ? a.author.name : (a.name || a);
                                        if (authorName) {
                                            addAuthorRow($("#book-form"), authorName);
                                        }
                                    });
                                } else if (item.author) {
                                    // Single string
                                    addAuthorRow($("#book-form"), item.author);
                                } else {
                                    // Add empty author row
                                    addAuthorRow($("#book-form"), "");
                                }

                                // Narrators - handle multiple formats
                                $("#narrators-group").html("");
                                if (item.narratorsList && Array.isArray(item.narratorsList) && item.narratorsList.length > 0) {
                                    // Simple array: ["Nathaniel Parker"]
                                    item.narratorsList.forEach(function (narrator) {
                                        addNarratorRow($("#book-form"), narrator);
                                    });
                                } else if (item.narrators && Array.isArray(item.narrators) && item.narrators.length > 0) {
                                    // Nested array: [{narrator: {name: "Nathaniel Parker", id: "..."}}]
                                    item.narrators.forEach(function (n) {
                                        var narratorName = n.narrator && n.narrator.name ? n.narrator.name : (n.name || n);
                                        if (narratorName) {
                                            addNarratorRow($("#book-form"), narratorName);
                                        }
                                    });
                                } else if (item.narratorList && Array.isArray(item.narratorList) && item.narratorList.length > 0) {
                                    // Alternative spelling
                                    item.narratorList.forEach(function (narrator) {
                                        addNarratorRow($("#book-form"), narrator);
                                    });
                                } else if (item.narrator) {
                                    // Single narrator (string or array)
                                    if (typeof item.narrator === "string") {
                                        addNarratorRow($("#book-form"), item.narrator);
                                    } else if (Array.isArray(item.narrator)) {
                                        item.narrator.forEach(function (narrator) {
                                            addNarratorRow($("#book-form"), narrator);
                                        });
                                    }
                                } else {
                                    // Add empty narrator row if no narrators
                                    addNarratorRow($("#book-form"), "");
                                }

                                // Series - handle both formats
                                var series = item.series || "";
                                var seriesNumber =
                                    item.seriesNumber ||
                                    item.series_number ||
                                    "";
                                if (series) {
                                    var seriesGroup = $("#series-group");
                                    seriesGroup.html("");
                                    addSeriesRow(
                                        $("#book-form"),
                                        series,
                                        seriesNumber,
                                    );
                                }

                                // Release Date - handle both formats
                                var releaseDateInput = $("#release_date");
                                var publishedYear =
                                    item.publishedYear ||
                                    (item.published_date
                                        ? item.published_date.substring(0, 4)
                                        : "");
                                if (releaseDateInput.length && publishedYear) {
                                    // Convert year to date format
                                    releaseDateInput.val(publishedYear + '-01-01');
                                }

                                // Cover - handle both formats
                                var coverUrl =
                                    item.coverImageUrl ||
                                    item.cover_image_url ||
                                    "";
                                if (coverUrl) {
                                    // Instead of setting file input value, create/update a hidden input for the cover URL
                                    var coverUrlInput = $("#coverImageUrl");
                                    if (!coverUrlInput.length) {
                                        // Create hidden input if not present
                                        $("<input>")
                                            .attr({
                                                type: "hidden",
                                                id: "coverImageUrl",
                                                name: "coverImageUrl",
                                                value: coverUrl,
                                            })
                                            .appendTo("#book-form");
                                    } else {
                                        coverUrlInput.val(coverUrl);
                                    }

                                    // If we're in the cover selection UI, add this cover to the options
                                    var $coverCandidatesList = $("#cover-candidates-list");
                                    if ($coverCandidatesList.length) {
                                        // Check if we already have a radio button for this source
                                        var sourceType = item.source === "Audible" ? "audible" : "googlebooks";
                                        var existingRadio = $('input[name="coverImageCandidate"][data-source="' + sourceType + '"]');

                                        if (existingRadio.length) {
                                            // Update existing radio button's image
                                            existingRadio.closest('label').find('img').attr('src', coverUrl);
                                            // Select this radio button
                                            existingRadio.prop('checked', true);
                                            console.log("[DEBUG] Updated existing " + sourceType + " cover radio button and selected it");
                                        } else {
                                            // Create a new radio button option
                                            var sourceLabel = item.source === "Audible" ? "Audible Cover" : "Google Books Cover";
                                            var newOption = $('<div class="text-center">' +
                                                '<label class="d-flex flex-column align-items-center">' +
                                                '<input type="radio" name="coverImageCandidate" value="' + sourceType + '" ' +
                                                'data-source="' + sourceType + '" checked class="mb-2">' +
                                                '<img src="' + coverUrl + '" alt="' + sourceLabel + '" ' +
                                                'style="max-width:100px;max-height:140px;border:1px solid #ccc;">' +
                                                '</label>' +
                                                '<div class="mt-1" style="font-size:12px;word-break:break-all;">' +
                                                sourceLabel + '<br>' +
                                                '<small class="text-muted">External Cover</small>' +
                                                '</div>' +
                                                '</div>');

                                            $coverCandidatesList.append(newOption);
                                            console.log("[DEBUG] Added new " + sourceType + " cover radio button and selected it");

                                            // If this is the first cover option, make the container visible
                                            if ($coverCandidatesList.children().length === 1) {
                                                $("#cover-candidates-group").show();
                                            }
                                        }

                                        // Ensure the cover candidates group is visible
                                        $("#cover-candidates-group").show();

                                        // Update the corner preview image to show the newly selected cover
                                        var $cornerPreview = $("#cover-preview-trigger img");
                                        if ($cornerPreview.length) {
                                            $cornerPreview.attr("src", coverUrl);
                                            $("#cover-preview-container").show();
                                            console.log("[DEBUG] Updated corner preview to show autofilled cover");
                                        }
                                    } else {
                                        // If no cover candidates list, still update corner preview
                                        var $cornerPreview = $("#cover-preview-trigger img");
                                        if ($cornerPreview.length && coverUrl) {
                                            $cornerPreview.attr("src", coverUrl);
                                            $("#cover-preview-container").show();
                                            console.log("[DEBUG] Updated corner preview to show autofilled cover");
                                        }
                                    }
                                }

                                // Handle source-specific IDs and fields
                                console.log("[DEBUG] Processing source-specific IDs and fields for " + item.source);
                                if (item.source === "Audible") {
                                    console.log("[DEBUG] Processing Audible-specific IDs and fields");
                                    console.log("[DEBUG] item: ", item);
                                    // Set Audible ID
                                    var audibleIdInput = $("#audibleId");
                                    var audibleId =
                                        item.audibleId || item.asin || item.id || "";
                                    console.log("[DEBUG] Audible ID: " + audibleId);
                                    if (!audibleIdInput.length) {
                                        // Create hidden input if not present
                                        $("<input>")
                                            .attr({
                                                type: "hidden",
                                                id: "audibleId",
                                                name: "audibleId",
                                                value: audibleId,
                                            })
                                            .appendTo("#book-form");
                                        console.log("[DEBUG] Created hidden input for Audible ID (value: " + audibleId + ")");
                                    } else {
                                        audibleIdInput.val(audibleId);
                                        console.log("[DEBUG] Updated hidden input for Audible ID (value: " + audibleId + ")");
                                    }

                                    // Narrators are now handled globally above
                                } else {
                                    // Set Google Books ID
                                    var gbIdInput = $("#googleBooksId");
                                    var googleBooksId =
                                        item.googleBooksId || "";
                                    if (!gbIdInput.length) {
                                        // Create hidden input if not present
                                        $("<input>")
                                            .attr({
                                                type: "hidden",
                                                id: "googleBooksId",
                                                name: "googleBooksId",
                                                value: googleBooksId,
                                            })
                                            .appendTo("#book-form");
                                    } else {
                                        gbIdInput.val(googleBooksId);
                                    }

                                    // For Google Books, we may not have narrators, so add an empty row if needed
                                    $("#narrators-group").html("");
                                    if (item.narrator) {
                                        if (typeof item.narrator === "string") {
                                            addNarratorRow(
                                                $("#book-form"),
                                                item.narrator
                                            );
                                        } else if (
                                            Array.isArray(item.narrator)
                                        ) {
                                            item.narrator.forEach(
                                                function (narrator) {
                                                    addNarratorRow(
                                                        $("#book-form"),
                                                        narrator
                                                    );
                                                }
                                            );
                                        }
                                    } else {
                                        // Add an empty narrator row
                                        addNarratorRow($("#book-form"), "");
                                    }
                                }
}

// Magic Autofill Button - Auto-search Audible and apply first result
$(document).on("click", "#magic-autofill-btn", function(e) {
    e.preventDefault();
    console.log("[DEBUG] Magic autofill button clicked");

    // Get current form values
    var title = $('input[name="title"]').val() || '';
    var author = '';
    var authorInputs = $('input[name="authors[]"]');
    if (authorInputs.length > 0) {
        author = $(authorInputs[0]).val() || '';
    }

    // Set autofill modal fields
    $("#autofill-title").val(title);
    $("#autofill-author").val(author);

    // Perform Audible search and auto-apply first result
    performAutofillSearch("audible", true);
});

// Function to ensure a cover image radio button is always selected if any are available
function ensureCoverImageSelected() {
    console.log("[DEBUG] Ensuring a cover image radio button is selected");
    const $coverRadios = $('input[name="coverImageCandidate"]');

    // If we have cover image radio buttons but none are selected
    if ($coverRadios.length > 0 && $coverRadios.filter(":checked").length === 0) {
        console.log("[DEBUG] Found cover radio buttons but none selected, selecting first one");

        // First try to select external covers (Audible or Google Books) if available
        const $externalCoverRadios = $coverRadios.filter('[data-source="audible"], [data-source="googlebooks"]');
        if ($externalCoverRadios.length > 0) {
            $externalCoverRadios.first().prop("checked", true);
            console.log("[DEBUG] Selected external cover radio button");
        } else {
            // Otherwise select the first available radio button
            $coverRadios.first().prop("checked", true);
            console.log("[DEBUG] Selected first available cover radio button");
        }
    } else if ($coverRadios.length > 0) {
        console.log("[DEBUG] Cover radio button already selected:", $coverRadios.filter(":checked").val());
    } else {
        console.log("[DEBUG] No cover radio buttons found");
    }
}

// Toast notification helper
function showToast(message, type = 'success') {
    const toast = $('<div>')
        .addClass('alert alert-' + type)
        .css({
            position: 'fixed',
            top: '20px',
            left: '50%',
            transform: 'translateX(-50%)',
            zIndex: 9999,
            minWidth: '250px',
            boxShadow: '0 4px 6px rgba(0,0,0,0.1)'
        })
        .text(message);

    $('body').append(toast);

    setTimeout(function() {
        toast.fadeOut(300, function() {
            $(this).remove();
        });
    }, 2000);
}

// Resync fields from directory path - reads current directoryPath value
$(document).off('click.resyncPath', '#resync-path-btn').on('click.resyncPath', '#resync-path-btn', function() {
    const directoryPath = $('input[name="directoryPath"]:not(#directoryPathHidden)').val();
    if (!directoryPath) {
        showToast('Please enter a directory path first', 'warning');
        return;
    }

    $.ajax({
        url: window.BOOK_FORM_ROUTES.parsePath,
        method: 'POST',
        data: {
            path: directoryPath,
            _token: $('meta[name="csrf-token"]').attr('content')
        },
        success: function(data) {
            if (data.title) {
                $('#title').val(data.title);
            }
            if (data.author) {
                const firstAuthorInput = $('#authors-group .author-row:first input[name="author[]"]');
                if (firstAuthorInput.length) {
                    firstAuthorInput.val(data.author);
                }
            }
            if (data.genre) {
                const firstGenreSelect = $('#genres-group .genre-row:first select[name="genre[]"]');
                if (firstGenreSelect.length) {
                    firstGenreSelect.val(data.genre);
                }
            }
            if (data.series) {
                const firstSeriesNameInput = $('#series-group .series-row:first input[name*="[seriesName]"]');
                if (firstSeriesNameInput.length) {
                    firstSeriesNameInput.val(data.series);
                }
            }
            if (data.seriesNumber) {
                const firstSeriesNumberInput = $('#series-group .series-row:first input[name*="[number]"]');
                if (firstSeriesNumberInput.length) {
                    firstSeriesNumberInput.val(data.seriesNumber);
                }
            }
            showToast('Fields updated from path!', 'success');
        },
        error: function(xhr) {
            showToast('Error parsing path: ' + (xhr.responseJSON?.error || 'Unknown error'), 'danger');
        }
    });
});

// Update path from fields - constructs path from current field values
$(document).off('click.updatePathFromFields', '#update-path-from-fields-btn').on('click.updatePathFromFields', '#update-path-from-fields-btn', function() {
    const genre = $('#genres-group .genre-row:first select[name="genre[]"]').val();
    const author = $('#authors-group .author-row:first input[name="author[]"]').val();
    const title = $('#title').val();
    const seriesName = $('#series-group .series-row:first input[name*="[seriesName]"]').val();
    const seriesNumber = $('#series-group .series-row:first input[name*="[number]"]').val();

    if (!genre || !author || !title) {
        showToast('Please fill in at least Genre, Author, and Title fields', 'warning');
        return;
    }

    let path;

    if (seriesName && seriesName.trim() !== '') {
        // Series book: Genre/Author/Series/## Title
        let finalDir = title;
        if (seriesNumber && seriesNumber.toString().trim() !== '') {
            // Zero-pad the series number to 2 digits
            const paddedNumber = String(seriesNumber).padStart(2, '0');
            finalDir = `${paddedNumber} ${title}`;
        }
        path = `${genre}/${author}/${seriesName}/${finalDir}`;
    } else {
        // Standalone book: Genre/Author/Title
        path = `${genre}/${author}/${title}`;
    }

    $('input[name="directoryPath"]:not(#directoryPathHidden)').val(path);
    showToast('Directory path updated from fields!', 'success');
});

// Update path from genre change - replaces the genre portion of the path
$(document).off('click.updatePathFromGenre', '.update-path-from-genre').on('click.updatePathFromGenre', '.update-path-from-genre', function() {
    const currentPath = $('input[name="directoryPath"]:not(#directoryPathHidden)').val();
    const newGenre = $('#genres-group .genre-row:first select[name="genre[]"]').val();

    if (!currentPath) {
        showToast('No directory path to update', 'warning');
        return;
    }

    if (!newGenre) {
        showToast('Please select a genre first', 'warning');
        return;
    }

    const pathParts = currentPath.split('/');
    if (pathParts.length > 0) {
        pathParts[0] = newGenre;
        const newPath = pathParts.join('/');
        $('input[name="directoryPath"]:not(#directoryPathHidden)').val(newPath);

        $(this).hide();
        const firstGenreRow = $('#genres-group .genre-row:first');
        firstGenreRow.attr('data-original-genre', newGenre);

        showToast('Directory path updated to new genre!', 'success');
    }
});

// Monitor first genre select for changes to show/hide the update path button
$(document).off('change.genrePathUpdate', '#genres-group').on('change.genrePathUpdate', '#genres-group .genre-row:first select[name="genre[]"]', function() {
    const firstGenreRow = $(this).closest('.genre-row');
    const originalGenre = firstGenreRow.attr('data-original-genre');
    const currentGenre = $(this).val();
    const updateBtn = firstGenreRow.find('.update-path-from-genre');

    if (currentGenre && currentGenre !== originalGenre && currentGenre !== '') {
        updateBtn.show();
    } else {
        updateBtn.hide();
    }
});

// Handle metadata button clicks
$(document).on('click', '.view-metadata', function(e) {
    e.preventDefault();
    const filePath = $(this).data('file');
    const modal = new bootstrap.Modal(document.getElementById('audioMetadataModal'));
    const metadataContent = $('#metadata-content');

    // Show loading state
    metadataContent.html(`
        <div class="text-center p-4">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading metadata...</p>
        </div>
    `);

    modal.show();

    // Fetch metadata
    $.ajax({
        url: window.BOOK_FORM_ROUTES.audioMetadata || '/admin/books/audio-metadata',
        method: 'GET',
        data: { file: filePath },
        success: function(response) {
            if (response.success && response.metadata) {
                displayMetadata(response.metadata, response.file);
            } else {
                metadataContent.html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Failed to load metadata
                    </div>
                `);
            }
        },
        error: function(xhr) {
            const error = xhr.responseJSON?.error || 'Unknown error';
            metadataContent.html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Error: ${error}
                </div>
            `);
        }
    });
});

function displayMetadata(metadata, filename) {
    let html = '<div class="metadata-display">';

    // File info
    if (metadata.file) {
        html += '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-file me-2"></i>File Information</h6>';
        html += '<table class="table table-sm table-bordered mb-4">';
        html += '<tr><th style="width: 30%">Filename</th><td>' + filename + '</td></tr>';
        if (metadata.file.size_formatted) {
            html += '<tr><th>Size</th><td>' + metadata.file.size_formatted + '</td></tr>';
        }
        if (metadata.file.modified) {
            html += '<tr><th>Modified</th><td>' + metadata.file.modified + '</td></tr>';
        }
        if (metadata.file.extension) {
            html += '<tr><th>Extension</th><td>' + metadata.file.extension.toUpperCase() + '</td></tr>';
        }
        html += '</table>';
    }

    // Format info
    if (metadata.format) {
        html += '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i>Format Information</h6>';
        html += '<table class="table table-sm table-bordered mb-4">';
        if (metadata.format.format_long_name) {
            html += '<tr><th style="width: 30%">Format</th><td>' + metadata.format.format_long_name + '</td></tr>';
        }
        if (metadata.format.duration_formatted) {
            html += '<tr><th>Duration</th><td>' + metadata.format.duration_formatted + '</td></tr>';
        }
        if (metadata.format.bit_rate_formatted) {
            html += '<tr><th>Bit Rate</th><td>' + metadata.format.bit_rate_formatted + '</td></tr>';
        }
        html += '</table>';
    }

    // Stream info
    if (metadata.streams && metadata.streams.length > 0) {
        html += '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-stream me-2"></i>Audio Streams</h6>';
        metadata.streams.forEach(function(stream, index) {
            html += '<table class="table table-sm table-bordered mb-3">';
            html += '<tr><th colspan="2" class="bg-light">Stream ' + (index + 1) + '</th></tr>';
            if (stream.codec_long_name) {
                html += '<tr><th style="width: 30%">Codec</th><td>' + stream.codec_long_name + '</td></tr>';
            }
            if (stream.sample_rate_formatted) {
                html += '<tr><th>Sample Rate</th><td>' + stream.sample_rate_formatted + '</td></tr>';
            }
            if (stream.channels) {
                html += '<tr><th>Channels</th><td>' + stream.channels + (stream.channel_layout ? ' (' + stream.channel_layout + ')' : '') + '</td></tr>';
            }
            if (stream.bit_rate_formatted) {
                html += '<tr><th>Bit Rate</th><td>' + stream.bit_rate_formatted + '</td></tr>';
            }
            html += '</table>';
        });
    }

    // Tags
    if (metadata.tags && Object.keys(metadata.tags).length > 0) {
        html += '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-tags me-2"></i>Metadata Tags</h6>';
        html += '<table class="table table-sm table-bordered mb-3">';
        Object.keys(metadata.tags).forEach(function(key) {
            const displayKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            html += '<tr><th style="width: 30%">' + displayKey + '</th><td>' + metadata.tags[key] + '</td></tr>';
        });
        html += '</table>';
    }

    html += '</div>';
    $('#metadata-content').html(html);
}

console.log("Form JS loaded 6");
