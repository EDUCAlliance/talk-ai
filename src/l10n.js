import {
	getCanonicalLocale,
	n as translatePlural,
	t as translate,
} from '@nextcloud/l10n'

/**
 * Translate text that will be escaped by Vue or another text-only consumer.
 *
 * Nextcloud escapes placeholder values as HTML entities by default. Vue then
 * escapes those entities again, which would display names such as `O&#39;Brien`
 * literally. The complete result remains sanitized by @nextcloud/l10n.
 *
 * @param {string} app App id
 * @param {string} text Source text
 * @param {object|number} [placeholders] Placeholder values or plural number
 * @param {object} [options] Translation options
 * @return {string} Translated text
 */
export function t(app, text, placeholders, options = {}) {
	return translate(app, text, placeholders, { escape: false, ...options })
}

/**
 * Translate plural text for Vue or another text-only consumer.
 *
 * @param {string} app App id
 * @param {string} singular Singular source text
 * @param {string} plural Plural source text
 * @param {number} count Plural count
 * @param {object} [placeholders] Placeholder values
 * @param {object} [options] Translation options
 * @return {string} Translated text
 */
export function n(app, singular, plural, count, placeholders, options = {}) {
	return translatePlural(app, singular, plural, count, placeholders, { escape: false, ...options })
}

/**
 * Install the Nextcloud translation helpers for Vue templates.
 *
 * Script blocks should import t/n directly from this module so the
 * Nextcloud translation extractor can discover every source string.
 *
 * @param {typeof import('vue')} Vue Vue constructor
 */
export function installL10n(Vue) {
	Vue.mixin({ methods: { t, n } })
}

export { getCanonicalLocale }
