/**
 * Series Rename Functionality
 * Handles renaming a series across all books
 */

(function() {
    'use strict';

    let renameSeriesUrl = '';

    // Initialize series rename
    function initSeriesRename() {
        renameSeriesUrl = window.BOOK_FORM_ROUTES?.renameSeries || '/admin/books/rename-series';

        // Handle rename series button click
        $('#rename-series-btn').on('click', function(e) {
            e.preventDefault();
            openRenameSeriesModal();
        });

        // Handle confirm rename button
        $('#confirm-rename-series-btn').on('click', function() {
            renameSeries();
        });

        // Clear feedback when modal is closed
        $('#renameSeriesModal').on('hidden.bs.modal', function() {
            $('#new-series-name').val('');
            $('#rename-series-feedback').html('');
        });
    }

    // Open rename series modal
    function openRenameSeriesModal() {
        // Get the first series name from the form
        const firstSeriesInput = $('input[name^="series"][name$="[seriesName]"]').first();
        const currentSeriesName = firstSeriesInput.val();

        if (!currentSeriesName) {
            alert('No series name found to rename.');
            return;
        }

        $('#old-series-name').val(currentSeriesName);
        $('#new-series-name').val('');
        $('#rename-series-feedback').html('');

        const modal = new bootstrap.Modal(document.getElementById('renameSeriesModal'));
        modal.show();
    }

    // Rename series
    function renameSeries() {
        const oldName = $('#old-series-name').val();
        const newName = $('#new-series-name').val().trim();

        if (!newName) {
            showFeedback('Please enter a new series name.', 'danger');
            return;
        }

        if (oldName === newName) {
            showFeedback('New name must be different from current name.', 'danger');
            return;
        }

        const confirmBtn = $('#confirm-rename-series-btn');
        const originalText = confirmBtn.html();
        confirmBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Renaming...');

        $.ajax({
            url: renameSeriesUrl,
            method: 'POST',
            data: {
                oldName: oldName,
                newName: newName,
                _token: $('input[name="_token"]').val()
            },
            dataType: 'json',
            success: function(response) {
                confirmBtn.prop('disabled', false).html(originalText);

                if (response.success) {
                    showFeedback(
                        `Successfully renamed series "${oldName}" to "${newName}" for ${response.count} book(s).`,
                        'success'
                    );

                    // Update the series name in the form
                    $('input[name^="series"][name$="[seriesName]"]').each(function() {
                        if ($(this).val() === oldName) {
                            $(this).val(newName);
                        }
                    });

                    // Close modal after 2 seconds
                    setTimeout(function() {
                        bootstrap.Modal.getInstance(document.getElementById('renameSeriesModal')).hide();
                    }, 2000);
                } else {
                    showFeedback(response.message || 'Failed to rename series.', 'danger');
                }
            },
            error: function(xhr, status, error) {
                confirmBtn.prop('disabled', false).html(originalText);
                console.error('Error renaming series:', error);
                showFeedback('An error occurred while renaming the series.', 'danger');
            }
        });
    }

    // Show feedback message
    function showFeedback(message, type) {
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        $('#rename-series-feedback').html(`
            <div class="alert ${alertClass} mb-0">
                ${message}
            </div>
        `);
    }

    // Initialize on document ready
    $(document).ready(function() {
        if ($('#rename-series-btn').length) {
            initSeriesRename();
        }
    });
})();
