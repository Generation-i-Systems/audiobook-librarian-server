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
                    hide: jest.fn(),
                }),
            },
        };

        // Mock the CSRF token
        document.body.innerHTML = `
            <meta name="csrf-token" content="test-csrf-token">
            <div id="directory-path-breadcrumbs"></div>
            <div id="letter-filter">
                <button data-letter="">All</button>
                <button data-letter="A">A</button>
            </div>
            <input type="text" id="search-filter" />
            <button id="bulk-import-btn">Bulk Import</button>
            <span id="bulk-import-status"></span>
            <table id="directory-browser-table">
                <tbody id="directory-browser"></tbody>
            </table>
            <div id="addBookModal"><div id="addBookModalBody"></div></div>
            <div id="alerts-container"></div>
        `;

        testData = {
            directories: [
                { name: "Authors", path: "Authors" },
                { name: "Empty", path: "Empty" },
            ],
            files: [
                { name: "book.mp3", size: 1024 },
                { name: "cover.jpg", size: 512 },
            ],
        };
    });

    beforeEach(() => {
        jest.clearAllMocks();
        // Default mock implementation
        $.ajax = jest.fn().mockImplementation((opts) => {
            if (opts.success) opts.success(testData);
            return Promise.resolve(testData);
        });
        importDir.registerEventHandlers();
        // Reset state between tests
        $("#search-filter").val("");
        $("#directory-browser").empty();
        $("#bulk-import-status").text("");
    });

    describe("Core Logic", () => {
        test("should load directory", (done) => {
            importDir.loadDirectory("test", true, false, (data) => {
                expect($.ajax).toHaveBeenCalled();
                expect($("#directory-browser").text()).toContain("Authors");
                done();
            });
        });

        test("should navigate on click", () => {
            importDir.renderDirectoryBrowser(testData, "");
            $(".directory-link").first().click();
            expect($.ajax).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: expect.objectContaining({ path: "Authors" }),
                }),
            );
        });

        test("should filter by letter", () => {
            $('[data-letter="A"]').click();
            expect($.ajax).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: expect.objectContaining({ filter_letter: "A" }),
                }),
            );
        });

        test("should debounce search", (done) => {
            $.ajax.mockClear();
            $("#search-filter").val("abc").trigger("input");

            expect($.ajax).not.toHaveBeenCalled();

            setTimeout(() => {
                expect($.ajax).toHaveBeenCalledWith(
                    expect.objectContaining({
                        data: expect.objectContaining({ search: "abc" }),
                    }),
                );
                done();
            }, 500);
        });

        test("should handle empty response in render", () => {
            importDir.renderDirectoryBrowser({}, "");
            expect($("#directory-browser").text()).toContain("No items found");
        });

        test("should build complex breadcrumbs", () => {
            importDir.buildBreadcrumb("Author/Series/Book");
            const links = $(".breadcrumb-link");
            expect(links.length).toBe(4); // Root + 3 segments
            expect($(links[1]).text()).toBe("Author");
            expect($(links[3]).text()).toBe("Book");

            $(links[1]).click();
            expect($.ajax).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: expect.objectContaining({ path: "Author" }),
                }),
            );
        });
    });

    describe("Edge Cases and Branches", () => {
        test("loadDirectory should handle missing callback and history", () => {
            const oldHistory = window.history;
            delete window.history;

            importDir.loadDirectory("path", false, true);
            expect($.ajax).toHaveBeenCalled();

            window.history = oldHistory;
        });

        test("renderDirectoryBrowser should handle non-array directories/files", () => {
            importDir.renderDirectoryBrowser(
                { directories: null, files: "invalid" },
                "path",
            );
            expect($("#directory-browser").text()).toContain("No items found");
        });

        test("renderDirectoryBrowser should handle missing path segments", () => {
            importDir.renderDirectoryBrowser(
                {
                    directories: [{ name: null, path: null }],
                    files: [{ name: null, size: "not-a-number" }],
                },
                null,
            );
            expect($("#directory-browser tr").length).toBeGreaterThan(0);
        });

        test("escapeHtml should handle non-strings", () => {
            expect(importDir.escapeHtml(null)).toBe("");
            expect(importDir.escapeHtml(123)).toBe("");
        });

        test("escapeHtml should escape entities", () => {
            expect(importDir.escapeHtml("<>&\"'")).toBe(
                "&lt;&gt;&amp;&quot;&#039;",
            );
        });

        test("loadDirectory should handle error callback", (done) => {
            $.ajax = jest.fn().mockImplementation((opts) => {
                if (opts.error) opts.error({ status: 500 });
                return Promise.resolve();
            });

            importDir.loadDirectory("path", true, false, (data) => {
                expect(data).toBeUndefined();
                done();
            });
        });

        test("handleBulkImportClick should handle error", () => {
            $.ajax = jest.fn().mockImplementation((opts) => {
                if (opts.error)
                    opts.error({ responseJSON: { error: "Failed" } });
                return Promise.resolve();
            });

            $("#bulk-import-btn").click();
            expect($("#bulk-import-status").text()).toContain("Error: Failed");
        });

        test("handleBulkImportClick should handle missing status element", () => {
            const status = $("#bulk-import-status");
            status.remove();

            $.ajax = jest.fn().mockImplementation((opts) => {
                if (opts.success) opts.success({ message: "OK" });
                return Promise.resolve();
            });

            $("#bulk-import-btn").click();
            expect($.ajax).toHaveBeenCalled();

            // Restore for other tests
            document.body.innerHTML += '<span id="bulk-import-status"></span>';
        });

        test("handleAddBookClick should handle valid url", () => {
            const mockEvent = { preventDefault: jest.fn() };
            const $btn = $('<button data-url="/test-url"></button>');

            $.get = jest.fn().mockImplementation((url, cb) => {
                cb("<div>Form</div>");
                return { fail: () => {} };
            });

            importDir.handleAddBookClick.call($btn[0], mockEvent);

            expect(mockEvent.preventDefault).toHaveBeenCalled();
            expect($.get).toHaveBeenCalledWith(
                "/test-url",
                expect.any(Function),
            );
            expect($("#addBookModalBody").html()).toContain("Form");
        });

        test("handleAddBookClick should handle missing url", () => {
            const mockEvent = { preventDefault: jest.fn() };
            const $btn = $("<button></button>");
            importDir.handleAddBookClick.call($btn[0], mockEvent);
            expect(mockEvent.preventDefault).toHaveBeenCalled();
            expect($.ajax).not.toHaveBeenCalled();
        });
    });

    describe("Helpers", () => {
        test("showAlert should render alert", () => {
            importDir.showAlert("Message", "danger");
            expect($("#alerts-container").text()).toContain("Message");
        });

        test("showAlert should handle missing container", () => {
            const container = $("#alerts-container");
            container.remove();
            console.log = jest.fn();
            importDir.showAlert("Message", "success");
            expect(console.log).toHaveBeenCalledWith("[success]", "Message");
        });

        test("updateBookAction should add button", () => {
            $("#directory-browser").html("<tr></tr>");
            importDir.updateBookAction("book1", "/edit/1");
            expect($(".edit-book-btn").length).toBe(1);
        });

        test("updateBookAction should handle missing id", () => {
            importDir.updateBookAction(null, "/edit");
            expect($(".edit-book-btn").length).toBe(0);
        });
    });
});
