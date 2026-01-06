// Jest setup for JavaScript tests
require("jest-fetch").enableMocks();

// Mock jQuery for tests that expect it
global.$ = jest.fn((selector) => ({
    val: jest.fn().mockReturnValue("test value"),
    text: jest.fn().mockReturnValue("test text"),
    addClass: jest.fn(),
    removeClass: jest.fn(),
    on: jest.fn(),
    off: jest.fn(),
    click: jest.fn(),
    submit: jest.fn(),
    ajax: jest.fn().mockReturnValue({
        done: jest.fn(),
        fail: jest.fn(),
        success: jest.fn(),
    }),
    ready: jest.fn(),
}));

// Mock Bootstrap components
global.bootstrap = {
    Tooltip: jest.fn(),
    Modal: jest.fn(),
    Dropdown: jest.fn(),
};

// Mock window.fetch for tests
global.fetch = jest.fn(() =>
    Promise.resolve({
        ok: true,
        status: 200,
        json: () => {},
    }),
);
