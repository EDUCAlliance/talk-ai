import assert from 'node:assert/strict'
import test from 'node:test'
import { normalizeToolSelectionAliases } from '../../src/utils/toolAliases.js'

const canonical = 'builtin:catalogue_search'
const legacy = 'builtin:catalogue_search_courses'
const tools = [{ is_builtin: true, builtin_name: 'catalogue_search', aliases: ['catalogue_search_courses'] }]

test('legacy loadout is selected by the canonical checkbox and retains config', () => {
	const selected = [legacy, 7]
	const configs = { [legacy]: { scope: 'saved' }, 7: { limit: 10 } }
	assert.deepEqual(normalizeToolSelectionAliases(selected, configs, tools), {
		selected: [canonical, 7], configs: { [canonical]: { scope: 'saved' }, 7: { limit: 10 } },
	})
	assert.deepEqual(selected, [legacy, 7])
	assert.deepEqual(configs[legacy], { scope: 'saved' })
})

test('canonical config wins over legacy regardless of stored order', () => {
	for (const selected of [[legacy, canonical], [canonical, legacy]]) {
		assert.deepEqual(normalizeToolSelectionAliases(selected, { [legacy]: { limit: 3 }, [canonical]: { limit: 5 } }, tools), {
			selected: [canonical], configs: { [canonical]: { limit: 5 } },
		})
		assert.deepEqual(normalizeToolSelectionAliases(selected, { [legacy]: { limit: 3 } }, tools), {
			selected: [canonical], configs: {},
		})
	}
})

test('disabled and unknown provider assignments are retained until explicit editing', () => {
	assert.deepEqual(normalizeToolSelectionAliases([legacy, 'builtin:unknown'], { [legacy]: { limit: 3 } }, []), {
		selected: [legacy, 'builtin:unknown'], configs: { [legacy]: { limit: 3 } },
	})
})

test('loading definitions after selection resolves aliases without shadowing canonical tools', () => {
	const initial = normalizeToolSelectionAliases([legacy], { [legacy]: { limit: 3 } }, [])
	assert.deepEqual(normalizeToolSelectionAliases(initial.selected, initial.configs, tools), {
		selected: [canonical], configs: { [canonical]: { limit: 3 } },
	})
	assert.deepEqual(normalizeToolSelectionAliases([legacy], {}, [...tools, { is_builtin: true, builtin_name: 'catalogue_search_courses' }]), {
		selected: [legacy], configs: {},
	})
})
