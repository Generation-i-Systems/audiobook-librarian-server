// Book search form on /books: author/series autocomplete and Enter-to-search on every field.

/**
 * Normalise an autocomplete endpoint response (strings, author/series records with `name`,
 * label/value items, optionally wrapped in `{data: [...]}`) into jQuery UI items, dropping blanks.
 */
export function toAutocompleteItems(data) {
    const list = Array.isArray(data) ? data : Array.isArray(data?.data) ? data.data : [];

    return list
        .map((item) => {
            if (typeof item === "string") {
                return item;
            }
            if (item && typeof item === "object") {
                return item.value || item.name || item.label || "";
            }
            return "";
        })
        .filter((text) => typeof text === "string" && text.trim() !== "")
        .map((text) => ({ label: text, value: text }));
}

/** True when a jQuery UI autocomplete "select" came from the keyboard (Enter) rather than a click. */
export function isKeyboardSelection(event) {
    let current = event?.originalEvent;
    while (current) {
        if (current.type === "keydown") {
            return true;
        }
        current = current.originalEvent;
    }

    return false;
}

function initAutocomplete($, form, selector, url, paramName) {
    const $input = $(form).find(selector);
    if (!url || !$input.length) {
        return;
    }

    $input.autocomplete({
        minLength: 2,
        source(request, response) {
            $.ajax({ url, dataType: "json", data: { [paramName]: request.term } })
                .done((data) => response(toAutocompleteItems(data)))
                .fail((xhr) => {
                    console.error("[book-search] autocomplete request failed:", url, xhr.status);
                    response([]);
                });
        },
        select(event, ui) {
            $input.val(ui.item.value);
            if (isKeyboardSelection(event)) {
                form.requestSubmit();
            }
            return false;
        },
    });
}

export function initBookSearchForm(form) {
    // Text inputs submit on Enter natively; selects and checkboxes do not.
    form.addEventListener("keydown", (event) => {
        if (event.key !== "Enter" || event.isComposing) {
            return;
        }
        if (event.target.matches("select, input[type=checkbox]")) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    const $ = window.jQuery;
    if (!$?.fn?.autocomplete) {
        console.error("[book-search] requires jQuery UI autocomplete");
        return;
    }

    initAutocomplete($, form, "#author", form.dataset.authorsAutocompleteUrl, "term");
    initAutocomplete($, form, "#series", form.dataset.seriesAutocompleteUrl, "term");
}

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("form[data-book-search]").forEach(initBookSearchForm);
});
