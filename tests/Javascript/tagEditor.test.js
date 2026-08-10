// @jest-environment jsdom
import $ from "jquery";
import "@/admin/books/tag-editor.js";

describe("Book tag editor", () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <form id="system-tags-form"></form>
            <div class="tag-editor" data-tags-input="system-tags" data-suggestions='["award-winner"]'>
                <input id="system-tags" form="system-tags-form" name="tags" value="featured, classic" list="system-tags-suggestions" />
                <datalist id="system-tags-suggestions"><option value="award-winner"></option></datalist>
            </div>
        `;
    });

    test("renders removable chips and keeps the submitted value in sync", () => {
        window.BookForm.initializeTagEditors($(document));

        expect(document.querySelectorAll(".tag-chip")).toHaveLength(2);
        expect(document.querySelector(".tag-entry").getAttribute("list")).toBe("system-tags-suggestions");

        document.querySelector(".tag-entry").value = "award-winner";
        document.querySelector(".tag-entry").dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true }));

        expect(document.querySelector("#system-tags").value).toBe("featured, classic, award-winner");

        document.querySelector(".tag-chip-remove").click();

        expect(document.querySelector("#system-tags").value).toBe("classic, award-winner");
    });

    test("removes duplicate initial tags and accepts tags on blur or comma", () => {
        document.body.innerHTML = `
            <div class="tag-editor" data-tags-input="my-tags">
                <input id="my-tags" name="tags" value="reread, REREAD, " />
            </div>
            <div class="tag-editor" data-tags-input="missing-tags"></div>
        `;
        window.BookForm.initializeTagEditors($(document));

        const entry = document.querySelector(".tag-entry");
        expect(document.querySelectorAll(".tag-chip")).toHaveLength(1);

        entry.value = "favorite";
        entry.dispatchEvent(new Event("blur"));
        entry.value = "reread";
        entry.dispatchEvent(new KeyboardEvent("keydown", { key: ",", bubbles: true }));

        expect(document.querySelector("#my-tags").value).toBe("reread, favorite");
    });

    test("saves tags in place without navigating away from the book form", () => {
        document.body.innerHTML = `
            <form id="my-tags-form" data-tag-save data-status-target="my-tags-save-status" action="/books/1/tags" method="POST">
                <input name="_token" value="csrf-token" />
                <input name="scope" value="user" />
                <input name="tags" value="reread" />
            </form>
            <div class="tag-save-status" id="my-tags-save-status"></div>
        `;
        $.ajax = jest.fn((options) => options.success({ message: "Tags updated successfully!" }));

        window.BookForm.initializeTagSaveForms();
        const form = document.querySelector("#my-tags-form");
        const event = new Event("submit", { bubbles: true, cancelable: true });

        expect(form.dispatchEvent(event)).toBe(false);
        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            method: "POST",
            url: "http://localhost/books/1/tags",
        }));
        expect(document.querySelector(".tag-save-status").textContent).toBe("Tags updated successfully!");
    });

    test("keeps the edit page open and shows a tag-save error", () => {
        document.body.innerHTML = `
            <form id="my-tags-form" data-tag-save data-status-target="my-tags-save-status" action="/books/1/tags">
                <input name="scope" value="user" />
            </form>
            <div class="tag-save-status" id="my-tags-save-status"></div>
        `;
        $.ajax = jest.fn((options) => options.error({ responseJSON: { message: "Tag save failed." } }));

        window.BookForm.initializeTagSaveForms();
        window.BookForm.initializeTagSaveForms();
        const form = document.querySelector("#my-tags-form");

        expect(form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }))).toBe(false);
        expect(document.querySelector(".tag-save-status").textContent).toBe("Tag save failed.");
        expect(document.querySelector(".tag-save-status").classList).toContain("text-danger");
    });

    test("commits the active tag before a tag-save button receives the click", () => {
        document.body.innerHTML = `
            <form id="my-tags-form"></form>
            <div class="tag-editor" data-tags-input="my-tags">
                <input id="my-tags" form="my-tags-form" name="tags" value="" />
            </div>
            <button type="submit" form="my-tags-form">Save My Tags</button>
        `;
        window.BookForm.initializeTagEditors($(document));

        document.querySelector(".tag-entry").value = "reread";
        document.querySelector("button[form='my-tags-form']").dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));

        expect(document.querySelector("#my-tags").value).toBe("reread");
    });

    test("saves tag forms before continuing the main book-form submission", async () => {
        document.body.innerHTML = `
            <form id="book-form"></form>
            <form id="my-tags-form" data-tag-save data-status-target="my-tags-save-status" action="/books/1/tags" method="POST">
                <input name="scope" value="user" />
                <input name="tags" value="reread" />
            </form>
            <div class="tag-save-status" id="my-tags-save-status"></div>
        `;
        $.ajax = jest.fn((options) => options.success({ message: "Tags updated successfully!" }));
        const bookForm = document.querySelector("#book-form");
        bookForm.requestSubmit = jest.fn();
        const downstreamSubmitHandler = jest.fn();
        bookForm.addEventListener("submit", downstreamSubmitHandler);

        window.BookForm.initializeBookFormTagSave();

        expect(bookForm.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }))).toBe(false);
        await Promise.resolve();
        await Promise.resolve();

        expect($.ajax).toHaveBeenCalledTimes(1);
        expect(bookForm.requestSubmit).toHaveBeenCalledTimes(1);
        expect(downstreamSubmitHandler).not.toHaveBeenCalled();
    });
});
