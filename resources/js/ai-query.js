let currentQueryId = null;
let currentOperationType = null;
let currentQueries = [];
let selectedBookIds = new Set();

document.addEventListener("DOMContentLoaded", function () {
    const toggleBtn = document.getElementById("toggle-ai-prompt");
    const promptBody = document.getElementById("ai-prompt-body");
    const submitBtn = document.getElementById("submit-ai-query");
    const queryInput = document.getElementById("ai-query-input");
    const viewQueriesBtn = document.getElementById("view-queries-btn");
    const saveQueriesBtn = document.getElementById("save-queries-btn");
    const executeBtn = document.getElementById("execute-query-btn");
    const closeResultsBtn = document.getElementById("close-ai-results");
    const resultsOverlay = document.getElementById("ai-results-overlay");

    if (toggleBtn) {
        toggleBtn.addEventListener("click", function () {
            const isVisible = promptBody.style.display !== "none";
            promptBody.style.display = isVisible ? "none" : "block";
            toggleBtn.innerHTML = isVisible
                ? '<i class="fas fa-chevron-down"></i>'
                : '<i class="fas fa-chevron-up"></i>';
        });
    }

    if (closeResultsBtn) {
        closeResultsBtn.addEventListener("click", function () {
            resultsOverlay.style.display = "none";
            document.body.style.overflow = "auto";
        });
    }

    // Close overlay when clicking outside modal
    if (resultsOverlay) {
        resultsOverlay.addEventListener("click", function (e) {
            if (e.target === resultsOverlay) {
                resultsOverlay.style.display = "none";
                document.body.style.overflow = "auto";
            }
        });
    }

    if (submitBtn) {
        submitBtn.addEventListener("click", submitQuery);
    }

    if (queryInput) {
        queryInput.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && e.ctrlKey) {
                submitQuery();
            }
        });
    }

    if (viewQueriesBtn) {
        viewQueriesBtn.addEventListener("click", function () {
            const editor = document.getElementById("query-editor");
            editor.style.display =
                editor.style.display === "none" ? "block" : "none";
        });
    }

    if (saveQueriesBtn) {
        saveQueriesBtn.addEventListener("click", saveQueryChanges);
    }

    if (executeBtn) {
        executeBtn.addEventListener("click", executeQuery);
    }
});

async function submitQuery() {
    const queryInput = document.getElementById("ai-query-input");
    const prompt = queryInput.value.trim();

    if (!prompt) {
        showError("Please enter a query");
        return;
    }

    showLoading(true);
    hideError();
    hideResults();

    try {
        const response = await fetch("/admin/ai-query/process", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
            body: JSON.stringify({ prompt }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error("Network error: Unable to connect to server");
        }

        if (!data.success) {
            const errorMessage = data.error || "Unknown error occurred";
            console.error("[AI Query] Processing failed:", errorMessage);
            throw new Error(errorMessage);
        }

        currentQueryId = data.query_id;
        currentOperationType = data.operation_type;
        currentQueries = data.queries;

        displayQueryInfo(data);
    } catch (error) {
        showError(error.message);
    } finally {
        showLoading(false);
    }
}

async function executeQuery() {
    if (!currentQueryId) {
        showError("No query to execute");
        return;
    }

    showLoading(true);
    hideError();

    try {
        const response = await fetch("/admin/ai-query/execute", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
            body: JSON.stringify({ query_id: currentQueryId }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error("Network error: Unable to connect to server");
        }

        if (!data.success) {
            const errorMessage = data.error || "Unknown error occurred";
            console.error("[AI Query] Execution failed:", errorMessage);
            throw new Error(errorMessage);
        }

        displayResults(data.data);
    } catch (error) {
        showError(error.message);
    } finally {
        showLoading(false);
    }
}

async function applyBulkUpdate() {
    if (!currentQueryId || selectedBookIds.size === 0) {
        showError("No items selected");
        return;
    }

    if (!confirm(`Apply changes to ${selectedBookIds.size} item(s)?`)) {
        return;
    }

    showLoading(true);
    hideError();

    try {
        const response = await fetch("/admin/ai-query/apply-bulk-update", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
            body: JSON.stringify({
                query_id: currentQueryId,
                selected_ids: Array.from(selectedBookIds),
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error("Network error: Unable to connect to server");
        }

        if (!data.success) {
            const errorMessage = data.error || "Unknown error occurred";
            console.error("[AI Query] Bulk update failed:", errorMessage);
            throw new Error(errorMessage);
        }

        showSuccess(
            `Successfully applied changes to ${data.applied_count} item(s)`,
        );
        setTimeout(() => {
            window.location.reload();
        }, 2000);
    } catch (error) {
        showError(error.message);
    } finally {
        showLoading(false);
    }
}

async function saveQueryChanges() {
    if (!currentQueryId) {
        showError("No query to save");
        return;
    }

    const updatedQueries = [];
    const queryItems = document.querySelectorAll(".query-item");

    queryItems.forEach((item, index) => {
        updatedQueries.push({
            type: currentQueries[index].type,
            query: item.querySelector("textarea").value,
            purpose: item.querySelector(".query-purpose").textContent,
        });
    });

    try {
        const response = await fetch("/admin/ai-query/edit", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
            body: JSON.stringify({
                query_id: currentQueryId,
                queries: updatedQueries,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error("Network error: Unable to connect to server");
        }

        if (!data.success) {
            const errorMessage = data.error || "Unknown error occurred";
            console.error("[AI Query] Save failed:", errorMessage);
            throw new Error(errorMessage);
        }

        currentQueries = updatedQueries;
        showSuccess("Queries updated successfully");
    } catch (error) {
        showError(error.message);
    }
}

function displayQueryInfo(data) {
    document.getElementById("operation-type").textContent = data.operation_type;
    document.getElementById("explanation").textContent = data.explanation;

    const queriesContainer = document.getElementById("queries-container");
    queriesContainer.innerHTML = "";

    data.queries.forEach((query, index) => {
        const queryDiv = document.createElement("div");
        queryDiv.className = "query-item";
        queryDiv.innerHTML = `
            <div class="mb-2">
                <strong>Query ${index + 1}:</strong>
                <span class="badge bg-secondary">${query.type}</span>
            </div>
            <div class="mb-2 query-purpose"><small>${query.purpose}</small></div>
            <textarea class="form-control" rows="3">${query.query}</textarea>
        `;
        queriesContainer.appendChild(queryDiv);
    });

    document.getElementById("ai-query-results").style.display = "block";
}

function displayResults(data) {
    const resultsDiv = document.getElementById("ai-results-display");
    const overlay = document.getElementById("ai-results-overlay");
    resultsDiv.innerHTML = "";

    if (currentOperationType === "research") {
        displayResearchResults(data, resultsDiv);
    } else if (currentOperationType === "list") {
        displayListResults(data, resultsDiv);
    } else if (currentOperationType === "bulk_update") {
        displayBulkUpdateResults(data, resultsDiv);
    }

    // Show overlay modal
    overlay.style.display = "block";
    document.body.style.overflow = "hidden";
}

function displayResearchResults(data, container) {
    const html = `
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Research Results</h5>
            </div>
            <div class="card-body">
                ${data.stats
                    .map(
                        (stat) => `
                    <div class="mb-3">
                        <h6>${stat.purpose}</h6>
                        ${
                            stat.error
                                ? `<div class="alert alert-danger">${stat.error}</div>`
                                : `
                            <pre class="bg-light p-3 rounded">${JSON.stringify(stat.result, null, 2)}</pre>
                        `
                        }
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>
    `;

    container.innerHTML = html;
}

function displayListResults(data, container) {
    const html = `
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-list"></i> Book List (${data.books.length} results)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Authors</th>
                                <th>Genres</th>
                                <th>Series</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.books
                                .map(
                                    (book) => `
                                <tr>
                                    <td>${book.title}</td>
                                    <td>${Array.isArray(book.authors) ? book.authors.join(", ") : book.authors}</td>
                                    <td>${Array.isArray(book.genres) ? book.genres.join(", ") : book.genres}</td>
                                    <td>${book.series || "-"}</td>
                                    <td>
                                        <a href="${book.edit_url}" class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            `,
                                )
                                .join("")}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    container.innerHTML = html;
}

function displayBulkUpdateResults(data, container) {
    selectedBookIds.clear();

    const html = `
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="fas fa-edit"></i> Bulk Update Preview (${data.preview.length} items)</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                        <i class="fas fa-check-square"></i> Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectNone()">
                        <i class="fas fa-square"></i> Select None
                    </button>
                    <button type="button" class="btn btn-sm btn-success ms-3" onclick="applyBulkUpdate()">
                        <i class="fas fa-save"></i> Apply Selected Changes
                    </button>
                </div>

                <div class="bulk-update-preview">
                    ${data.preview
                        .map(
                            (item) => `
                        <div class="book-card" data-book-id="${item.id}">
                            <div class="form-check mb-2">
                                <input
                                    class="form-check-input book-checkbox"
                                    type="checkbox"
                                    id="book-${item.id}"
                                    data-book-id="${item.id}"
                                    onchange="toggleBookSelection(${item.id})"
                                >
                                <label class="form-check-label fw-bold" for="book-${item.id}">
                                    ${item.before.title}
                                </label>
                            </div>

                            ${
                                Object.keys(item.changes).length > 0
                                    ? `
                                <div class="changes-list">
                                    ${Object.entries(item.changes)
                                        .map(
                                            ([field, change]) => `
                                        <div class="mb-2">
                                            <strong>${field}:</strong><br>
                                            <span class="change-badge change-from">
                                                From: ${Array.isArray(change.from) ? change.from.join(", ") : change.from}
                                            </span>
                                            <i class="fas fa-arrow-right"></i>
                                            <span class="change-badge change-to">
                                                To: ${Array.isArray(change.to) ? change.to.join(", ") : change.to}
                                            </span>
                                        </div>
                                    `,
                                        )
                                        .join("")}
                                </div>
                            `
                                    : '<p class="text-muted mb-0">No changes</p>'
                            }
                        </div>
                    `,
                        )
                        .join("")}
                </div>
            </div>
        </div>
    `;

    container.innerHTML = html;
}

function toggleBookSelection(bookId) {
    const checkbox = document.getElementById(`book-${bookId}`);
    const card = checkbox.closest(".book-card");

    if (checkbox.checked) {
        selectedBookIds.add(bookId);
        card.classList.add("selected");
    } else {
        selectedBookIds.delete(bookId);
        card.classList.remove("selected");
    }
}

function selectAll() {
    document.querySelectorAll(".book-checkbox").forEach((checkbox) => {
        checkbox.checked = true;
        const bookId = parseInt(checkbox.dataset.bookId);
        selectedBookIds.add(bookId);
        checkbox.closest(".book-card").classList.add("selected");
    });
}

function selectNone() {
    document.querySelectorAll(".book-checkbox").forEach((checkbox) => {
        checkbox.checked = false;
        const bookId = parseInt(checkbox.dataset.bookId);
        selectedBookIds.delete(bookId);
        checkbox.closest(".book-card").classList.remove("selected");
    });
}

function showLoading(show) {
    document.getElementById("ai-query-loading").style.display = show
        ? "block"
        : "none";
}

function hideResults() {
    document.getElementById("ai-query-results").style.display = "none";
    document.getElementById("ai-results-overlay").style.display = "none";
    document.body.style.overflow = "auto";
}

function showError(message) {
    const errorDiv = document.getElementById("ai-query-error");
    errorDiv.textContent = message;
    errorDiv.style.display = "block";
}

function hideError() {
    document.getElementById("ai-query-error").style.display = "none";
}

function showSuccess(message) {
    const resultsDiv = document.getElementById("ai-results-display");
    const overlay = document.getElementById("ai-results-overlay");
    const successDiv = document.createElement("div");
    successDiv.className = "alert alert-success";
    successDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
    resultsDiv.innerHTML = "";
    resultsDiv.appendChild(successDiv);
    overlay.style.display = "block";
    document.body.style.overflow = "hidden";
}
