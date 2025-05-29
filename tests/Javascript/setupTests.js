// Mock jQuery
const $ = require('jquery');
global.$ = global.jQuery = $;

// Mock window object
global.window = window;
window.$.fn.modal = jest.fn(); // Mock Bootstrap's modal if needed

// Mock any global variables your app uses
window.BOOK_FORM_ROUTES = {
    filesAjax: '/admin/books/files-ajax'
};

// Mock any other global functions or objects your code might use
