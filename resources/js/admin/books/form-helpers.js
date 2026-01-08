(function (window, $) {
    "use strict";

    if (!$) {
        console.error("form-helpers.js requires jQuery");
        return;
    }

    const bookForm = (window.BookForm = window.BookForm || {});

    function escapeHtml(text)
    {
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

    function addAuthorRow($container, authorName = "")
    {
        const group = $container.find("#authors-group")[0];
        if (!group) {
            return;
        }

        const div = document.createElement("div");
        div.className = "d-flex align-items-start mb-2 author-row";
        div.innerHTML = ` < input type = "text" name = "author[]" class = "form-control author-autocomplete" value = "${escapeHtml(authorName)}" style = "height:32px; flex:1;" placeholder = "Author Name" required > < div class = "d-flex flex-column ms-2" style = "gap:2px;" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-author" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria - label = "Remove author" > & times; < / button > < button type = "button" class = "btn btn-primary btn-sm add-author-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria - label = "Add author" > + < / button > < / div > `;

        group.appendChild(div);

        if (typeof bookForm.initializeAutocomplete === "function") {
            bookForm.initializeAutocomplete(
                $(div),
                ".author-autocomplete",
                window.BOOK_FORM_ROUTES ? .authorsAutocomplete,
            );
        }

        updateAddRowButtons($container, "#authors-group", ".author-row", ".add-author-row");
    }

        const div = document.createElement("div");
        div.className = "d-flex align-items-start mb-2 author-row";
        div.innerHTML = ` < input type = "text" name = "author[]" class = "form-control author-autocomplete" value = "${authorName}" style = "height:32px; flex:1;" placeholder = "Author Name" required > < div class = "d-flex flex-column ms-2" style = "gap:2px;" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-author" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" > & times; < / button > < button type = "button" class = "btn btn-primary btn-sm add-author-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" > + < / button > < / div > `;

        group.appendChild(div);

    if (typeof bookForm.initializeAutocomplete === "function") {
        bookForm.initializeAutocomplete(
            $(div),
            ".author-autocomplete",
            window.BOOK_FORM_ROUTES ? .authorsAutocomplete,
        );
    }

        updateAddRowButtons(
            $container,
            "#authors-group",
            ".author-row",
            ".add-author-row",
        );
}

    function addNarratorRow($container, narratorName = "")
    {
        const group = $container.find("#narrators-group")[0];
    if (!group) {
        return;
    }

        const div = document.createElement("div");
        div.className = "d-flex align-items-start mb-2 narrator-row";
        div.innerHTML = ` < input type = "text" name = "narrator[]" class = "form-control narrator-autocomplete" value = "${escapeHtml(narratorName)}" style = "height:32px; flex:1;" placeholder = "Narrator Name" > < div class = "d-flex flex-column ms-2" style = "gap:2px;" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria - label = "Remove narrator" > & times; < / button > < button type = "button" class = "btn btn-primary btn-sm add-narrator-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria - label = "Add narrator" > + < / button > < / div > `;

        group.appendChild(div);

    if (
            typeof bookForm.initializeAutocomplete === "function" &&
            window.BOOK_FORM_ROUTES ? .narratorsAutocomplete
        ) {
        bookForm.initializeAutocomplete(
            $(div),
            ".narrator-autocomplete",
            window.BOOK_FORM_ROUTES.narratorsAutocomplete,
        );
    }

        updateAddRowButtons($container, "#narrators-group", ".narrator-row", ".add-narrator-row");
    }

        const div = document.createElement("div");
        div.className = "d-flex align-items-start mb-2 narrator-row";
        div.innerHTML = ` < input type = "text" name = "narrator[]" class = "form-control narrator-autocomplete" value = "${narratorName}" style = "height:32px; flex:1;" placeholder = "Narrator Name" > < div class = "d-flex flex-column ms-2" style = "gap:2px;" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" > & times; < / button > < button type = "button" class = "btn btn-primary btn-sm add-narrator-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" > + < / button > < / div > `;

        group.appendChild(div);

        if (
            typeof bookForm.initializeAutocomplete === "function" &&
            window.BOOK_FORM_ROUTES ? .narratorsAutocomplete
        ) {
            bookForm.initializeAutocomplete(
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

    function addSeriesRow($container, seriesName = "", seriesNumber = "")
    {
        const group = $container.find("#series-group")[0];
    if (!group) {
        return;
    }

        const idx = group.querySelectorAll(".series-row").length;

        const div = document.createElement("div");
        div.className = "d-flex align-items-start mb-2 series-row";
        div.innerHTML = ` < input type = "number" name = "series[${idx}][number]" class = "form-control" style = "width:80px; height:32px; flex-shrink:0;" placeholder = "#" value = "${escapeHtml(seriesNumber)}" step = "any" > < input type = "text" name = "series[${idx}][seriesName]" class = "form-control series-autocomplete ms-2" style = "height:32px; flex:1;" placeholder = "Series Name" value = "${escapeHtml(seriesName)}" > < div style = "width:32px; height:32px; margin-left:0.5rem; flex-shrink:0;" > < / div > < div class = "d-flex flex-column ms-2" style = "gap:2px;" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-series" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria - label = "Remove series" > & times; < / button > < button type = "button" class = "btn btn-primary btn-sm add-series-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria - label = "Add series" > + < / button > < / div > `;

        group.appendChild(div);

    if (typeof bookForm.initializeAutocomplete === "function") {
        bookForm.initializeAutocomplete(
            $(div),
            ".series-autocomplete",
            window.BOOK_FORM_ROUTES ? .seriesAutocomplete,
        );
    }

        updateAddRowButtons($container, "#series-group", ".series-row", ".add-series-row");
    }

        const idx = group.querySelectorAll(".series-row").length;

        const div = document.createElement("div");
        div.className = "d-flex align-items-start mb-2 series-row";
        div.innerHTML = ` < input type = "number" name = "series[${idx}][number]" class = "form-control" style = "width:80px; height:32px; flex-shrink:0;" placeholder = "#" value = "${seriesNumber}" step = "any" > < input type = "text" name = "series[${idx}][seriesName]" class = "form-control series-autocomplete ms-2" style = "height:32px; flex:1;" placeholder = "Series Name" value = "${seriesName}" > < div style = "width:32px; height:32px; margin-left:0.5rem; flex-shrink:0;" > < / div > < div class = "d-flex flex-column ms-2" style = "gap:2px;" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-series" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" > & times; < / button > < button type = "button" class = "btn btn-primary btn-sm add-series-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" > + < / button > < / div > `;

        group.appendChild(div);

        if (typeof bookForm.initializeAutocomplete === "function") {
            bookForm.initializeAutocomplete(
                $(div),
                ".series-autocomplete",
                window.BOOK_FORM_ROUTES ? .seriesAutocomplete,
            );
        }

        updateAddRowButtons(
            $container,
            "#series-group",
            ".series-row",
            ".add-series-row",
        );
    }

    function addGenreRow($container, selectedGenre = "")
    {
        const group = $container.find("#genres-group")[0];
    if (!group) {
        return;
    }

        const div = document.createElement("div");
        div.className = "d-flex align-items-start mb-2 genre-row";
        let optionsHtml = '<option value="">Select a genre</option>';
        (window.GENRE_OPTIONS || []).forEach((genre) => {
            const selected = selectedGenre === genre ? "selected" : "";
            optionsHtml += ` < option value = "${escapeHtml(genre)}" ${selected} > ${escapeHtml(genre)} < / option > `;
        });

        div.innerHTML = ` < select name = "genre[]" class = "form-select" style = "height:32px; flex:1;" required > ${optionsHtml} < / select > < div class = "d-flex flex-column ms-2" style = "gap:2px;" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-genre" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria - label = "Remove genre" > & times; < / button > < button type = "button" class = "btn btn-primary btn-sm add-genre-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria - label = "Add genre" > + < / button > < / div > `;

        group.appendChild(div);
        updateAddRowButtons($container, "#genres-group", ".genre-row", ".add-genre-row");
    }

        const div = document.createElement("div");
        div.className = "d-flex align-items-start mb-2 genre-row";
        let optionsHtml = '<option value="">Select a genre</option>';
        (window.GENRE_OPTIONS || []).forEach((genre) => {
            const selected = selectedGenre === genre ? "selected" : "";
            optionsHtml += ` < option value = "${genre}" ${selected} > ${genre} < / option > `;
        });

        div.innerHTML = ` < select name = "genre[]" class = "form-select" style = "height:32px; flex:1;" required > ${optionsHtml} < / select > < div class = "d-flex flex-column ms-2" style = "gap:2px;" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-genre" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" > & times; < / button > < button type = "button" class = "btn btn-primary btn-sm add-genre-row" style = "width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" > + < / button > < / div > `;

        group.appendChild(div);
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
