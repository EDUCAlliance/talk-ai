import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { createRenderer, h, nextTick } from 'vue'
import { getCanonicalLocale, n, t } from '../../src/l10n.js'
import { apiToolBlockReason, unavailableSelections } from '../../src/utils/apiConnections.js'
import { getApiErrorMessage } from '../../src/utils/apiError.js'
import { SCOPE_ICONS, reviewersOf } from '../../src/utils/botScope.js'
import { capabilitiesOf, capabilityTexts, groupOfferedTools } from '../../src/utils/capabilities.js'
import * as modelSelection from '../../src/utils/modelSelection.js'
import { normalizeToolSelectionAliases } from '../../src/utils/toolAliases.js'

const plain = value => JSON.parse(JSON.stringify(value))
const deferred = () => {
	let resolve
	const promise = new Promise(yes => { resolve = yes })
	return { promise, resolve }
}
const settle = async () => {
	for (let round = 0; round < 6; round++) {
		await nextTick()
		await Promise.resolve()
	}
}

// Mounts without rendering, like helpers/vueState.mjs, but keeps the mounted hook: BotEditor inherits it from
// BotForm, and loading on mount is part of what these tests cover.
const renderer = createRenderer({ createComment: () => ({}), insert() {}, remove() {}, parentNode: () => null, nextSibling: () => null })
function mountState(definition, props, listeners) {
	let state
	const app = renderer.createApp({
		render: () => h({ ...definition, render: () => null }, { ...props, ...listeners, ref: value => { if (value) state = value } }),
	})
	app.mount({})
	return { state, unmount: () => app.unmount() }
}

const builtin = name => ({ id: null, name, description: '', is_builtin: true, builtin_name: name, aliases: [] })
const OFFER = ['rag_search_documents', 'room_search_documents', 'room_search_images', 'attachment_analyze_image', 'wiki_search'].map(builtin)
const DOCUMENTS = 'builtin:room_search_documents'
const IMAGES = ['builtin:room_search_images', 'builtin:attachment_analyze_image']

const savedBot = (changes = {}) => ({
	id: 4, bot_name: 'Study FAQ', mention_name: '@studyfaq', description: 'Answers questions.', system_prompt: 'Help students.',
	visibility: 'groups', allowed_groups: ['ikm'], allowed_teams: [], approval_status: 'approved', rag_enabled: false,
	onboarding_questions: null, ...changes,
})

// The real scripts of BotForm and of BotEditor, which extends it, with their Vue reactivity and watchers.
// HTTP, dialogs and child components are fixtures; the rules they call are the real ones.
function editor(ctx, { props = {}, routes = {}, ownBots = [], publicBots = [] } = {}) {
	const errors = [], emitted = [], requests = []
	const answer = (method, url, payload) => {
		requests.push([method, url, payload === undefined ? undefined : plain(payload)])
		const route = routes[`${method} ${url}`] ?? routes[url]
		const value = typeof route === 'function' ? route(payload) : route
		return value && typeof value.then === 'function' ? value : Promise.resolve({ data: value ?? {} })
	}
	const axios = { get: url => answer('GET', url), post: (url, payload) => answer('POST', url, payload) }
	const scope = {
		axios, WIKI_ROOT_FOLDER: 'Talk AI', generateUrl: value => value,
		NcProgressBar: {}, NcSelect: {}, ModelSelector: {}, TalkAiIcon: {},
		t, n, getCanonicalLocale, getApiErrorMessage, modelSelection, normalizeToolSelectionAliases,
		apiToolBlockReason, unavailableSelections, SCOPE_ICONS, reviewersOf, capabilitiesOf, capabilityTexts, groupOfferedTools,
		store: { state: { ownBots, publicBots } },
		showError: value => errors.push(value), showSuccess() {},
		console: { error() {}, log() {}, warn() {} },
		setInterval: () => 1, clearInterval() {},
	}
	vm.createContext(scope)
	for (const name of ['BotForm', 'BotEditor']) {
		const source = readFileSync(new URL(`../../src/components/${name}.vue`, import.meta.url), 'utf8')
		vm.runInContext(source.match(/<script>([\s\S]*?)<\/script>/)[1]
			.replace(/^import .+$/gm, '').replace('export default', `globalThis.${name} =`), scope)
	}
	const mounted = mountState(scope.BotEditor, props, {
		onSave: value => emitted.push(['save', plain(value)]),
		'onUpdate:dirty': value => emitted.push(['dirty', value]),
	})
	ctx.after(mounted.unmount)
	return { ...mounted, errors, emitted, requests }
}

const API = '/apps/educai/api/v1'
const offer = { [`${API}/tools`]: { tools: OFFER } }

test('a template applies its capabilities even when the user typed before the tool offer arrived', async ctx => {
	const tools = deferred()
	const { state } = editor(ctx, {
		props: { mode: 'wizard', preset: { systemPrompt: 'Explain.', capabilities: ['documents', 'images'] } },
		routes: { [`${API}/tools`]: () => tools.promise },
	})
	await settle()
	assert.equal(state.formData.systemPrompt, 'Explain.')
	assert.equal(state.toolsPending, true)

	state.arm()
	state.formData.botName = 'Typed early'
	await settle()
	assert.equal(state.dirty, true)

	tools.resolve({ data: { tools: OFFER } })
	await settle()
	assert.equal(state.toolsPending, false)
	assert.deepEqual([...state.formData.selectedTools].sort(), [DOCUMENTS, ...IMAGES].sort())
	assert.deepEqual(plain(state.capabilityCards.map(card => [card.key, card.offered, card.on])), [
		['documents', true, true], ['images', true, true], ['voice', false, false],
	])
})

test('a new bot without a template starts with "Documents in chat" and nothing else', async ctx => {
	const { state } = editor(ctx, { props: { mode: 'wizard' }, routes: offer })
	await settle()
	assert.deepEqual(plain(state.formData.selectedTools), [DOCUMENTS])
	assert.equal(state.dirty, false)
})

test('loading a saved bot is not a change; the first input is, and it is reported once', async ctx => {
	const { state, emitted } = editor(ctx, {
		props: { bot: savedBot() },
		routes: { ...offer, [`${API}/bots/4/tools`]: { tools: [{ is_builtin: true, builtin_name: 'room_search_documents' }] }, [`${API}/bots/4/sources`]: { sources: [] } },
	})
	await settle()
	assert.deepEqual(plain(state.formData.selectedTools), [DOCUMENTS])
	assert.equal(state.dirty, false)
	assert.deepEqual(emitted, [])

	state.arm()
	state.formData.description = 'Changed.'
	await settle()
	state.formData.description = 'Changed again.'
	await settle()
	assert.equal(state.dirty, true)
	assert.deepEqual(emitted, [['dirty', true]])
})

test('the switches wait for the saved selection, also after discarding', async ctx => {
	const first = deferred(), second = deferred()
	const answers = [first, second]
	const { state } = editor(ctx, {
		props: { bot: savedBot() },
		routes: { ...offer, [`${API}/bots/4/tools`]: () => answers.shift().promise, [`${API}/bots/4/sources`]: { sources: [] } },
	})
	await settle()
	assert.equal(state.toolsPending, true)
	first.resolve({ data: { tools: [{ is_builtin: true, builtin_name: 'room_search_documents' }] } })
	await settle()
	assert.equal(state.toolsPending, false)

	state.arm()
	state.formData.botName = 'Unsaved name'
	state.toggleCapability(state.capabilityCards.find(card => card.key === 'images'))
	await settle()
	assert.equal(state.dirty, true)
	assert.equal(state.capabilityCards.find(card => card.key === 'images').on, true)

	state.reset()
	await settle()
	// The selection is being loaded again: no switch to click until it is known
	assert.equal(state.toolsPending, true)
	assert.equal(state.formData.botName, 'Study FAQ')
	assert.equal(state.dirty, false)
	second.resolve({ data: { tools: [{ is_builtin: true, builtin_name: 'room_search_documents' }] } })
	await settle()
	assert.equal(state.toolsPending, false)
	assert.deepEqual(plain(state.formData.selectedTools), [DOCUMENTS])
	assert.equal(state.dirty, false)
})

test('a capability switch turns all of its tools on and off, the exact tools show the same selection', async ctx => {
	const { state } = editor(ctx, { props: { mode: 'wizard' }, routes: offer })
	await settle()
	const images = () => state.capabilityCards.find(card => card.key === 'images')
	state.toggleCapability(images())
	assert.deepEqual([...state.formData.selectedTools].sort(), [DOCUMENTS, ...IMAGES].sort())
	// One of the two tools removed in the exact list: the capability still counts as on
	state.formData.selectedTools = state.formData.selectedTools.filter(key => key !== IMAGES[0])
	assert.equal(images().on, true)
	state.toggleCapability(images())
	assert.deepEqual(plain(state.formData.selectedTools), [DOCUMENTS])
	assert.deepEqual(state.previewBot.tools.map(tool => tool.builtin_name), ['room_search_documents'])
})

test('the first source turns the knowledge search on, a paused search stays paused', async ctx => {
	const sources = [{ id: 1, status: 'ready', node_type: 'file', path: '/a.pdf' }]
	const fresh = editor(ctx, { props: { bot: savedBot() }, routes: { ...offer, [`${API}/bots/4/tools`]: { tools: [] }, [`${API}/bots/4/sources`]: { sources: [] } } })
	await settle()
	assert.equal(fresh.state.knowledgePaused, false)
	fresh.state.rag.sources = sources
	fresh.state.afterSourcesAdded(0)
	await settle()
	assert.equal(fresh.state.formData.ragEnabled, true)
	assert.equal(fresh.state.dirty, true)
	assert.equal(fresh.state.knowledgeOn, true)

	// Sources that the saved bot does not search were paused on purpose
	const paused = editor(ctx, { props: { bot: savedBot() }, routes: { ...offer, [`${API}/bots/4/tools`]: { tools: [] }, [`${API}/bots/4/sources`]: { sources } } })
	await settle()
	assert.equal(paused.state.knowledgePaused, true)
	assert.equal(paused.state.dirty, false)
	paused.state.rag.sources = [...sources, { id: 2, status: 'pending', node_type: 'url', source_url: 'https://example.org' }]
	paused.state.afterSourcesAdded(1)
	assert.equal(paused.state.formData.ragEnabled, false)
	paused.state.setKnowledgePaused(false)
	assert.equal(paused.state.formData.ragEnabled, true)
})

test('creativity presets map onto the temperature and back', async ctx => {
	const { state } = editor(ctx, { props: { mode: 'wizard' }, routes: offer })
	await settle()
	assert.equal(state.creativity, 'default')
	const level = key => state.creativityLevels.find(entry => entry.key === key)
	state.setCreativity(level('balanced'))
	assert.deepEqual([state.formData.useCustomTemperature, state.formData.temperature, state.creativity], [true, 0.4, 'balanced'])
	state.setCreativity(level('custom'))
	assert.equal(state.creativity, 'custom')
	assert.equal(state.formData.temperature, 0.4)
	state.formData.temperature = 0.55
	assert.equal(state.creativity, 'custom')
	state.setCreativity(level('default'))
	assert.deepEqual([state.formData.useCustomTemperature, state.creativity], [false, 'default'])
})

test('a step names what is missing, and a mention that another bot uses', async ctx => {
	const { state, errors, emitted } = editor(ctx, {
		props: { mode: 'wizard' }, routes: offer, publicBots: [{ id: 9, mention_name: '@Canteen' }],
	})
	await settle()
	assert.equal(state.validateStep(1), 'Give the bot a name.')
	state.formData.botName = 'Canteen'
	state.onNameInput()
	assert.equal(state.formData.mentionName, 'canteen')
	assert.equal(state.validateStep(1), 'Another bot already uses this mention.')
	state.formData.mentionName = 'my canteen'
	assert.equal(state.validateStep(1), 'Use only letters, numbers, hyphens and underscores.')
	state.formData.mentionName = 'mycanteen'
	assert.equal(state.validateStep(1), 'Describe what the bot should do.')
	state.formData.systemPrompt = 'Tell the menu.'
	assert.equal(state.validateStep(1), '')
	state.formData.visibility = 'groups'
	assert.equal(state.validateStep(2), 'Choose at least one group.')

	assert.equal(state.save(), false)
	assert.deepEqual(errors, ['Choose at least one group.'])
	assert.deepEqual(emitted.filter(([name]) => name === 'save'), [])
})

test('the mention follows the name until it is typed by hand', async ctx => {
	const { state } = editor(ctx, { props: { mode: 'wizard' }, routes: offer })
	await settle()
	state.formData.botName = 'Übungs-Coach für Prüfungen 2026'
	state.onNameInput()
	assert.equal(state.formData.mentionName, 'ubungscoachfurprufun')
	state.mentionTouched = true
	state.formData.botName = 'Other name'
	state.onNameInput()
	assert.equal(state.formData.mentionName, 'ubungscoachfurprufun')
})

test('a new bot is saved with its tools, and with the knowledge search on when sources are waiting', async ctx => {
	const { state, emitted, requests } = editor(ctx, { props: { mode: 'wizard' }, routes: offer })
	await settle()
	Object.assign(state.formData, { botName: 'Handbook', mentionName: 'handbook', systemPrompt: 'Answer from the handbook.' })
	assert.equal(state.save(), true)
	assert.equal(emitted.find(([name]) => name === 'save')[1].ragEnabled, false)

	state.pendingSources.push({ kind: 'url', sourceUrl: 'https://example.org/a', label: 'example.org/a' }, { kind: 'node', nodeId: 55, nodeType: 'folder', label: '/Docs' })
	assert.equal(state.save(), true)
	const payload = emitted.filter(([name]) => name === 'save')[1][1]
	assert.equal(payload.ragEnabled, true)
	assert.equal(payload.id, undefined)
	assert.deepEqual(payload.tools, [{ is_builtin: true, builtin_name: 'room_search_documents' }])

	assert.equal(await state.attachPendingSources(12), 0)
	assert.deepEqual(requests.filter(([method]) => method === 'POST'), [
		['POST', `${API}/bots/12/sources`, { sourceUrl: 'https://example.org/a' }],
		['POST', `${API}/bots/12/sources`, { nodeId: 55, nodeType: 'folder' }],
	])
})

test('a source that cannot be attached is reported by name and does not stop the others', async ctx => {
	let calls = 0
	const { state, errors } = editor(ctx, {
		props: { mode: 'wizard' },
		routes: { ...offer, [`POST ${API}/bots/12/sources`]: () => ++calls === 1 ? Promise.reject(new Error('offline')) : Promise.resolve({ data: {} }) },
	})
	await settle()
	state.pendingSources.push({ kind: 'url', sourceUrl: 'https://a.example', label: 'a.example' }, { kind: 'url', sourceUrl: 'https://b.example', label: 'b.example' })
	assert.equal(await state.attachPendingSources(12), 1)
	assert.equal(calls, 2)
	assert.deepEqual(errors, ['Could not add “a.example” to the knowledge.'])
})

test('saving says when a change goes into review first', async ctx => {
	const routes = { ...offer, [`${API}/bots/4/tools`]: { tools: [] }, [`${API}/bots/4/sources`]: { sources: [] } }
	const groupAdmin = { isAdmin: false, isGroupAdmin: true, isTeamAdmin: false, adminGroups: ['ikm'], adminTeams: [] }
	const cases = [
		// The owner of an approved bot never approves their own change, even as admin of the group
		['owner of an approved group bot', savedBot(), [{ id: 4 }], groupAdmin, true],
		['group admin editing a bot of someone else', savedBot(), [], groupAdmin, false],
		['owner of a draft', savedBot({ approval_status: 'draft' }), [{ id: 4 }], {}, false],
		['owner of a personal bot', savedBot({ visibility: 'personal', approval_status: 'personal', allowed_groups: [] }), [{ id: 4 }], {}, false],
		['bot with a change already in review', savedBot({ approval_status: 'pending', has_pending_changes: true, pending_changes: {} }), [{ id: 4 }], {}, true],
	]
	for (const [name, bot, ownBots, permissions, expected] of cases) {
		const { state } = editor(ctx, { props: { bot, userPermissions: { isAdmin: false, isGroupAdmin: false, isTeamAdmin: false, adminGroups: [], adminTeams: [], ...permissions } }, routes, ownBots })
		await settle()
		assert.equal(state.saveGoesToReview, expected, name)
	}
})

test('the preview is the bot as the tile will show it, and says when it goes live', async ctx => {
	const { state } = editor(ctx, {
		props: { mode: 'wizard', userPermissions: { isAdmin: false, isGroupAdmin: false, isTeamAdmin: false, adminGroups: [], adminTeams: [] } },
		routes: { ...offer, [`${API}/groups`]: { groups: [{ id: 'ikm', displayName: 'IKM students' }] } },
	})
	await settle()
	assert.equal(state.preview.goesLive, 'Right after creation, only for you')
	assert.equal(state.preview.requiresApproval, false)
	Object.assign(state.formData, { botName: 'Handbook', mentionName: 'handbook', visibility: 'groups', allowedGroups: ['ikm'] })
	state.pendingSources.push({ kind: 'url', sourceUrl: 'https://example.org/a', label: 'example.org/a' })
	assert.deepEqual(plain(state.preview.bot), {
		id: null, bot_name: 'Handbook', mention_name: 'handbook', description: '', visibility: 'groups',
		allowed_groups: ['ikm'], allowed_teams: [], rag_enabled: true, rag_source_count: 1,
		tools: [{ name: 'room_search_documents', is_builtin: true, builtin_name: 'room_search_documents' }],
	})
	assert.equal(state.preview.requiresApproval, true)
	assert.equal(state.preview.goesLive, 'After approval by the admins of the group “IKM students”')
	assert.equal(state.preview.knowledge, '1 source')
	assert.equal(state.preview.abilities, 'Knowledge · 1 source · Documents in chat')
	assert.deepEqual(plain(state.summaryRows.map(row => [row.step, row.value, row.warn])), [
		[1, 'Handbook · @handbook', true],
		[2, 'IKM students · needs approval', false],
		[3, '1 source', false],
		[4, 'Knowledge · 1 source · Documents in chat', false],
	])
})
