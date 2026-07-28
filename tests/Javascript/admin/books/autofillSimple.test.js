// @jest-environment jsdom
import $ from "jquery";
import { jest } from "@jest/globals";

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

async function loadAutofillModules() {
    await import("@/admin/books/form-helpers.js");
    await import("@/admin/books/autofill-simple.js");
    window.BookForm.initializeTemplates($("#book-form"));
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

        await loadAutofillModules();
        window.BookForm.applyAutofillGenres(window.autofillMatches[0]);

        expect(selectedGenres()).toEqual(["Fantasy"]);
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

        await loadAutofillModules();
        window.BookForm.applyAutofillGenres(window.autofillMatches[0]);

        expect(selectedGenres()).toEqual(["Science Fiction"]);
    });
});
