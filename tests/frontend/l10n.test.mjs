import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { runInNewContext } from 'node:vm'

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

test('German browser catalog executes and matches the server translation catalog', () => {
	const serverCatalog = JSON.parse(readFileSync(new URL('../../l10n/de.json', import.meta.url), 'utf8'))
	const registrations = []
	runInNewContext(readFileSync(new URL('../../l10n/de.js', import.meta.url), 'utf8'), {
		OC: { L10N: { register: (...args) => registrations.push(args) } },
	})
	assert.equal(registrations.length, 1)
	const [app, translations, pluralForm] = registrations[0]
	assert.equal(app, 'educai')
	assert.deepEqual(JSON.parse(JSON.stringify(translations)), serverCatalog.translations)
	assert.equal(pluralForm, serverCatalog.pluralForm)
	assert.equal(translations['Incomplete'], 'Unvollständig')
	assert.equal(translations['Response incomplete: output limit reached.'], 'Antwort unvollständig: Ausgabelimit erreicht.')
})
