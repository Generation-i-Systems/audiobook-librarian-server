// Jest setup for JavaScript tests
import $ from "jquery";
import { jest } from "@jest/globals";

// Ensure jest is available globally for ESM tests
global.jest = jest;

// Mock jQuery for tests that expect it
global.$ = global.jQuery = $;

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
