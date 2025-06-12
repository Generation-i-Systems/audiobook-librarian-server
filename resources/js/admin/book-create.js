/**
 * This function will take the Jquery and has
 *
 */

jQuery(function () {
    const directoryBrowser = $('#directory-browser');

    function escapeHtml(text)
    {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function (m) {
            return map[m]; });
    }

    function updateBreadcrumbs(path)
    {
        let pathParts = path.split('/');
        var html = '';

        if (path !== '') {
            html += '<li class="breadcrumb-item"><a href="#" class="breadcrumb-link" data-path="">Root</a></li>';
        }

        for (let i = 0; i < pathParts.length; i++) {
            if (pathParts[i] === "" || pathParts[i] === null || pathParts[i] === undefined || pathParts[i] == '/') {
                continue;
            }
            let crumbPath = '';

            for (let j = 0; j <= i; j++) {
                if (pathParts[j] === "" || pathParts[j] === null || pathParts[j] === undefined || pathParts[j] == '/') {
                    continue;
                }
                crumbPath += "/" + pathParts[j];
            }

            html += '<li class="breadcrumb-item"><a href="#" class="breadcrumb-link" data-path="' + crumbPath + '">' + pathParts[i] + '</a></li>';
        }
        $('#directory-path-breadcrumbs').html(html);

    }
    function loadDirectory(path)
    {
        $.ajax({
            url: '{{ route("admin.directoryBrowser") }}',
            type: 'GET',
            data: { path: path },
            success: function (data) {
                let html = '<ul>';
                if (path !== '') {
                    html += '<li><a href="#" onclick="history.back()">Previous</a></li>';
                }
                $.each(data, function (index, item) {
                    if (item.type === 'directory') {
                        let encodedPath = encodeURIComponent(item.path);
                        let addBookUrl = '{{ route("admin.books.create") }}?path=' + encodedPath;

                        html += '<li><a href="#" class="directory-link" data-path="' + item.path + '">' + item.name + '</a>';
                        if (item.edit) {
                            html += '<a href="' + item.edit + '" style="float: right; margin-left: 10px;">Edit Book</a></li>';
                        } else if (item.create) {
                            html += '<a href="' + addBookUrl + '" style="float: right; margin-left: 10px;">Add Book</a></li>';
                        }
                    } else {
                        html += '<li>' + item.name + '</li>';
                    }
                });
                html += '</ul>';
                updateBreadcrumbs(path);
                $('#directory-browser').html(html);
                directoryBrowser.html(html);
                attachDirectoryLinkHandlers(path)
            },
            error: function (xhr, status, error) {
                $('#directory-browser').html('<p>Error loading directory: ' + error + '</p>');
            }
        });
    }
    function attachDirectoryLinkHandlers(path)
    {
        $(".directory-link").on("click", function (e) {
            e.preventDefault();
            const newPath = $(this).data('path');
            history.pushState({ path: newPath }, null, null);
            loadDirectory(newPath);
        });
        $(".breadcrumb-link").on("click", function (e) {
            e.preventDefault();
            const newPath = $(this).data('path');
            history.pushState({ path: newPath }, null, null);
            loadDirectory(newPath);
        });
    }

    // Initial Load
    loadDirectory('');

    window.addEventListener('popstate', function (event) {
        loadDirectory(event.state ? event.state.path : '');
    });
});
