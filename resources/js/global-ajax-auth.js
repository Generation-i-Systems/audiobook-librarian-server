// Global AJAX 401 handler for all jQuery AJAX requests
$(document).ajaxError(function(event, jqxhr, settings, thrownError) {
    if (jqxhr.status === 401) {
        // Optionally, show a notification
        window.location = '/login'; // Laravel default login route
    }
});
