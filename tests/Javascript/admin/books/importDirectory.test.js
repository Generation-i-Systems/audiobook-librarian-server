/**
 * Tests for the import directory functionality in the admin panel
 */
import { jest } from "@jest/globals";
import $ from "jquery";
import * as importDir from "@/admin/books/import_directory.js";

describe("Import Directory", () => {
    let testData;

    beforeAll(() => {
        // Mock Bootstrap modal
        $.fn.modal = jest.fn(function () {
            return this;
        });

        // Mock bootstrap global
        global.bootstrap = {
            Modal: {
                getOrCreateInstance: jest.fn().mockReturnValue({
                    show: jest.fn(),
                }),
            },
        };

        // Mock the CSRF token
        document.body.innerHTML = `
            <meta name="csrf-token" content="test-csrf-token">
            <div id="test-container">
                <div id="directory-path-breadcrumbs" class="breadcrumb-container"></div>
                <div id="letter-filter" class="btn-group">
                    <button data-letter="">All</button>
                    <button data-letter="A">A</button>
                    <button data-letter="B">B</button>
                </div>
                <input type="text" id="search-filter" />
                <button id="bulk-import-btn">Bulk Import</button>
                <span id="bulk-import-status"></span>
                <div id="directory-loading-spinner" style="display: none;">Loading...</div>
                <table id="directory-browser-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="directory-browser"></tbody>
                </table>
            </div>
            <!--Modals-->
            <div class="modal" id="addBookModal">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-body" id="addBookModalBody"></div>
                    </div>
                </div>
            </div>
        `;

        // Mock test data
        testData = {
            directories: [
                { name: "Authors", type: "dir", path: "/path/to/Authors" },
                {
                    name: "Stephen King",
                    type: "dir",
                    path: "/path/to/Authors/Stephen King",
                },
            ],
            files: [
                {
                    name: "book1.mp3",
                    type: "file",
                    path: "/path/to/book1.mp3",
                    size: 1024 * 1024,
                },
            ],
        };
    });

    beforeEach(() => {
        jest.clearAllMocks();

        // Mock AJAX
        $.ajax = jest.fn().mockImplementation((options) => {
            if (options.url.includes("directory-browser")) {
                options.success({
                    success: true,
                    directories: testData.directories,
                    files: testData.files,
                });
            } else if (options.url.includes("bulk-import-dir")) {
                options.success({
                    success: true,
                    message: "Bulk import started successfully",
                });
            }
            return Promise.resolve();
        });

        // Reset the DOM
        $("#directory-browser").empty();
        $("#directory-path-breadcrumbs").empty();
        $("#bulk-import-status").text("");

        // Re-register handlers
        importDir.registerEventHandlers();
    });

    describe("Directory Browsing", () => {
        test("should load directory via manual call", (done) => {
            importDir.loadDirectory("", true, false, () => {
                expect($.ajax).toHaveBeenCalledWith(
                    expect.objectContaining({
                        url: expect.stringContaining("directory-browser"),
                        data: expect.objectContaining({ path: "" }),
                    }),
                );

                const items = $("#directory-browser tr");
                expect(items.length).toBeGreaterThan(0);
                done();
            });
        });

        test("should navigate into subdirectory when clicked", (done) => {
            importDir.loadDirectory("", true, false, () => {
                // Clear previous call from loadDirectory
                $.ajax.mockClear();

                const $dirLink = $('a.directory-link:contains("Stephen King")');
                expect($dirLink.length).toBe(1);

                $dirLink.click();

                setTimeout(() => {
                    expect($.ajax).toHaveBeenCalledWith(
                        expect.objectContaining({
                            data: expect.objectContaining({
                                path: "/path/to/Authors/Stephen King",
                            }),
                        }),
                    );
                    done();
                }, 50);
            });
        });
    });

    describe("Filtering", () => {
        test("should filter by first letter", (done) => {
            importDir.loadDirectory("", true, false, () => {
                $('[data-letter="A"]').click();

                setTimeout(() => {
                    expect($.ajax).toHaveBeenLastCalledWith(
                        expect.objectContaining({
                            data: expect.objectContaining({
                                filter_letter: "A",
                            }),
                        }),
                    );
                    done();
                }, 50);
            });
        });
    });

    describe("Bulk Import", () => {
        test("should trigger bulk import for current directory", (done) => {
            $("#bulk-import-btn").click();

            setTimeout(() => {
                expect($.ajax).toHaveBeenCalledWith(
                    expect.objectContaining({
                        url: expect.stringContaining("bulk-import-dir"),
                        type: "POST",
                    }),
                );
                expect($("#bulk-import-status").text()).toContain(
                    "successfully",
                );
                done();
            }, 50);
        });
    });
});
