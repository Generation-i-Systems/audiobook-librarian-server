// Global settings/variables
window.googleBooksMatchLimit = 10;
window.googleBooksMoreMatches = false;
window.googleBooksMatches = [];

// Raw JSON Edit Modal logic
export function initRawJsonEdit() {
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
            if (!bookId) {
                return;
            }
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
                data: {
                    _token: $('meta[name="csrf-token"]').attr("content"),
                    json: JSON.stringify(json),
                },
                success: function () {
                    $("#raw-json-error").hide();
                    $("#rawJsonModal").modal("hide");
                    location.reload();
                },
                error: function (xhr) {
                    $("#raw-json-error")
                        .text(
                            "Failed to save JSON: " +
                                (xhr.responseJSON?.error || xhr.statusText),
                        )
                        .show();
                },
            });
        });
    }
}

export function initModalEnterGuards() {
    $(document)
        .off("keydown.modalEnterGuards", ".modal input, .modal select")
        .on(
            "keydown.modalEnterGuards",
            ".modal input, .modal select",
            function (e) {
                if (e.key !== "Enter") {
                    return;
                }

                e.preventDefault();
                e.stopPropagation();

                const modal = e.target.closest(".modal");
                if (!modal) {
                    return;
                }

                if (modal.id === "renameSeriesModal") {
                    $("#confirm-rename-series-btn").trigger("click");
                    return;
                }

                if (modal.id === "autofillModal") {
                    $("#search-all-btn").trigger("click");
                    return;
                }

                if (modal.id === "coverImageModal") {
                    const coverUrlInput =
                        document.getElementById("coverImageUrlText");
                    if (coverUrlInput && e.target === coverUrlInput) {
                        coverUrlInput.dispatchEvent(
                            new Event("change", { bubbles: true }),
                        );
                    }
                }
            },
        );
}

$(initRawJsonEdit);
$(initModalEnterGuards);
