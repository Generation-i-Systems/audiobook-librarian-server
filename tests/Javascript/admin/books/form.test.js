// Mock the loadDirectoryFiles function
function loadDirectoryFiles($container)
{
    const dirPath = $container.find('#directoryPath').val();
    const filesList = $container.find('#directory-files-list');
    const $viewFilesBtn = $container.find('#show-files-link');

    if (!dirPath) {
        filesList.html('<div class="p-3 text-danger">Please select a directory first.</div>').show();
        return;
    }

    const originalBtnHtml = $viewFilesBtn.html();
    $viewFilesBtn.html('<i class="fas fa-spinner fa-spin me-1"></i>Loading...');
    filesList.html('<div class="text-center p-3"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading files...</div>').show();

    // Simulate AJAX call with a timeout
    setTimeout(() => {
        const mockResponse = {
            files: ['book1.mp3', 'book2.mp3', 'cover.jpg']
        };

        let html = '<div class="list-group list-group-flush">';
        mockResponse.files.forEach(filename => {
            const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(filename);
            const isAudio = /\.(mp3|m4b|m4a|ogg|wav|flac)$/i.test(filename);
            let icon = '📄';
            if (isImage) {
                icon = '🖼️';
            } else if (isAudio) {
                icon = '🔊';
            }
            html += ` < div class = "list-group-item" > ${icon} ${filename} < / div > `;
        });
        html += '</div>';

        filesList.html(html).show();
        $viewFilesBtn.html(originalBtnHtml);
    }, 100);
}

// Mock autocomplete functionality
function initializeAutocomplete($container)
{
    // Mock author autocomplete
    $container.find('.author-autocomplete').autocomplete({
        source: function (request, response) {
            const mockAuthors = [
                'Stephen King',
                'J.K. Rowling',
                'George R.R. Martin',
                'Agatha Christie',
                'J.R.R. Tolkien'
            ].filter(
                author => author.toLowerCase().includes(request.term.toLowerCase())
            );
            response(mockAuthors);
        },
        minLength: 2
    });

    // Mock series autocomplete
    $container.find('.series-autocomplete').autocomplete({
        source: function (request, response) {
            const mockSeries = [
                'Harry Potter',
                'A Song of Ice and Fire',
                'The Lord of the Rings',
                'The Dark Tower',
                'Sherlock Holmes'
            ].filter(
                series => series.toLowerCase().includes(request.term.toLowerCase())
            );
            response(mockSeries);
        },
        minLength: 2
    });
}

// Initialize the form
function initBookForm($container)
{
    // Directory files loading
    $container.off('click', '#show-files-link').on('click', '#show-files-link', function (e) {
        e.preventDefault();
        loadDirectoryFiles($container);
    });

    // Initialize autocomplete
    initializeAutocomplete($container);

    // Add author row
    $container.off('click', '.add-author-row').on('click', '.add-author-row', function (e) {
        e.preventDefault();
        const $authorsGroup = $container.find('#authors-group');
        const newRow = ` < div class = "author-row mb-2 d-flex align-items-center" > < input type = "text" class = "form-control author-autocomplete" name = "authors[]" placeholder = "Author name" > < button type = "button" class = "btn btn-outline-danger btn-sm ms-2 remove-author" > & times; < / button > < / div > `;
        $authorsGroup.append(newRow);
    });

    // Remove author row
    $container.on('click', '.remove-author', function () {
        $(this).closest('.author-row').remove();
    });

    // Add series row
    $container.off('click', '.add-series-row').on('click', '.add-series-row', function (e) {
        e.preventDefault();
        const $seriesGroup = $container.find('#series-group');
        const newRow = ` < div class = "series-row mb-2 d-flex align-items-center" > < input type = "text" class = "form-control series-autocomplete me-2" name = "series_names[]" placeholder = "Series name" > < input type = "number" class = "form-control me-2" name = "series_numbers[]" placeholder = "Book #" min = "1" step = "1" > < button type = "button" class = "btn btn-outline-danger btn-sm remove-series" > & times; < / button > < / div > `;
        $seriesGroup.append(newRow);
    });

    // Remove series row
    $container.on('click', '.remove-series', function () {
        $(this).closest('.series-row').remove();
    });
}

// Export for testing
module.exports = {
    loadDirectoryFiles,
    initBookForm,
    initializeAutocomplete
};

// For browser
if (typeof window !== 'undefined') {
    window.loadDirectoryFiles = loadDirectoryFiles;
    window.initBookForm = initBookForm;
    window.initializeAutocomplete = initializeAutocomplete;
}

// Helper function to create form HTML with optional modal wrapper
const createFormHtml = (isModal = false) => {
    const formHtml = ` < form id = "book-form" ${isModal ? 'data-modal-form="true"' : ''} > < !--Directory Path-- > < div class = "form-group" > < label for = "directoryPath" > Directory Path < / label > < div class = "input-group" > < input type = "text" id = "directoryPath" class = "form-control" > < a href = "#" id = "showFilesLink" > < i class = "fas fa-folder-open me-1" > < / i > View Directory Files < / a > < / div > < div id = "directory-files-list" style = "display:none;" > < / div > < / div > < !--Authors-- > < div class = "form-group" > < label > Authors < / label > < div id = "authors-group" > < div class = "author-row mb-2 d-flex align-items-center" > < input type = "text" class = "form-control author-autocomplete" name = "authors[]" placeholder = "Author name" > < button type = "button" class = "btn btn-outline-success btn-sm ms-2 add-author-row" > + < / button > < / div > < / div > < / div > < !--Series-- > < div class = "form-group" > < label > Series < / label > < div id = "series-group" > < div class = "series-row mb-2 d-flex align-items-center" > < input type = "text" class = "form-control series-autocomplete me-2" name = "series_names[]" placeholder = "Series name" > < input type = "number" class = "form-control me-2" name = "series_numbers[]" placeholder = "Book #" min = "1" step = "1" style = "width: 80px;" > < button type = "button" class = "btn btn-outline-success btn-sm add-series-row" > + < / button > < / div > < / div > < / div > < !--Other form fields-- > < div class = "form-group" > < label for = "title" > Title < / label > < input type = "text" id = "title" name = "title" class = "form-control" placeholder = "Book Title" > < / div > < div class = "form-group" > < label for = "description" > Description < / label > < textarea id = "description" name = "description" class = "form-control" rows = "4" placeholder = "Book Description" > < / textarea > < / div > < div class = "form-group" > < label for = "release_date" > Release Date < / label > < input type = "date" id = "release_date" name = "release_date" class = "form-control" > < / div > < button type = "submit" class = "btn btn-primary" > Save Book < / button > < / form > `;

    if (isModal) {
        return ` < div class = "modal fade" id = "bookModal" tabindex = "-1" aria - hidden = "true" > < div class = "modal-dialog" > < div class = "modal-content" > < div class = "modal-header" > < h5 class = "modal-title" > ${isModal === 'edit' ? 'Edit Book' : 'Add New Book'} < / h5 > < button type = "button" class = "btn-close" data - bs - dismiss = "modal" aria - label = "Close" > < / button > < / div > < div class = "modal-body" > ${formHtml} < / div > < / div > < / div > < / div > < button type = "button" class = "btn btn-primary" data - bs - toggle = "modal" data - bs - target = "#bookModal" > Open Modal < / button > `;
    }

    return formHtml;
};

describe('Book Form', () => {
    let $;
    let testFunctions;

    beforeAll(() => {
        // Mock jQuery and jQuery UI autocomplete
        $ = require('jquery');
        global.$ = $;
        global.jQuery = $;

        // Mock jQuery UI autocomplete
        $.fn.autocomplete = function (options) {
            if (typeof options === 'string') {
                // Handle method calls like 'search'
                return this;
            }
            // Store options for later use
            this.each(function () {
                $(this).data('ui-autocomplete', { options: options });
            });
            return this;
        };

        // Mock Bootstrap Modal
        const Modal = jest.fn();
        Modal.prototype.show = jest.fn();
        Modal.prototype.hide = jest.fn();
        $.fn.modal = function (method) {
            if (method === 'show' || method === 'hide') {
                return this.each(function () {
                    // Call the corresponding method if it exists
                    if (method === 'show') {
                        $(this).addClass('show').css('display', 'block');
                    } else if (method === 'hide') {
                        $(this).removeClass('show').css('display', 'none');
                    }
                });
            }
            return this;
        };

        // Mock Bootstrap's data API
        $.fn.modal.Constructor = Modal;
    });

    const setupTest = (isModal = false, mode = 'add') => {
        // Clear previous test DOM
        document.body.innerHTML = '';

        // Create test DOM with form elements
        document.body.innerHTML = createFormHtml(isModal ? mode : false);

        let container;
        if (isModal) {
            // For modal mode, the form is inside a modal
            container = $('.modal-body form');
            // Initialize modal
            $('.modal').modal('show');
        } else {
            // For direct mode, the form is directly in the document
            container = $('#book-form');
        }

        // Initialize the form
        testFunctions = require('./form.test');
        testFunctions.initBookForm(container);

        return { container };
    };

    // Common test function that runs for both modes
    const testInBothModes = (testName, testFn) => {
        describe(testName, () => {
            describe('Direct Form', () => {
                beforeEach(() => {
                    setupTest(false);
                });
                testFn('direct');
            });

            describe('Modal Form - Add Mode', () => {
                beforeEach(() => {
                    setupTest(true, 'add');
                });
                testFn('modal-add');
            });

            describe('Modal Form - Edit Mode', () => {
                beforeEach(() => {
                    setupTest(true, 'edit');
                });
                testFn('modal-edit');
            });
        });
    };

    // Directory Browsing and File Operations Tests
    describe('Directory and File Operations', () => {
        beforeEach(() => {
            // Reset mocks before each test
            jest.clearAllMocks();

            // Mock the AJAX call for loading directory contents
            $.ajax = jest.fn().mockImplementation(({ url, success }) => {
                if (url.includes('files-ajax')) {
                    success({
                        success: true,
                        files: [
                            { name: 'book1.mp3', type: 'file', size: 1024 * 1024, path: '/test/path/book1.mp3' },
                            { name: 'book2.mp3', type: 'file', size: 2 * 1024 * 1024, path: '/test/path/book2.mp3' },
                            { name: 'cover.jpg', type: 'file', size: 500 * 1024, path: '/test/path/cover.jpg' },
                            { name: 'subfolder', type: 'dir', path: '/test/path/subfolder' }
                        ]
                    });
                }
                return Promise.resolve();
            });

            // Setup for each test
            setupTest(false); // Use direct form for these tests
        });

        test('should show directory contents when directory is selected', (done) => {
            $('#directoryPath').val('/test/path');
            $('#showFilesLink').click();

            // Check loading state
            expect($('#showFilesLink').html()).toContain('fa-spinner');

            setTimeout(() => {
                const fileItems = $('#directory-files-list').find('.list-group-item');
                expect(fileItems.length).toBe(4);

                // Check file items
                expect(fileItems.eq(0).text()).toContain('book1.mp3');
                expect(fileItems.eq(0).find('.badge').text()).toContain('1.0 MB');

                // Check folder item
                expect(fileItems.eq(3).text()).toContain('subfolder');
                expect(fileItems.eq(3).find('.badge').text()).toContain('Folder');

                done();
            }, 200);
        });

        test('should handle AJAX errors when loading directory', (done) => {
            // Mock AJAX to return an error
            $.ajax = jest.fn().mockImplementation(({ error }) => {
                error({ status: 500, responseJSON: { message: 'Server error' } });
                return Promise.reject();
            });

            $('#directoryPath').val('/invalid/path');
            $('#showFilesLink').click();

            setTimeout(() => {
                expect($('#directory-files-list').text()).toContain('Error loading directory');
                done();
            }, 200);
        });

        test('should filter files by type when filter is applied', (done) => {
            // Add a filter button to the test DOM
            $('body').append(` < div class = "btn-group mb-3" role = "group" > < button type = "button" class = "btn btn-outline-secondary active" data - filter = "all" > All < / button > < button type = "button" class = "btn btn-outline-secondary" data - filter = "audio" > Audio < / button > < button type = "button" class = "btn btn-outline-secondary" data - filter = "image" > Images < / button > < / div > `);

            // Initialize the filter functionality
            $('[data-filter]').on('click', function () {
                const filter = $(this).data('filter');
                $('[data-filter]').removeClass('active');
                $(this).addClass('active');

                // Simulate filtering (actual implementation would filter the displayed items)
                if (filter === 'audio') {
                    $('.list-group-item').hide().filter(function () {
                        return $(this).data('type') === 'audio';
                    }).show();
                } else if (filter === 'image') {
                    $('.list-group-item').hide().filter(function () {
                        return $(this).data('type') === 'image';
                    }).show();
                } else {
                    $('.list-group-item').show();
                }
            });

            // Load the files
            $('#directoryPath').val('/test/path');
            $('#showFilesLink').click();

            setTimeout(() => {
                // Test audio filter
                $('[data-filter="audio"]').click();
                expect($('.list-group-item:visible').length).toBe(2); // Only audio files
                expect($('.list-group-item:visible:first').text()).toContain('.mp3');

                // Test image filter
                $('[data-filter="image"]').click();
                expect($('.list-group-item:visible').length).toBe(1); // Only image file
                expect($('.list-group-item:visible:first').text()).toContain('.jpg');

                // Test all filter
                $('[data-filter="all"]').click();
                expect($('.list-group-item:visible').length).toBe(4); // All items

                done();
            }, 200);
        });
    });

    // Book Management Tests
    describe('Book Management', () => {
        beforeEach(() => {
            // Mock AJAX for book operations
            $.ajax = jest.fn().mockImplementation(({ url, type, data, success }) => {
                if (type === 'POST' && url.includes('books')) {
                    // Simulate successful book creation/update
                    success({
                        success: true,
                        message: 'Book saved successfully',
                        book: {
                            id: data.id || 'new-book-123',
                            title: data.title,
                            authors: data.authors || [],
                            series: data.series_names ? data.series_names[0] : null,
                            series_number: data.series_numbers ? data.series_numbers[0] : null,
                            directoryPath: data.directoryPath
                        }
                    });
                }
                return Promise.resolve();
            });

            setupTest(false); // Use direct form for these tests
        });

        test('should create a new book with valid data', (done) => {
            // Fill in form data
            $('#title').val('Test Book');
            $('.author-autocomplete').first().val('Test Author');
            $('#directoryPath').val('/test/books/test-book');
            $('#release_date').val('2023-01-01');
            $('#description').val('A test book description');

            // Mock form submission
            let formSubmitted = false;
            let responseData = null;

            $('#book-form').on('submit', function (e) {
                e.preventDefault();
                formSubmitted = true;

                // Simulate AJAX success
                $.ajax({
                    url: '/admin/books',
                    type: 'POST',
                    data: $(this).serialize(),
                    success: function (data) {
                        responseData = data;
                        // Show success message
                        $('<div class="alert alert-success">' + data.message + '</div>').insertBefore('#book-form');
                    }
                });
            });

            // Submit the form
            $('#book-form').trigger('submit');

            // Check if form was submitted and processed
            expect(formSubmitted).toBe(true);

            setTimeout(() => {
                expect(responseData).not.toBeNull();
                expect(responseData.success).toBe(true);
                expect($('.alert-success').text()).toContain('saved successfully');
                expect(responseData.book.title).toBe('Test Book');
                done();
            }, 100);
        });

        test('should update an existing book', (done) => {
            // Set up form with existing book data
            const bookId = 'existing-book-123';
            $('body').append(` < input type = "hidden" name = "id" value = "${bookId}" > `);

            // Fill in form data
            $('#title').val('Updated Book Title');
            $('.author-autocomplete').first().val('Updated Author');
            $('#directoryPath').val('/test/books/updated-book');
            $('#release_date').val('2023-01-01');

            // Mock form submission
            let formSubmitted = false;
            let responseData = null;

            $('#book-form').on('submit', function (e) {
                e.preventDefault();
                formSubmitted = true;

                // Simulate AJAX success
                $.ajax({
                    url: ` / admin / books / ${bookId}`,
                    type: 'PUT',
                    data: $(this).serialize(),
                    success: function (data) {
                        responseData = data;
                        // Show success message
                        $('<div class="alert alert-success">' + data.message + '</div>').insertBefore('#book-form');
                    }
                });
            });

            // Submit the form
            $('#book-form').trigger('submit');

            // Check if form was submitted and processed
            expect(formSubmitted).toBe(true);

            setTimeout(() => {
                expect(responseData).not.toBeNull();
                expect(responseData.success).toBe(true);
                expect($('.alert-success').text()).toContain('saved successfully');
                expect(responseData.book.id).toBe(bookId);
                expect(responseData.book.title).toBe('Updated Book Title');
                done();
            }, 100);
        });

        test('should handle form submission errors', (done) => {
            // Mock AJAX to return an error
            $.ajax = jest.fn().mockImplementation(({ error }) => {
                error({
                    status: 422,
                    responseJSON: {
                        message: 'Validation failed',
                        errors: {
                            title: ['The title field is required.']
                        }
                    }
                });
                return Promise.reject();
            });

            // Submit empty form
            $('#book-form').on('submit', function (e) {
                e.preventDefault();

                // Simulate AJAX call
                $.ajax({
                    url: '/admin/books',
                    type: 'POST',
                    data: $(this).serialize(),
                    error: function (xhr) {
                        // Show validation errors
                        const errors = xhr.responseJSON.errors;
                        for (const field in errors) {
                            $(`#${field}`).addClass('is-invalid');
                            $(` < div class = "invalid-feedback" > ${errors[field][0]} < / div > `).insertAfter(`#${field}`);
                        }
                    }
                });
            });

            // Submit the form
            $('#book-form').trigger('submit');

            setTimeout(() => {
                // Check for validation errors
                expect($('#title').hasClass('is-invalid')).toBe(true);
                expect($('.invalid-feedback').text()).toContain('The title field is required');
                done();
            }, 100);
        });
    });

    // Author Autocomplete Tests
    testInBothModes('Author Autocomplete', (mode) => {
        test('should initialize autocomplete on author input', () => {
            const $authorInput = $('.author-autocomplete').first();
            expect($authorInput.length).toBe(1);
            expect($authorInput.data('ui-autocomplete')).toBeDefined();
        });

        test('should filter authors based on input', (done) => {
            const $authorInput = $('.author-autocomplete').first();
            const autocomplete = $authorInput.data('ui-autocomplete');

            // Mock the source function
            const originalSource = autocomplete.options.source;
            autocomplete.options.source = function (request, response) {
                originalSource.call(this, request, function (items) {
                    expect(Array.isArray(items)).toBe(true);
                    if (request.term.toLowerCase() === 'king') {
                        expect(items).toContain('Stephen King');
                        expect(items).not.toContain('J.K. Rowling');
                    }
                    response(items);
                    done();
                });
            };

            // Trigger autocomplete search
            $authorInput.val('king');
            $authorInput.trigger('keydown');
        });

        test('should add new author row when clicking add button', () => {
            const initialCount = $('.author-row').length;
            $('.add-author-row').first().click();
            expect($('.author-row').length).toBe(initialCount + 1);
        });
    });

    // Series Autocomplete Tests
    testInBothModes('Series Autocomplete', (mode) => {
        test('should initialize autocomplete on series input', () => {
            const $seriesInput = $('.series-autocomplete').first();
            expect($seriesInput.length).toBe(1);
            expect($seriesInput.data('ui-autocomplete')).toBeDefined();
        });

        test('should filter series based on input', (done) => {
            const $seriesInput = $('.series-autocomplete').first();
            const autocomplete = $seriesInput.data('ui-autocomplete');

            // Mock the source function
            const originalSource = autocomplete.options.source;
            autocomplete.options.source = function (request, response) {
                originalSource.call(this, request, function (items) {
                    expect(Array.isArray(items)).toBe(true);
                    if (request.term.toLowerCase() === 'harry') {
                        expect(items).toContain('Harry Potter');
                        expect(items).not.toContain('A Song of Ice and Fire');
                    }
                    response(items);
                    done();
                });
            };

            // Trigger autocomplete search
            $seriesInput.val('harry');
            $seriesInput.trigger('keydown');
        });

        test('should add new series row when clicking add button', () => {
            const initialCount = $('.series-row').length;
            $('.add-series-row').first().click();
            expect($('.series-row').length).toBe(initialCount + 1);
        });
    });

    // Form Submission Tests
    testInBothModes('Form Submission', (mode) => {
        test('should validate required fields', () => {
            // Clear all fields
            $('#title').val('');
            $('.author-autocomplete').val('');

            // Mock form submission
            let formSubmitted = false;
            $('#book-form').on('submit', function (e) {
                e.preventDefault();
                formSubmitted = true;
            });

            // Trigger form submission
            $('#book-form').trigger('submit');

            // Form should not be submitted
            expect(formSubmitted).toBe(false);

            // Check for validation errors
            expect($('#title').hasClass('is-invalid')).toBe(true);
            expect($('.author-autocomplete').first().hasClass('is-invalid')).toBe(true);
        });

        test('should submit form with valid data', () => {
            // Fill in required fields
            $('#title').val('Test Book');
            $('.author-autocomplete').first().val('Test Author');
            $('#release_date').val('2023-01-01');

            // Mock form submission
            let formSubmitted = false;
            let formData = null;

            $('#book-form').on('submit', function (e) {
                e.preventDefault();
                formSubmitted = true;
                formData = $(this).serializeArray();
            });

            // Trigger form submission
            $('#book-form').trigger('submit');

            // Form should be submitted
            expect(formSubmitted).toBe(true);

            // Check if form data is correct
            const titleField = formData.find(field => field.name === 'title');
            expect(titleField.value).toBe('Test Book');

            const authorField = formData.find(field => field.name === 'authors[]');
            expect(authorField.value).toBe('Test Author');
        });
    });

    // Modal-specific tests
    describe('Modal-specific Functionality', () => {
        beforeEach(() => {
            setupTest(true); // Setup modal mode
        });

        test('should initialize modal with correct title for add mode', () => {
            setupTest(true, 'add');
            expect($('.modal-title').text()).toBe('Add New Book');
        });

        test('should initialize modal with correct title for edit mode', () => {
            setupTest(true, 'edit');
            expect($('.modal-title').text()).toBe('Edit Book');
        });

        test('should close modal when clicking close button', () => {
            // Show the modal
            $('.modal').addClass('show').css('display', 'block');

            // Click the close button
            $('.btn-close').click();

            // Check if modal is hidden
            expect($('.modal').hasClass('show')).toBe(false);
            expect($('.modal').css('display')).toBe('none');
        });
    });

    // Direct form specific tests
    describe('Direct Form-specific Functionality', () => {
        beforeEach(() => {
            setupTest(false); // Setup direct form mode
        });

        test('should not have modal wrapper in direct mode', () => {
            expect($('.modal').length).toBe(0);
            expect($('#book-form').closest('.modal').length).toBe(0);
        });
    });
});
