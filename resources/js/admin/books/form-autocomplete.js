(function (window, $) {
    "use strict";

    if (!$ || !$.fn || !$.fn.autocomplete) {
        console.warn(
            "form-autocomplete.js: jQuery UI autocomplete not available",
        );
    }

    const bookForm = (window.BookForm = window.BookForm || {});

    function initializeAutocomplete($container, selector, sourceUrl) {
        if (!sourceUrl) {
            return;
        }

        $container.on("focus", selector, function () {
            const $input = $(this);
            if ($input.data("autocomplete-initialized")) {
                return;
            }

            $input.autocomplete({
                minLength: 2,
                source(request, responseCallback) {
                    $.ajax({
                        url: sourceUrl,
                        dataType: "json",
                        data: { term: request.term },
                        success(data) {
                            if (Array.isArray(data)) {
                                const processed = data
                                    .map(function (item) {
                                        if (typeof item === "string") {
                                            return { label: item, value: item };
                                        }
                                        if (
                                            typeof item === "object" &&
                                            item !== null
                                        ) {
                                            return {
                                                label:
                                                    item.label ||
                                                    item.name ||
                                                    item.value ||
                                                    "",
                                                value:
                                                    item.value ||
                                                    item.name ||
                                                    item.label ||
                                                    "",
                                            };
                                        }
                                        return null;
                                    })
                                    .filter(function (item) {
                                        return (
                                            item !== null && item.label !== ""
                                        );
                                    });
                                responseCallback(processed);
                            } else {
                                responseCallback([]);
                            }
                        },
                        error() {
                            responseCallback([]);
                        },
                    });
                },
                select(event, ui) {
                    event.preventDefault();
                    $input.val(ui.item.value || "");
                    return false;
                },
            });

            $input.data("autocomplete-initialized", true);
        });
    }

    bookForm.initializeAutocomplete = initializeAutocomplete;
    window.initializeAutocomplete = initializeAutocomplete;
})(window, window.jQuery);
