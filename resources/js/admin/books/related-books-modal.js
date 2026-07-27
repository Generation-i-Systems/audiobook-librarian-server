/**
 * Related Books Modal
 * Shows other books by the same author or in the same series
 */

(function () {
    "use strict";

    let relatedBooksUrl = "";

    function initRelatedBooksModal() {
        relatedBooksUrl =
            window.BOOK_FORM_ROUTES?.relatedBooks ||
            "/admin/books/related-ajax";

        $(document).on("click", ".view-related-books-btn", function (e) {
            e.preventDefault();
            const type = $(this).data("type");
            const id = $(this).data("id");
            const name = $(this).data("name");
            openRelatedBooksModal(type, id, name);
        });
    }

    function openRelatedBooksModal(type, id, name) {
        if (!type || !id) {
            return;
        }

        const label = type === "series" ? "Other Books in" : "Other Books by";
        $("#relatedBooksModalLabel").text(
            name ? `${label} "${name}"` : "Other Books",
        );
        $("#related-books-list").html(
            '<div class="text-center p-4">' +
                '<div class="spinner-border" role="status">' +
                '<span class="visually-hidden">Loading...</span></div></div>',
        );

        const modal = new bootstrap.Modal(
            document.getElementById("relatedBooksModal"),
        );
        modal.show();

        $.ajax({
            url: relatedBooksUrl,
            method: "GET",
            data: {
                type: type,
                id: id,
                exclude: window.BOOK_ID || "",
            },
            dataType: "json",
            success: function (response) {
                renderRelatedBooks(response.books || [], type);
            },
            error: function () {
                $("#related-books-list").html(
                    '<div class="alert alert-danger mb-0">Failed to load books.</div>',
                );
            },
        });
    }

    function renderRelatedBooks(books, type) {
        if (!books.length) {
            $("#related-books-list").html(
                '<p class="text-muted mb-0">No other books found.</p>',
            );
            return;
        }

        const $list = $('<ul class="list-group">');

        books.forEach(function (book) {
            const $item = $('<li class="list-group-item d-flex align-items-center">');

            if (book.coverUrl) {
                $('<img>')
                    .attr("src", book.coverUrl)
                    .attr("alt", "")
                    .css({
                        width: "32px",
                        height: "32px",
                        objectFit: "cover",
                        marginRight: "10px",
                        flexShrink: "0",
                    })
                    .appendTo($item);
            }

            const $link = $("<a>")
                .attr("href", book.editUrl)
                .attr("target", "_blank")
                .attr("rel", "noopener")
                .text(book.title || "Untitled");

            if (type === "series" && book.seriesNumber) {
                $item.append(`<span class="text-muted me-2">#${book.seriesNumber}</span>`);
            }

            $item.append($link);
            $list.append($item);
        });

        $("#related-books-list").empty().append($list);
    }

    $(document).ready(function () {
        if ($(".view-related-books-btn").length || $("#book-form").length) {
            initRelatedBooksModal();
        }
    });
})();
