(function (window, $) {
    "use strict";

    if (!$) {
        console.error("form-directory.js requires jQuery");
        return;
    }

    const bookForm = (window.BookForm = window.BookForm || {});

    function formatFileSize(bytes) {
        if (!bytes) {
            return "0 B";
        }
        const k = 1024;
        const sizes = ["B", "KB", "MB", "GB"];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        if (i >= sizes.length) {
            return (
                parseFloat((bytes / Math.pow(k, sizes.length - 1)).toFixed(2)) +
                " " +
                sizes[sizes.length - 1]
            );
        }
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
    }
    function showToast(message, type) {
        const toast = $("<div>")
            .addClass("alert alert-" + (type || "success"))
            .css({
                position: "fixed",
                top: "20px",
                left: "50%",
                transform: "translateX(-50%)",
                zIndex: 9999,
                minWidth: "250px",
                boxShadow: "0 4px 6px rgba(0,0,0,0.1)",
            })
            .text(message);

        const $body = $("body");
        $body.append(toast);
        setTimeout(function () {
            toast.fadeOut(300, function () {
                $(this).remove();
            });
        }, 2000);
    }

    function resyncFromDirectoryPath() {
        const directoryPath = $("#directoryPath").val();
        if (!directoryPath) {
            showToast("Please enter a directory path first", "warning");
            return;
        }

        $.ajax({
            url: window.BOOK_FORM_ROUTES.parsePath,
            method: "POST",
            data: {
                path: directoryPath,
                _token: $('meta[name="csrf-token"]').attr("content"),
            },
            success(data) {
                if (data.title) {
                    $("#title").val(data.title);
                }
                if (data.author) {
                    const firstAuthorInput = $(
                        '#authors-group .author-row:first input[name="author[]"]',
                    );
                    if (firstAuthorInput.length) {
                        // Normalize author name for DB (spaces between initials)
                        // e.g. J.R.R. Tolkien -> J. R. R. Tolkien
                        const normalizedAuthor = window.BookForm
                            .normalizeAuthorName
                            ? window.BookForm.normalizeAuthorName(data.author)
                            : data.author;
                        firstAuthorInput.val(normalizedAuthor);
                    }
                }
                if (data.genre) {
                    const firstGenreSelect = $(
                        '#genres-group .genre-row:first select[name="genre[]"]',
                    );
                    if (firstGenreSelect.length) {
                        firstGenreSelect.val(data.genre);
                    }
                }
                if (data.series) {
                    const firstSeriesNameInput = $(
                        '#series-group .series-row:first input[name*="[seriesName]"]',
                    );
                    if (firstSeriesNameInput.length) {
                        firstSeriesNameInput.val(data.series);
                    }
                }
                if (data.seriesNumber) {
                    const firstSeriesNumberInput = $(
                        '#series-group .series-row:first input[name*="[number]"]',
                    );
                    if (firstSeriesNumberInput.length) {
                        firstSeriesNumberInput.val(data.seriesNumber);
                    }
                }

                showToast("Fields updated from path!", "success");
            },
            error(xhr) {
                const msg =
                    (xhr.responseJSON && xhr.responseJSON.error) ||
                    "Unknown error";
                showToast("Error parsing path: " + msg, "danger");
            },
        });
    }

    function loadDirectoryFiles($container) {
        const filesList = $container.find("#directory-files-list-content");
        if (!filesList.length) {
            return;
        }

        const $viewFilesBtn = $container.find(
            "#show-files-link, #showFilesLink",
        );
        if (!$viewFilesBtn.length) {
            return;
        }

        if (!$viewFilesBtn.data("originalHtml")) {
            $viewFilesBtn.data("originalHtml", $viewFilesBtn.html());
        }

        if (filesList.is(":visible")) {
            filesList.slideUp();
            $viewFilesBtn.html($viewFilesBtn.data("originalHtml"));
            return;
        }

        $viewFilesBtn.html(
            '<i class="fas fa-folder-open"></i> Hide Directory Files',
        );
        const dirPath = $container.find("#directoryPath").val();
        if (!dirPath) {
            filesList
                .html(
                    '<div class="p-3 text-danger">Please select a directory first.</div>',
                )
                .show();
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
            .show();

        const filesAjaxUrl =
            window.BOOK_FORM_ROUTES.filesAjax || "/admin/books/files-ajax";

        $.ajax({
            url: filesAjaxUrl,
            method: "GET",
            data: { directory: dirPath },
            dataType: "json",
            success(response) {
                $viewFilesBtn.prop("disabled", false).html(originalBtnHtml);
                if (response.exists === false) {
                    filesList.html(
                        '<div class="p-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Directory not found</div>',
                    );
                    return;
                }

                const files = response.files || [];
                if (!files.length) {
                    filesList.html(
                        '<div class="p-3 text-muted text-center">No files found in this directory.</div>',
                    );
                    return;
                }

                const listItems = files
                    .map(function (file) {
                        const filename =
                            file.name || file.filename || "Unknown";
                        const size = file.size ? formatFileSize(file.size) : "";
                        const ext = (file.extension || "").toLowerCase();

                        let icon = "fa-file";
                        let type = "";
                        if (
                            [
                                "mp3",
                                "m4b",
                                "m4a",
                                "aac",
                                "flac",
                                "ogg",
                                "wav",
                            ].includes(ext)
                        ) {
                            icon = "fa-file-audio text-primary";
                            type = "audio";
                        } else if (
                            ["jpg", "jpeg", "png", "gif", "webp"].includes(ext)
                        ) {
                            icon = "fa-file-image text-success";
                            type = "image";
                        } else if (["txt", "nfo", "md"].includes(ext)) {
                            icon = "fa-file-alt text-info";
                        } else if (["json", "xml"].includes(ext)) {
                            icon = "fa-file-code text-warning";
                        }

                        const dataAttrs = type
                            ? ' data-type="' + type + '"'
                            : "";
                        const coverAttr =
                            type === "image"
                                ? ' data-cover-file="' + filename + '"'
                                : "";
                        const badge = size
                            ? '<span class="text-muted">' + size + "</span>"
                            : "";
                        const playBtn =
                            type === "audio"
                                ? '<button type="button" class="btn btn-sm btn-outline-primary me-1 play-audio-btn" data-file="' +
                                  dirPath +
                                  "/" +
                                  filename +
                                  '" title="Play Audio"><i class="fas fa-play"></i></button>'
                                : "";
                        const viewBtn =
                            type === "image"
                                ? '<button type="button" class="btn btn-sm btn-outline-secondary me-1 view-image-btn" data-file="' +
                                  dirPath +
                                  "/" +
                                  filename +
                                  '" title="View Image"><i class="fas fa-image"></i></button>'
                                : "";
                        const metadataBtn =
                            type === "audio"
                                ? '<a href="#" class="btn btn-sm btn-outline-primary view-metadata" data-file="' +
                                  dirPath +
                                  "/" +
                                  filename +
                                  '" title="View Metadata"><i class="fas fa-info-circle"></i></a>'
                                : "";

                        return (
                            '<div class="list-group-item py-1 px-2 small d-flex justify-content-between align-items-center' +
                            (type === "image"
                                ? " list-group-item-action cursor-pointer"
                                : "") +
                            '"' +
                            dataAttrs +
                            coverAttr +
                            ">" +
                            '<span><i class="fas ' +
                            icon +
                            ' me-2"></i>' +
                            filename +
                            (type === "image"
                                ? ' <small class="text-muted">(click to set as cover)</small>'
                                : "") +
                            "</span>" +
                            '<span class="d-flex align-items-center gap-2">' +
                            badge +
                            playBtn +
                            viewBtn +
                            metadataBtn +
                            "</span></div>"
                        );
                    })
                    .join("");

                filesList
                    .html(
                        '<div class="list-group list-group-flush">' +
                            listItems +
                            "</div>",
                    )
                    .show();
            },
            error() {
                $viewFilesBtn.prop("disabled", false).html(originalBtnHtml);
                filesList
                    .html(
                        '<div class="p-3 text-danger">Error loading files. Please check the console.</div>',
                    )
                    .show();
            },
        });
    }

    function registerDirectoryHandlers($container) {
        $container
            .off("click.directory", "#show-files-link, #showFilesLink")
            .on(
                "click.directory",
                "#show-files-link, #showFilesLink",
                function (e) {
                    e.preventDefault();
                    loadDirectoryFiles($container);
                },
            );

        $container
            .off("click.coverFile", "[data-cover-file]")
            .on("click.coverFile", "[data-cover-file]", function (e) {
                e.preventDefault();
                const filename = $(this).data("cover-file");
                const dirPath = $container.find("#directoryPath").val();
                if (!filename || !dirPath) {
                    showToast(
                        "Unable to set cover - missing filename or directory path",
                        "warning",
                    );
                    return;
                }

                const $coverInput = $container.find("#coverImage");
                if ($coverInput.length) {
                    $coverInput.val(filename).trigger("change");
                    $(this).addClass("bg-success text-white");
                    setTimeout(() => {
                        $(this).removeClass("bg-success text-white");
                    }, 1000);

                    const coverUrl = "/cover/" + dirPath + "/" + filename;
                    const $coverPreview = $container.find(
                        ".cover-preview img, img[alt='cover'], .cover-option img",
                    );
                    if ($coverPreview.length) {
                        $coverPreview.each(function () {
                            const $img = $(this);
                            const currentSrc = $img.attr("src");
                            if (currentSrc && currentSrc.includes("/cover/")) {
                                $img.attr("src", coverUrl);
                            }
                        });
                    }
                }
            });

        $container
            .find("#directoryPath")
            .off("change.coverPath")
            .on("change.coverPath", function () {
                const newPath = $(this).val();
                const $coverInput = $container.find("#coverImage");
                const coverFilename = $coverInput.val();
                if (!(newPath && coverFilename)) {
                    return;
                }
                const coverUrl = "/cover/" + newPath + "/" + coverFilename;
                const $coverPreview = $container.find(
                    ".cover-preview img, img[alt='cover'], .cover-option img",
                );
                if ($coverPreview.length) {
                    $coverPreview.each(function () {
                        const $img = $(this);
                        const currentSrc = $img.attr("src");
                        if (currentSrc && currentSrc.includes("/cover/")) {
                            $img.attr("src", coverUrl);
                        }
                    });
                }
            });
    }

    function registerResyncHandler() {
        $(document)
            .off("click.resyncPath", "#resync-path-btn")
            .on("click.resyncPath", "#resync-path-btn", function (e) {
                e.preventDefault();
                resyncFromDirectoryPath();
            });
    }

    function updatePathFromFields() {
        const genre = $(
            '#genres-group .genre-row:first select[name="genre[]"]',
        ).val();
        const author = $(
            '#authors-group .author-row:first input[name="author[]"]',
        ).val();
        const series = $(
            '#series-group .series-row:first input[name*="[seriesName]"]',
        ).val();
        const seriesNumber = $(
            '#series-group .series-row:first input[name*="[number]"]',
        ).val();
        const title = $("#title").val();

        if (!genre && !author && !series && !title) {
            showToast(
                "Please fill in at least one field (genre, author, series, or title)",
                "warning",
            );
            return;
        }

        $.ajax({
            url: window.BOOK_FORM_ROUTES.buildPathFromFields,
            method: "POST",
            data: {
                genre: genre || "",
                author: author || "",
                series: series || "",
                seriesNumber: seriesNumber || "",
                title: title || "",
                _token: $('meta[name="csrf-token"]').attr("content"),
            },
            success(data) {
                if (data.success && data.directoryPath) {
                    $("#directoryPath")
                        .val(data.directoryPath)
                        .trigger("change");
                    showToast("Directory path updated!", "success");
                } else {
                    showToast(data.message || "Failed to build path", "danger");
                }
            },
            error(xhr) {
                const msg =
                    (xhr.responseJSON && xhr.responseJSON.message) ||
                    "Unknown error";
                showToast("Error building path: " + msg, "danger");
            },
        });
    }

    function registerUpdatePathFromFieldsHandler() {
        $(document)
            .off("click.updatePathFromFields", "#update-path-from-fields-btn")
            .on(
                "click.updatePathFromFields",
                "#update-path-from-fields-btn",
                function (e) {
                    e.preventDefault();
                    updatePathFromFields();
                },
            );
    }

    function registerGenreChangeHandler() {
        $(document)
            .off(
                "change.genreUpdate",
                '#genres-group .genre-row:first select[name="genre[]"]',
            )
            .on(
                "change.genreUpdate",
                '#genres-group .genre-row:first select[name="genre[]"]',
                function () {
                    const $updateBtn = $(".update-path-from-genre");
                    const originalGenre = $(this)
                        .closest(".genre-row")
                        .data("original-genre");
                    const currentGenre = $(this).val();

                    // Show button if genre changed from original
                    if (
                        originalGenre &&
                        currentGenre &&
                        originalGenre !== currentGenre
                    ) {
                        $updateBtn.css("display", "flex");
                    } else {
                        $updateBtn.hide();
                    }
                },
            );
    }

    function updatePathFromGenre() {
        const genre = $(
            '#genres-group .genre-row:first select[name="genre[]"]',
        ).val();
        const currentPath = $("#directoryPath").val();

        if (!genre) {
            showToast("Please select a genre first", "warning");
            return;
        }

        if (!currentPath) {
            showToast("No current directory path to update", "warning");
            return;
        }

        // Split current path and replace first part (genre) with new genre
        const parts = currentPath.split("/");
        if (parts.length > 0) {
            parts[0] = genre.replace(/[\\\/]/g, "-");
            const newPath = parts.join("/");
            $("#directoryPath").val(newPath).trigger("change");
            showToast("Directory path updated with new genre!", "success");

            // Hide the button after updating
            $(".update-path-from-genre").hide();
        }
    }

    function registerUpdatePathFromGenreHandler() {
        $(document)
            .off("click.updatePathFromGenre", ".update-path-from-genre")
            .on(
                "click.updatePathFromGenre",
                ".update-path-from-genre",
                function (e) {
                    e.preventDefault();
                    updatePathFromGenre();
                },
            );
    }

    function registerMetadataHandler() {
        $(document)
            .off("click.metadata", ".view-metadata")
            .on("click.metadata", ".view-metadata", function (e) {
                e.preventDefault();
                const filePath = $(this).data("file");
                const modal = new bootstrap.Modal(
                    document.getElementById("audioMetadataModal"),
                );
                const metadataContent = $("#metadata-content");

                metadataContent.html(
                    '<div class="text-center p-4">' +
                        '<div class="spinner-border" role="status">' +
                        '<span class="visually-hidden">Loading...</span>' +
                        "</div>" +
                        '<p class="mt-2 text-muted">Loading metadata...</p>' +
                        "</div>",
                );

                modal.show();

                $.ajax({
                    url:
                        window.BOOK_FORM_ROUTES.audioMetadata ||
                        "/admin/books/audio-metadata",
                    method: "GET",
                    data: { file: filePath },
                    success(response) {
                        if (response.success && response.metadata) {
                            displayMetadata(response.metadata, response.file);
                        } else {
                            metadataContent.html(
                                '<div class="alert alert-danger">' +
                                    '<i class="fas fa-exclamation-triangle me-2"></i>' +
                                    "Failed to load metadata" +
                                    "</div>",
                            );
                        }
                    },
                    error(xhr) {
                        const error =
                            (xhr.responseJSON && xhr.responseJSON.error) ||
                            "Unknown error";
                        metadataContent.html(
                            '<div class="alert alert-danger">' +
                                '<i class="fas fa-exclamation-triangle me-2"></i>' +
                                "Error: " +
                                error +
                                "</div>",
                        );
                    },
                });
            });
    }

    function registerAudioPlayerHandler() {
        $(document)
            .off("click.audioPlayer", ".play-audio-btn")
            .on("click.audioPlayer", ".play-audio-btn", function (e) {
                e.preventDefault();
                e.stopPropagation();
                const filePath = $(this).data("file");
                const filename = filePath.split("/").pop();
                const coverUrl = "/cover/" + filePath;
                window.openAudioPlayer(filename, coverUrl);
            });
    }

    function registerImageViewerHandler() {
        $(document)
            .off("click.imageViewer", ".view-image-btn")
            .on("click.imageViewer", ".view-image-btn", function (e) {
                e.preventDefault();
                e.stopPropagation();
                const filePath = $(this).data("file");
                const filename = filePath.split("/").pop();
                const imageUrl = "/cover/" + filePath;
                window.openImageViewer(filename, imageUrl);
            });
    }

    function displayMetadata(metadata, filename) {
        let html = '<div class="metadata-display">';
        if (metadata.file) {
            html +=
                '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-file me-2"></i>File Information</h6>';
            html += '<table class="table table-sm table-bordered mb-4">';
            html +=
                '<tr><th style="width: 30%">Filename</th><td>' +
                filename +
                "</td></tr>";
            if (metadata.file.size_formatted) {
                html +=
                    "<tr><th>Size</th><td>" +
                    metadata.file.size_formatted +
                    "</td></tr>";
            }
            if (metadata.file.modified) {
                html +=
                    "<tr><th>Modified</th><td>" +
                    metadata.file.modified +
                    "</td></tr>";
            }
            if (metadata.file.extension) {
                html +=
                    "<tr><th>Extension</th><td>" +
                    metadata.file.extension.toUpperCase() +
                    "</td></tr>";
            }
            html += "</table>";
        }

        if (metadata.format) {
            html +=
                '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i>Format Information</h6>';
            html += '<table class="table table-sm table-bordered mb-4">';
            if (metadata.format.format_long_name) {
                html +=
                    '<tr><th style="width: 30%">Format</th><td>' +
                    metadata.format.format_long_name +
                    "</td></tr>";
            }
            if (metadata.format.duration_formatted) {
                html +=
                    "<tr><th>Duration</th><td>" +
                    metadata.format.duration_formatted +
                    "</td></tr>";
            }
            if (metadata.format.bit_rate_formatted) {
                html +=
                    "<tr><th>Bit Rate</th><td>" +
                    metadata.format.bit_rate_formatted +
                    "</td></tr>";
            }
            html += "</table>";
        }

        if (metadata.streams && metadata.streams.length) {
            html +=
                '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-stream me-2"></i>Audio Streams</h6>';
            metadata.streams.forEach(function (stream, index) {
                html += '<table class="table table-sm table-bordered mb-3">';
                html +=
                    '<tr><th colspan="2" class="bg-light">Stream ' +
                    (index + 1) +
                    "</th></tr>";
                if (stream.codec_long_name) {
                    html +=
                        '<tr><th style="width: 30%">Codec</th><td>' +
                        stream.codec_long_name +
                        "</td></tr>";
                }
                if (stream.sample_rate_formatted) {
                    html +=
                        "<tr><th>Sample Rate</th><td>" +
                        stream.sample_rate_formatted +
                        "</td></tr>";
                }
                if (stream.channels) {
                    html +=
                        "<tr><th>Channels</th><td>" +
                        stream.channels +
                        (stream.channel_layout
                            ? " (" + stream.channel_layout + ")"
                            : "") +
                        "</td></tr>";
                }
                if (stream.bit_rate_formatted) {
                    html +=
                        "<tr><th>Bit Rate</th><td>" +
                        stream.bit_rate_formatted +
                        "</td></tr>";
                }
                html += "</table>";
            });
        }

        if (metadata.tags && Object.keys(metadata.tags).length) {
            html +=
                '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-tags me-2"></i>Metadata Tags</h6>';
            html += '<table class="table table-sm table-bordered mb-3">';
            Object.keys(metadata.tags).forEach(function (key) {
                const displayKey = key
                    .replace(/_/g, " ")
                    .replace(/\b\w/g, function (l) {
                        return l.toUpperCase();
                    });
                html +=
                    '<tr><th style="width: 30%">' +
                    displayKey +
                    "</th><td>" +
                    metadata.tags[key] +
                    "</td></tr>";
            });
            html += "</table>";
        }

        html += "</div>";
        $("#metadata-content").html(html);
    }

    function initialize($container) {
        registerDirectoryHandlers($container);
        registerResyncHandler();
        registerUpdatePathFromFieldsHandler();
        registerGenreChangeHandler();
        registerUpdatePathFromGenreHandler();
        registerMetadataHandler();
        registerAudioPlayerHandler();
        registerImageViewerHandler();
    }

    bookForm.loadDirectoryFiles = loadDirectoryFiles;
    bookForm.registerDirectoryHandlers = registerDirectoryHandlers;
    bookForm.registerDirectoryFeatures = initialize;
    bookForm.formatFileSize = formatFileSize;
    bookForm.showToast = showToast;
    bookForm.resyncFromDirectoryPath = resyncFromDirectoryPath;
    bookForm.displayMetadata = displayMetadata;

    window.showToast = showToast;
    window.resyncFromDirectoryPath = resyncFromDirectoryPath;

    // Audio player popup
    window.openAudioPlayer = function (filename, url) {
        const player = document.getElementById("audioPlayer");
        document.getElementById("audioModalTitle").textContent = filename;
        player.pause();
        player.src = url;
        player.load();
        const modal = new bootstrap.Modal(document.getElementById("audioModal"));
        modal.show();
        document.getElementById("audioModal").addEventListener(
            "hidden.bs.modal",
            function () {
                player.pause();
                player.src = "";
            },
            { once: true },
        );
    };

    // Image viewer popup
    window.openImageViewer = function (filename, url) {
        document.getElementById("imageModalTitle").textContent = filename;
        document.getElementById("imageModalImg").src = url;
        new bootstrap.Modal(document.getElementById("imageModal")).show();
    };
})(window, window.jQuery);
