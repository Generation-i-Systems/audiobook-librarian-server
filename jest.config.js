export default {
    testEnvironment: 'jsdom',
    testMatch: [
        '**/tests/Javascript/**/*.test.js'
    ],
    moduleNameMapper: {
        '^@/(.*)$': '<rootDir>/resources/js/$1',
        '^~/(.*)$': '<rootDir>/public/js/$1',
        '^\\.(css|less|scss|sass)$': 'identity-obj-proxy'
    },
    transform: {
        '^.+\\.js$': 'babel-jest'
    },
    setupFilesAfterEnv: ['<rootDir>/tests/Javascript/setupTests.js'],
    transformIgnorePatterns: [
        '/node_modules/(?!(jquery|bootstrap)/)'
    ]
};
