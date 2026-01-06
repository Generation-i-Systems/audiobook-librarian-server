// Global AJAX 401 handler for all AJAX requests
document.addEventListener("DOMContentLoaded", function () {
    // Set up global AJAX error handler
    const originalFetch = window.fetch;

    window.fetch = function (url, options = {}) {
        // Merge with default headers for CSRF protection
        const defaultOptions = {
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN":
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute("content") || "",
            },
        };

        const mergedOptions = { ...defaultOptions, ...options };

        // Call original fetch
        return originalFetch(url, mergedOptions)
            .then((response) => {
                // Handle 401 unauthorized responses
                if (response.status === 401) {
                    // Optionally, show a notification
                    window.location = "/login"; // Laravel default login route
                }
                return response;
            })
            .catch((error) => {
                // Handle network errors that might be 401s
                if (error.message && error.message.includes("401")) {
                    window.location = "/login";
                }
                throw error;
            });
    };

    // Add global error event listener for better debugging
    window.addEventListener("error", function (event) {
        // Check if it's an AJAX-related error
        if (
            event.target &&
            (event.target.tagName === "A" || event.target.tagName === "FORM")
        ) {
            console.error("Global error handler caught:", event);
        }
    });
});
