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
        console.log(
            "[init-book-form] initBookForm called with selector:",
            formContainerSelector,
        );
        const $container = $(formContainerSelector);
        if (!$container.length) {
            console.log("[init-book-form] Container not found!");
            return;
        }
        console.log(
            "[init-book-form] Container found, BookForm object:",
            bookForm,
        );

        const {
            initializeTemplates,
            addAuthorRow,
            addNarratorRow,
            addSeriesRow,
            addGenreRow,
            updateAddRowButtons,
            clearAuthorRow,
            clearNarratorRow,
            clearSeriesRow,
            clearGenreRow,
            initializeAutocomplete,
            attachCoverPreviewModal,
            setupAutofillModal,
        } = bookForm;

        if (typeof initializeTemplates === "function") {
            initializeTemplates($container);
        }

        if (typeof updateAddRowButtons === "function") {
            updateAddRowButtons(
                $container,
                "#authors-group",
                ".author-row",
                ".add-author-row",
            );
            updateAddRowButtons(
                $container,
                "#genres-group",
                ".genre-row",
                ".add-genre-row",
            );
            updateAddRowButtons(
                $container,
                "#series-group",
                ".series-row",
                ".add-series-row",
            );
            updateAddRowButtons(
                $container,
                "#narrators-group",
                ".narrator-row",
                ".add-narrator-row",
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
            .off("click", ".add-series-row")
            .on("click", ".add-series-row", function () {
                if (typeof addSeriesRow === "function") {
                    addSeriesRow($container);
                }
            });

        $container
            .off("click", ".add-narrator-row")
            .on("click", ".add-narrator-row", function () {
                if (typeof addNarratorRow === "function") {
                    addNarratorRow($container);
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
                } else if (row && typeof clearAuthorRow === "function") {
                    clearAuthorRow(row);
                }
                if (typeof updateAddRowButtons === "function") {
                    updateAddRowButtons(
                        $container,
                        "#authors-group",
                        ".author-row",
                        ".add-author-row",
                    );
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
                } else if (row && typeof clearSeriesRow === "function") {
                    clearSeriesRow(row);
                }
                if (typeof updateAddRowButtons === "function") {
                    updateAddRowButtons(
                        $container,
                        "#series-group",
                        ".series-row",
                        ".add-series-row",
                    );
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
                } else if (row && typeof clearGenreRow === "function") {
                    clearGenreRow(row);
                }
                if (typeof updateAddRowButtons === "function") {
                    updateAddRowButtons(
                        $container,
                        "#genres-group",
                        ".genre-row",
                        ".add-genre-row",
                    );
                }
            });

        $container
            .off("click", ".remove-narrator")
            .on("click", ".remove-narrator", function () {
                const group = $container.find("#narrators-group")[0];
                if (!group) {
                    return;
                }
                const rows = group.querySelectorAll(".narrator-row");
                const row = $(this).closest(".narrator-row")[0];
                if (rows.length > 1) {
                    if (row) row.remove();
                } else if (row && typeof clearNarratorRow === "function") {
                    clearNarratorRow(row);
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

        if (typeof attachCoverPreviewModal === "function") {
            console.log("[init-book-form] Calling attachCoverPreviewModal");
            attachCoverPreviewModal($container);
        } else {
            console.log(
                "[init-book-form] attachCoverPreviewModal is not a function",
            );
        }
        if (typeof setupAutofillModal === "function") {
            console.log("[init-book-form] Calling setupAutofillModal");
            setupAutofillModal($container);
        } else {
            console.log(
                "[init-book-form] setupAutofillModal is not a function",
            );
        }
        if (typeof bookForm.registerDirectoryFeatures === "function") {
            console.log("[init-book-form] Calling registerDirectoryFeatures");
            bookForm.registerDirectoryFeatures($container);
        } else {
            console.log(
                "[init-book-form] registerDirectoryFeatures is not a function",
            );
        }
    }

    window.initBookForm = initBookForm;
})(window, window.jQuery);
