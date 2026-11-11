import assert from 'node:assert/strict'
import test from 'node:test'

import { CAPABILITY_KEYS, capabilitiesOf, capabilityTexts, groupOfferedTools, toolKeyOf } from '../../src/utils/capabilities.js'

const builtin = (name, label = name) => ({ id: null, name: label, is_builtin: true, builtin_name: name })
const offered = [
	builtin('rag_search_documents', 'Document Search (RAG)'),
	builtin('room_search_documents', 'Room Document Search'),
	builtin('room_search_images'),
	builtin('attachment_analyze_image'),
	builtin('wiki_search'),
	builtin('wiki_write_page'),
	builtin('catalogue_search'),
	builtin('catalogue_get_opportunity'),
	builtin('api_mensa_menu', 'Canteen menu'),
	{ id: 7, name: 'Room booking', description: 'Creates bookings', is_builtin: false, builtin_name: null },
]

test('every capability has its texts', () => {
	for (const key of CAPABILITY_KEYS) {
		const texts = capabilityTexts(key)
		assert.ok(texts.short && texts.title && texts.text, key)
	}
})

test('selection keys follow the bot form: builtin:<name> or the numeric id', () => {
	assert.equal(toolKeyOf(builtin('room_search_documents')), 'builtin:room_search_documents')
	assert.equal(toolKeyOf({ id: 7, is_builtin: false }), 7)
	assert.equal(toolKeyOf({ id: null, is_builtin: true }), null)
})

test('offered tools become capabilities; knowledge and wiki have their own place', () => {
	const { capabilities, extras } = groupOfferedTools(offered)
	assert.deepEqual(capabilities.map(capability => [capability.key, capability.offered, capability.toolKeys]), [
		['documents', true, ['builtin:room_search_documents']],
		['images', true, ['builtin:room_search_images', 'builtin:attachment_analyze_image']],
		['voice', false, []],
		['catalogue', true, ['builtin:catalogue_search', 'builtin:catalogue_get_opportunity']],
	])
	assert.equal(capabilities.find(capability => capability.key === 'documents').recommended, true)
	assert.deepEqual(extras.map(tool => tool.name), ['Canteen menu', 'Room booking'])
	assert.deepEqual(groupOfferedTools(undefined), {
		capabilities: capabilities.map(capability => ({ ...capability, toolKeys: [], offered: false })),
		extras: [],
	})
})

test('a bot lists knowledge only with sources, then its capabilities, then its single tools', () => {
	const tools = [
		builtin('attachment_analyze_image'),
		builtin('room_search_images'),
		builtin('room_search_documents'),
		builtin('wiki_read_page'),
		{ name: 'Room booking', is_builtin: false },
		builtin('api_mensa_menu', 'Canteen menu'),
	]
	assert.deepEqual(capabilitiesOf({ rag_enabled: true, rag_source_count: 18, tools }).map(entry => entry.label),
		['Knowledge · 18 sources', 'Documents in chat', 'Images', 'Room booking', 'Canteen menu'])
	assert.deepEqual(capabilitiesOf({ rag_enabled: true, rag_source_count: 1, tools: [] }).map(entry => entry.label), ['Knowledge · 1 source'])
	assert.deepEqual(capabilitiesOf({ rag_enabled: true, tools: [] }).map(entry => entry.label), ['Knowledge'])
	assert.deepEqual(capabilitiesOf({ rag_enabled: true, rag_source_count: 0, tools: [] }), [])
	assert.deepEqual(capabilitiesOf({ rag_enabled: false, rag_source_count: 4, tools: [] }), [])
	assert.deepEqual(capabilitiesOf({}), [])
})

test('the old catalogue tool name still counts as the catalogue', () => {
	assert.deepEqual(capabilitiesOf({ tools: [builtin('catalogue_search_courses')] }).map(entry => entry.key), ['catalogue'])
})
