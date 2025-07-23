// Autocomplete for author, narrator, and series fields on the book form
$(function () {
    function setupAutocomplete(selector, endpoint) {
        $(document).on('input', selector, function (e) {
            // Prevent any default form submission behavior
            e.preventDefault();
            e.stopPropagation();
            
            let $input = $(this);
            let query = $input.val();
            if (query.length < 2) return;
            
            // Use the admin autocomplete endpoints with proper error handling
            $.get(endpoint, { term: query, limit: 10 })
                .done(function (data) {
                    // Handle different response formats
                    let list = [];
                    if (Array.isArray(data)) {
                        list = data;
                    } else if (data && Array.isArray(data.data)) {
                        list = data.data;
                    } else if (data && data.results && Array.isArray(data.results)) {
                        list = data.results;
                    }
                    
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
                })
                .fail(function (xhr, status, error) {
                    console.log('Autocomplete request failed:', status, error);
                    // Don't show errors to user, just silently fail
                });
        });
    }
    
    // Use the admin autocomplete endpoints that work with Laravel auth
    setupAutocomplete('.author-autocomplete', '/admin/books/autocomplete/authors');
    setupAutocomplete('.narrator-autocomplete', '/admin/books/autocomplete/narrators');
    setupAutocomplete('.series-autocomplete', '/admin/books/autocomplete/series');
});
