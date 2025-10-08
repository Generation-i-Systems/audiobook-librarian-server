/**
 * Directory Browser for Book Edit Form
 * Handles directory browsing and path selection
 */

(function() {
    'use strict';

    let currentBrowserPath = '';
    let browseDirectoriesUrl = '';

    // Initialize directory browser
    function initDirectoryBrowser() {
        browseDirectoriesUrl = window.BOOK_FORM_ROUTES?.browseDirectories || '/admin/books/browse-directories';

        // Handle red X button click
        $(document).on('click', '#directory-not-found-btn', function(e) {
            e.preventDefault();
            const currentPath = $('#directoryPath').val();
            openDirectoryBrowser(currentPath);
        });

        // Handle directory browser modal
        $('#dir-browser-up-btn').on('click', function() {
            navigateToParent();
        });

        $('#dir-browser-select-btn').on('click', function() {
            selectCurrentDirectory();
        });

        // Handle directory clicks in browser
        $(document).on('click', '.dir-browser-item', function(e) {
            e.preventDefault();
            const path = $(this).data('path');
            loadDirectoryBrowser(path);
        });
    }

    // Open directory browser modal
    function openDirectoryBrowser(initialPath) {
        currentBrowserPath = initialPath || '';
        const modal = new bootstrap.Modal(document.getElementById('directoryBrowserModal'));
        modal.show();
        loadDirectoryBrowser(currentBrowserPath);
    }

    // Load directory browser content
    function loadDirectoryBrowser(path) {
        $('#dir-browser-list').html('<div class="text-center text-muted"><div class="spinner-border spinner-border-sm"></div> Loading...</div>');
        
        $.ajax({
            url: browseDirectoriesUrl,
            method: 'GET',
            data: { path: path },
            dataType: 'json',
            success: function(response) {
                currentBrowserPath = response.currentPath || '';
                $('#dir-browser-current-path').val(currentBrowserPath || '(root)');
                
                // Enable/disable up button
                $('#dir-browser-up-btn').prop('disabled', !response.canGoUp);
                
                // Render directories
                let html = '';
                if (response.directories && response.directories.length > 0) {
                    html = '<div class="list-group">';
                    response.directories.forEach(function(dir) {
                        html += `
                            <a href="#" class="list-group-item list-group-item-action dir-browser-item" data-path="${dir.path}">
                                <i class="fas fa-folder text-warning me-2"></i>${dir.name}
                            </a>
                        `;
                    });
                    html += '</div>';
                } else {
                    html = '<div class="text-center text-muted p-3">No subdirectories found</div>';
                }
                
                $('#dir-browser-list').html(html);
            },
            error: function(xhr, status, error) {
                console.error('Error loading directories:', error);
                $('#dir-browser-list').html('<div class="alert alert-danger">Error loading directories</div>');
            }
        });
    }

    // Navigate to parent directory
    function navigateToParent() {
        $.ajax({
            url: browseDirectoriesUrl,
            method: 'GET',
            data: { path: currentBrowserPath },
            dataType: 'json',
            success: function(response) {
                if (response.parentPath !== null) {
                    loadDirectoryBrowser(response.parentPath);
                }
            }
        });
    }

    // Select current directory
    function selectCurrentDirectory() {
        $('#directoryPath').val(currentBrowserPath).trigger('change');
        bootstrap.Modal.getInstance(document.getElementById('directoryBrowserModal')).hide();
        
        // Re-check directory existence
        checkDirectoryExists();
    }

    // Check if directory exists and show/hide red X
    function checkDirectoryExists() {
        const dirPath = $('#directoryPath').val();
        if (!dirPath) {
            $('#directory-not-found-btn').hide();
            return;
        }

        const filesAjaxUrl = window.BOOK_FORM_ROUTES?.filesAjax || '/admin/books/files-ajax';
        
        $.ajax({
            url: filesAjaxUrl,
            method: 'GET',
            data: { directory: dirPath },
            dataType: 'json',
            success: function(response) {
                if (response.exists) {
                    $('#directory-not-found-btn').hide();
                } else {
                    $('#directory-not-found-btn').show();
                }
            },
            error: function() {
                $('#directory-not-found-btn').show();
            }
        });
    }

    // Initialize on document ready
    $(document).ready(function() {
        initDirectoryBrowser();
        
        // Check directory exists on page load
        if ($('#directoryPath').length && $('#directoryPath').val()) {
            checkDirectoryExists();
        }

        // Check directory exists when path changes
        $('#directoryPath').on('change blur', function() {
            checkDirectoryExists();
        });
    });

    // Export functions for external use
    window.DirectoryBrowser = {
        checkExists: checkDirectoryExists,
        open: openDirectoryBrowser
    };
})();
