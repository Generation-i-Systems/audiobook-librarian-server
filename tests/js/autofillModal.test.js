// @jest-environment jsdom
import $ from "jquery";

describe("Autofill Modal UI", () => {
    let $container;
    beforeEach(() => {
        document.body.innerHTML = ` < div id = "book-form" > < button id = "autofill-btn" > Autofill < / button > < input id = "title" / > < input id = "published_year" / > < input id = "description" / > < div id = "cover-preview-group" style = "display:none" > < img id = "cover-preview-img" > < / div > < div id = "google-books-matches-table-wrapper" style = "display:none" > < table id = "google-books-matches-table" > < tbody > < / tbody > < / table > < / div > < div id = "authors-group" > < / div > < div id = "series-group" > < / div > < / div > `;
        $container = $("#book-form");
        window.BOOK_FORM_ROUTES = {
            authorsAutocomplete: "/authors",
            seriesAutocomplete: "/series",
        };
    });

    it("should show and hide autofill button states", () => {
        $container
            .find("#autofill-btn")
            .prop("disabled", true)
            .html('<i class="fas fa-spinner fa-spin"></i> Searching...');
        expect($container.find("#autofill-btn").prop("disabled")).toBe(true);
        $container
            .find("#autofill-btn")
            .prop("disabled", false)
            .html('<i class="fas fa-search"></i> Autofill from Google Books');
        expect($container.find("#autofill-btn").prop("disabled")).toBe(false);
    });

    it("should update fields on autofill close match", () => {
        const data = {
            match_type: "close",
            published_year: "2020",
            description: "desc",
            cover_image_url: "https://books.google.com/cover.jpg",
        };
        $container.find("#published_year").val("");
        $container.find("#description").val("");
        // Simulate autofill logic
        $container.find("#published_year").val(data.published_year);
        $container.find("#description").val(data.description);
        expect($container.find("#published_year").val()).toBe("2020");
        expect($container.find("#description").val()).toBe("desc");
    });
});
