/**
 * Series Rename Functionality
 * Handles renaming a series across all books
 */

(function () {
    "use strict";

    let renameSeriesUrl = "";

    // Initialize series rename
    function initSeriesRename() {
        renameSeriesUrl =
            window.BOOK_FORM_ROUTES?.renameSeries ||
            "/admin/books/rename-series";

        // Handle rename series button click (using event delegation for dynamically added buttons)
        $(document).on("click", ".rename-series-btn", function (e) {
            e.preventDefault();
            const seriesName = $(this).data("series-name");
            openRenameSeriesModal(seriesName);
        });

        // Handle confirm rename button
        $("#confirm-rename-series-btn").on("click", function () {
            renameSeries();
        });

        // Clear feedback when modal is closed
        $("#renameSeriesModal").on("hidden.bs.modal", function () {
            $("#new-series-name").val("");
            $("#rename-series-feedback").html("");
        });
    }

    // Open rename series modal
    function openRenameSeriesModal(seriesName) {
        if (!seriesName) {
            alert("No series name found to rename.");
            return;
        }

        $("#old-series-name").val(seriesName);
        $("#new-series-name").val("");
        $("#rename-series-feedback").html("");

        const modal = new bootstrap.Modal(
            document.getElementById("renameSeriesModal"),
        );
        modal.show();
    }

    // Rename series
    function renameSeries(merge = false) {
        const oldName = $("#old-series-name").val();
        const newName = $("#new-series-name").val().trim();

        if (!newName) {
            showFeedback("Please enter a new series name.", "danger");
            return;
        }

        if (oldName === newName) {
            showFeedback(
                "New name must be different from current name.",
                "danger",
            );
            return;
        }

        const confirmBtn = $("#confirm-rename-series-btn");
        const originalText = confirmBtn.html();
        confirmBtn
            .prop("disabled", true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i>Renaming...');

        $.ajax({
            url: renameSeriesUrl,
            method: "POST",
            data: {
                oldName: oldName,
                newName: newName,
                merge: merge ? 1 : 0,
                _token: $('input[name="_token"]').val(),
            },
            dataType: "json",
            success: function (response) {
                confirmBtn.prop("disabled", false).html(originalText);

                if (response.success) {
                    const action = response.merged ? "merged" : "renamed";
                    showFeedback(
                        `Successfully ${action} series "${oldName}" to "${newName}" for ${response.count} book(s).`,
                        "success",
                    );

                    // Update the series name in the form
                    $('input[name^="series"][name$="[seriesName]"]').each(
                        function () {
                            if ($(this).val() === oldName) {
                                $(this).val(newName);
                            }
                        },
                    );

                    // Close modal after 2 seconds
                    setTimeout(function () {
                        bootstrap.Modal.getInstance(
                            document.getElementById("renameSeriesModal"),
                        ).hide();
                    }, 2000);
                } else if (response.warning) {
                    // Series already exists, ask for confirmation
                    showMergeConfirmation(response.warning, oldName, newName);
                } else {
                    showFeedback(
                        response.message || "Failed to rename series.",
                        "danger",
                    );
                }
            },
            error: function (xhr, status, error) {
                confirmBtn.prop("disabled", false).html(originalText);
                console.error("Error renaming series:", error);
                showFeedback(
                    "An error occurred while renaming the series.",
                    "danger",
                );
            },
        });
    }

    // Show merge confirmation
    function showMergeConfirmation(message, oldName, newName) {
        const confirmHtml = `
            <div class="alert alert-warning mb-0">
                <p><strong>${message}</strong></p>
                <p>Do you want to merge these series?</p>
                <button type="button" class="btn btn-warning btn-sm" id="confirm-merge-btn">
                    <i class="fas fa-compress-alt me-1"></i>Yes, Merge Series
                </button>
                <button type="button" class="btn btn-secondary btn-sm" id="cancel-merge-btn">Cancel</button>
            </div>
        `;

        $("#rename-series-feedback").html(confirmHtml);

        // Handle merge confirmation
        $("#confirm-merge-btn").on("click", function () {
            renameSeries(true);
        });

        // Handle cancel
        $("#cancel-merge-btn").on("click", function () {
            $("#rename-series-feedback").html("");
        });
    }

    // Show feedback message
    function showFeedback(message, type) {
        const alertClass =
            type === "success" ? "alert-success" : "alert-danger";
        const $feedback = $("#rename-series-feedback");
        const $alert = $("<div>")
            .addClass(`alert ${alertClass} mb-0`)
            .text(message);
        $feedback.empty().append($alert);
    }

    // Initialize on document ready
    $(document).ready(function () {
        if ($(".rename-series-btn").length || $("#series-group").length) {
            initSeriesRename();
        }
    });
})();
