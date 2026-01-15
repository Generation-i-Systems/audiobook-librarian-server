export default {
    testEnvironment: "jsdom",
    setupFilesAfterEnv: ["<rootDir>/tests/Javascript/setup.js"],
    moduleNameMapper: {
        "^~/(.*)$": "<rootDir>/resources/js/$1",
        "^\\.(css|less|scss|sass)$": "identity-obj-proxy",
        "^admin/books/(.*)$": "<rootDir>/resources/js/admin/books/$1",
        "^admin/(.*)$": "<rootDir>/resources/js/admin/$1",
        "^@/(.*)$": "<rootDir>/resources/js/$1",
    },
    collectCoverage: true,
    coverageDirectory: "coverage",
    coverageReporters: ["text", "lcov", "html"],
    coverageThreshold: {
        global: {
            branches: 70,
            functions: 70,
            lines: 70,
            statements: 70,
        },
    },
    testMatch: ["**/tests/Javascript/**/*.test.js"],
    transform: {},
};
