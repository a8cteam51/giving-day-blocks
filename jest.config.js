/**
 * Jest configuration.
 *
 * Uses @wordpress/jest-preset-default for jsdom + the WordPress-friendly
 * babel/sass setup. Unit tests live alongside the shared utilities and
 * the block sources.
 */
const defaultPreset = require('@wordpress/jest-preset-default/jest-preset');

module.exports = {
    ...defaultPreset,
    testMatch: [
    '<rootDir>/tests/**/*.test.[jt]s?(x)',
    '<rootDir>/blocks/src/**/*.test.[jt]s?(x)',
    ],
};
