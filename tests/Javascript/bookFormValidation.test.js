// @jest-environment jsdom

const $ = require('jquery');

describe('Book form validation UX', () => {
    beforeEach(() => {
        jest.resetModules();

        document.body.innerHTML = `
            <form id="book-form">
                <input type="text" name="title" />
                <div id="authors-group">
                    <div class="author-row">
                        <input type="text" name="author[]" value="" />
                    </div>
                </div>
                <div id="genres-group">
                    <div class="genre-row">
                        <select name="genre[]">
                            <option value="">Select</option>
                            <option value="Fantasy">Fantasy</option>
                        </select>
                    </div>
                </div>
                <button type="submit">Update</button>
            </form>
        `;

        // Load the real script so we test the actual submit handler.
        require('~/admin/books/form.js');

        global.initBookForm = jest.fn();

        document.dispatchEvent(new Event('DOMContentLoaded'));
    });

    test('shows a visible validation summary and blocks submission when required fields are missing', () => {
        const form = document.getElementById('book-form');
        const submitBtn = form.querySelector('button[type="submit"]');

        const event = new Event('submit', { bubbles: true, cancelable: true });
        const prevented = !form.dispatchEvent(event);

        // Either dispatchEvent returns false or event.defaultPrevented should be true.
        expect(prevented || event.defaultPrevented).toBe(true);

        const summary = document.getElementById('book-form-validation-summary');
        expect(summary).not.toBeNull();
        expect(summary.textContent).toContain('Cannot save because there are validation errors');
        expect(summary.textContent).toContain('Title is required');

        // Submit button should be disabled until the user changes something.
        expect(submitBtn.disabled).toBe(true);

        // Simulate user fixing title.
        const title = form.querySelector('input[name="title"]');
        title.value = 'A Title';
        title.dispatchEvent(new Event('input', { bubbles: true }));

        expect(submitBtn.disabled).toBe(false);
    });
});
