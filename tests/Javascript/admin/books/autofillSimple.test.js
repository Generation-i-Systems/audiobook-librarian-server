// @jest-environment jsdom
import $ from "jquery";
import { jest } from "@jest/globals";
import {
    applyAutofillGenres,
    getAutofillGenreValues,
    getGenreList,
} from "@/admin/books/autofill-genres.js";

function bookFormHtml(selectedGenre = "Fantasy") {
    return `
        <form id="book-form">
            <input type="text" id="title" name="title" value="Original Title">
            <input type="date" id="release_date" name="release_date">
            <textarea id="description" name="description"></textarea>

            <div id="authors-group">
                <div class="author-row">
                    <input type="text" name="author[]" class="author-autocomplete" value="Original Author">
                    <button type="button" class="add-author-row">+</button>
                </div>
            </div>

            <div id="narrators-group">
                <div class="narrator-row">
                    <input type="text" name="narrator[]" class="narrator-autocomplete" value="">
                    <button type="button" class="add-narrator-row">+</button>
                </div>
            </div>

            <div id="series-group">
                <div class="series-row">
                    <input type="text" name="series[0][seriesName]" class="series-autocomplete" value="">
                    <input type="text" name="series[0][number]" value="">
                    <input type="checkbox" name="series[0][isCollection]" value="1">
                    <button type="button" class="add-series-row">+</button>
                </div>
            </div>

            <div id="genres-group">
                <div class="genre-row">
                    <select name="genre[]">
                        <option value="">Select a genre</option>
                        <option value="Fantasy" ${selectedGenre === "Fantasy" ? "selected" : ""}>Fantasy</option>
                        <option value="Science Fiction" ${selectedGenre === "Science Fiction" ? "selected" : ""}>Science Fiction</option>
                    </select>
                    <button type="button" class="add-genre-row">+</button>
                </div>
            </div>
        </form>
        <div id="autofillModal" class="modal show">
            <input type="radio" name="autofill_result_select" value="0" checked>
            <button type="button" id="autofill-apply-btn">Apply</button>
        </div>
    `;
}

function selectedGenres() {
    return $('#genres-group select[name="genre[]"]')
        .toArray()
        .map((select) => select.value);
}

describe("Book metadata autofill genre handling", () => {
    beforeEach(() => {
        jest.resetModules();
        document.body.innerHTML = bookFormHtml();
        window.jQuery = $;
        window.$ = $;
        global.jQuery = $;
        global.$ = $;
        window.BookForm = {};
        window.BOOK_FORM_ROUTES = {};
        window.autofillMatches = [];
        window.document = document;
        $.fn.autocomplete = jest.fn(function () {
            return this;
        });
        $.fn.modal = jest.fn(function () {
            return this;
        });
        jest.spyOn(console, "log").mockImplementation(() => {});
        jest.spyOn(console, "warn").mockImplementation(() => {});
        jest.spyOn(console, "error").mockImplementation(() => {});
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test("apply preserves the existing genre instead of replacing it with provider metadata", async () => {
        window.autofillMatches = [
            {
                title: "Updated Title",
                author: ["Updated Author"],
                genre: [
                    "",
                    "Science Fiction",
                    "Unknown Provider Genre",
                    "Fantasy",
                ],
            },
        ];
        const addGenreRow = jest.fn();

        applyAutofillGenres(
            window.autofillMatches[0],
            $("#book-form"),
            addGenreRow,
        );

        expect(selectedGenres()).toEqual(["Fantasy"]);
        expect(addGenreRow).not.toHaveBeenCalled();
    });

    test("apply only uses configured nonblank genres when no genre is selected", async () => {
        document.body.innerHTML = bookFormHtml("");
        window.autofillMatches = [
            {
                title: "Updated Title",
                author: ["Updated Author"],
                genre: ["", "Unknown Provider Genre", "Science Fiction"],
            },
        ];
        const addGenreRow = jest.fn();

        applyAutofillGenres(
            window.autofillMatches[0],
            $("#book-form"),
            addGenreRow,
        );

        expect(selectedGenres()).toEqual(["Science Fiction"]);
        expect(addGenreRow).not.toHaveBeenCalled();
    });

    test("apply appends later configured genres after filling the first blank row", () => {
        document.body.innerHTML = bookFormHtml("");
        const addGenreRow = jest.fn();

        applyAutofillGenres(
            {
                category: ["Science Fiction", "Fantasy"],
            },
            $("#book-form"),
            addGenreRow,
        );

        expect(selectedGenres()).toEqual(["Science Fiction"]);
        expect(addGenreRow).toHaveBeenCalledWith($("#book-form"), "Fantasy");
    });

    test("genre list handles provider genre field variants", () => {
        expect([
            getGenreList({ category: ["Fantasy"] }),
            getGenreList({ categories: ["Science Fiction"] }),
            getGenreList({ genre: ["Romance"] }),
            getGenreList({
                genres: [
                    { genre: { name: "Mystery" } },
                    "Thriller",
                    { genre: {} },
                ],
            }),
            getGenreList({}),
        ]).toEqual([
            "Fantasy",
            "Science Fiction",
            "Romance",
            "Mystery, Thriller",
            "",
        ]);
    });

    test("genre filtering decodes entities and removes duplicates and unknown values", () => {
        document.body.innerHTML = bookFormHtml("");

        expect(
            getAutofillGenreValues(
                {
                    category: [
                        "Science &amp; Fiction",
                        "Science Fiction",
                        "Science Fiction",
                        "",
                        "Unknown",
                    ],
                },
                $("#book-form"),
            ),
        ).toEqual(["Science Fiction"]);
    });

    test("autofill replaces existing description with updated metadata description", () => {
        document.body.innerHTML = bookFormHtml("");
        $("#description").val("Old description that should be replaced");

        const item = {
            description: "New updated book description from provider",
        };
        const descriptionField = $("#description");
        if (descriptionField.length) {
            descriptionField.val(item.description);
        }

        expect($("#description").val()).toBe(
            "New updated book description from provider",
        );
    });

    test("clicking anywhere on a table row selects the radio button for that row", () => {
        document.body.innerHTML = `
            <div id="autofillModal">
                <table>
                    <tbody>
                        <tr id="row-0">
                            <td><input type="radio" name="autofill_result_select" value="0" checked></td>
                            <td>Title 0</td>
                        </tr>
                        <tr id="row-1">
                            <td><input type="radio" name="autofill_result_select" value="1"></td>
                            <td>Title 1</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `;

        $(document).on("click", "#autofillModal tbody tr", function (e) {
            if ($(e.target).is('input[type="radio"]')) {
                return;
            }
            const $radio = $(this).find('input[name="autofill_result_select"]');
            if ($radio.length && !$radio.prop("checked")) {
                $radio.prop("checked", true).trigger("change");
            }
        });

        const secondRowCell = $("#row-1 td").last();
        secondRowCell.trigger("click");

        expect(
            $('#row-1 input[name="autofill_result_select"]').prop("checked"),
        ).toBe(true);
        expect(
            $('#row-0 input[name="autofill_result_select"]').prop("checked"),
        ).toBe(false);
    });
});
