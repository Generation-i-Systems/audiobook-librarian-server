/**
 * Initializes the dynamic behaviour for the book form. Depends on helpers registered on window.BookForm.
 */
(function (window, $) {
    "use strict";

    if (!$) {
        console.error("init-book-form.js requires jQuery");
        return;
    }

    const bookForm = (window.BookForm = window.BookForm || {});

    function initBookForm(formContainerSelector) {
        const $container = $(formContainerSelector);
        if (!$container.length) {
            return;
        }

        const {
            addAuthorRow,
            addNarratorRow,
            addSeriesRow,
            addGenreRow,
            updateAddRowButtons,
            initializeAutocomplete,
            attachCoverPreviewModal,
            setupAutofillModal,
        } = bookForm;

        if (typeof updateAddRowButtons === "function") {
            updateAddRowButtons($container, "#authors-group", ".author-row", ".add-author-row");
            updateAddRowButtons($container, "#series-group", ".series-row", ".add-series-row");
            updateAddRowButtons($container, "#genres-group", ".genre-row", ".add-genre-row");
            updateAddRowButtons($container, "#narrators-group", ".narrator-row", ".add-narrator-row");
        }

        if (typeof initializeAutocomplete === "function" && window.BOOK_FORM_ROUTES) {
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
        }

        $container
            .off("click", ".add-author-row")
            .on("click", ".add-author-row", function () {
                if (typeof addAuthorRow === "function") {
                    addAuthorRow($container);
                }
            });

        $container
            .off("click", ".add-narrator-row")
            .on("click", ".add-narrator-row", function () {
                if (typeof addNarratorRow === "function") {
                    addNarratorRow($container);
                }
            })
            .off("click", ".remove-row")
            .on("click", ".remove-row", function () {
                const group = $container.find("#narrators-group")[0];
                if (!group) {
                    return;
                }
                const rows = group.querySelectorAll(".narrator-row");
                const row = $(this).closest(".narrator-row")[0];
                if (rows.length > 1) {
                    if (row) row.remove();
                } else if (row) {
                    const input = row.querySelector('input[name="narrator[]"]');
                    if (input) input.value = "";
                }
                if (typeof updateAddRowButtons === "function") {
                    updateAddRowButtons(
                        $container,
                        "#narrators-group",
                        ".narrator-row",
                        ".add-narrator-row",
                    );
                }
            });

        $container
            .off("click", ".add-series-row")
            .on("click", ".add-series-row", function () {
                if (typeof addSeriesRow === "function") {
                    addSeriesRow($container);
                }
            });

        $container
            .off("click", ".add-genre-row")
            .on("click", ".add-genre-row", function () {
                if (typeof addGenreRow === "function") {
                    addGenreRow($container);
                }
            });

        $container
            .off("click", ".remove-author")
            .on("click", ".remove-author", function () {
                const group = $container.find("#authors-group")[0];
                if (!group) {
                    return;
                }
                const rows = group.querySelectorAll(".author-row");
                const row = $(this).closest(".author-row")[0];
                if (rows.length > 1) {
                    if (row) row.remove();
                } else if (row) {
                    const input = row.querySelector('input[name="author[]"]');
                    if (input) input.value = "";
                }
                if (typeof updateAddRowButtons === "function") {
                    updateAddRowButtons($container, "#authors-group", ".author-row", ".add-author-row");
                }
            });

        $container
            .off("click", ".remove-series")
            .on("click", ".remove-series", function () {
                const group = $container.find("#series-group")[0];
                if (!group) {
                    return;
                }
                const rows = group.querySelectorAll(".series-row");
                const row = $(this).closest(".series-row")[0];
                if (rows.length > 1) {
                    if (row) row.remove();
                } else if (row) {
                    const numberInput = row.querySelector('input[name*="[number]"]');
                    const seriesNameInput = row.querySelector('input[name*="[seriesName]"]');
                    if (numberInput) numberInput.value = "";
                    if (seriesNameInput) seriesNameInput.value = "";
                }
                if (typeof updateAddRowButtons === "function") {
                    updateAddRowButtons($container, "#series-group", ".series-row", ".add-series-row");
                }
            });

        $container
            .off("click", ".remove-genre")
            .on("click", ".remove-genre", function () {
                const group = $container.find("#genres-group")[0];
                if (!group) {
                    return;
                }
                const rows = group.querySelectorAll(".genre-row");
                const row = $(this).closest(".genre-row")[0];
                if (rows.length > 1) {
                    if (row) row.remove();
                } else if (row) {
                    const select = row.querySelector('select[name="genre[]"]');
                    if (select) select.value = "";
                }
                if (typeof updateAddRowButtons === "function") {
                    updateAddRowButtons($container, "#genres-group", ".genre-row", ".add-genre-row");
                }
            });

        if (typeof attachCoverPreviewModal === "function") {
            attachCoverPreviewModal($container);
        }
        if (typeof setupAutofillModal === "function") {
            setupAutofillModal($container);
        }
    }

    window.initBookForm = initBookForm;
})(window, window.jQuery);
