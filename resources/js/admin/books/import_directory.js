const state = {
    currentPath: "",
    filterLetter: "",
    searchTerm: "",
};

let searchDebounceId = null;

export function escapeHtml(text) {
    if (typeof text !== "string") {
        return "";
    }
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

export function buildBreadcrumb(path) {
    const $container = $("#directory-path-breadcrumbs");
    if (!$container.length) {
        return;
    }

    const segments = (path || "").split("/").filter(Boolean);
    let accumulated = "";
    const items = ['<a href="#" class="breadcrumb-link" data-path="">Root</a>'];

    segments.forEach((segment) => {
        accumulated += (accumulated ? "/" : "") + segment;
        items.push(
            '<span class="mx-1">/</span><a href="#" class="breadcrumb-link" data-path="' +
                escapeHtml(accumulated) +
                '">' +
                escapeHtml(segment) +
                "</a>",
        );
    });

    $container.html(items.join(""));
}

export function renderDirectoryBrowser(data, path) {
    const $tbody = $("#directory-browser");
    if (!$tbody.length) {
        return;
    }

    const rows = [];
    const directories = Array.isArray(data.directories) ? data.directories : [];
    const files = Array.isArray(data.files) ? data.files : [];

    directories.forEach((dir) => {
        const safeName = escapeHtml(dir.name || "");
        const safePath = escapeHtml(dir.path || "");
        const addUrl =
            "/admin/books/create?path=" + encodeURIComponent(dir.path || "");

        rows.push(
            '<tr class="directory-row">' +
                '<td><a href="#" class="directory-link" data-path="' +
                safePath +
                '">' +
                safeName +
                "</a></td>" +
                "<td>Directory</td>" +
                "<td>" +
                '<button type="button" class="btn btn-sm btn-outline-primary add-book-btn" data-url="' +
                addUrl +
                '"><i class="fas fa-plus me-1"></i>Add Book</button>' +
                "</td>" +
                "</tr>",
        );
    });

    files.forEach((file) => {
        const safeName = escapeHtml(file.name || "");
        const size =
            typeof file.size === "number"
                ? Math.round(file.size / 1024) + " KB"
                : "";
        rows.push(
            '<tr class="file-row">' +
                "<td>" +
                safeName +
                "</td>" +
                "<td>File</td>" +
                '<td><span class="text-muted">' +
                escapeHtml(size) +
                "</span></td>" +
                "</tr>",
        );
    });

    if (!rows.length) {
        rows.push(
            '<tr><td colspan="3" class="text-muted">No items found.</td></tr>',
        );
    }

    $tbody.html(rows.join(""));
    buildBreadcrumb(path);
}

export function loadDirectory(
    path = "",
    updateFilter = true,
    updateHistory = false,
    callback,
) {
    state.currentPath = path;
    if (updateFilter) {
        state.filterLetter = "";
        state.searchTerm = "";
        $("#search-filter").val("");
        $("#letter-filter button").removeClass("active");
        $("#letter-filter button[data-letter='']").addClass("active");
    }

    const ajaxOptions = {
        url: "/admin/directory-browser",
        type: "GET",
        data: {
            path: path,
            filter_letter: state.filterLetter,
            search: state.searchTerm,
        },
        success(response) {
            renderDirectoryBrowser(response || {}, path);
            if (typeof callback === "function") {
                callback(response);
            }
        },
        error(xhr) {
            console.error("Error loading directory", xhr);
            if (typeof callback === "function") {
                callback();
            }
        },
    };

    $.ajax(ajaxOptions);

    if (updateHistory && typeof window.history?.pushState === "function") {
        const url = new URL(window.location.href);
        url.searchParams.set("path", path || "");
        window.history.pushState({ path: path }, "", url);
    }
}

export function handleLetterFilterClick(event) {
    event.preventDefault();
    const letter = $(this).data("letter") || "";
    state.filterLetter = letter;
    $("#letter-filter button").removeClass("active");
    $(this).addClass("active");
    loadDirectory(state.currentPath, false);
}

export function handleSearchInput() {
    const term = $(this).val() || "";
    state.searchTerm = term;
    if (searchDebounceId) {
        clearTimeout(searchDebounceId);
    }
    searchDebounceId = window.setTimeout(() => {
        loadDirectory(state.currentPath, false);
    }, 250);
}

export function handleDirectoryClick(event) {
    event.preventDefault();
    const path = $(this).data("path") || "";
    loadDirectory(path, true, true);
}

export function handleBreadcrumbClick(event) {
    event.preventDefault();
    const path = $(this).data("path") || "";
    loadDirectory(path, true, true);
}

export function handleBulkImportClick(event) {
    event.preventDefault();
    const statusEl = $("#bulk-import-status");
    const currentPath =
        state.currentPath ||
        new URL(window.location.href).searchParams.get("path") ||
        "";
    const token = $('meta[name="csrf-token"]').attr("content") || "";

    if (statusEl.length) {
        statusEl.text("Starting bulk import for " + currentPath + " ...");
    }

    $.ajax({
        url: "/admin/books/bulk-import-dir",
        type: "POST",
        data: {
            dir: currentPath,
            _token: token,
        },
        success(response) {
            if (statusEl.length) {
                statusEl.text(
                    response?.message || "Bulk import started successfully",
                );
            }
        },
        error(xhr) {
            if (statusEl.length) {
                const msg =
                    xhr?.responseJSON?.error ||
                    xhr?.statusText ||
                    "Unknown error";
                statusEl.text("Error: " + msg);
            }
        },
    });
}

export function handleAddBookClick(event) {
    event.preventDefault();
    const url = $(this).data("url");
    if (!url) {
        return;
    }

    const modalEl = document.getElementById("addBookModal");
    if (!modalEl || typeof window.bootstrap === "undefined") {
        return;
    }

    const modalBody = document.getElementById("addBookModalBody");
    if (!modalBody) {
        return;
    }

    modalBody.innerHTML =
        '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';

    $.get(url, function (html) {
        modalBody.innerHTML = html;
        const modalInstance =
            window.bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInstance.show();
    });
}

export function showAlert(message, type) {
    const $container = $("#alerts-container");
    if (!$container.length) {
        console.log("[" + type + "]", message);
        return;
    }

    const $alert = $(
        '<div class="alert alert-' +
            escapeHtml(type || "info") +
            ' alert-dismissible fade show" role="alert">' +
            escapeHtml(message || "") +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
            "</div>",
    );

    $container.append($alert);
    window.setTimeout(() => {
        $alert.alert?.("close");
        $alert.remove();
    }, 3000);
}

export function updateBookAction(bookId, editUrl) {
    if (!bookId) {
        return;
    }
    let $button = $(
        '.edit-book-btn[data-book-id="' + escapeHtml(bookId) + '"]',
    );
    if (!$button.length) {
        const $row = $(
            '<tr class="book-action-row"><td colspan="3" class="text-center"></td></tr>',
        );
        $button = $(
            '<button type="button" class="btn btn-sm btn-primary edit-book-btn" data-book-id="' +
                escapeHtml(bookId) +
                '" data-url="' +
                escapeHtml(editUrl || "") +
                '"><i class="fas fa-edit me-1"></i>Edit</button>',
        );
        $row.find("td").append($button);
        $("#directory-browser").append($row);
    } else {
        $button.attr("data-url", editUrl || "");
    }
}

export function registerEventHandlers() {
    $(document)
        .off("click", ".directory-link")
        .on("click", ".directory-link", handleDirectoryClick);
    $(document)
        .off("click", ".breadcrumb-link")
        .on("click", ".breadcrumb-link", handleBreadcrumbClick);
    $(document)
        .off("click", ".add-book-btn")
        .on("click", ".add-book-btn", handleAddBookClick);
    $("#bulk-import-btn").off("click").on("click", handleBulkImportClick);
    $("#letter-filter")
        .off("click", "button")
        .on("click", "button", handleLetterFilterClick);
    $("#search-filter").off("input").on("input", handleSearchInput);
}

export function initImportDirectory() {
    registerEventHandlers();
    loadDirectory("", true, false);
}

$(function () {
    if (typeof process === "undefined" || process.env.NODE_ENV !== "test") {
        initImportDirectory();
    }
});

// For browser
window.loadDirectory = loadDirectory;
window.renderDirectoryBrowser = renderDirectoryBrowser;
window.showAlert = showAlert;
window.updateBookAction = updateBookAction;
window.importDirectoryModule = {
    loadDirectory,
    renderDirectoryBrowser,
    showAlert,
    updateBookAction,
    handleLetterFilterClick,
    handleSearchInput,
};
