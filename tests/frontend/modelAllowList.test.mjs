import assert from 'node:assert/strict'
import test from 'node:test'
import { buildAllowListGroups, setReleased, NEW_MODEL_SECONDS } from '../../src/utils/modelAllowList.js'

const now = 2_000_000_000
const option = (id, extra = {}) => ({ id, availability: 'available', ...extra })
const ids = group => group.items.map(item => item.id)

test('models are grouped per provider with released models first', () => {
	const options = [
		option('primary:zeta'), option('primary:alpha'), option('primary:mid'),
		option('secondary:other'), option('secondary:chosen'),
	]
	const groups = buildAllowListGroups(options, ['primary:mid', 'secondary:chosen'], { now })
	assert.deepEqual(groups.map(group => group.endpoint), ['primary', 'secondary'])
	assert.deepEqual(ids(groups[0]), ['primary:mid', 'primary:alpha', 'primary:zeta'])
	assert.equal(groups[0].selectedCount, 1)
	assert.equal(groups[0].total, 3)
	assert.deepEqual(ids(groups[1]), ['secondary:chosen', 'secondary:other'])
})

test('tags mark default, fallback, new, stale and missing models', () => {
	const options = [
		option('primary:default'),
		option('primary:fallback'),
		option('primary:fresh', { first_seen_at: now - 3600 }),
		option('primary:old', { first_seen_at: now - NEW_MODEL_SECONDS - 1 }),
		option('primary:baseline', { first_seen_at: null }),
		option('secondary:cached', { availability: 'stale' }),
	]
	const groups = buildAllowListGroups(options, ['primary:default', 'secondary:gone'], {
		now, defaultModel: 'primary:default', fallbackModel: 'primary:fallback',
	})
	const tags = Object.fromEntries(groups.flatMap(group => group.items).map(item => [item.id, item.tags]))
	assert.deepEqual(tags['primary:default'], ['default'])
	assert.deepEqual(tags['primary:fallback'], ['fallback'])
	assert.deepEqual(tags['primary:fresh'], ['new'])
	assert.deepEqual(tags['primary:old'], [])
	assert.deepEqual(tags['primary:baseline'], [], 'The first catalogue load is a baseline, not new')
	assert.deepEqual(tags['secondary:cached'], ['stale'])
	assert.deepEqual(tags['secondary:gone'], ['missing'], 'A released model that disappeared stays visible')
	assert.equal(groups.find(group => group.endpoint === 'secondary').total, 1, 'Missing models do not count as catalogue entries')
})

test('a released new model is not flagged as new', () => {
	const groups = buildAllowListGroups([option('primary:fresh', { first_seen_at: now })], ['primary:fresh'], { now })
	assert.deepEqual(groups[0].items[0].tags, [])
})

test('long lists collapse unreleased models but always show released ones', () => {
	const options = Array.from({ length: 30 }, (_, index) => option(`secondary:model-${String(index).padStart(2, '0')}`))
	const selected = ['secondary:model-29', 'secondary:model-28']
	const collapsed = buildAllowListGroups(options, selected, { now, collapsedLimit: 5 })[0]
	assert.equal(collapsed.items.length, 7)
	assert.deepEqual(ids(collapsed).slice(0, 2), ['secondary:model-28', 'secondary:model-29'])
	assert.equal(collapsed.hiddenCount, 23)
	const expanded = buildAllowListGroups(options, selected, { now, collapsedLimit: 5, expanded: { secondary: true } })[0]
	assert.equal(expanded.items.length, 30)
	assert.equal(expanded.hiddenCount, 0)
})

test('the filter searches model names case-insensitively across all models', () => {
	const options = [option('primary:Llama-3.3-70B'), option('primary:qwen3-32b'), option('secondary:meta/llama-4')]
	const groups = buildAllowListGroups(options, [], { now, filter: 'LLAMA' })
	assert.deepEqual(groups.map(ids), [['primary:Llama-3.3-70B'], ['secondary:meta/llama-4']])
	assert.deepEqual(groups[0].matchIds, ['primary:Llama-3.3-70B'])
	const limited = buildAllowListGroups(options, [], { now, filter: '3', filterLimit: 1 })
	assert.equal(limited[0].items.length, 1)
	assert.equal(limited[0].hiddenCount, 1)
	assert.deepEqual(limited[0].matchIds, ['primary:Llama-3.3-70B', 'primary:qwen3-32b'], '"All" also covers hidden matches')
	assert.deepEqual(buildAllowListGroups(options, [], { now, filter: 'nothing' }), [])
})

test('released references without a provider stay in their own group', () => {
	const groups = buildAllowListGroups([option('primary:alpha')], ['legacy-model'], { now })
	const unbound = groups.find(group => group.endpoint === 'unbound')
	assert.deepEqual(ids(unbound), ['legacy-model'])
	assert.deepEqual(unbound.items[0].tags, ['missing'])
})

test('counts only listed models as "of total" and reports the rest separately', () => {
	const options = [option('primary:alpha'), option('primary:beta')]
	const [group] = buildAllowListGroups(options, ['primary:alpha', 'primary:gone-1', 'primary:gone-2'], { now })
	assert.equal(group.total, 2)
	assert.equal(group.selectedCount, 1, 'Released models outside the list never exceed the list size')
	assert.equal(group.missingCount, 2)
	assert.equal(group.manualCount, 0)
})

test('providers without a model list show released models as manual entries, not as missing', () => {
	const selected = ['primary:microsoft/gpt-5.4', 'primary:gwdg/qwen3-30b', 'secondary:gone']
	const groups = buildAllowListGroups([option('secondary:beta')], selected, { now, unlistedEndpoints: ['primary'] })
	const primary = groups.find(group => group.endpoint === 'primary')
	assert.equal(primary.total, 0)
	assert.equal(primary.selectedCount, 0)
	assert.equal(primary.manualCount, 2)
	assert.equal(primary.missingCount, 0)
	assert.deepEqual(primary.items.map(item => item.tags), [[], []])
	const secondary = groups.find(group => group.endpoint === 'secondary')
	assert.deepEqual(secondary.items.find(item => item.id === 'secondary:gone').tags, ['missing'], 'Listing providers still flag disappeared models')
})

test('releasing keeps the order of existing entries and never duplicates', () => {
	assert.deepEqual(setReleased(['b', 'a'], ['c', 'a', 'c', 'd'], true), ['b', 'a', 'c', 'd'])
	assert.deepEqual(setReleased(['b', 'a', 'c'], ['a', 'x'], false), ['b', 'c'])
	assert.deepEqual(setReleased(null, ['a'], true), ['a'])
	const selected = ['a']
	setReleased(selected, ['b'], true)
	assert.deepEqual(selected, ['a'], 'The input array is not mutated')
})
