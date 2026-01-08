// import_file.js - File/Audio Import Browser for Book Import
// PSR12/camelCase, debug logging, no global leaks

(function ($) {
    "use strict";
    window.initImportFileBrowser = function (rootSelector, options = {}) {
        // Return this instance for chaining and external reference
        const instance = this;
        // Store reference to this for closure access
        const self = this;
        const $root = $(rootSelector);
        if ($root.length === 0) {
            return;
        }
        // Options
        const ajaxRootsUrl = options.ajaxRootsUrl || "/admin/import/roots";
        const ajaxListUrl = options.ajaxListUrl || "/admin/import/list";
        const ajaxExtractUrl =
            options.ajaxExtractUrl || "/admin/import/extract";
        const ajaxExtractAIUrl =
            options.ajaxExtractAIUrl || "/admin/import/extract-ai";
        const ajaxMoveUrl = options.ajaxMoveUrl || "/admin/import/move";
        const ajaxProcessImportUrl =
            options.ajaxProcessImportUrl || "/admin/books/processImport";

        // Initialize from URL parameters if present
        let currentPath = "";

        // Check if we have URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const urlRoot = urlParams.get("root");
        const urlPath = urlParams.get("path");

        let currentRoot = null;

        // Store instance reference on DOM element
        $root.data("instance", self);

        // Make loadRoots accessible to the instance
        this.loadRoots = function () {
            // Call init() instead of loadRootsInternal which doesn't exist
            init();
        };

        // Clear any previous error messages
        $root.find("#import-directory-list").empty();

        // Initialize the browser
        init();

        // AI processing handlers
        initAIHandlers();

        // Initialize
        function init() {
            // Load roots
            $.getJSON(ajaxRootsUrl, function (data) {
                const $select = $root.find("#import-root-select");
                $select.empty();
                for (const root of data) {
                    const $option = $("<option>")
                        .val(root.value)
                        .text(root.label);
                    $select.append($option);
                }

                if (data.length > 0) {
                    // Only use URL parameters if they exist
                    if (urlRoot && urlPath) {
                        // Check if the URL root exists in the available roots
                        const rootExists = data.some(
                            (root) => root.value === urlRoot,
                        );
                        if (rootExists) {
                            currentRoot = urlRoot;
                            $select.val(currentRoot);
                            loadDirectory(urlPath); // Load the directory from URL parameters
                        } else {
                            // Fallback to default if root doesn't exist
                            currentRoot = data[0].value;
                            $select.val(currentRoot);
                            loadDirectory(""); // Load the root directory
                        }
                    } else {
                        // No URL parameters, use defaults
                        currentRoot = data[0].value;
                        $select.val(currentRoot);
                        loadDirectory(""); // Load the root directory
                    }
                }
            }).fail(function (xhr) {
                const $error = $('<div class="alert alert-danger">').text(
                    "Failed to load import roots: ",
                );
                $("<span>")
                    .text(xhr.statusText || "Unknown error")
                    .appendTo($error);
                $root.find("#import-directory-list").empty().append($error);
            });
        }
        function loadDirectory(path) {
            // Store previous path for debugging
            const previousPath = currentPath;

            // Update currentPath
            currentPath = path;

            // Update UI
            $root.find("#import-path-input").val(path);

            $root
                .find("#import-directory-list")
                .html('<div class="text-muted">Loading...</div>');

            $.getJSON(
                ajaxListUrl,
                { root: currentRoot, path: path },
                function (data) {
                    renderDirectoryList(data);
                },
            ).fail(function (xhr) {
                $root
                    .find("#import-directory-list")
                    .html(
                        '<div class="text-danger">Failed to load directory: ' +
                            xhr.statusText +
                            "</div>",
                    );
            });
        }

        // Initialize AI processing handlers
        function initAIHandlers() {
            // AI checkbox toggle handler
            $root.on("change", "#enable-ai-processing", function () {
                const isEnabled = $(this).is(":checked");
                const $modelSelection = $root.find("#ai-model-selection");
                const $costInfo = $root.find("#ai-cost-info");
                const $btnText = $root.find("#import-btn-text");

                if (isEnabled) {
                    $modelSelection.show();
                    $btnText.text("Select & Process with AI");
                    updateCostInfo();
                } else {
                    $modelSelection.hide();
                    $costInfo.text("💰 Basic extraction (no AI cost)");
                    $btnText.text("Select & Extract");
                }
            });

            // AI model selection change handler
            $root.on("change", "#ai-model-select", function () {
                updateCostInfo();
            });

            // Initialize cost info
            updateCostInfo();
        }

        // Update cost information based on selected model
        function updateCostInfo() {
            const $costInfo = $root.find("#ai-cost-info");
            const selectedModel = $root.find("#ai-model-select").val();

            const costInfo = {
                "gemini-2.5-flash-lite": "💰 Free tier - 1,000 requests/day",
                "gemini-2.0-flash-lite": "💰 Free tier - 200 requests/day",
                "gpt-4o-mini": "💰 ~$0.0002 per book (~$0.22/1000 books)",
                "gpt-3.5-turbo": "💰 ~$0.0006 per book (~$0.60/1000 books)",
                "claude-3-5-haiku": "💰 ~$0.0012 per book (~$1.20/1000 books)",
                "gpt-4o": "💰 ~$0.0038 per book (~$3.75/1000 books)",
                "claude-3-5-sonnet": "💰 ~$0.0045 per book (~$4.50/1000 books)",
            };

            $costInfo.text(
                costInfo[selectedModel] || "💰 Cost varies by usage",
            );
        }

        function renderDirectoryList(data) {
            const $list = $('<ul class="list-group"></ul>');
            if (data.parent) {
                $list.append(
                    '<li class="list-group-item list-group-item-action" data-type="parent" style="cursor:pointer"><i class="fas fa-level-up-alt"></i> .. (up)</li>',
                );
            }

            // Check if directory contains audio files
            const hasAudioFiles =
                data.items && data.items.some((item) => item.type === "file");

            // Enable select button if we have items (files or directories)
            // Allow selecting directories even without immediate audio files
            if (data.items && data.items.length > 0) {
                $root.find("#import-select-btn").prop("disabled", false);
            } else {
                $root.find("#import-select-btn").prop("disabled", true);
            }

            for (const item of data.items) {
                let icon = item.type === "dir" ? "fa-folder" : "fa-file-audio";
                let cls =
                    item.type === "dir"
                        ? "list-group-item-info"
                        : "list-group-item-secondary";

                const $li = $("<li>")
                    .addClass(
                        "list-group-item " + cls + " list-group-item-action",
                    )
                    .data("type", item.type)
                    .data("name", item.name)
                    .css("cursor", "pointer");

                $("<i>")
                    .addClass("fas " + icon + " me-2")
                    .appendTo($li);
                $("<span>").text(item.name).appendTo($li);

                $list.append($li);
            }
            $root.find("#import-directory-list").empty().append($list);

            // Attach click handlers directly to the newly created items
            $list.find(".list-group-item").on("click", function () {
                const $item = $(this);
                const type = $item.data("type");
                const name = $item.data("name");

                if (type === "parent") {
                    // Go up one level
                    const parts = currentPath.split("/");
                    parts.pop();
                    loadDirectory(parts.join("/"));
                } else if (type === "dir") {
                    // Navigate into directory
                    loadDirectory(
                        currentPath ? currentPath + "/" + name : name,
                    );
                } else if (type === "file") {
                    // Select file
                    $list.find(".list-group-item").removeClass("active");
                    $item.addClass("active");
                }
            });
        }
        // Event handlers
        $root.on("change", "#import-root-select", function () {
            currentRoot = $(this).val();
            loadDirectory("");
        });
        // Updated click handler for new simplified workflow with AI support
        $root.on("click", "#import-select-btn", function () {
            // Determine the selected item (file or directory)
            const $selectedItem = $root.find(".list-group-item.active");
            let selectedPath, selectedType;

            if ($selectedItem.length) {
                // User selected a specific file
                selectedPath = currentPath
                    ? currentPath + "/" + $selectedItem.data("name")
                    : $selectedItem.data("name");
                selectedType = $selectedItem.data("type");
            } else {
                // No specific selection, use current directory
                selectedPath = currentPath;
                selectedType = "dir";
            }

            // Check if AI processing is enabled
            const aiEnabled = $root
                .find("#enable-ai-processing")
                .is(":checked");
            const aiModel = $root.find("#ai-model-select").val();

            // Show loading state
            const $btn = $(this);
            const originalText = $btn.html();
            $btn.prop("disabled", true).html(
                '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...',
            );

            // Prepare payload for metadata extraction and redirect
            const payload = {
                root: currentRoot,
                path: selectedPath,
                type: selectedType,
                redirectToForm: true,
            };

            // Add AI parameters if enabled
            if (aiEnabled) {
                payload.aiModel = aiModel;
            }

            // Choose the appropriate endpoint
            const extractUrl = aiEnabled ? ajaxExtractAIUrl : ajaxExtractUrl;

            // Create a form to submit the data via POST
            const $form = $("<form>", {
                action: extractUrl,
                method: "POST",
                style: "display: none;",
            });

            // Add CSRF token
            $form.append(
                $("<input>", {
                    type: "hidden",
                    name: "_token",
                    value: $('meta[name="csrf-token"]').attr("content"),
                }),
            );

            // Add form data
            Object.keys(payload).forEach(function (key) {
                $form.append(
                    $("<input>", {
                        type: "hidden",
                        name: key,
                        value: payload[key],
                    }),
                );
            });

            // Submit form
            $("body").append($form);
            $form.submit();
            $form.remove();

            // Re-enable button after delay (form will redirect)
            setTimeout(function () {
                $btn.prop("disabled", false).html(originalText);
            }, 2000);
        });
        // This function is no longer needed in the new simplified workflow
        // but keeping for backward compatibility if needed
        function renderMetadataSummary(data) {
            if (!data.success) {
                return (
                    '<div class="text-danger">' +
                    (data.message || "Metadata extraction failed.") +
                    "</div>"
                );
            }
            let html = '<div class="card card-body bg-light">';
            html += "<h5>Extracted Metadata</h5>";
            html += '<ul class="mb-2">';
            html +=
                "<li><strong>Title:</strong> " + (data.title || "-") + "</li>";
            html +=
                "<li><strong>Author:</strong> " +
                (data.author || "-") +
                "</li>";
            // Check for both series and seriesName fields
            const seriesDisplay = data.series || data.seriesName || "-";
            html += "<li><strong>Series:</strong> " + seriesDisplay + "</li>";
            html +=
                "<li><strong>Genre:</strong> " + (data.genre || "-") + "</li>";
            html +=
                "<li><strong>Genre Path:</strong> " +
                (data.genrePath || "-") +
                "</li>";
            html +=
                "<li><strong>Directory Path:</strong> " +
                (data.directoryPath || "-") +
                "</li>";
            html += "</ul>";
            html +=
                '<div class="alert alert-info">Files will be moved when you save the book form.</div>';
            html += "</div>";
            return html;
        }

        // Remove any existing click handlers before adding a new one to prevent duplicates
        $root
            .off("click", "#import-move-btn")
            .on("click", "#import-move-btn", function () {
                // Prevent multiple clicks
                const $btn = $(this);
                if ($btn.data("processing")) {
                    return;
                }
                $btn.data("processing", true);
                try {
                    const $summary = $root.find("#import-metadata-summary");
                    let meta = {};
                    $summary.find("li").each(function () {
                        let label = $(this)
                            .find("strong")
                            .text()
                            .replace(":", "")
                            .toLowerCase();
                        let value = $(this)
                            .contents()
                            .filter(function () {
                                return this.nodeType === 3;
                            })
                            .text()
                            .trim();
                        if (value && value !== "-") {
                            meta[label] = value;
                        }
                    });
                    // COMPREHENSIVE DEBUGGING

                    // Log URL parameters
                    const urlParams = new URLSearchParams(
                        window.location.search,
                    );
                    console.log("URL parameters:", {
                        root: urlParams.get("root"),
                        path: urlParams.get("path"),
                    });

                    // Log all variables
                    console.log("currentRoot:", currentRoot);
                    console.log("currentPath:", currentPath);

                    // Log DOM state
                    console.log(
                        "Active item:",
                        $root.find(".list-group-item.active").length
                            ? "found"
                            : "not found",
                    );
                    console.log("Active item data:", {
                        name: $root
                            .find(".list-group-item.active")
                            .data("name"),
                        type: $root
                            .find(".list-group-item.active")
                            .data("type"),
                    });

                    // Log path input value
                    console.log(
                        "Path input value:",
                        $root.find("#import-path-input").val(),
                    );

                    // Get extracted metadata from the summary element
                    const extractedGenre = $summary.data("genre");
                    const extractedAuthor = $summary.data("author");
                    const extractedSeries = $summary.data("series");
                    const extractedGenrePath = $summary.data("genrePath");
                    const extractedDirectoryPath =
                        $summary.data("directoryPath");

                    // Store extracted metadata
                    const extractedData = {
                        genre: extractedGenre,
                        author: extractedAuthor,
                        series: extractedSeries,
                        genrePath: extractedGenrePath,
                        directoryPath: extractedDirectoryPath,
                    };

                    // Add backend fields
                    meta["root"] = currentRoot;

                    // Use extracted values or fall back to meta from the DOM
                    const author =
                        extractedAuthor || meta.author || "Unknown Author";
                    const series =
                        extractedSeries !== undefined &&
                        extractedSeries !== null
                            ? extractedSeries
                            : meta.series || "";
                    const genrePath =
                        extractedGenrePath || extractedGenre || "Other";

                    // Create book data object for submission
                    const bookData = {
                        genrePath: genrePath,
                        author: author,
                        series: series,
                        directoryPath: extractedDirectoryPath,
                    };

                    // Use the extracted directoryPath if available, otherwise construct it
                    let formattedDirectoryPath;
                    if (extractedDirectoryPath) {
                        formattedDirectoryPath = extractedDirectoryPath;
                    } else {
                        // Construct the path: Genre/Author/Series/Book
                        formattedDirectoryPath = genrePath + "/" + author;
                        // Only include series if it's not empty, null, undefined, or just a dash
                        if (series && series.trim() !== "" && series !== "-") {
                            formattedDirectoryPath += "/" + series;
                        } else {
                        }

                        // Add the current directory name (book title/number)
                        const bookDir = currentPath.split("/").pop();
                        formattedDirectoryPath += "/" + bookDir;
                    }

                    // Store both the full directoryPath and the genrePath separately
                    meta["directoryPath"] = formattedDirectoryPath;
                    meta["genrePath"] = genrePath; // Add genrePath parameter
                    // Get the active item's path - this should be the relative path from root to the specific directory
                    const activeItem = $root.find(".list-group-item.active");
                    meta["path"] = currentPath; // Use currentPath which contains the full relative path
                    meta["type"] = $root
                        .find(".list-group-item.active")
                        .data("type");
                    // Log path information for debugging
                    console.log("Path information:", {
                        "URL path": urlParams.get("path") || "",
                        "Input field path":
                            $root.find("#import-path-input").val() || "",
                        "currentPath variable": currentPath || "",
                        "Active item name":
                            $root
                                .find(".list-group-item.active")
                                .data("name") || "",
                    });

                    // CRITICAL CHECK: Verify directoryPath is set correctly and not blank
                    console.log(
                        "directoryPath is set to:",
                        meta["directoryPath"],
                    );

                    // If directoryPath is blank, abort the request and show error
                    if (
                        !meta["directoryPath"] ||
                        meta["directoryPath"] === ""
                    ) {
                        $summary.html(
                            '<div class="alert alert-danger">Cannot move: Current directory path is blank. Please navigate to a valid directory first.</div>',
                        );
                        console.groupEnd();
                        return; // Stop execution here
                    }

                    // Debug AJAX headers
                    console.log("AJAX headers:", {
                        "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                            "content",
                        ),
                    });

                    // Add a timestamp to help correlate client and server logs
                    const timestamp = new Date().toISOString();
                    meta["debug_timestamp"] = timestamp;

                    // Log the final request data
                    console.log(
                        "Final request data:",
                        JSON.parse(JSON.stringify(meta)),
                    );
                    console.groupEnd();

                    // Log the move request directoryPath specifically
                    $summary.html(
                        '<div class="spinner-border" role="status"><span class="visually-hidden">Moving...</span></div> <span class="ms-2">Moving file/directory...</span>',
                    );
                    // Get the CSRF token from meta tag
                    const csrfToken = $('meta[name="csrf-token"]').attr(
                        "content",
                    );

                    // Log CSRF token status for debugging
                    if (!csrfToken) {
                    }

                    // Force directoryPath to be set if it's blank
                    if (
                        !meta["directoryPath"] ||
                        meta["directoryPath"] === ""
                    ) {
                        meta["directoryPath"] = currentPath;
                    }

                    // Clone meta object to avoid reference issues
                    const requestData = JSON.parse(JSON.stringify(meta));

                    // Log the actual data being sent

                    $.ajax({
                        url: ajaxMoveUrl,
                        type: "POST",
                        data: requestData,
                        headers: { "X-CSRF-TOKEN": csrfToken },
                        // Log request before it's sent
                        beforeSend: function (xhr) {},
                        success: function (data) {
                            console.log("Move request succeeded:", data);
                            // Reset processing flag
                            $btn.data("processing", false);
                            if (data.success) {
                                // Create a success message that preserves the buttons
                                $summary.html(
                                    '<div class="alert alert-success mb-3 cursor-pointer" style="cursor: pointer;" title="Click to Create book record">Moved to: <code>' +
                                        (data.newPath || "-") +
                                        "</code> <small>(click to import)</small></div>" +
                                        '<div id="metadata-buttons">' +
                                        '<button id="import-prefill-btn" class="btn btn-primary">Create Book</button>' +
                                        "</div>",
                                );
                                // Autofill API integration
                                let meta = {};
                                $summary
                                    .siblings("ul")
                                    .find("li")
                                    .each(function () {
                                        let label = $(this)
                                            .find("strong")
                                            .text()
                                            .replace(":", "")
                                            .toLowerCase();
                                        let value = $(this)
                                            .contents()
                                            .filter(function () {
                                                return this.nodeType === 3;
                                            })
                                            .text()
                                            .trim();
                                        if (value && value !== "-") {
                                            meta[label] = value;
                                        }
                                    });
                                // Google Books
                                $.get(
                                    window.BOOK_FORM_ROUTES.googleBooks,
                                    {
                                        title: meta.title || "",
                                        author: meta.author || "",
                                    },
                                    function (resp) {
                                        if (
                                            resp &&
                                            resp.items &&
                                            resp.items.length
                                        ) {
                                            let best = resp.items[0].volumeInfo;
                                            $summary.append(
                                                '<div class="mt-2"><strong>Google Books:</strong> ' +
                                                    "<span>" +
                                                    (best.title || "-") +
                                                    " by " +
                                                    (best.authors
                                                        ? best.authors.join(
                                                              ", ",
                                                          )
                                                        : "-") +
                                                    "</span>" +
                                                    (best.imageLinks &&
                                                    best.imageLinks.thumbnail
                                                        ? '<br><img src="' +
                                                          best.imageLinks
                                                              .thumbnail +
                                                          '" style="max-height:80px;">'
                                                        : "") +
                                                    "</div>",
                                            );
                                        } else {
                                            $summary.append(
                                                '<div class="mt-2 text-muted">No Google Books match found.</div>',
                                            );
                                        }
                                    },
                                );
                                // Goodreads search button
                                let goodreadsUrl =
                                    "https://www.goodreads.com/search?q=" +
                                    encodeURIComponent(
                                        (meta.title || "") +
                                            " " +
                                            (meta.author || ""),
                                    );
                                $summary.append(
                                    '<a href="' +
                                        goodreadsUrl +
                                        '" target="_blank" class="btn btn-secondary btn-sm mt-2">Search Goodreads</a>',
                                );
                                // TODO: Add Audible/genre guess as needed
                            } else {
                                $summary.html(
                                    '<div class="alert alert-danger">Move failed: ' +
                                        (data.message || "Unknown error") +
                                        "</div>",
                                );
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error(
                                "Move request failed:",
                                status,
                                error,
                            );
                            console.log("XHR response:", xhr);
                            // Reset processing flag
                            $btn.data("processing", false);

                            // Show error message
                            let errorMsg = "Error moving file/directory";
                            if (xhr.status === 403) {
                                errorMsg =
                                    "Permission denied (403 Forbidden). Please check your session or refresh the page.";
                            } else if (
                                xhr.responseJSON &&
                                xhr.responseJSON.message
                            ) {
                                errorMsg = xhr.responseJSON.message;
                            } else {
                                errorMsg = "Move failed: " + xhr.statusText;
                            }

                            $summary.html(
                                '<div class="alert alert-danger">' +
                                    errorMsg +
                                    "</div>",
                            );

                            // Try to refresh the CSRF token
                            $.get(window.location.href, function (data) {
                                const newToken = $(data)
                                    .filter('meta[name="csrf-token"]')
                                    .attr("content");
                                if (newToken) {
                                    $('meta[name="csrf-token"]').attr(
                                        "content",
                                        newToken,
                                    );
                                    console.log("CSRF token refreshed");
                                }
                            });
                        },
                    });
                } catch (error) {
                    $root
                        .find("#import-metadata-summary")
                        .html(
                            '<div class="alert alert-danger">Error processing move request: ' +
                                error.message +
                                "</div>",
                        );
                    console.groupEnd();
                }
            });
        // Add a direct click handler to the document for any button with this ID
        $(document).on("click", "#import-prefill-btn", function (e) {
            e.preventDefault();
            handleImportPrefill($(this));
        });

        // Make the entire metadata summary clickable after a successful move
        $(document).on(
            "click",
            "#import-metadata-summary .alert-success",
            function (e) {
                // Don't trigger if clicking on a button or link inside the summary
                if ($(e.target).closest("button, a").length === 0) {
                    const $btn = $("#import-prefill-btn");
                    if ($btn.length && !$btn.prop("disabled")) {
                        handleImportPrefill($btn);
                    }
                }
            },
        );

        // Separate the handler function for reuse
        function handleImportPrefill($btn) {
            const $summary = $root.find("#import-metadata-summary");
            const originalBtnText = $btn.html();

            // Check if button is disabled
            if ($btn.prop("disabled")) {
                return;
            }

            // Show processing state
            $btn.prop("disabled", true).html(
                '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...',
            );

            // Extract metadata from the summary
            let meta = {};

            // Debug the metadata extraction
            console.log(
                "Extracting metadata from:",
                $root.find("#import-metadata-summary").siblings("ul").html(),
            );

            // Extract metadata from the list items in the metadata section
            // First try the metadata list in the summary section
            const $metadataList = $root
                .find("#import-metadata-summary")
                .siblings("ul")
                .find("li");
            console.log(
                "Found metadata items in summary:",
                $metadataList.length,
            );

            if ($metadataList.length > 0) {
                $metadataList.each(function () {
                    const $item = $(this);
                    const labelText = $item.find("strong").text();
                    const label = labelText.replace(":", "").toLowerCase();
                    const value = $item
                        .contents()
                        .filter(function () {
                            return this.nodeType === 3;
                        })
                        .text()
                        .trim();

                    console.log(
                        "Metadata item from summary:",
                        label,
                        "=",
                        value,
                    );

                    if (value && value !== "-") {
                        meta[label] = value;
                    }
                });
            } else {
                // If no metadata in summary, try the file browser metadata section
                const $browserMetadata = $root
                    .find(".file-metadata-section")
                    .find("li");
                console.log(
                    "Found metadata items in browser:",
                    $browserMetadata.length,
                );

                $browserMetadata.each(function () {
                    const $item = $(this);
                    const labelText = $item.find("strong").text();
                    const label = labelText.replace(":", "").toLowerCase();
                    const value = $item
                        .contents()
                        .filter(function () {
                            return this.nodeType === 3;
                        })
                        .text()
                        .trim();

                    console.log(
                        "Metadata item from browser:",
                        label,
                        "=",
                        value,
                    );

                    if (value && value !== "-") {
                        meta[label] = value;
                    }
                });
            }

            // Try to extract from the active item name if title is missing
            if (!meta.title && $root.find(".list-group-item.active").length) {
                const activeItemName = $root
                    .find(".list-group-item.active")
                    .text()
                    .trim();
                meta.title = activeItemName.replace(/\.[^/.]+$/, ""); // Remove file extension if present
                console.log("Set title from active item:", meta.title);
            }

            // Ensure we have the directoryPath
            if (
                !meta.directorypath &&
                $root.find(".list-group-item.active").length
            ) {
                meta.directorypath =
                    $root.find(".list-group-item.active").data("path") ||
                    $root.find(".list-group-item.active").data("name") ||
                    currentPath +
                        "/" +
                        $root.find(".list-group-item.active").text().trim();
                console.log(
                    "Set directoryPath from active item:",
                    meta.directorypath,
                );
            }

            // Check if we have moved the files (look for success message with newPath)
            const $successMessage = $root.find(
                "#import-metadata-summary .alert-success",
            );
            let importPath, importRoot;

            if (
                $successMessage.length &&
                $successMessage.text().includes("Moved to:")
            ) {
                // Extract the path from the success message
                const newPathMatch = $successMessage.find("code").text();
                console.log(
                    "Found moved path in success message:",
                    newPathMatch,
                );
                if (newPathMatch && newPathMatch !== "-") {
                    // Use the moved path as import_path
                    importPath = newPathMatch;
                    console.log("Using moved path:", importPath);
                }
            } else {
                // Fall back to current path if not moved
                importPath = currentPath
                    ? currentPath +
                      "/" +
                      $root.find(".list-group-item.active").data("name")
                    : $root.find(".list-group-item.active").data("name");
                console.log("Using original path:", importPath);
            }

            // Set import path and root in metadata
            meta.import_path = importPath;
            meta.import_type =
                $root.find(".list-group-item.active").data("type") || "dir";

            // Make sure directory_path is set correctly (camelCase to match backend)
            if (meta.directorypath && !meta.directory_path) {
                meta.directory_path = meta.directorypath;
                delete meta.directorypath; // Remove the incorrect key
            }

            // Make sure genre_path is set
            if (meta.genre && !meta.genre_path) {
                meta.genre_path = meta.genre;
            }

            // Process metadata for book creation
            const bookData = {
                title: meta.title || "",
                description: meta.description || "",
                import_path: meta.import_path,
                import_type: meta.import_type,
            };

            // Extract directory name from import_path if title is empty
            if (!bookData.title && meta.import_path) {
                const pathParts = meta.import_path.split("/");
                const lastPart = pathParts[pathParts.length - 1];
                if (lastPart) {
                    bookData.title = lastPart;
                    console.log("Set title from import_path:", bookData.title);
                }
            }

            // Try to extract author from directory path if not set
            if (!meta.author && meta.import_path) {
                const pathParts = meta.import_path.split("/");
                if (pathParts.length > 2) {
                    // Often the author is two levels up from the book directory
                    const possibleAuthor = pathParts[pathParts.length - 3];
                    if (
                        possibleAuthor &&
                        possibleAuthor !== "books" &&
                        possibleAuthor !== "audiobooks"
                    ) {
                        meta.author = possibleAuthor;
                        console.log("Set author from path:", meta.author);
                    }
                }
            }

            // Try to extract genre from directory path if not set
            if (!meta.genre && meta.import_path) {
                const pathParts = meta.import_path.split("/");
                if (pathParts.length > 3) {
                    // Often the genre is three levels up from the book directory
                    const possibleGenre = pathParts[pathParts.length - 4];
                    if (
                        possibleGenre &&
                        possibleGenre !== "books" &&
                        possibleGenre !== "audiobooks"
                    ) {
                        meta.genre = possibleGenre;
                        console.log("Set genre from path:", meta.genre);
                    }
                }
            }

            // Add genre_path if available
            if (meta.genrePath) {
                bookData.genre_path = meta.genrePath;
            } else if (meta.genre) {
                bookData.genre_path = meta.genre;
            }

            // Add directory_path if available
            if (meta.directoryPath) {
                bookData.directory_path = meta.directoryPath;
            } else if (meta.directory_path) {
                bookData.directory_path = meta.directory_path;
            } else if (meta.import_path) {
                // Use import_path as directory_path if not set
                bookData.directory_path = meta.import_path;
                console.log(
                    "Using import_path as directory_path:",
                    bookData.directory_path,
                );
            }

            // Process author
            if (meta.author) {
                bookData.author = meta.author.split(/,\s*/);
            } else {
                bookData.author = ["Unknown"];
            }

            // Process genre
            if (meta.genre) {
                bookData.genre = meta.genre.split(/,\s*/);
            } else {
                bookData.genre = ["Uncategorized"];
            }

            // Process narrator
            if (meta.narrator) {
                bookData.narrator = meta.narrator.split(/,\s*/);
            }

            // Process series
            if (meta.series) {
                bookData.series = [
                    {
                        seriesName: meta.series,
                        number: meta.seriesNumber || "",
                    },
                ];
            } else if (meta.import_path) {
                // Try to extract series from directory path
                const pathParts = meta.import_path.split("/");
                if (pathParts.length > 1) {
                    const dirName = pathParts[pathParts.length - 1];
                    // Look for common series patterns like "Book 1" or "(Book 1)" or "#1"
                    const seriesMatch = dirName.match(
                        /(?:book|vol(?:ume)?|part|#)\s*(\d+)/i,
                    );
                    if (seriesMatch) {
                        // Try to extract series name from directory name
                        let seriesName = dirName
                            .replace(/(?:book|vol(?:ume)?|part|#)\s*\d+/i, "")
                            .trim();
                        seriesName = seriesName
                            .replace(/[\(\)\[\]\{\}]/g, "")
                            .trim(); // Remove brackets

                        if (seriesName) {
                            bookData.series = [
                                {
                                    seriesName: seriesName,
                                    number: seriesMatch[1] || "",
                                },
                            ];
                            console.log(
                                "Extracted series from path:",
                                bookData.series,
                            );
                        }
                    }
                }
            }

            // Add additional metadata if available
            if (meta.year) bookData.year = meta.year;
            if (meta.publisher) bookData.publisher = meta.publisher;
            if (meta.isbn) bookData.isbn = meta.isbn;

            // Log what we're actually sending
            console.log("Sending to processImport:", bookData);

            // Create a form to submit the data and redirect to the book creation form
            const $form = $("<form>", {
                action: "/admin/books/create",
                method: "GET",
                style: "display: none;",
            });

            // Add all the book data as hidden fields
            Object.keys(bookData).forEach(function (key) {
                const value = bookData[key];
                if (Array.isArray(value)) {
                    // Handle arrays (like author, genre)
                    value.forEach(function (item, index) {
                        if (typeof item === "object") {
                            // Handle objects like series
                            Object.keys(item).forEach(function (subKey) {
                                $form.append(
                                    $("<input>", {
                                        type: "hidden",
                                        name:
                                            key +
                                            "[" +
                                            index +
                                            "][" +
                                            subKey +
                                            "]",
                                        value: item[subKey],
                                    }),
                                );
                            });
                        } else {
                            $form.append(
                                $("<input>", {
                                    type: "hidden",
                                    name: key + "[]",
                                    value: item,
                                }),
                            );
                        }
                    });
                } else {
                    $form.append(
                        $("<input>", {
                            type: "hidden",
                            name: key,
                            value: value,
                        }),
                    );
                }
            });

            // Log what we're submitting
            console.log(
                "Redirecting to book creation form with data:",
                bookData,
            );

            // Append form to body, submit it, and remove it
            $("body").append($form);
            $form.submit();
            $form.remove();

            // Show loading message
            $root
                .find("#import-result")
                .html(
                    '<div class="alert alert-info">Redirecting to book creation form...</div>',
                );

            // Re-enable button after a delay (form will redirect)
            setTimeout(function () {
                $btn.prop("disabled", false).html(originalBtnText);
            }, 2000);
        }

        // The handleImportPrefill function now handles all the import logic

        // Return the instance for chaining
        return instance;
    };

    // Expose the loadRoots function directly on the initImportFileBrowser object
    window.initImportFileBrowser.loadRoots = function () {
        // Find all import file browser containers
        const $containers = $(".import-file-browser").closest("[id]");

        if ($containers.length > 0) {
            // Initialize each container if not already initialized
            $containers.each(function () {
                const $container = $(this);
                const id = "#" + $container.attr("id");

                if ($container.data("instance")) {
                    $container.data("instance").loadRoots();
                } else {
                    const instance = window.initImportFileBrowser(id);
                    if (instance && instance.loadRoots) {
                        instance.loadRoots();
                    }
                }
            });
        } else {
            // Try with the default selector as fallback
            const $root = $("#import-file-browser-root");
            if ($root.length > 0) {
                if ($root.data("instance")) {
                    $root.data("instance").loadRoots();
                } else {
                    window.initImportFileBrowser("#import-file-browser-root");
                }
            } else {
            }
        }
    };

    // Auto-initialize when document is ready
    $(document).ready(function () {
        // Find all import file browser containers
        const $containers = $(".import-file-browser").closest("[id]");

        if ($containers.length > 0) {
            // Initialize each container
            $containers.each(function () {
                const $container = $(this);
                const id = "#" + $container.attr("id");
                const instance = window.initImportFileBrowser(id);
            });

            // Call the global loadRoots function to initialize all browsers
            if (
                window.initImportFileBrowser &&
                window.initImportFileBrowser.loadRoots
            ) {
                window.initImportFileBrowser.loadRoots();
            }
        } else if ($("#import-file-browser-root").length > 0) {
            // Fallback to the default selector
            const instance = window.initImportFileBrowser(
                "#import-file-browser-root",
            );
            if (instance && instance.loadRoots) {
                instance.loadRoots();
            }
        } else {
        }
    });
})(jQuery);
