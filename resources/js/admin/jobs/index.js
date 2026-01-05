document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh the page every 30 seconds if there are any processing/queued jobs
    const shouldAutoRefresh = document.body.getAttribute('data-auto-refresh') === 'true';
    
    if (shouldAutoRefresh) {
        setTimeout(function() {
            window.location.reload();
        }, 30000);
    }
});
