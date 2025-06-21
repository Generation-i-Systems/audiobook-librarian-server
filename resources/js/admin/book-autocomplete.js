// Autocomplete for author, narrator, and series fields on the book form
$(function () {
    function setupAutocomplete(selector, endpoint) {
        $(document).on('input', selector, function () {
            let $input = $(this);
            let query = $input.val();
            if (query.length < 2) return;
            $.get(endpoint, { query: query, limit: 10 }, function (data) {
                let list = data.data || [];
                let $datalist = $input.next('datalist');
                if ($datalist.length === 0) {
                    $datalist = $('<datalist>').attr('id', $input.attr('id') + '-list');
                    $input.attr('list', $datalist.attr('id'));
                    $input.after($datalist);
                }
                $datalist.empty();
                list.forEach(function (item) {
                    $datalist.append($('<option>').val(item));
                });
            });
        });
    }
    setupAutocomplete('.author-autocomplete', '/api/v1/authors/autocomplete');
    setupAutocomplete('.narrator-autocomplete', '/api/v1/narrators/autocomplete');
    setupAutocomplete('.series-autocomplete', '/api/v1/series/autocomplete');
});
