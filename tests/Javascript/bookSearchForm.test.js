// @jest-environment jsdom
import { jest } from "@jest/globals";
import {
    initBookSearchForm,
    isKeyboardSelection,
    toAutocompleteItems,
} from "@/books/search-form.js";

describe("toAutocompleteItems", () => {
    test.each([
        ["plain strings", ["Ian Irvine", "Iain Banks"], ["Ian Irvine", "Iain Banks"]],
        ["author records", [{ id: 39, name: "Ian Irvine" }], ["Ian Irvine"]],
        ["label/value items", [{ label: "Dune", value: "Dune" }], ["Dune"]],
        ["data envelope", { data: ["Mistrunner", { name: "The Last Hunter" }] }, ["Mistrunner", "The Last Hunter"]],
        ["blank and null entries", ["", null, { name: null }, { id: 1 }, "Kept"], ["Kept"]],
        ["unexpected payload", { error: "nope" }, []],
    ])("maps %s", (_name, data, expected) => {
        expect(toAutocompleteItems(data)).toEqual(
            expected.map((text) => ({ label: text, value: text })),
        );
    });
});

describe("isKeyboardSelection", () => {
    test.each([
        ["Enter keydown nested in menu events", { type: "autocompleteselect", originalEvent: { type: "menuselect", originalEvent: { type: "keydown" } } }, true],
        ["mouse click", { type: "autocompleteselect", originalEvent: { type: "menuselect", originalEvent: { type: "click" } } }, false],
        ["no original event", { type: "autocompleteselect" }, false],
    ])("%s", (_name, event, expected) => {
        expect(isKeyboardSelection(event)).toBe(expected);
    });
});

describe("initBookSearchForm Enter handling", () => {
    let form;

    beforeEach(() => {
        document.body.innerHTML = `
            <form data-book-search>
                <input type="text" id="search" name="search">
                <select id="genre" name="genre"><option value="">All</option></select>
                <input type="checkbox" id="semantic" name="semantic" value="1">
                <button type="submit">Filter</button>
            </form>`;
        form = document.querySelector("form");
        form.requestSubmit = jest.fn();
        initBookSearchForm(form);
    });

    function pressEnter(selector) {
        const event = new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true });
        document.querySelector(selector).dispatchEvent(event);

        return event;
    }

    test.each([["#genre"], ["#semantic"]])("Enter on %s submits the search", (selector) => {
        const event = pressEnter(selector);

        expect([form.requestSubmit.mock.calls.length, event.defaultPrevented]).toEqual([1, true]);
    });

    test("Enter in a text field is left to the browser's own submit", () => {
        const event = pressEnter("#search");

        expect([form.requestSubmit.mock.calls.length, event.defaultPrevented]).toEqual([0, false]);
    });
});
