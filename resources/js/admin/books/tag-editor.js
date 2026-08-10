(function (window, $) {
    "use strict";

    if (!$) {
        console.error("tag-editor.js requires jQuery");
        return;
    }

    const bookForm = (window.BookForm = window.BookForm || {});

    function parseTags(value) {
        const seen = new Set();

        return String(value || "")
            .split(",")
            .map((tag) => tag.trim())
            .filter((tag) => {
                const key = tag.toLocaleLowerCase();
                if (!tag || seen.has(key)) {
                    return false;
                }

                seen.add(key);
                return true;
            });
    }

    function initializeTagEditor(editor) {
        const $editor = $(editor);
        if ($editor.data("tag-editor-initialized")) {
            return;
        }

        const input = document.getElementById($editor.data("tags-input"));
        if (!input) {
            return;
        }

        const $valueInput = $(input);
        const listId = $valueInput.attr("list");
        let tags = parseTags($valueInput.val());
        const $chips = $('<div class="tag-chips d-flex flex-wrap gap-2 align-items-center"></div>');
        const $entry = $("<input>", {
            class: "form-control tag-entry flex-grow-1",
            type: "text",
            placeholder: "Add a tag",
            "aria-label": "Add a tag",
        });

        if (listId) {
            $entry.attr("list", listId);
        }

        function syncValue() {
            $valueInput.val(tags.join(", "));
        }

        function renderChips() {
            $chips.empty();
            tags.forEach((tag, index) => {
                const $chip = $('<span class="badge text-bg-secondary tag-chip d-inline-flex align-items-center gap-1"></span>');
                const $remove = $("<button>", {
                    class: "btn-close btn-close-white tag-chip-remove",
                    type: "button",
                    "aria-label": `Remove ${tag}`,
                });

                $chip.append(document.createTextNode(tag));
                $remove.on("click", function () {
                    tags.splice(index, 1);
                    syncValue();
                    renderChips();
                });
                $chip.append($remove);
                $chips.append($chip);
            });
            $chips.append($entry);
        }

        function addEnteredTags() {
            const enteredTags = parseTags($entry.val());
            const existingTags = new Set(tags.map((tag) => tag.toLocaleLowerCase()));

            enteredTags.forEach((tag) => {
                if (!existingTags.has(tag.toLocaleLowerCase())) {
                    tags.push(tag);
                    existingTags.add(tag.toLocaleLowerCase());
                }
            });

            $entry.val("");
            syncValue();
            renderChips();
        }

        $entry.on("keydown", function (event) {
            if (event.key === "Enter" || event.key === ",") {
                event.preventDefault();
                addEnteredTags();
            }
        });
        $entry.on("blur", function () {
            if ($entry.val().trim()) {
                addEnteredTags();
            }
        });

        const formId = $valueInput.attr("form");
        if (formId) {
            $(`button[form="${formId}"]`).on("mousedown", addEnteredTags);
        }

        $valueInput.attr("type", "hidden");
        $valueInput.after($chips);
        $editor.data("tag-editor-initialized", true);
        $editor.data("commit-tags", addEnteredTags);
        renderChips();
    }

    function initializeTagEditors($container) {
        $container.find(".tag-editor").each(function () {
            initializeTagEditor(this);
        });
    }

    function initializeTagSaveForms() {
        $("form[data-tag-save]").each(function () {
            const $form = $(this);
            if ($form.data("tag-save-initialized")) {
                return;
            }

            $form.on("submit", function (event) {
                event.preventDefault();
                saveTagForm($form);
            });
            $form.data("tag-save-initialized", true);
        });
    }

    function saveTagForm($form) {
        const statusTarget = document.getElementById($form.data("status-target"));
        const $status = $(statusTarget);

        return new Promise((resolve) => {
            $.ajax({
                url: $form[0].action,
                method: ($form.attr("method") || "POST").toUpperCase(),
                data: new FormData($form[0]),
                processData: false,
                contentType: false,
                headers: { Accept: "application/json" },
                success(data) {
                    $status.removeClass("text-danger").addClass("text-success").text(data.message);
                    resolve(true);
                },
                error(xhr) {
                    const message = xhr.responseJSON?.message || "Unable to save tags. Please try again.";
                    $status.removeClass("text-success").addClass("text-danger").text(message);
                    resolve(false);
                },
            });
        });
    }

    function initializeBookFormTagSave() {
        const form = document.getElementById("book-form");
        if (!form || form.dataset.tagSaveInitialized === "true") {
            return;
        }

        form.addEventListener("submit", function (event) {
            if (form.dataset.tagSaveReady === "true") {
                delete form.dataset.tagSaveReady;
                return;
            }

            const tagForms = $("form[data-tag-save]");
            if (!tagForms.length) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            $(".tag-editor").each(function () {
                const commitTags = $(this).data("commit-tags");
                if (typeof commitTags === "function") {
                    commitTags();
                }
            });

            Promise.all(tagForms.toArray().map((tagForm) => saveTagForm($(tagForm)))).then((results) => {
                if (results.every(Boolean)) {
                    form.dataset.tagSaveReady = "true";
                    form.requestSubmit(event.submitter);
                }
            });
        }, true);
        form.dataset.tagSaveInitialized = "true";
    }

    bookForm.initializeTagEditors = initializeTagEditors;
    bookForm.initializeTagSaveForms = initializeTagSaveForms;
    bookForm.initializeBookFormTagSave = initializeBookFormTagSave;
})(window, window.jQuery);
