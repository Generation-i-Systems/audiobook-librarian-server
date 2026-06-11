(function (window, $) {
    "use strict";

    if (!$) {
        console.error("autofill-simple.js requires jQuery");
        return;
    }

    console.log("[autofill-simple] Module loaded");

    const bookForm = (window.BookForm = window.BookForm || {});

    function setupAutofillModal() {
        console.log("[autofill-simple] setupAutofillModal called (no-op)");
    }

    function normalizeTitleForAutofill(title) {
        if (!title) return "";
        return title
            .replace(/^[\d\s]+/, "")
            .replace(/^(part|book|volume)\s+\d+/i, "")
            .trim();
    }

    function decodeHtmlEntities(text) {
        if (!text || typeof text !== "string") {
            return text;
        }
        const textarea = document.createElement("textarea");
        textarea.innerHTML = text;
        return textarea.value;
    }

    function getFirstAuthor(authorStr) {
        if (!authorStr || typeof authorStr !== "string") {
            return "";
        }
        const trimmed = authorStr.trim();
        if (!trimmed) {
            return "";
        }
        const parts = trimmed.split(/,\s*|&\s*|\s+and\s+/i);
        return parts[0] ? parts[0].trim() : "";
    }

    function scoreAutofillResult(result) {
        let score = 0;
        if (!result) {
            return score;
        }

        const currentTitle = $("#title").val();
        const normalizedCurrentTitle = normalizeTitleForAutofill(currentTitle);
        const currentAuthor = $('input[name="author[]"]').first().val() || "";
        const currentNarrator =
            $('input[name="narrator[]"]').first().val() || "";
        const currentSeries =
            $('#series-group input[name*="[seriesName]"]').first().val() || "";
        const currentDescription = $("#description").val() || "";
        const currentYear = $("#release_date").val()
            ? $("#release_date").val().substring(0, 4)
            : "";

        if (result.coverImageUrl || result.cover_image_url) {
            score += 35;
        }

        if (currentNarrator) {
            const resultNarratorList = getNarratorList(result);
            if (
                resultNarratorList &&
                resultNarratorList.toLowerCase() ===
                    currentNarrator.toLowerCase()
            ) {
                score += 5;
            }
        }

        if (result.description && currentDescription) {
            const resultDesc = result.description.toLowerCase();
            const currentDescLower = currentDescription.toLowerCase();
            if (
                resultDesc.includes(currentDescLower) ||
                currentDescLower.includes(resultDesc)
            ) {
                score += 10;
            }
        }

        if (
            result.series &&
            currentSeries &&
            result.series.toLowerCase() === currentSeries.toLowerCase()
        ) {
            score += 5;
        }

        if (currentYear && (result.publishedYear || result.published_date)) {
            const resultYear = String(
                result.publishedYear ||
                    (result.published_date
                        ? result.published_date.substring(0, 4)
                        : ""),
            );
            if (resultYear === currentYear) {
                score += 4;
            }
        }

        if (
            result.title &&
            normalizedCurrentTitle &&
            result.title.toLowerCase() === normalizedCurrentTitle.toLowerCase()
        ) {
            score += 12;
        }

        const resultAuthor = Array.isArray(result.author)
            ? result.author.join(", ")
            : result.author || "";
        if (
            resultAuthor &&
            currentAuthor &&
            resultAuthor.toLowerCase() === currentAuthor.toLowerCase()
        ) {
            score += 10;
        }

        return score;
    }

    function getNarratorList(result) {
        if (Array.isArray(result.narrator)) {
            return result.narrator
                .map(function (n) {
                    const narrator = n.narrator || n;
                    const name =
                        typeof narrator === "object" && narrator.name
                            ? narrator.name
                            : String(narrator);
                    return name;
                })
                .join(", ");
        }
        if (Array.isArray(result.narrators)) {
            return result.narrators
                .map(function (n) {
                    const narrator = n.narrator || n;
                    const name =
                        typeof narrator === "object" && narrator.name
                            ? narrator.name
                            : String(narrator);
                    return name;
                })
                .join(", ");
        }
        if (Array.isArray(result.narratorsList)) {
            return result.narratorsList.join(", ");
        }
        return "";
    }

    function getGenreList(result) {
        // Audible: result.category = ["Science Fiction", "Fantasy"]
        if (Array.isArray(result.category) && result.category.length) {
            return result.category.join(", ");
        }
        // Google Books: result.categories = ["Fiction / Fantasy"]
        if (Array.isArray(result.categories) && result.categories.length) {
            return result.categories.join(", ");
        }
        // Hardcover details: result.genre = ["Fantasy"] (plain strings)
        if (Array.isArray(result.genre) && result.genre.length) {
            return result.genre.join(", ");
        }
        // Hardcover search: result.genres = [{genre:{name:"Fantasy"}}]
        if (Array.isArray(result.genres) && result.genres.length) {
            return result.genres
                .map(function (g) {
                    return (
                        (typeof g === "object" && g.genre && g.genre.name) ||
                        (typeof g === "string" && g) ||
                        ""
                    );
                })
                .filter(Boolean)
                .join(", ");
        }
        return "";
    }

    function sortAutofillResults(results) {
        return results.slice().sort(function (a, b) {
            return scoreAutofillResult(b) - scoreAutofillResult(a);
        });
    }

    function performAutofillSearch(source) {
        console.log("[autofill-simple] Performing search with source:", source);

        const $modal = $("#autofillModal");
        const $table = $modal.find("#autofill-results-table");
        let $resultsTable = $table.find("tbody");
        if ($resultsTable.length === 0) {
            $table.append("<tbody></tbody>");
            $resultsTable = $table.find("tbody");
        }
        const $applyBtn = $modal.find("#autofill-apply-btn");
        const $resultsWrapper = $modal.find("#autofill-results-wrapper");

        $resultsTable.html(
            '<tr><td colspan="9" class="text-center text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Searching...</td></tr>',
        );
        $applyBtn.prop("disabled", true);
        if ($resultsWrapper.length > 0) {
            $resultsWrapper.show();
        }

        const title = normalizeTitleForAutofill($("#autofill-title").val());
        const author = $("#autofill-author").val();
        const series = $("#autofill-series").val();

        console.log("[autofill-simple] Search params:", {
            source,
            title,
            author,
            series,
        });

        const endpoint =
            window.BOOK_FORM_ROUTES.search || "/admin/books/search";

        $.get(endpoint, {
            source: source,
            title: title,
            author: author,
            series: series,
        })
            .done(function (response) {
                console.log("[autofill-simple] Search results:", response);
                const results = Array.isArray(response) ? response : [];

                if (results.length === 0) {
                    $resultsTable.html(
                        '<tr><td colspan="7" class="text-center text-muted">No results found</td></tr>',
                    );
                    $applyBtn.prop("disabled", true);
                    window.autofillMatches = [];
                    const $noResultsWrapper = $("#autofillModal").find(
                        "#autofill-results-wrapper",
                    );
                    if ($noResultsWrapper.length > 0) {
                        $noResultsWrapper.show();
                    }
                    return;
                }

                const rows = results
                    .map(function (item, idx) {
                        const authors = decodeHtmlEntities(
                            Array.isArray(item.author)
                                ? item.author.join(", ")
                                : item.author || "",
                        );
                        const narrators = decodeHtmlEntities(
                            getNarratorList(item),
                        );
                        const coverUrl =
                            item.coverImageUrl || item.cover_image_url || "";
                        const publishedYear =
                            item.publishedYear ||
                            (item.published_date
                                ? item.published_date.substring(0, 4)
                                : "");
                        const checkedAttr = idx === 0 ? " checked" : "";

                        const genres = decodeHtmlEntities(getGenreList(item));
                        return `<tr>
                    <td><input type="radio" name="autofill_result_select" value="${idx}"${checkedAttr}></td>
                    <td>${coverUrl ? `<img src="${coverUrl}" alt="Cover" style="height:48px;max-width:40px;">` : ""}</td>
                    <td>${decodeHtmlEntities(item.title || "")}</td>
                    <td>${authors}</td>
                    <td>${narrators}</td>
                    <td>${decodeHtmlEntities(item.series || "")}</td>
                    <td>${genres}</td>
                    <td>${publishedYear}</td>
                    <td>${item.source || ""}</td>
                </tr>`;
                    })
                    .join("");

                $resultsTable.html(rows);
                window.autofillMatches = results;

                if (results.length > 0) {
                    $applyBtn.prop("disabled", false);
                    $applyBtn.data("selectedIdx", 0);
                } else {
                    $applyBtn.prop("disabled", true);
                    $applyBtn.data("selectedIdx", null);
                }
            })
            .fail(function (xhr) {
                console.error("[autofill-simple] Search failed:", xhr);
                $resultsTable.html(
                    '<tr><td colspan="7" class="text-center text-danger">Search failed</td></tr>',
                );
                $applyBtn.prop("disabled", true);
                if ($resultsWrapper.length > 0) {
                    $resultsWrapper.show();
                }
            });
    }

    function performAllSearches() {
        console.log("[autofill-simple] Performing all searches");

        const sources = ["audible", "google", "audiobookbay", "hardcover"];
        const allResults = [];
        let completedRequests = 0;

        const endpoint =
            window.BOOK_FORM_ROUTES.search || "/admin/books/search";
        const $modal = $("#autofillModal");
        console.log("[autofill-simple] Found modal:", $modal.length > 0);

        const $table = $modal.find("#autofill-results-table");
        let $resultsTable = $table.find("tbody");
        if ($resultsTable.length === 0) {
            $table.append("<tbody></tbody>");
            $resultsTable = $table.find("tbody");
        }
        console.log(
            "[autofill-simple] Found results table/tbody:",
            $resultsTable.length > 0,
        );

        const $applyBtn = $modal.find("#autofill-apply-btn");
        const $resultsWrapper = $modal.find("#autofill-results-wrapper");

        $resultsTable.html(
            '<tr><td colspan="7" class="text-center text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Searching all sources...</td></tr>',
        );
        $applyBtn.prop("disabled", true);
        if ($resultsWrapper.length > 0) {
            $resultsWrapper.show();
        }

        const title = normalizeTitleForAutofill($("#autofill-title").val());
        const author = $("#autofill-author").val();
        const series = $("#autofill-series").val();

        sources.forEach(function (source) {
            $.get(endpoint, {
                source: source,
                title: title,
                author: author,
                series: series,
            })
                .done(function (response) {
                    console.log(
                        "[autofill-simple] " + source + " results:",
                        response,
                    );
                    const results = Array.isArray(response) ? response : [];
                    console.log(
                        "[autofill-simple] " + source + " results array:",
                        results,
                        "length:",
                        results.length,
                    );
                    results.forEach(function (item) {
                        item.source =
                            source.charAt(0).toUpperCase() + source.slice(1);
                    });
                    console.log(
                        "[autofill-simple] " +
                            source +
                            " appending to allResults, current total:",
                        allResults.length,
                    );
                    Array.prototype.push.apply(allResults, results);
                    console.log(
                        "[autofill-simple] " + source + " after append, total:",
                        allResults.length,
                    );
                })
                .fail(function (xhr) {
                    console.error(
                        "[autofill-simple] " + source + " failed:",
                        xhr,
                    );
                })
                .always(function () {
                    completedRequests++;
                    console.log(
                        "[autofill-simple] Request completed, total:",
                        completedRequests,
                        "of",
                        sources.length,
                    );

                    if (completedRequests === sources.length) {
                        console.log(
                            "[autofill-simple] All searches completed, total results:",
                            allResults.length,
                        );
                        console.log("[autofill-simple] Results:", allResults);

                        const sortedResults = sortAutofillResults(allResults);
                        console.log(
                            "[autofill-simple] Sorted results:",
                            sortedResults,
                        );

                        if (sortedResults.length === 0) {
                            $resultsTable.html(
                                '<tr><td colspan="7" class="text-center text-muted">No results found</td></tr>',
                            );
                            $applyBtn.prop("disabled", true);
                            window.autofillMatches = [];
                            const $noResultsWrapper = $("#autofillModal").find(
                                "#autofill-results-wrapper",
                            );
                            if ($noResultsWrapper.length > 0) {
                                $noResultsWrapper.show();
                            }
                            return;
                        }

                        const rows = sortedResults
                            .map(function (item, idx) {
                                const authors = decodeHtmlEntities(
                                    Array.isArray(item.author)
                                        ? item.author.join(", ")
                                        : item.author || "",
                                );
                                const narrators = decodeHtmlEntities(
                                    getNarratorList(item),
                                );
                                const coverUrl =
                                    item.coverImageUrl ||
                                    item.cover_image_url ||
                                    "";
                                const publishedYear =
                                    item.publishedYear ||
                                    (item.published_date
                                        ? item.published_date.substring(0, 4)
                                        : "");
                                const checkedAttr = idx === 0 ? " checked" : "";

                                const genres = decodeHtmlEntities(
                                    getGenreList(item),
                                );
                                return `<tr>
                            <td><input type="radio" name="autofill_result_select" value="${idx}"${checkedAttr}></td>
                            <td>${coverUrl ? `<img src="${coverUrl}" alt="Cover" style="height:48px;max-width:40px;">` : ""}</td>
                            <td>${decodeHtmlEntities(item.title || "")}</td>
                            <td>${authors}</td>
                            <td>${narrators}</td>
                            <td>${decodeHtmlEntities(item.series || "")}</td>
                            <td>${genres}</td>
                            <td>${publishedYear}</td>
                            <td>${item.source || ""}</td>
                        </tr>`;
                            })
                            .join("");

                        console.log(
                            "[autofill-simple] Rendering rows, count:",
                            rows.length,
                        );
                        $resultsTable.html(rows);
                        console.log(
                            "[autofill-simple] HTML set, table now has",
                            $resultsTable.find("tr").length,
                            "rows",
                        );

                        if ($resultsWrapper.length > 0) {
                            $resultsWrapper.show();
                        }

                        window.autofillMatches = sortedResults;

                        if (sortedResults.length > 0) {
                            $applyBtn.prop("disabled", false);
                            $applyBtn.data("selectedIdx", 0);
                        } else {
                            $applyBtn.prop("disabled", true);
                            $applyBtn.data("selectedIdx", null);
                        }
                    }
                });
        });
    }

    $(document).ready(function () {
        console.log("[autofill-simple] DOM ready, setting up handlers");

        $(document).on(
            "click",
            "#autofill-modal-btn, #magic-autofill-btn",
            function (e) {
                e.preventDefault();
                console.log("[autofill-simple] Open button clicked");

                const modalEl = document.getElementById("autofillModal");
                if (!modalEl) {
                    console.error("[autofill-simple] autofillModal not found!");
                    return;
                }

                $("#autofill-title").val(
                    normalizeTitleForAutofill($("#title").val() || ""),
                );
                const firstAuthorInput =
                    $('input[name="author[]"]').first().val() || "";
                $("#autofill-author").val(getFirstAuthor(firstAuthorInput));
                $("#autofill-series").val(
                    $('#series-group input[name*="[seriesName]"]')
                        .first()
                        .val() || "",
                );

                if (window.bootstrap?.Modal) {
                    const modal =
                        window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                } else {
                    $(modalEl).modal("show");
                }
            },
        );

        $(document).on("click", "#search-audible-btn", function (e) {
            e.preventDefault();
            console.log("[autofill-simple] Audible search clicked");
            performAutofillSearch("audible");
        });

        $(document).on("click", "#search-google-btn", function (e) {
            e.preventDefault();
            console.log("[autofill-simple] Google search clicked");
            performAutofillSearch("google");
        });

        $(document).on("click", "#search-audiobookbay-btn", function (e) {
            e.preventDefault();
            console.log("[autofill-simple] Audiobookbay search clicked");
            performAutofillSearch("audiobookbay");
        });

        $(document).on("click", "#search-hardcover-btn", function (e) {
            e.preventDefault();
            console.log("[autofill-simple] Hardcover search clicked");
            performAutofillSearch("hardcover");
        });

        $(document).on("click", "#search-all-btn", function (e) {
            e.preventDefault();
            console.log("[autofill-simple] Search all clicked");
            performAllSearches();
        });

        $(document).on("show.bs.modal", "#autofillModal", function () {
            console.log("[autofill-simple] Modal shown, auto-searching");
            setTimeout(function () {
                performAllSearches();
            }, 100);
        });

        $(document).on(
            "change",
            'input[name="autofill_result_select"]',
            function () {
                const idx = $(this).val();
                const item = window.autofillMatches?.[idx];
                console.log(
                    "[autofill-simple] Radio changed to index:",
                    idx,
                    item,
                );

                const $applyBtn = $("#autofill-apply-btn");
                if (item && $applyBtn.length) {
                    $applyBtn.prop("disabled", false);
                    $applyBtn.data("selectedIdx", idx);
                }
            },
        );

        $(document).on("click", "#autofill-apply-btn", function (e) {
            if ($(this).prop("disabled")) {
                console.warn("[autofill-simple] Apply button is disabled");
                return;
            }

            e.preventDefault();
            console.log("[autofill-simple] Apply button clicked");

            const selectedRadio = $(
                'input[name="autofill_result_select"]:checked',
            );
            if (!selectedRadio.length) {
                console.warn("[autofill-simple] No selection made");
                return;
            }

            const idx = parseInt(selectedRadio.val(), 10);
            const item = window.autofillMatches?.[idx];

            console.log("[autofill-simple] Applying item:", item);
            if (!item) {
                console.error(
                    "[autofill-simple] Item not found at index:",
                    idx,
                );
                return;
            }

            $("#title").val(decodeHtmlEntities(item.title || ""));

            const authorsGroup = $("#authors-group");
            const authors = Array.isArray(item.author)
                ? item.author
                : [item.author];
            authorsGroup.empty();
            if (typeof window.BookForm?.addAuthorRow === "function") {
                authors.forEach(function (author) {
                    let authorName = decodeHtmlEntities(author || "");
                    if (
                        typeof window.BookForm?.normalizeAuthorName ===
                        "function"
                    ) {
                        authorName =
                            window.BookForm.normalizeAuthorName(authorName);
                    }
                    window.BookForm.addAuthorRow($("#book-form"), authorName);
                });
            } else {
                console.error(
                    "[autofill-simple] BookForm.addAuthorRow not available",
                );
            }

            const narrators = decodeHtmlEntities(getNarratorList(item));
            if (narrators) {
                const narratorsGroup = $("#narrators-group");
                narratorsGroup.empty();
                if (typeof window.BookForm?.addNarratorRow === "function") {
                    const narratorArray = narrators
                        .split(", ")
                        .filter((n) => n.trim());
                    narratorArray.forEach(function (narrator) {
                        window.BookForm.addNarratorRow(
                            $("#book-form"),
                            narrator.trim(),
                        );
                    });
                } else {
                    console.error(
                        "[autofill-simple] BookForm.addNarratorRow not available",
                    );
                }
            }

            const series = decodeHtmlEntities(item.series || "");
            const seriesNumber = item.seriesNumber || item.series_number || "";
            if (series) {
                const seriesGroup = $("#series-group");
                seriesGroup.empty();
                if (typeof window.BookForm?.addSeriesRow === "function") {
                    window.BookForm.addSeriesRow($("#book-form"), {
                        number: seriesNumber || "",
                        seriesName: series,
                        isCollection: false,
                    });
                } else {
                    console.error(
                        "[autofill-simple] BookForm.addSeriesRow not available",
                    );
                }
            }

            const publishedYear =
                item.publishedYear ||
                (item.published_date
                    ? item.published_date.substring(0, 4)
                    : "");
            if (publishedYear) {
                $("#release_date").val(publishedYear + "-01-01");
            }

            if (item.description) {
                const descriptionField = $("#description");
                if (descriptionField.length && !descriptionField.val()) {
                    descriptionField.val(decodeHtmlEntities(item.description));
                }
            }

            const coverUrl = item.coverImageUrl || item.cover_image_url || "";
            if (coverUrl) {
                let coverUrlInput = $("#coverImageUrl");
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

                const coverPreview = $("#current-cover-image");
                if (coverPreview.length) {
                    coverPreview.attr("src", coverUrl);
                }
            }

            const genreStr = getGenreList(item);
            if (genreStr && typeof window.BookForm?.addGenreRow === "function") {
                const genresGroup = $("#genres-group");
                genresGroup.empty();
                genreStr.split(", ").filter((g) => g.trim()).forEach(function (genre) {
                    window.BookForm.addGenreRow($("#book-form"), genre.trim());
                });
            }

            console.log(
                "[autofill-simple] Applied successfully, closing modal",
            );

            setTimeout(function () {
                const modalEl = document.getElementById("autofillModal");
                if (window.bootstrap?.Modal) {
                    const modal = window.bootstrap.Modal.getInstance(modalEl);
                    if (modal) {
                        console.log(
                            "[autofill-simple] Closing with Bootstrap API",
                        );
                        modal.hide();
                    }
                }
                if ($(modalEl).hasClass("show")) {
                    console.log(
                        "[autofill-simple] Closing with jQuery fallback",
                    );
                    $(modalEl).modal("hide");
                }
            }, 100);
        });
    });

    bookForm.setupAutofillModal = setupAutofillModal;
    window.setupAutofillModal = setupAutofillModal;
})(window, window.jQuery);
