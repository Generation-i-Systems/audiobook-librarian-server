module.exports = {
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
    testMatch: [
        "**/tests/Javascript/**/*.test.js",
        "**/tests/Feature/**/*.php",
        "**/tests/Unit/**/*.php",
    ],
    projects: [
        {
            displayName: "JavaScript Unit Tests",
            file: ["**/tests/Javascript/**/*.test.js"],
            testPathIgnorePatterns: ["/node_modules/", "/vendor/"],
        },
        {
            displayName: "PHP Feature Tests",
            testMatch: ["**/tests/Feature/**/*.php"],
            testPathIgnorePatterns: ["/node_modules/", "/vendor/"],
        },
        {
            displayName: "PHP Unit Tests",
            testMatch: ["**/tests/Unit/**/*.php"],
            testPathIgnorePatterns: ["/node_modules/", "/vendor/"],
        },
    ],
};
