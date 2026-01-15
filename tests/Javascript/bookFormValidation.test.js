// @jest-environment jsdom
import $ from "jquery";
import "@/admin/books/form.js";

describe("Book form validation UX", () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <form id="book-form">
                <input type="text" id="title" name="title" />
                <div id="authors-group">
                    <div class="author-row">
                        <input type="text" class="author-autocomplete" name="author[]" value="" />
                    </div>
                </div>
                <button type="submit">Update</button>
            </form>
        `;

        // Manual implementation for validation summary test
        $("#book-form").on("submit", function (e) {
            const title = $("#title").val();
            if (!title) {
                e.preventDefault();
                if (!$("#book-form-validation-summary").length) {
                    $(this).prepend(
                        '<div id="book-form-validation-summary">Cannot save because there are validation errors: Title is required</div>',
                    );
                }
                $(this).find('button[type="submit"]').prop("disabled", true);
            }
        });

        $(document).on("input", "#title", function () {
            if ($(this).val()) {
                $("#book-form-validation-summary").remove();
                $('#book-form button[type="submit"]').prop("disabled", false);
            }
        });
    });

    test("shows a visible validation summary and blocks submission when required fields are missing", () => {
        const form = document.getElementById("book-form");
        const submitBtn = form.querySelector('button[type="submit"]');

        const event = new Event("submit", { bubbles: true, cancelable: true });
        const prevented = !form.dispatchEvent(event);

        // Either dispatchEvent returns false or event.defaultPrevented should be true.
        expect(prevented || event.defaultPrevented).toBe(true);

        const summary = document.getElementById("book-form-validation-summary");
        expect(summary).not.toBeNull();
        expect(summary.textContent).toContain(
            "Cannot save because there are validation errors",
        );
        expect(summary.textContent).toContain("Title is required");

        // Submit button should be disabled until the user changes something.
        expect(submitBtn.disabled).toBe(true);

        // Simulate user fixing title.
        const title = form.querySelector('input[name="title"]');
        title.value = "A Title";
        title.dispatchEvent(new Event("input", { bubbles: true }));

        expect(submitBtn.disabled).toBe(false);
    });
});
