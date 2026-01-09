(function (window, $) {
    "use strict";

    if (!$) {
        console.error("form-cover.js requires jQuery");
        return;
    }

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
        const $cornerPreview = $("#cover-preview-trigger img");
        if (!$cornerPreview.length) {
            return;
        }
        const $labelImage = $radio.closest("label").find("img");
        if ($labelImage.length) {
            $cornerPreview.attr("src", $labelImage.attr("src"));
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
        // Use delegated events if $container is a jQuery object
        const $cont = $($container);
        $cont
            .off("change.coverRadios", 'input[name="coverImageCandidate"]')
            .on(
                "change.coverRadios",
                'input[name="coverImageCandidate"]',
                function () {
                    setCornerPreviewFromRadio($(this));
                    updateCoverSourceField();
                },
            );
    }

    function syncCornerPreview() {
        const $checked = $('input[name="coverImageCandidate"]:checked');
        if ($checked.length) {
            setCornerPreviewFromRadio($checked.first());
        }
    }

    function attachCoverPreviewModal($container) {
        const coverPreviewTrigger = document.getElementById(
            "cover-preview-trigger",
        );
        if (coverPreviewTrigger) {
            coverPreviewTrigger.addEventListener("click", function () {
                const modalEl = document.getElementById("coverImageModal");
                if (modalEl && window.bootstrap?.Modal) {
                    const modal =
                        window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                } else if ($("#coverImageModal").length) {
                    $("#coverImageModal").modal("show");
                }
            });
        }
    }

    bookForm.ensureCoverImageSelected = ensureCoverImageSelected;
    bookForm.registerCoverRadioHandlers = registerCoverRadioHandlers;
    bookForm.updateCoverSourceField = updateCoverSourceField;
    bookForm.syncCornerPreview = syncCornerPreview;
    bookForm.attachCoverPreviewModal = attachCoverPreviewModal;

    window.ensureCoverImageSelected = ensureCoverImageSelected;
})(window, window.jQuery);
