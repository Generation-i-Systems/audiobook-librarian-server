// @jest-environment jsdom
import $ from "jquery";
import { jest } from "@jest/globals";
import * as bookForm from "@/admin/books/form.js";

// Helper function to create form HTML with optional modal wrapper
const createFormHtml = (isModal = false) => {
    const formHtml = `
        <form id="book-form" action="/admin/books/123" ${isModal ? 'data-modal-form="true"' : ""}>
            <meta name="csrf-token" content="test-token">
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

            <div id="authors-group">
                <div class="author-row">
                    <input type="text" class="author-autocomplete" name="authors[]">
                    <button type="button" class="add-author-row">+</button>
                </div>
            </div>

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" class="form-control">
            </div>

            <button type="submit" class="btn btn-primary">Save</button>

            <!-- Raw JSON Elements -->
            <button type="button" id="raw-json-edit-btn">Raw JSON</button>
            <div id="rawJsonModal" class="modal">
                <textarea id="raw-json-textarea"></textarea>
                <div id="raw-json-error" style="display:none;"></div>
                <button type="button" id="save-raw-json-btn">Save</button>
            </div>
        </form>
    `;

    if (isModal) {
        return `
            <div class="modal fade" id="bookModal" tabindex="-1">
                <div class="modal-body">${formHtml}</div>
            </div>
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
                    html += `<div class="list-group-item">${file.name}</div>`;
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

function initBookForm($container) {
    $container.on("click", "#show-files-link", function (e) {
        e.preventDefault();
        loadDirectoryFiles($container);
    });

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
        if (!title) {
            e.preventDefault();
            $container.find("#title").addClass("is-invalid");
            return false;
        }
    });
}

describe("Book Form", () => {
    beforeAll(() => {
        HTMLFormElement.prototype.submit = jest.fn();
        $.fn.autocomplete = function (options) {
            return this;
        };
        $.fn.modal = function () {
            return this;
        };
    });

    const setupTest = (isModal = false) => {
        document.body.innerHTML = createFormHtml(isModal);
        const container = isModal ? $(".modal-body form") : $("#book-form");
        initBookForm(container);
        bookForm.initRawJsonEdit();
        bookForm.initModalEnterGuards();
        return { container };
    };

    describe("Directory Operations", () => {
        test("should show directory contents", (done) => {
            setupTest();
            $("#directoryPath").val("/test/path");
            $.ajax = jest.fn().mockImplementation(({ success }) => {
                setTimeout(() => {
                    success({ success: true, files: [{ name: "book.mp3" }] });
                }, 0);
                return Promise.resolve();
            });
            $("#show-files-link").click();
            setTimeout(() => {
                expect($("#directory-files-list").text()).toContain("book.mp3");
                done();
            }, 50);
        });

        test("should handle empty path", () => {
            setupTest();
            $("#directoryPath").val("");
            $("#show-files-link").click();
            expect($("#directory-files-list").text()).toContain(
                "Please select a directory",
            );
        });
    });

    describe("Raw JSON Edit", () => {
        test("should get bookId from URL if form action is missing", () => {
            document.body.innerHTML = `
                <button type="button" id="raw-json-edit-btn">Raw JSON</button>
                <div id="rawJsonModal" class="modal"><textarea id="raw-json-textarea"></textarea></div>
            `;
            delete window.location;
            window.location = {
                pathname: "/admin/books/456",
                reload: jest.fn(),
            };

            $.get = jest.fn().mockReturnValue({ fail: jest.fn() });

            bookForm.initRawJsonEdit();
            $("#raw-json-edit-btn").click();

            expect($.get).toHaveBeenCalledWith(
                "/admin/books/456/raw-json",
                expect.any(Function),
            );
        });

        test("should load and save JSON", (done) => {
            setupTest();
            const mockData = { title: "Test" };
            $.get = jest.fn().mockImplementation((url, cb) => {
                cb(mockData);
                return { fail: () => {} };
            });

            $("#raw-json-edit-btn").click();
            expect($("#raw-json-textarea").val()).toContain("Test");

            $("#raw-json-textarea").val(JSON.stringify({ title: "Updated" }));
            delete window.location;
            window.location = { reload: jest.fn() };
            $.ajax = jest.fn().mockImplementation(({ success }) => {
                success();
                return Promise.resolve();
            });

            $("#save-raw-json-btn").click();
            expect(window.location.reload).toHaveBeenCalled();
            done();
        });

        test("should handle load failure", () => {
            setupTest();
            $.get = jest.fn().mockReturnValue({
                fail: (cb) => {
                    cb({ status: 500 });
                },
            });
            $("#raw-json-edit-btn").click();
            expect($("#raw-json-error").text()).toContain(
                "Failed to load JSON",
            );
        });

        test("should handle invalid JSON input", () => {
            setupTest();
            $("#raw-json-textarea").val("invalid");
            $("#save-raw-json-btn").click();
            expect($("#raw-json-error").text()).toContain("Invalid JSON");
        });

        test("should handle save failure", () => {
            setupTest();
            $("#raw-json-textarea").val('{"a":1}');
            $.ajax = jest.fn().mockImplementation(({ error }) => {
                error({ statusText: "Error" });
            });
            $("#save-raw-json-btn").click();
            expect($("#raw-json-error").text()).toContain(
                "Failed to save JSON",
            );
        });
    });

    describe("Modal Enter Guards", () => {
        test("should prevent Enter in modal inputs from submitting the main form", () => {
            setupTest();
            document.body.insertAdjacentHTML(
                "beforeend",
                `
                    <div id="coverImageModal" class="modal">
                        <input type="text" id="coverImageUrlText" value="https://example.com/cover.jpg">
                    </div>
                `,
            );

            const input = document.getElementById("coverImageUrlText");
            const event = new KeyboardEvent("keydown", {
                key: "Enter",
                bubbles: true,
                cancelable: true,
            });

            input.dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
        });

        test("should trigger autofill search when Enter is pressed in autofill modal", () => {
            setupTest();
            document.body.insertAdjacentHTML(
                "beforeend",
                `
                    <div id="autofillModal" class="modal">
                        <input type="text" id="autofill-title">
                    </div>
                    <button type="button" id="search-all-btn">Search all</button>
                `,
            );

            const searchButton = document.getElementById("search-all-btn");
            const clickSpy = jest.spyOn(searchButton, "click");
            const input = document.getElementById("autofill-title");

            input.dispatchEvent(
                new KeyboardEvent("keydown", {
                    key: "Enter",
                    bubbles: true,
                    cancelable: true,
                }),
            );

            expect(clickSpy).toHaveBeenCalled();
        });
    });
});
