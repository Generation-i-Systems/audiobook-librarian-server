(function (window, $) {
    "use strict";

    if (!$ || !$.fn || !$.fn.autocomplete) {
        console.warn("form-autocomplete.js: jQuery UI autocomplete not available");
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
                            responseCallback(data);
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
