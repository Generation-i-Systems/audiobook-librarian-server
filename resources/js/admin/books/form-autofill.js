(function (window, $) {
    "use strict";

    if (!$) {
        console.error("form-autofill.js requires jQuery");
        return;
    }

    const bookForm = (window.BookForm = window.BookForm || {});

    function googleBooksProxyUrl(url) {
        if (url && url.match(/^https?:\/\/books\.google\.com\//)) {
            try {
                // Ensure HTTPS for Google Books URLs
                const httpsUrl = url.replace(/^http:\/\//, "https://");
                const encodedUrl = btoa(httpsUrl);
                return `/google-books-cover/${encodedUrl}`;
            } catch (error) {
                console.error("Failed to encode Google Books URL", error);
                return url;
            }
        }
        return url;
    }

    function normalizeTitleForAutofill(title) {
        if (!title) {
            return "";
        }
        return title
            .replace(/^[\d\s]+/, "")
            .replace(/^(part|book|volume)\s+\d+/i, "")
            .trim();
    }

    function scoreAutofillResult(result) {
        let score = 0;
        if (!result) {
            return score;
        }

        if (
            result.title &&
            result.title.toLowerCase() ===
                normalizeTitleForAutofill($("#title").val()).toLowerCase()
        ) {
            score += 10;
        }
        if (Array.isArray(result.author) && result.author.length) {
            score += 5;
        } else if (result.author) {
            score += 3;
        }
        if (result.series) {
            score += 2;
        }
        if (result.publishedYear || result.published_date) {
            score += 1;
        }
        return score;
    }

    function sortAutofillResults(results) {
        return results.slice().sort(function (a, b) {
            return scoreAutofillResult(b) - scoreAutofillResult(a);
        });
    }

    function performAutofillSearch(sources, autoApplyFirst) {
        const $modal = $("#autofillModal");
        const $resultsTable = $modal.find("#autofill-results-table tbody");
        const $applyBtn = $modal.find("#autofill-apply-btn");
        $applyBtn.prop("disabled", true);

        $resultsTable.html(
            '<tr><td colspan="7" class="text-center text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Searching...</td></tr>',
        );

        const normalizedTitle = normalizeTitleForAutofill(
            $("#autofill-title").val(),
        );
        document.getElementById("autofill-title").value = normalizedTitle;
        document.getElementById("autofill-author").value =
            $("#autofill-author").val();
        document.getElementById("autofill-series").value =
            $("#autofill-series").val();

        $("#autofill-results-wrapper").show();

        const endpoint =
            window.BOOK_FORM_ROUTES.search || "/admin/books/search";
        const sourcesToSearch = Array.isArray(sources) ? sources : [sources];
        const allResults = [];
        let completedRequests = 0;

        sourcesToSearch.forEach(function (source) {
            let apiSource = "";
            if (source === "google") {
                apiSource = "googlebooks";
            } else if (source === "audible") {
                apiSource = "audible";
            } else if (source === "audiobookbay") {
                apiSource = "audiobookbay";
            } else if (source === "hardcover") {
                apiSource = "hardcover";
            } else {
                completedRequests += 1;
                if (completedRequests === sourcesToSearch.length) {
                    displayAutofillResults(allResults, autoApplyFirst);
                }
                return;
            }

            const params = {
                source: apiSource,
                title: normalizedTitle,
                author: $("#autofill-author").val(),
                series: $("#autofill-series").val(),
                api_id: $("#autofill-api-id").val(),
            };

            $.get(endpoint, params)
                .done(function (response) {
                    const results = Array.isArray(response) ? response : [];
                    results.forEach(function (item) {
                        item.source =
                            source.charAt(0).toUpperCase() + source.slice(1);
                    });
                    Array.prototype.push.apply(allResults, results);
                })
                .fail(function (xhr) {
                    console.error(
                        "[DEBUG] Search failed for " + source + ":",
                        xhr,
                    );
                })
                .always(function () {
                    completedRequests += 1;
                    if (completedRequests === sourcesToSearch.length) {
                        const sortedResults = sortAutofillResults(allResults);
                        displayAutofillResults(sortedResults, autoApplyFirst);
                    }
                });
        });
    }

    function displayAutofillResults(results, autoSelectFirst) {
        const $modal = $("#autofillModal");
        const $resultsTable = $modal.find("#autofill-results-table tbody");
        const $applyBtn = $modal.find("#autofill-apply-btn");

        if (results.length > 0) {
            const rows = results
                .map(function (item, idx) {
                    const authors = Array.isArray(item.author)
                        ? item.author.join(", ")
                        : item.author || "";
                    const coverUrl =
                        item.coverImageUrl || item.cover_image_url || "";
                    const publishedYear =
                        item.publishedYear ||
                        (item.published_date
                            ? item.published_date.substring(0, 4)
                            : "");

                    return (
                        "<tr>" +
                        '<td><input type="radio" name="autofill_result_select" value="' +
                        idx +
                        '" ' +
                        (idx === 0 ? "checked" : "") +
                        "></td>" +
                        "<td>" +
                        (coverUrl
                            ? '<img src="' +
                              coverUrl +
                              '" alt="Cover" style="height:48px;max-width:40px;">'
                            : "") +
                        "</td>" +
                        "<td>" +
                        (item.title || "") +
                        "</td>" +
                        "<td>" +
                        authors +
                        "</td>" +
                        "<td>" +
                        (item.series || "") +
                        "</td>" +
                        "<td>" +
                        publishedYear +
                        "</td>" +
                        "<td>" +
                        (item.source || "") +
                        "</td>" +
                        "</tr>"
                    );
                })
                .join("");

            $resultsTable.html(rows);
            window.autofillMatches = results;
            if (autoSelectFirst) {
                $applyBtn.prop("disabled", false);
                $applyBtn.data("selectedIdx", 0);
            } else {
                $applyBtn.prop("disabled", true);
                $applyBtn.data("selectedIdx", null);
            }

            $(document)
                .off("change.autofillResult")
                .on(
                    "change.autofillResult",
                    'input[name="autofill_result_select"]',
                    function () {
                        const idx = $(this).val();
                        const item = window.autofillMatches[idx];
                        if (!item) {
                            return;
                        }
                        $("#autofill-apply-btn").prop("disabled", false);
                        $("#autofill-apply-btn").data("selectedIdx", idx);
                    },
                );
            if (!autoSelectFirst) {
                $resultsTable
                    .find('input[name="autofill_result_select"]')
                    .prop("checked", false);
            }
        } else {
            $resultsTable.html(
                '<tr><td colspan="7" class="text-center text-muted">No results found</td></tr>',
            );
        }
    }

    function applyAutofillResult(idx) {
        const item = window.autofillMatches[idx];
        if (!item) {
            return;
        }

        const $form =
            window.BookForm?.activeForm && window.BookForm.activeForm.length
                ? window.BookForm.activeForm
                : $("#book-form");
        if (!$form.length) {
            console.warn("applyAutofillResult: unable to locate target form");
            return;
        }

        try {
            $form.find("#title").val(item.title || "");

            if (item.author) {
                const authorsGroup = $form.find("#authors-group");
                const addAuthor =
                    window.BookForm?.addAuthorRow || window.addAuthorRow;
                const authors = Array.isArray(item.author)
                    ? item.author
                    : [item.author];

                if (authorsGroup.length) {
                    authorsGroup.empty();
                    if (typeof addAuthor === "function") {
                        authors.forEach((author) => addAuthor($form, author));
                    } else {
                        authors.forEach((author) => {
                            const escapedAuthor =
                                typeof author === "string"
                                    ? author
                                          .replace(/"/g, "&quot;")
                                          .replace(/'/g, "&#039;")
                                    : "";
                            authorsGroup.append(
                                '<div class="author-row mb-2"><input type="text" name="author[]" class="form-control" value="' +
                                    escapedAuthor +
                                    '"></div>',
                            );
                        });
                    }
                }
            }

            const series = item.series || "";
            const seriesNumber = item.seriesNumber || item.series_number || "";
            if (series) {
                const seriesGroup = $form.find("#series-group");
                const addSeries =
                    window.BookForm?.addSeriesRow || window.addSeriesRow;
                if (seriesGroup.length) {
                    seriesGroup.empty();
                    if (typeof addSeries === "function") {
                        addSeries($form, series, seriesNumber);
                    } else {
                        const idx = 0;
                        seriesGroup.append(
                            '<div class="series-row mb-2">' +
                                '<input type="number" name="series[' +
                                idx +
                                '][number]" class="form-control" value="' +
                                (seriesNumber || "") +
                                '">' +
                                '<input type="text" name="series[' +
                                idx +
                                '][seriesName]" class="form-control ms-2" value="' +
                                series +
                                '">' +
                                "</div>",
                        );
                    }
                }
            }

            const releaseDateInput = $form.find("#release_date");
            const publishedYear =
                item.publishedYear ||
                (item.published_date
                    ? item.published_date.substring(0, 4)
                    : "");
            if (releaseDateInput.length && publishedYear) {
                releaseDateInput.val(publishedYear + "-01-01");
            }

            const coverUrl = item.coverImageUrl || item.cover_image_url || "";
            if (coverUrl) {
                let coverUrlInput = $form.find("#coverImageUrl");
                if (!coverUrlInput.length) {
                    coverUrlInput = $("<input>")
                        .attr({
                            type: "hidden",
                            id: "coverImageUrl",
                            name: "coverImageUrl",
                            value: coverUrl,
                        })
                        .appendTo("#book-form");
                } else {
                    coverUrlInput.val(coverUrl);
                }
                const coverPreview = document.querySelector(
                    "#cover-preview-trigger",
                );
                if (coverPreview) {
                    coverPreview.src = googleBooksProxyUrl(coverUrl);
                }
                const cornerRadio = $form.find(
                    'input[name="coverImageCandidate"][data-source="googlebooks"]',
                );
                if (cornerRadio.length) {
                    cornerRadio.prop("checked", true).trigger("change");
                }
            }

            if (item.description) {
                const descriptionField = $form.find("#description");
                if (descriptionField.length && !descriptionField.val()) {
                    descriptionField.val(item.description);
                }
            }

            $(document).trigger("book:autofillApplied", item);
        } catch (error) {
            console.error("Error applying autofill result", error, item);
        } finally {
            const modalEl = document.getElementById("autofillModal");
            if (modalEl && window.bootstrap?.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            } else {
                $("#autofillModal").modal("hide");
            }
        }
    }

    function registerAutofillHandlers($container) {
        const $autofillForm = $("#autofill-search-form");
        if (!$autofillForm.length) {
            return;
        }

        window.BookForm = window.BookForm || {};
        window.BookForm.activeForm = $container;

        $autofillForm.off("submit");

        $("#search-audible-btn")
            .off("click")
            .on("click", function (e) {
                e.preventDefault();
                performAutofillSearch("audible", true);
            });

        $("#search-google-btn")
            .off("click")
            .on("click", function (e) {
                e.preventDefault();
                performAutofillSearch("google", true);
            });

        $("#search-audiobookbay-btn")
            .off("click")
            .on("click", function (e) {
                e.preventDefault();
                performAutofillSearch("audiobookbay", true);
            });

        $("#search-hardcover-btn")
            .off("click")
            .on("click", function (e) {
                e.preventDefault();
                performAutofillSearch("hardcover", true);
            });

        $("#search-all-btn")
            .off("click")
            .on("click", function (e) {
                e.preventDefault();
                performAutofillSearch(
                    ["audible", "google", "audiobookbay", "hardcover"],
                    true,
                );
            });

        $("#autofill-apply-btn")
            .off("click.autofillApply")
            .on("click.autofillApply", function () {
                let idx = $(this).data("selectedIdx");

                if (idx === undefined || idx === null || Number.isNaN(idx)) {
                    const selectedRadio = $(
                        'input[name="autofill_result_select"]:checked',
                    );
                    if (selectedRadio.length) {
                        idx = parseInt(selectedRadio.val(), 10);
                    }
                }

                if (idx === undefined || idx === null || Number.isNaN(idx)) {
                    console.warn(
                        "autofill apply requested without a selection",
                    );
                    return;
                }

                console.debug(
                    "[autofill] applying result index",
                    idx,
                    window.autofillMatches?.[idx],
                );
                applyAutofillResult(idx);
            });

        const autofillModal = document.getElementById("autofillModal");
        if (autofillModal) {
            autofillModal.addEventListener("show.bs.modal", function () {
                const titleInput = document.getElementById("title");
                const normalized = normalizeTitleForAutofill(
                    titleInput ? titleInput.value : "",
                );
                document.getElementById("autofill-title").value = normalized;

                const authorField = document.querySelector(
                    "input[name='author[]']",
                );
                document.getElementById("autofill-author").value = authorField
                    ? authorField.value
                    : "";

                const seriesField = document.querySelector(
                    "#series-group input[name*='[seriesName]']",
                );
                document.getElementById("autofill-series").value = seriesField
                    ? seriesField.value
                    : "";

                performAutofillSearch(
                    ["audible", "google", "audiobookbay", "hardcover"],
                    true,
                );
            });
        }
    }

    bookForm.googleBooksProxyUrl = googleBooksProxyUrl;
    bookForm.normalizeTitleForAutofill = normalizeTitleForAutofill;
    bookForm.scoreAutofillResult = scoreAutofillResult;
    bookForm.sortAutofillResults = sortAutofillResults;
    bookForm.performAutofillSearch = performAutofillSearch;
    bookForm.displayAutofillResults = displayAutofillResults;
    bookForm.applyAutofillResult = applyAutofillResult;
    bookForm.registerAutofillHandlers = registerAutofillHandlers;

    window.googleBooksProxyUrl = googleBooksProxyUrl;
    window.normalizeTitleForAutofill = normalizeTitleForAutofill;
    window.scoreAutofillResult = scoreAutofillResult;
    window.sortAutofillResults = sortAutofillResults;
    window.performAutofillSearch = performAutofillSearch;
    window.displayAutofillResults = displayAutofillResults;
    window.applyAutofillResult = applyAutofillResult;
    window.registerAutofillHandlers = registerAutofillHandlers;
})(window, window.jQuery);
