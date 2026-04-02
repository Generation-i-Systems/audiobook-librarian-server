// @jest-environment jsdom
import { jest } from "@jest/globals";

describe("planned-actions cover URL sync", () => {
    beforeAll(async () => {
        await import("@/admin/books/planned-actions.js");
    });

    beforeEach(() => {
        document.body.innerHTML = `
            <form id="book-form">
                <input type="hidden" id="coverImageUrl" name="coverImageUrl" value="">
                <input type="hidden" id="audibleCoverImageUrl" name="audibleCoverImageUrl" value="https://example.com/old.jpg">
                <input type="text" id="coverImageUrlText" value="">
                <input type="radio" name="coverImageCandidate" value="cover.jpg" checked>
                <div id="planned-actions-preview"></div>
            </form>
        `;

        window.BOOK_FORM_ROUTES = {};
        document.dispatchEvent(new Event("DOMContentLoaded"));
    });

    test("syncs typed cover URL into hidden inputs and clears selected candidates", () => {
        const textInput = document.getElementById("coverImageUrlText");
        const hiddenInput = document.getElementById("coverImageUrl");
        const audibleHiddenInput = document.getElementById(
            "audibleCoverImageUrl",
        );
        const candidate = document.querySelector(
            'input[name="coverImageCandidate"]',
        );

        textInput.value = "https://example.com/new-cover.jpg";
        textInput.dispatchEvent(new Event("change", { bubbles: true }));

        expect(hiddenInput.value).toBe("https://example.com/new-cover.jpg");
        expect(audibleHiddenInput.value).toBe("");
        expect(candidate.checked).toBe(false);
    });

    test("clears stale cover URL fields when a candidate is selected", () => {
        const textInput = document.getElementById("coverImageUrlText");
        const hiddenInput = document.getElementById("coverImageUrl");
        const audibleHiddenInput = document.getElementById(
            "audibleCoverImageUrl",
        );
        const candidate = document.querySelector(
            'input[name="coverImageCandidate"]',
        );

        textInput.value = "https://example.com/new-cover.jpg";
        hiddenInput.value = "https://example.com/new-cover.jpg";
        audibleHiddenInput.value = "https://example.com/old.jpg";
        candidate.checked = true;

        candidate.dispatchEvent(new Event("change", { bubbles: true }));

        expect(textInput.value).toBe("");
        expect(hiddenInput.value).toBe("");
        expect(audibleHiddenInput.value).toBe("");
    });
});
