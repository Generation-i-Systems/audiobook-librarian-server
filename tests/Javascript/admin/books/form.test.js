// @jest-environment jsdom
import $ from "jquery";
import { jest } from "@jest/globals";

// Helper function to create form HTML with optional modal wrapper
const createFormHtml = (isModal = false) => {
    const formHtml = `
        <form id="book-form" ${isModal ? 'data-modal-form="true"' : ""}>
            <!--Directory Path-->
            <div class="form-group">
                <label for="directoryPath">Directory Path</label>
                <div class="input-group">
                    <input type="text" id="directoryPath" class="form-control">
                    <a href="#" id="show-files-link">
                        <i class="fas fa-folder-open me-1"></i>
                        View Directory Files
                    </a>
                </div>
                <div id="directory-files-list" style="display:none;"></div>
            </div>

            <!--Authors-->
            <div class="form-group">
                <label>Authors</label>
                <div id="authors-group">
                    <div class="author-row mb-2 d-flex align-items-center">
                        <input type="text" class="form-control author-autocomplete" name="authors[]" placeholder="Author name">
                        <button type="button" class="btn btn-outline-success btn-sm ms-2 add-author-row">+</button>
                    </div>
                </div>
            </div>

            <!--Series-->
            <div class="form-group">
                <label>Series</label>
                <div id="series-group">
                    <div class="series-row mb-2 d-flex align-items-center">
                        <input type="text" class="form-control series-autocomplete me-2" name="series_names[]" placeholder="Series name">
                        <input type="number" class="form-control me-2" name="series_numbers[]" placeholder="Book #" min="1" step="1" style="width: 80px;">
                        <button type="button" class="btn btn-outline-success btn-sm add-series-row">+</button>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" class="form-control" placeholder="Book Title">
            </div>

            <div class="form-group">
                <label for="release_date">Release Date</label>
                <input type="date" id="release_date" name="release_date" class="form-control">
            </div>

            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    `;

    if (isModal) {
        const title = isModal === "edit" ? "Edit Book" : "Add New Book";
        return `
            <div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${title}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">${formHtml}</div>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookModal">Open Modal</button>
        `;
    }

    return formHtml;
};

// Simple implementation of functions for testing
function loadDirectoryFiles($container) {
    const dirPath = $container.find("#directoryPath").val();
    const filesList = $container.find("#directory-files-list");
    const $viewFilesBtn = $container.find("#show-files-link");

    if (!dirPath) {
        filesList
            .html(
                '<div class="p-3 text-danger">Please select a directory first.</div>',
            )
            .show();
        return;
    }

    const originalBtnHtml = $viewFilesBtn.html();
    $viewFilesBtn.html('<i class="fas fa-spinner fa-spin me-1"></i>Loading...');
    filesList
        .html('<div class="text-center p-3">Loading files...</div>')
        .show();

    $.ajax({
        url: "/admin/files-ajax",
        data: { path: dirPath },
        success: (response) => {
            if (response.success) {
                let html = '<div class="list-group">';
                response.files.forEach((file) => {
                    const typeAttr =
                        file.type === "dir"
                            ? "dir"
                            : file.name.match(/\.(mp3|m4b)$/i)
                              ? "audio"
                              : "image";
                    html += `<div class="list-group-item" data-type="${typeAttr}">${file.name}</div>`;
                });
                html += "</div>";
                filesList.html(html);
            } else {
                filesList.html(
                    '<div class="text-danger">Error loading directory</div>',
                );
            }
            $viewFilesBtn.html(originalBtnHtml);
        },
        error: () => {
            filesList.html(
                '<div class="text-danger">Error loading directory</div>',
            );
            $viewFilesBtn.html(originalBtnHtml);
        },
    });
}

function initializeAutocomplete($container) {
    $container.find(".author-autocomplete").autocomplete({
        source: ["Stephen King", "J.K. Rowling"],
        minLength: 2,
    });
}

function initBookForm($container) {
    $container.on("click", "#show-files-link", function (e) {
        e.preventDefault();
        loadDirectoryFiles($container);
    });

    initializeAutocomplete($container);

    $container.on("click", ".add-author-row", function (e) {
        e.preventDefault();
        $container
            .find("#authors-group")
            .append(
                '<div class="author-row"><input class="author-autocomplete"></div>',
            );
    });

    $container.on("submit", function (e) {
        const title = $container.find("#title").val();
        const author = $container.find(".author-autocomplete").first().val();

        if (!title || !author) {
            e.preventDefault();
            if (!title) $container.find("#title").addClass("is-invalid");
            if (!author)
                $container
                    .find(".author-autocomplete")
                    .first()
                    .addClass("is-invalid");
            return false;
        }
    });
}

describe("Book Form", () => {
    beforeAll(() => {
        // Stub HTMLFormElement.prototype.submit since JSDOM doesn't implement it
        HTMLFormElement.prototype.submit = jest.fn();

        $.fn.autocomplete = function (options) {
            this.data("ui-autocomplete", { options });
            return this;
        };
        $.fn.modal = function () {
            return this;
        };
    });

    const setupTest = (isModal = false, mode = "add") => {
        document.body.innerHTML = createFormHtml(isModal ? mode : false);
        const container = isModal ? $(".modal-body form") : $("#book-form");
        initBookForm(container);
        return { container };
    };

    describe("Directory and File Operations", () => {
        test("should show directory contents when directory is selected", (done) => {
            const { container } = setupTest();
            $("#directoryPath").val("/test/path");

            $.ajax = jest.fn().mockImplementation(({ success }) => {
                setTimeout(() => {
                    success({
                        success: true,
                        files: [{ name: "book1.mp3", type: "file" }],
                    });
                }, 0);
                return Promise.resolve();
            });

            $("#show-files-link").click();
            expect($("#show-files-link").html()).toContain("fa-spinner");

            setTimeout(() => {
                expect($("#directory-files-list").text()).toContain(
                    "book1.mp3",
                );
                done();
            }, 50);
        });

        test("should handle AJAX errors when loading directory", (done) => {
            const { container } = setupTest();
            $("#directoryPath").val("/invalid");

            $.ajax = jest.fn().mockImplementation(({ error }) => {
                setTimeout(() => {
                    error();
                }, 0);
                return Promise.resolve({ success: false });
            });

            $("#show-files-link").click();

            setTimeout(() => {
                expect($("#directory-files-list").text()).toContain(
                    "Error loading directory",
                );
                done();
            }, 50);
        });
    });

    describe("Form Submission", () => {
        test("should validate required fields", () => {
            const { container } = setupTest();
            $("#title").val("");

            const event = $.Event("submit");
            container.trigger(event);

            expect($("#title").hasClass("is-invalid")).toBe(true);
        });

        test("should allow submission with valid data", () => {
            const { container } = setupTest();
            $("#title").val("Valid Title");
            $(".author-autocomplete").val("Valid Author");

            const event = $.Event("submit");
            container.trigger(event);

            expect($("#title").hasClass("is-invalid")).toBe(false);
        });
    });

    describe("UI Interactions", () => {
        test("should add new author row when clicking add button", () => {
            const { container } = setupTest();
            const initialCount = $(".author-row").length;
            $(".add-author-row").click();
            expect($(".author-row").length).toBe(initialCount + 1);
        });
    });
});
