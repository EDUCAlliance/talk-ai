import assert from 'node:assert/strict'
import test from 'node:test'

test('branding uses server state independently for display and storage', async () => {
	globalThis.document = {
		querySelector(selector) {
			assert.equal(selector, '#initial-state-educai-branding')
			return { value: btoa(JSON.stringify({ displayName: 'Campus Assistant', wikiRootFolder: 'EDUC AI' })) }
		},
	}
	try {
		const branding = await import('../../src/branding.js?configured')
		assert.equal(branding.APP_DISPLAY_NAME, 'Campus Assistant')
		assert.equal(branding.WIKI_ROOT_FOLDER, 'EDUC AI')
	} finally {
		delete globalThis.document
	}
})

test('branding has neutral defaults if initial state is not available', async () => {
	globalThis.document = { querySelector: () => null }
	try {
		const branding = await import('../../src/branding.js?default')
		assert.equal(branding.APP_DISPLAY_NAME, 'Talk AI')
		assert.equal(branding.WIKI_ROOT_FOLDER, 'Talk AI')
	} finally {
		delete globalThis.document
	}
})
