(function (window, $) {
    "use strict";

    if (!$) {
        console.error("form-helpers.js requires jQuery");
        return;
    }

    const bookForm = (window.BookForm = window.BookForm || {});

    function escapeHtml(text) {
        if (typeof text !== "string") {
            return text;
        }
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
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
            return;
        }

        const $div = $("<div>").addClass(
            "d-flex align-items-start mb-2 author-row",
        );

        $(
            '<input type="text" name="author[]" class="form-control author-autocomplete form-control-height-32 form-control-flex-1">',
        )
            .val(escapeHtml(authorName))
            .attr("placeholder", "Author Name")
            .prop("required", true)
            .appendTo($div);

        const $buttonGroup = $('<div class="d-flex flex-column ms-2 gap-2px">');
        $buttonGroup.append(
            $(
                '<button type="button" class="btn btn-outline-danger btn-sm remove-author btn-size-32">',
            )
                .attr("aria-label", "Remove author")
                .html("&times;"),
        );
        $buttonGroup.append(
            $(
                '<button type="button" class="btn btn-primary btn-sm add-author-row btn-size-32">',
            )
                .attr("aria-label", "Add author")
                .text("+"),
        );
        $buttonGroup.appendTo($div);

        group.appendChild($div[0]);

        if (typeof bookForm.initializeAutocomplete === "function") {
            bookForm.initializeAutocomplete(
                $div,
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
            return;
        }

        const $div = $("<div>").addClass(
            "d-flex align-items-start mb-2 narrator-row",
        );

        $(
            '<input type="text" name="narrator[]" class="form-control narrator-autocomplete form-control-height-32 form-control-flex-1">',
        )
            .val(escapeHtml(narratorName))
            .attr("placeholder", "Narrator Name")
            .appendTo($div);

        const $buttonGroup = $('<div class="d-flex flex-column ms-2 gap-2px">');
        $buttonGroup.append(
            $(
                '<button type="button" class="btn btn-outline-danger btn-sm remove-row btn-size-32">',
            )
                .attr("aria-label", "Remove narrator")
                .html("&times;"),
        );
        $buttonGroup.append(
            $(
                '<button type="button" class="btn btn-primary btn-sm add-narrator-row btn-size-32">',
            )
                .attr("aria-label", "Add narrator")
                .text("+"),
        );
        $buttonGroup.appendTo($div);

        group.appendChild($div[0]);

        if (
            typeof bookForm.initializeAutocomplete === "function" &&
            window.BOOK_FORM_ROUTES?.narratorsAutocomplete
        ) {
            bookForm.initializeAutocomplete(
                $div,
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

    function addSeriesRow($container, seriesName = "", seriesNumber = "") {
        const group = $container.find("#series-group")[0];
        if (!group) {
            return;
        }

        const idx = group.querySelectorAll(".series-row").length;
        const $div = $("<div>").addClass(
            "d-flex align-items-start mb-2 series-row",
        );

        $('<input type="number">')
            .attr("name", `series[${idx}][number]`)
            .addClass(
                "form-control width-80 form-control-height-32 flex-shrink-0",
            )
            .attr("placeholder", "#")
            .val(escapeHtml(seriesNumber))
            .attr("step", "any")
            .appendTo($div);

        $('<input type="text">')
            .attr("name", `series[${idx}][seriesName]`)
            .addClass(
                "form-control series-autocomplete ms-2 form-control-height-32 form-control-flex-1",
            )
            .attr("placeholder", "Series Name")
            .val(escapeHtml(seriesName))
            .appendTo($div);

        $(
            '<div style="width:32px; height:32px; margin-left:0.5rem; flex-shrink:0;"></div>',
        ).appendTo($div);

        const $buttonGroup = $('<div class="d-flex flex-column ms-2 gap-2px">');
        $buttonGroup.append(
            $(
                '<button type="button" class="btn btn-outline-danger btn-sm remove-series btn-size-32">',
            )
                .attr("aria-label", "Remove series")
                .html("&times;"),
        );
        $buttonGroup.append(
            $(
                '<button type="button" class="btn btn-primary btn-sm add-series-row btn-size-32">',
            )
                .attr("aria-label", "Add series")
                .text("+"),
        );
        $buttonGroup.appendTo($div);

        group.appendChild($div[0]);

        if (typeof bookForm.initializeAutocomplete === "function") {
            bookForm.initializeAutocomplete(
                $div,
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

    function addGenreRow($container, selectedGenre = "") {
        const group = $container.find("#genres-group")[0];
        if (!group) {
            return;
        }

        const $div = $("<div>").addClass(
            "d-flex align-items-start mb-2 genre-row",
        );
        const $select = $(
            '<select name="genre[]" class="form-select form-control-height-32 form-control-flex-1">',
        )
            .prop("required", true)
            .appendTo($div);

        $('<option value="">Select a genre</option>').appendTo($select);
        (window.GENRE_OPTIONS || []).forEach((genre) => {
            $("<option>")
                .val(escapeHtml(genre))
                .text(escapeHtml(genre))
                .prop("selected", selectedGenre === genre)
                .appendTo($select);
        });

        const $buttonGroup = $('<div class="d-flex flex-column ms-2 gap-2px">');
        $buttonGroup.append(
            $(
                '<button type="button" class="btn btn-outline-danger btn-sm remove-genre btn-size-32">',
            )
                .attr("aria-label", "Remove genre")
                .html("&times;"),
        );
        $buttonGroup.append(
            $(
                '<button type="button" class="btn btn-primary btn-sm add-genre-row btn-size-32">',
            )
                .attr("aria-label", "Add genre")
                .text("+"),
        );
        $buttonGroup.appendTo($div);

        group.appendChild($div[0]);
        updateAddRowButtons(
            $container,
            "#genres-group",
            ".genre-row",
            ".add-genre-row",
        );
    }

    bookForm.updateAddRowButtons = updateAddRowButtons;
    bookForm.addAuthorRow = addAuthorRow;
    bookForm.addNarratorRow = addNarratorRow;
    bookForm.addSeriesRow = addSeriesRow;
    bookForm.addGenreRow = addGenreRow;
    bookForm.escapeHtml = escapeHtml;

    window.updateAddRowButtons = updateAddRowButtons;
    window.addAuthorRow = addAuthorRow;
    window.addNarratorRow = addNarratorRow;
    window.addSeriesRow = addSeriesRow;
    window.addGenreRow = addGenreRow;
    window.escapeHtml = escapeHtml;
})(window, window.jQuery);
