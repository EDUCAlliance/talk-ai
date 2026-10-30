module.exports = {
    globals: {
        appVersion: true
    },
    parserOptions: {
        requireConfigFile: false
    },
    extends: [
        '@nextcloud/eslint-config/vue3'
    ],
    rules: {
        'jsdoc/require-jsdoc': 'off',
        'jsdoc/tag-lines': 'off',
        'vue/first-attribute-linebreak': 'off',
        'import/extensions': 'off',
        'vue/custom-event-name-casing': ['error', 'kebab-case', { ignores: ['/^update:[a-zA-Z]+$/u'] }]
    }
}
