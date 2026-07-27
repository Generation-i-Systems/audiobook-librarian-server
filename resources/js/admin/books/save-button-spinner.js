/**
 * Save Button Spinner
 * Shows a busy spinner on the book form's save button while the save request
 * is in flight, so the user knows the (sometimes slow) save is working.
 */

(function (window, $) {
    "use strict";

    if (!$) {
        console.error("save-button-spinner.js requires jQuery");
        return;
    }

    function initSaveButtonSpinner() {
        const $bookForm = $("#book-form");
        if (!$bookForm.length) {
            return;
        }

        const $submitBtn = $bookForm
            .find("#modal-update-btn, #modal-create-btn")
            .filter('[type="submit"]');
        if (!$submitBtn.length) {
            return;
        }

        const originalHtml = $submitBtn.html();

        function showSpinner() {
            $submitBtn
                .prop("disabled", true)
                .html(
                    '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...',
                );
        }

        function resetButton() {
            $submitBtn.prop("disabled", false).html(originalHtml);
        }

        // Registered after directory-conflict.js's submit handler (script load
        // order), so e.isDefaultPrevented() correctly reflects whether that
        // handler already intercepted this submit for an async conflict check.
        $bookForm.on("submit", function (e) {
            if (e.isDefaultPrevented()) {
                return;
            }
            showSpinner();
        });

        // If a directory conflict is found, the save didn't actually go
        // through yet, so let the user try again.
        $("#directoryConflictModal").on("show.bs.modal", resetButton);

        // Restore the button if the page is restored from the back/forward
        // cache after a save (avoids a permanently disabled button).
        window.addEventListener("pageshow", resetButton);
    }

    $(document).ready(initSaveButtonSpinner);
})(window, window.jQuery);
