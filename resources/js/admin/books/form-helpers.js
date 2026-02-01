(function (window, $) {
    "use strict";

    if (!$) {
        console.error("form-helpers.js requires jQuery");
        return;
    }

    const bookForm = (window.BookForm = window.BookForm || {});

    const templates = {};

    function escapeHtml(text) {
        if (typeof text !== "string") {
            return text;
        }
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Normalize author name for both database and directory (unspaced initials)
     * e.g., "J. R. R. Tolkien" -> "J.R.R. Tolkien"
     */
    function normalizeAuthorName(name) {
        if (!name) return "";
        let normalized = name.trim();

        // Ensure period after single initials
        normalized = normalized.replace(/\b([A-Z])\s+/, "$1. ");
        normalized = normalized.replace(/\s+([A-Z])$/, " $1.");

        // Remove spaces between initials
        // "J. R. R." -> "J.R.R."
        normalized = normalized.replace(/\b([A-Z]\.)\s+([A-Z]\.)/g, "$1$2");
        normalized = normalized.replace(/\b([A-Z]\.)\s+([A-Z]\.)/g, "$1$2");

        return normalized.trim();
    }

    /**
     * @deprecated Use normalizeAuthorName - now unified
     */
    function normalizeAuthorNameForDirectory(name) {
        return normalizeAuthorName(name);
    }

    /**
     * Normalize author name for directory (NO spaces between initials)
     * e.g., "J. R. R. Tolkien" -> "J.R.R. Tolkien"
     */
    function normalizeAuthorNameForDirectory(name) {
        if (!name) return "";
        let normalized = normalizeAuthorName(name); // First ensure it's clean

        // Remove spaces between initials
        // "J. R. R." -> "J.R.R."
        normalized = normalized.replace(/\b([A-Z]\.)\s+([A-Z]\.)/g, "$1$2");
        normalized = normalized.replace(/\b([A-Z]\.)\s+([A-Z]\.)/g, "$1$2");

        return normalized.trim();
    }

    function clearAuthorRow(row) {
        const input = row.querySelector('input[name="author[]"]');
        if (input) {
            input.value = "";
        }
    }

    function clearNarratorRow(row) {
        const input = row.querySelector('input[name="narrator[]"]');
        if (input) {
            input.value = "";
        }
    }

    function clearSeriesRow(row) {
        const numberInput = row.querySelector('input[name*="[number]"]');
        const nameInput = row.querySelector('input[name*="[seriesName]"]');
        const collectionInput = row.querySelector(
            'input[name*="[isCollection]"]',
        );

        if (numberInput) {
            numberInput.value = "";
        }
        if (nameInput) {
            nameInput.value = "";
        }
        if (collectionInput) {
            collectionInput.checked = false;
        }
    }

    function clearGenreRow(row) {
        const select = row.querySelector('select[name="genre[]"]');
        if (select) {
            select.value = "";
        }
    }

    function initializeTemplates($container) {
        const createTemplate = (groupSelector, rowSelector, clearFn) => {
            const group = $container.find(groupSelector)[0];
            if (!group) {
                console.warn(
                    `[form-helpers] Cannot find ${groupSelector} for template creation`,
                );
                return null;
            }
            const rows = group.querySelectorAll(rowSelector);
            if (rows.length === 0) {
                console.warn(
                    `[form-helpers] Cannot find ${rowSelector} in ${groupSelector} for template creation`,
                );
                return null;
            }
            // Clone from the last row since it has the add button
            const lastRow = rows[rows.length - 1];
            const template = lastRow.cloneNode(true);
            clearFn(template);
            return template;
        };

        templates.author = createTemplate(
            "#authors-group",
            ".author-row",
            clearAuthorRow,
        );
        templates.narrator = createTemplate(
            "#narrators-group",
            ".narrator-row",
            clearNarratorRow,
        );
        templates.series = createTemplate(
            "#series-group",
            ".series-row",
            clearSeriesRow,
        );
        templates.genre = createTemplate(
            "#genres-group",
            ".genre-row",
            clearGenreRow,
        );
    }

    function updateAddRowButtons(
        $container,
        groupSelector,
        rowSelector,
        buttonSelector,
    ) {
        const group = $container.find(groupSelector)[0];
        if (!group) {
            return;
        }

        group.querySelectorAll(buttonSelector).forEach((btn) => {
            btn.style.display = "none";
        });

        const rows = group.querySelectorAll(rowSelector);
        if (rows.length > 0) {
            const lastRow = rows[rows.length - 1];
            const addButton = lastRow.querySelector(buttonSelector);
            if (addButton) {
                addButton.style.display = "flex";
            }
        }
    }

    function addAuthorRow($container, authorName = "") {
        const group = $container.find("#authors-group")[0];
        if (!group) {
            console.error(
                "[form-helpers] Cannot add author row: #authors-group not found",
            );
            return;
        }
        if (!templates.author) {
            console.error(
                "[form-helpers] Cannot add author row: template not initialized",
            );
            return;
        }

        const row = templates.author.cloneNode(true);
        const input = row.querySelector('input[name="author[]"]');
        if (input && authorName) {
            input.value = authorName;
        }

        group.appendChild(row);

        if (typeof bookForm.initializeAutocomplete === "function") {
            bookForm.initializeAutocomplete(
                $(row),
                ".author-autocomplete",
                window.BOOK_FORM_ROUTES?.authorsAutocomplete,
            );
        }

        updateAddRowButtons(
            $container,
            "#authors-group",
            ".author-row",
            ".add-author-row",
        );
    }

    function addNarratorRow($container, narratorName = "") {
        const group = $container.find("#narrators-group")[0];
        if (!group) {
            console.error(
                "[form-helpers] Cannot add narrator row: #narrators-group not found",
            );
            return;
        }
        if (!templates.narrator) {
            console.error(
                "[form-helpers] Cannot add narrator row: template not initialized",
            );
            return;
        }

        const row = templates.narrator.cloneNode(true);
        const input = row.querySelector('input[name="narrator[]"]');
        if (input && narratorName) {
            input.value = narratorName;
        }

        group.appendChild(row);

        if (typeof bookForm.initializeAutocomplete === "function") {
            bookForm.initializeAutocomplete(
                $(row),
                ".narrator-autocomplete",
                window.BOOK_FORM_ROUTES?.narratorsAutocomplete,
            );
        }

        updateAddRowButtons(
            $container,
            "#narrators-group",
            ".narrator-row",
            ".add-narrator-row",
        );
    }

    function addSeriesRow($container, seriesData = {}) {
        const group = $container.find("#series-group")[0];
        if (!group) {
            console.error(
                "[form-helpers] Cannot add series row: #series-group not found",
            );
            return;
        }
        if (!templates.series) {
            console.error(
                "[form-helpers] Cannot add series row: template not initialized",
            );
            return;
        }

        const row = templates.series.cloneNode(true);
        const idx = group.querySelectorAll(".series-row").length;

        row.querySelectorAll('[name*="series["]').forEach((input) => {
            input.name = input.name.replace(/series\[\d+\]/, `series[${idx}]`);
        });

        const numberInput = row.querySelector('input[name*="[number]"]');
        const nameInput = row.querySelector('input[name*="[seriesName]"]');
        const collectionInput = row.querySelector(
            'input[name*="[isCollection]"]',
        );

        if (numberInput && seriesData.number) {
            numberInput.value = seriesData.number;
        }
        if (nameInput && seriesData.seriesName) {
            nameInput.value = seriesData.seriesName;
        }
        if (collectionInput && seriesData.isCollection) {
            collectionInput.checked = true;
        }

        group.appendChild(row);

        if (typeof bookForm.initializeAutocomplete === "function") {
            bookForm.initializeAutocomplete(
                $(row),
                ".series-autocomplete",
                window.BOOK_FORM_ROUTES?.seriesAutocomplete,
            );
        }

        updateAddRowButtons(
            $container,
            "#series-group",
            ".series-row",
            ".add-series-row",
        );
    }

    function addGenreRow($container, genreValue = "") {
        const group = $container.find("#genres-group")[0];
        if (!group) {
            console.error(
                "[form-helpers] Cannot add genre row: #genres-group not found",
            );
            return;
        }
        if (!templates.genre) {
            console.error(
                "[form-helpers] Cannot add genre row: template not initialized",
            );
            return;
        }

        const row = templates.genre.cloneNode(true);
        const select = row.querySelector('select[name="genre[]"]');
        if (select && genreValue) {
            select.value = genreValue;
        }

        group.appendChild(row);

        updateAddRowButtons(
            $container,
            "#genres-group",
            ".genre-row",
            ".add-genre-row",
        );
    }

    bookForm.escapeHtml = escapeHtml;
    bookForm.normalizeAuthorName = normalizeAuthorName;
    bookForm.normalizeAuthorNameForDirectory = normalizeAuthorNameForDirectory;
    bookForm.clearAuthorRow = clearAuthorRow;
    bookForm.clearNarratorRow = clearNarratorRow;
    bookForm.clearSeriesRow = clearSeriesRow;
    bookForm.clearGenreRow = clearGenreRow;
    bookForm.initializeTemplates = initializeTemplates;
    bookForm.updateAddRowButtons = updateAddRowButtons;
    bookForm.addAuthorRow = addAuthorRow;
    bookForm.addNarratorRow = addNarratorRow;
    bookForm.addSeriesRow = addSeriesRow;
    bookForm.addGenreRow = addGenreRow;
})(window, window.jQuery);
