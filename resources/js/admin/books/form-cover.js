(function (window) {
    "use strict";

    // Note: This file is being migrated from jQuery to vanilla JS
    // Some jQuery functionality may still be present for backward compatibility during transition

    const bookForm = (window.BookForm = window.BookForm || {});

    function ensureCoverImageSelected() {
        const $coverRadios = $('input[name="coverImageCandidate"]');
        if ($coverRadios.length === 0) {
            return;
        }

        if ($coverRadios.filter(":checked").length === 0) {
            const $preferred = $coverRadios.filter(
                '[data-source="audible"], [data-source="googlebooks"]',
            );
            const $radioToCheck = $preferred.length
                ? $preferred.first()
                : $coverRadios.first();
            $radioToCheck.prop("checked", true).trigger("change");
        }
    }

    function setCornerPreviewFromRadio($radio) {
        const $previewContainer = document.querySelector(
            "#cover-preview-trigger",
        );
        const $cornerPreview =
            $previewContainer && $previewContainer.querySelector("img");
        if (!$cornerPreview) {
            return;
        }
        const $labelImage = $radio.closest("label").find("img");
        if ($labelImage.length) {
            $cornerPreview.src = $labelImage.getAttribute("src");
        }
    }

    function updateCoverSourceField() {
        const checked = document.querySelector(
            'input[name="coverImageCandidate"]:checked',
        );
        const coverSourceField = document.getElementById("coverImageSource");
        if (checked && coverSourceField) {
            coverSourceField.value = checked.dataset.source || "";
        }
    }

    function registerCoverRadioHandlers($container) {
        const radioButtons = $container.querySelectorAll(
            'input[name="coverImageCandidate"]',
        );

        // Remove existing event listeners
        radioButtons.forEach((radio) => {
            radio.removeEventListener("change", handleRadioChange);
        });

        // Add new event listeners
        radioButtons.forEach((radio) => {
            radio.addEventListener("change", handleRadioChange);
        });
    }

    function handleRadioChange() {
        const $radio = this;
        setCornerPreviewFromRadio($radio);
        updateCoverSourceField();
    }

    function syncCornerPreview() {
        const $checked = $('input[name="coverImageCandidate"]:checked');
        if ($checked.length) {
            setCornerPreviewFromRadio($checked.first());
        }
    }

    bookForm.ensureCoverImageSelected = ensureCoverImageSelected;
    bookForm.registerCoverRadioHandlers = registerCoverRadioHandlers;
    bookForm.updateCoverSourceField = updateCoverSourceField;
    bookForm.syncCornerPreview = syncCornerPreview;

    window.ensureCoverImageSelected = ensureCoverImageSelected;
})(window, window.jQuery);
