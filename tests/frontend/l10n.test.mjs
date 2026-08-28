import assert from 'node:assert/strict'
import test from 'node:test'

import { n, t } from '../../src/l10n.js'

test('leaves placeholder text unescaped for Vue text rendering', () => {
	assert.equal(
		t('educai', 'Bot {name}', { name: "O'Brien & Co" }),
		"Bot O'Brien & Co",
	)
})

test('uses the source plural forms when no catalog is registered', () => {
	assert.equal(n('educai', '%n bot', '%n bots', 1), '1 bot')
	assert.equal(n('educai', '%n bot', '%n bots', 2), '2 bots')
})
