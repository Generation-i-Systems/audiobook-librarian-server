/**
 * Directory Conflict Detection and Resolution
 */

(function (window, $) {
    "use strict";

    if (!$) {
        console.error("directory-conflict.js requires jQuery");
        return;
    }

    let conflictData = null;
    let checkTimeoutId = null;
    let lastCheckedPath = null;

    function initDirectoryConflictDetection() {
        const $directoryPath = $("#directoryPath");
        const $bookForm = $("#book-form");

        if (!$directoryPath.length || !$bookForm.length) {
            console.warn("Directory path field or book form not found");
            return;
        }

        // Get current book ID from URL if editing
        const urlParts = window.location.pathname.split("/");
        const currentBookId =
            urlParts[urlParts.length - 2] === "books"
                ? urlParts[urlParts.length - 1]
                : null;

        // Check for conflicts when directory path changes
        $directoryPath.on("blur change", function () {
            const path = $(this).val().trim();

            // Don't check if path is empty or same as last checked
            if (!path || path === lastCheckedPath) {
                return;
            }

            // Debounce the check
            if (checkTimeoutId) {
                clearTimeout(checkTimeoutId);
            }

            checkTimeoutId = setTimeout(function () {
                checkDirectoryConflict(path, currentBookId);
            }, 500);
        });

        // Handle conflict resolution buttons
        $("#conflict-change-path-btn").on("click", function () {
            // Just close the modal and let user change the path
            const modal = bootstrap.Modal.getInstance(
                document.getElementById("directoryConflictModal"),
            );
            modal.hide();
            $("#directoryPath").focus().select();
        });

        $("#conflict-merge-btn").on("click", function () {
            // Allow merge - just close modal and proceed with form submission
            const modal = bootstrap.Modal.getInstance(
                document.getElementById("directoryConflictModal"),
            );
            modal.hide();
            // Flag that we're allowing the merge
            $bookForm.data("allowDirectoryMerge", true);
        });

        $("#conflict-move-other-btn").on("click", function () {
            // TODO: Implement move other book functionality if needed
            alert(
                "This feature is not yet implemented. Please choose another option.",
            );
        });

        // Prevent form submission if there's an unresolved conflict
        $bookForm.on("submit", function (e) {
            const path = $directoryPath.val().trim();

            // If we've allowed the merge, don't check again
            if ($(this).data("allowDirectoryMerge")) {
                $(this).removeData("allowDirectoryMerge");
                return true;
            }

            // If path hasn't been checked or has changed since last check, check now
            if (path && path !== lastCheckedPath) {
                e.preventDefault();
                checkDirectoryConflict(path, currentBookId, true);
                return false;
            }
        });
    }

    function checkDirectoryConflict(directoryPath, currentBookId, onSubmit) {
        const csrfToken =
            $('meta[name="csrf-token"]').attr("content") ||
            $('input[name="_token"]').val();

        if (
            !window.BOOK_FORM_ROUTES ||
            !window.BOOK_FORM_ROUTES.checkDirectoryConflict
        ) {
            console.error("Directory conflict check route not defined");
            return;
        }

        $.ajax({
            url: window.BOOK_FORM_ROUTES.checkDirectoryConflict,
            method: "POST",
            data: {
                directoryPath: directoryPath,
                currentBookId: currentBookId,
                _token: csrfToken,
            },
            success: function (response) {
                lastCheckedPath = directoryPath;

                if (response.conflict) {
                    conflictData = response;
                    showConflictModal(response, onSubmit);
                } else {
                    // No conflict, allow form submission if this was triggered on submit
                    if (onSubmit) {
                        $("#book-form")
                            .data("allowDirectoryMerge", true)
                            .submit();
                    }
                }
            },
            error: function (xhr, status, error) {
                console.error("Error checking directory conflict:", error);
                // Don't block the form if check fails
                if (onSubmit) {
                    $("#book-form").submit();
                }
            },
        });
    }

    function showConflictModal(data, onSubmit) {
        const $modal = $("#directoryConflictModal");
        const $bookList = $("#conflict-book-list");

        // Clear previous content
        $bookList.empty();

        // Populate book list
        if (data.books && data.books.length > 0) {
            data.books.forEach(function (book) {
                const $li = $("<li>").addClass("mb-2");
                $li.html(
                    '<i class="fas fa-book me-2"></i>' +
                        "<strong>" +
                        escapeHtml(book.title) +
                        "</strong>" +
                        '<br><small class="text-muted ms-4">by ' +
                        escapeHtml(book.author) +
                        "</small>",
                );
                $bookList.append($li);
            });
        }

        // Show the modal
        const modal = new bootstrap.Modal($modal[0], {
            backdrop: "static",
            keyboard: false,
        });
        modal.show();
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Initialize immediately since this is an IIFE
    initDirectoryConflictDetection();
})(window, window.jQuery);
