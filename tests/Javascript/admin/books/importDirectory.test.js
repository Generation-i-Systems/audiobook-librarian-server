/**
 * Tests for the import directory functionality in the admin panel
 */

describe('Import Directory', () => {
    let $;
    let testData;

    beforeAll(() => {
        // Setup jQuery and global mocks
        $ = require('jquery');
        global.$ = $;
        global.jQuery = $;

        // Mock Bootstrap modal
        $.fn.modal = jest.fn(function () {
            return this; });

        // Mock the CSRF token
        document.body.innerHTML = ` < meta name = "csrf-token" content = "test-csrf-token" > < div id = "test-container" > < div id = "directory-path-breadcrumbs" class = "breadcrumb-container" > < / div > < div id = "letter-filter" class = "btn-group" > < button data - letter = "" > All < / button > < button data - letter = "A" > A < / button > < button data - letter = "B" > B < / button > < / div > < input type = "text" id = "search-filter" / > < button id = "bulk-import-btn" > Bulk Import < / button > < span id = "bulk-import-status" > < / span > < div id = "directory-loading-spinner" style = "display: none;" > Loading... < / div > < table id = "directory-browser-table" > < thead > < tr > < th > Name < / th > < th > Type < / th > < th > Actions < / th > < / tr > < / thead > < tbody id = "directory-browser" > < / tbody > < / table > < / div > < !--Modals-- > < div class = "modal" id = "addBookModal" > < div class = "modal-dialog" > < div class = "modal-content" > < div class = "modal-body" id = "addBookModalBody" > < / div > < / div > < / div > < / div > < div class = "modal" id = "editBookModal" > < div class = "modal-dialog" > < div class = "modal-content" > < div class = "modal-body" id = "edit-book-modal-body" > < / div > < / div > < / div > < / div > `;

        // Mock test data
        testData = {
            directories: [
                { name: 'Authors', type: 'dir', path: '/path/to/Authors' },
                { name: 'Stephen King', type: 'dir', path: '/path/to/Authors/Stephen King' },
                { name: 'The Stand', type: 'dir', path: '/path/to/Authors/Stephen King/The Stand' },
                { name: 'Brandon Sanderson', type: 'dir', path: '/path/to/Authors/Brandon Sanderson' },
            ],
            files: [
                { name: 'book1.mp3', type: 'file', path: '/path/to/book1.mp3', size: 1024 * 1024 },
                { name: 'cover.jpg', type: 'file', path: '/path/to/cover.jpg', size: 500 * 1024 },
            ]
        };

        // Load the import directory script
        require('../../../public/js/admin/books/import_directory.js');
    });

    beforeEach(() => {
        // Reset mocks before each test
        jest.clearAllMocks();

        // Mock AJAX
        $.ajax = jest.fn().mockImplementation((options) => {
            if (options.url.includes('directory-browser')) {
                options.success({
                    success: true,
                    directories: testData.directories,
                    files: testData.files
                });
            } else if (options.url.includes('bulkImportDir')) {
                options.success({
                    success: true,
                    message: 'Bulk import started successfully'
                });
            }
            return Promise.resolve();
        });

        // Reset the DOM
        $('#directory-browser').empty();
        $('#directory-path-breadcrumbs').empty();
        $('#bulk-import-status').text('');
    });

    describe('Directory Browsing', () => {
        test('should load root directory on page load', (done) => {
            // Trigger document ready
            $(document).ready();

            setTimeout(() => {
                // Check if AJAX was called to load the root directory
                expect($.ajax).toHaveBeenCalledWith(
                    expect.objectContaining({
                        url: expect.stringContaining('directory-browser'),
                        data: { path: '' }
                    })
                );

                // Check if directory contents are rendered
                const items = $('#directory-browser tr');
                expect(items.length).toBeGreaterThan(0);
                done();
            }, 100);
        });

        test('should navigate into subdirectory when clicked', (done) => {
            // Initial load
            $(document).ready();

            setTimeout(() => {
                // Click on a directory
                const $dirLink = $('a.directory-link:contains("Stephen King")');
                $dirLink.click();

                setTimeout(() => {
                    // Check if AJAX was called with the correct path
                    expect($.ajax).toHaveBeenLastCalledWith(
                        expect.objectContaining({
                            data: { path: '/path/to/Authors/Stephen King' }
                        })
                    );

                    // Check if breadcrumbs were updated
                    expect($('#directory-path-breadcrumbs').text()).toContain('Stephen King');
                    done();
                }, 100);
            }, 100);
        });

        test('should update breadcrumbs when navigating', (done) => {
            // Initial load
            $(document).ready();

            setTimeout(() => {
                // Navigate to a subdirectory
                const $dirLink = $('a.directory-link:contains("Authors")');
                $dirLink.click();

                setTimeout(() => {
                    // Verify breadcrumbs
                    const breadcrumbs = $('#directory-path-breadcrumbs').text();
                    expect(breadcrumbs).toContain('Authors');

                    // Click on a subdirectory
                    const $subDirLink = $('a.directory-link:contains("Stephen King")');
                    $subDirLink.click();

                    setTimeout(() => {
                        // Verify updated breadcrumbs
                        const updatedBreadcrumbs = $('#directory-path-breadcrumbs').text();
                        expect(updatedBreadcrumbs).toContain('Authors');
                        expect(updatedBreadcrumbs).toContain('Stephen King');
                        done();
                    }, 100);
                }, 100);
            }, 100);
        });
    });

    describe('Filtering', () => {
        test('should filter by first letter', (done) => {
            $(document).ready();

            setTimeout(() => {
                // Click on letter filter 'A'
                $('[data-letter="A"]').click();

                setTimeout(() => {
                    expect($.ajax).toHaveBeenLastCalledWith(
                        expect.objectContaining({
                            data: expect.objectContaining({
                                filter_letter: 'A'
                            })
                        })
                    );
                    done();
                }, 100);
            }, 100);
        });

        test('should filter by search term', (done) => {
            $(document).ready();

            setTimeout(() => {
                // Type in search box
                const searchTerm = 'King';
                $('#search-filter').val(searchTerm).trigger('input');

                // Wait for debounce
                setTimeout(() => {
                    expect($.ajax).toHaveBeenLastCalledWith(
                        expect.objectContaining({
                            data: expect.objectContaining({
                                search: searchTerm
                            })
                        })
                    );
                    done();
                }, 500); // Longer timeout for debounce
            }, 100);
        });
    });

    describe('Bulk Import', () => {
        test('should trigger bulk import for current directory', (done) => {
            // Set a current path
            const testPath = '/path/to/import';
            window.history.pushState({ path: testPath }, '', ` ? path = ${encodeURIComponent(testPath)}`);

            // Click bulk import button
            $('#bulk-import-btn').click();

            setTimeout(() => {
                // Check if AJAX was called with the correct data
                expect($.ajax).toHaveBeenCalledWith({
                    url: expect.stringContaining('bulk-import-dir'),
                    type: 'POST',
                    data: {
                        dir: testPath,
                        _token: 'test-csrf-token'
                    },
                    success: expect.any(Function),
                    error: expect.any(Function)
                });

                // Check if status was updated
                expect($('#bulk-import-status').text()).toContain('started');
                done();
            }, 100);
        });

        test('should show error when bulk import fails', (done) => {
            // Mock AJAX to fail
            $.ajax = jest.fn().mockImplementation((options) => {
                options.error({
                    responseJSON: { error: 'Import failed' },
                    statusText: 'Error'
                });
                return Promise.reject();
            });

            // Click bulk import button
            $('#bulk-import-btn').click();

            setTimeout(() => {
                expect($('#bulk-import-status').text()).toContain('Error');
                done();
            }, 100);
        });
    });

    describe('Book Management', () => {
        test('should open add book modal for directory', (done) => {
            $(document).ready();

            // Mock the modal content load
            $.get = jest.fn().mockImplementation((url, callback) => {
                callback('<form id="book-form"><input name="title"></form>');
                return Promise.resolve();
            });

            setTimeout(() => {
                // Click on a directory's add button
                $('.add-book-btn').first().click();

                setTimeout(() => {
                    // Check if modal was opened with the correct content
                    expect($.get).toHaveBeenCalledWith(
                        expect.stringContaining('books/create'),
                        expect.any(Function),
                        'html'
                    );

                    expect($('#addBookModal').hasClass('show')).toBe(true);
                    expect($('#book-form').length).toBe(1);
                    done();
                }, 100);
            }, 100);
        });

        test('should handle book form submission', (done) => {
            // Mock form submission
            $(document).on('submit', '#book-form', function (e) {
                e.preventDefault();
                const $form = $(this);

                // Simulate successful submission
                if (typeof $form.data('submitted') === 'undefined') {
                    $form.data('submitted', true);

                    // Simulate a response that would trigger updateBookAction
                    window.updateBookAction(
                        'book-123',
                        '/admin/books/book-123/edit',
                        '/path/to/book'
                    );
                }
            });

            // Trigger form submission
            $('<form id="book-form"><input name="title" value="Test Book"></form>')
                .appendTo('body')
                .submit();

            setTimeout(() => {
                // Check if the book action was updated in the UI
                expect($('.edit-book-btn[data-book-id="book-123"]').length).toBe(1);
                done();
            }, 100);
        });
    });
});
