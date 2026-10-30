import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { nextTick } from 'vue'
import { mountState } from './helpers/vueState.mjs'
import { getApiErrorMessage } from '../../src/utils/apiError.js'
import { normalizeToolSelectionAliases } from '../../src/utils/toolAliases.js'
import * as modelSelection from '../../src/utils/modelSelection.js'
import { t, n, getCanonicalLocale } from '../../src/l10n.js'

const plain = value => JSON.parse(JSON.stringify(value))
const deferred = () => {
	let resolve, reject
	const promise = new Promise((yes, no) => { resolve = yes; reject = no })
	return { promise, resolve, reject }
}
const settle = async () => {
	await nextTick()
	await Promise.resolve()
	await nextTick()
}

// Real component methods, computed state and Vue watchers; HTTP/dialogs are fixtures.
function component(ctx, path, axios, props = {}, globals = {}) {
	const source = readFileSync(new URL(`../../src/${path}.vue`, import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .+$/gm, '').replace('export default', 'globalThis.component =')
	const errors = [], successes = [], emitted = []
	const scope = {
		axios, APP_DISPLAY_NAME: 'Talk AI', WIKI_ROOT_FOLDER: 'Talk AI',
		BotCard: {}, BotForm: {}, ModelSelector: {}, NcProgressBar: {},
		generateUrl: value => value, t, n, getCanonicalLocale, getApiErrorMessage,
		modelSelection, normalizeToolSelectionAliases,
		showError: value => errors.push(value), showSuccess: value => successes.push(value),
		console: { error() {}, log() {}, warn() {} },
		setInterval: () => 1, clearInterval() {}, ...globals,
	}
	vm.runInNewContext(script, scope)
	const mounted = mountState(scope.component, props, {
		onSave: value => emitted.push(['save', value]),
		onCancel: () => emitted.push(['cancel']),
	})
	ctx.after(mounted.unmount)
	return { ...mounted, definition: scope.component, errors, successes, emitted }
}

const originalTree = () => ({ start: 'q1', questions: [
	{ id: 'q1', text: 'Original question', answers: [
		{ id: 'a', text: 'First answer', next: 'q2' },
		{ id: 'b', text: 'Second answer', next: null },
	] },
	{ id: 'q2', text: 'Follow up', answers: [
		{ id: 'a', text: 'Yes', next: null }, { id: 'b', text: 'No', next: null },
	] },
] })
const bot = (id = 1, changes = {}) => ({
	id, bot_name: `Bot ${id}`, mention_name: `@bot${id}`, system_prompt: 'Help users',
	visibility: 'personal', allowed_groups: [], allowed_teams: [], onboarding_questions: originalTree(), ...changes,
})
const wikiTools = id => [{ is_builtin: true, builtin_name: 'wiki_read_page', config: { wiki_location: 'collective', wiki_collective_id: id } }]
const locations = () => ({ data: { collectives: [
	{ id: 7, display_name: 'First collective', team_id: 'team-a' },
	{ id: 8, display_name: 'Second collective', team_id: 'team-b' },
] } })
const form = (ctx, axios = { get: async () => ({ data: { tools: [], sources: [] } }) }, savedBot = bot()) =>
	component(ctx, 'components/BotForm', axios, { bot: savedBot, userPermissions: { isAdmin: true } })

for (const reason of [null, '', 'Needs a clearer prompt']) {
	test(`rejection dialog ${reason === null ? 'Cancel does nothing' : `confirmation sends optional reason ${JSON.stringify(reason)}`}`, async ctx => {
		const posts = []
		let refreshed = 0
		const { state, successes } = component(ctx, 'views/PersonalBots', {
			post: async (url, payload) => { posts.push([url, plain(payload)]) },
		}, {}, { prompt: () => reason })
		state.loadPendingApprovals = async () => { refreshed++ }
		await state.rejectBot(17)
		assert.deepEqual(posts, reason === null ? [] : [['/apps/educai/api/v1/bots/17/reject', { reason }]])
		assert.equal(refreshed, reason === null ? 0 : 1)
		assert.equal(successes.length, reason === null ? 0 : 1)
	})
}

for (const pending of [false, true]) {
	test(`cancelled nested onboarding edits never change ${pending ? 'pending' : 'approved'} data or the next save`, async ctx => {
		const savedBot = bot(1, pending ? { has_pending_changes: true, pending_changes: { onboarding_questions: originalTree(), tools: [] } } : {})
		const snapshot = plain(savedBot)
		const { state, emitted } = form(ctx, undefined, savedBot)
		state.initializeForm()
		await settle()
		state.formData.onboardingQuestions.questions[0].text = 'Cancelled question'
		state.formData.onboardingQuestions.questions[0].answers[0].text = 'Cancelled answer'
		state.formData.onboardingQuestions.questions[0].answers[0].next = null
		state.removeOnboardingQuestion(1)
		state.addOnboardingQuestion()
		state.setQuestionFreeText(0, true)
		state.$emit('cancel')
		assert.deepEqual(plain(savedBot), snapshot)
		assert.deepEqual(emitted, [['cancel']])

		const reopened = form(ctx, undefined, savedBot)
		reopened.state.initializeForm()
		await settle()
		reopened.state.formData.description = 'An unrelated description edit'
		reopened.state.submitForm()
		assert.equal(reopened.emitted.length, 1)
		const payload = plain(reopened.emitted[0][1])
		assert.equal(payload.description, 'An unrelated description edit')
		assert.deepEqual(payload.onboardingQuestions, originalTree())
		assert.deepEqual(plain(savedBot), snapshot)
	})
}

test('saving intentionally edited onboarding changes sends the new tree without mutating approved data', async ctx => {
	const savedBot = bot()
	const { state, emitted } = form(ctx, undefined, savedBot)
	state.initializeForm()
	await settle()
	state.formData.onboardingQuestions.questions[0].answers[1].text = 'Changed intentionally'
	state.submitForm()
	assert.equal(emitted[0][1].onboardingQuestions.questions[0].answers[1].text, 'Changed intentionally')
	assert.deepEqual(plain(savedBot.onboarding_questions), originalTree())
})

for (const visibility of ['personal', 'teams']) {
	for (const toolsFirst of [true, false]) {
		test(`${visibility} collective selection and save survive ${toolsFirst ? 'tools' : 'locations'} arriving first`, async ctx => {
			const tools = deferred(), lists = deferred()
			const { state, emitted } = form(ctx, { get: url => url.endsWith('/tools') ? tools.promise : lists.promise },
				bot(1, { visibility, allowed_teams: ['team-a'] }))
			state.formData.visibility = visibility
			state.formData.allowedTeams = ['team-a']
			const toolsRequest = state.loadBotTools(), locationsRequest = state.loadWikiLocations()
			if (toolsFirst) {
				tools.resolve({ data: { tools: wikiTools(7) } })
				await toolsRequest
				await settle()
				assert.equal(state.formData.personalWikiCollectiveId, '7')
				assert.equal(state.unresolvedWikiCollectiveId, '7')
				lists.resolve(locations())
			} else {
				lists.resolve(locations())
				await locationsRequest
				await settle()
				tools.resolve({ data: { tools: wikiTools(7) } })
			}
			await Promise.all([toolsRequest, locationsRequest])
			await settle()
			assert.equal(state.formData.personalWikiCollectiveId, '7')
			assert.equal(state.unresolvedWikiCollectiveId, '')
			assert.equal(state.formData.personalWikiEnabled, true)
			state.submitForm()
			assert.equal(emitted.length, 1)
			assert(emitted[0][1].tools.every(entry => entry.config.wiki_collective_id === 7))
		})
	}
}

test('pending tool selections remain selected while collective locations are loading', async ctx => {
	const lists = deferred()
	const savedBot = bot(1, { has_pending_changes: true, pending_changes: { tools: wikiTools(7) } })
	const { state } = form(ctx, { get: () => lists.promise }, savedBot)
	const request = state.loadWikiLocations()
	await state.loadBotTools()
	assert.equal(state.formData.personalWikiCollectiveId, '7')
	lists.resolve(locations())
	await request
	assert.equal(state.formData.personalWikiCollectiveId, '7')
})

for (const malformed of [false, true]) {
	test(`${malformed ? 'malformed' : 'failed'} collective response preserves saved ID and retry recovers`, async ctx => {
		let attempts = 0
		const { state, emitted, errors } = form(ctx, { get: async () => {
			if (++attempts > 1) return locations()
			if (malformed) return { data: {} }
			throw new Error('Network failed')
		} })
		state.applyToolSelection(wikiTools(7))
		await state.loadWikiLocations()
		assert.equal(state.formData.personalWikiCollectiveId, '7')
		assert.equal(state.unresolvedWikiCollectiveId, '7')
		assert.equal(state.wikiLocationsLoaded, false)
		assert.equal(state.wikiLocationsError, true)
		state.submitForm()
		assert.equal(emitted.length, 0)
		assert.match(errors.at(-1), /saved selection has been preserved/)
		await state.loadWikiLocations()
		assert.equal(state.wikiLocationsError, false)
		assert.equal(state.formData.personalWikiCollectiveId, '7')
		state.submitForm()
		assert.equal(emitted.length, 1)
	})
}

test('failed collective refresh retains previously loaded options and the chosen collective', async ctx => {
	let fail = false
	const { state } = form(ctx, { get: async () => { if (fail) throw new Error('Offline'); return locations() } })
	await state.loadWikiLocations()
	state.applyToolSelection(wikiTools(7))
	fail = true
	await state.loadWikiLocations()
	assert.equal(state.formData.personalWikiCollectiveId, '7')
	assert.equal(state.availableCollectives.length, 2)
	assert.equal(state.wikiLocationsError, true)
})

test('successful authoritative empty collective list and team scope changes still invalidate selections', async ctx => {
	const { state } = form(ctx, { get: async () => locations() })
	state.formData.visibility = 'teams'
	state.formData.allowedTeams = ['team-a']
	state.applyToolSelection(wikiTools(7))
	await state.loadWikiLocations()
	assert.equal(state.formData.personalWikiCollectiveId, '7')
	state.formData.allowedTeams = ['team-b']
	await settle()
	assert.equal(state.formData.personalWikiCollectiveId, '')
	state.applyToolSelection(wikiTools(8))
	assert.equal(state.formData.personalWikiCollectiveId, '8')
	state.availableCollectives = []
	state.ensureWikiSelectionValid()
	assert.equal(state.formData.personalWikiCollectiveId, '')
})

test('older collective success or failure cannot overwrite the newest locations', async ctx => {
	for (const rejectOld of [false, true]) {
		const old = deferred(), latest = deferred()
		let count = 0
		const { state } = form(ctx, { get: () => ++count === 1 ? old.promise : latest.promise })
		state.applyToolSelection(wikiTools(7))
		const first = state.loadWikiLocations(), second = state.loadWikiLocations()
		latest.resolve(locations())
		await second
		if (rejectOld) old.reject(new Error('Old failed request'))
		else old.resolve({ data: { collectives: [] } })
		await first
		assert.equal(state.formData.personalWikiCollectiveId, '7')
		assert.equal(state.availableCollectives.length, 2)
		assert.equal(state.wikiLocationsError, false)
		assert.equal(state.wikiLocationsLoading, false)
	}
})

test('tool load failure or a still pending response cannot erase saved tools through unrelated save', async ctx => {
	const request = deferred()
	const { state, emitted, errors } = form(ctx, { get: () => request.promise })
	const loading = state.loadBotTools()
	state.submitForm()
	assert.equal(emitted.length, 0)
	assert.match(errors.at(-1), /Loading tools/)
	request.reject(new Error('Offline'))
	await loading
	state.submitForm()
	assert.equal(emitted.length, 0)
	assert.match(errors.at(-1), /Failed to load tools/)
})

test('switching edited bots resets local drafts and rejects the previous bot tools and sources', async ctx => {
	const oldTools = deferred(), oldSources = deferred()
	const { state, errors, setProps } = form(ctx, { get: url => {
		if (url.includes('/1/')) return url.endsWith('/tools') ? oldTools.promise : oldSources.promise
		return Promise.resolve({ data: url.endsWith('/tools') ? { tools: wikiTools(8) } : { sources: [{ id: 202, status: 'ready' }] } })
	} })
	state.wikiLocationsLoaded = true
	state.availableCollectives = locations().data.collectives
	state.initializeForm()
	state.formData.onboardingQuestions.questions[0].text = 'Unsaved first bot edit'
	setProps({ bot: bot(2) })
	await settle()
	assert.equal(state.formData.botName, 'Bot 2')
	assert.equal(state.formData.onboardingQuestions.questions[0].text, 'Original question')
	assert.equal(state.formData.personalWikiCollectiveId, '8')
	assert.equal(state.rag.sources[0].id, 202)
	oldTools.resolve({ data: { tools: wikiTools(7) } })
	oldSources.resolve({ data: { sources: [{ id: 101, status: 'pending' }] } })
	await settle()
	assert.equal(state.formData.personalWikiCollectiveId, '8')
	assert.equal(state.rag.sources[0].id, 202)
	assert.equal(state.rag.pollingInterval, null)
	assert.equal(errors.length, 0)
	setProps({ bot: null })
	await settle()
	assert.equal(state.formData.botName, '')
	assert.equal(state.formData.personalWikiEnabled, false)
	assert.equal(state.rag.sources.length, 0)
})

const pollingForm = (ctx, axios) => {
	ctx.mock.timers.enable({ apis: ['setInterval'] })
	return component(ctx, 'components/BotForm', axios,
		{ bot: bot(), userPermissions: { isAdmin: true } }, { setInterval, clearInterval })
}

for (const responseDelay of [3000, 5000]) {
	test(`source polling accepts a ${responseDelay}ms response and stops after indexing completes`, async ctx => {
		const pending = deferred()
		let requests = 0
		const { state } = pollingForm(ctx, { get: () => { requests++; return pending.promise } })
		state.rag.sources = [{ id: 101, status: 'pending' }]
		state.startPolling()
		ctx.mock.timers.tick(2000)
		assert.equal(requests, 1)
		assert.equal(state.rag.loading, true)
		ctx.mock.timers.tick(responseDelay)
		assert.equal(requests, 1, 'automatic ticks must not supersede the slow request')
		pending.resolve({ data: { sources: [{ id: 101, status: 'ready' }] } })
		await settle()
		assert.equal(state.rag.sources[0].status, 'ready')
		assert.equal(state.rag.loading, false)
		ctx.mock.timers.tick(2000)
		assert.equal(state.rag.pollingInterval, null)
		ctx.mock.timers.tick(10000)
		assert.equal(requests, 1)
	})
}

test('source polling recovers after a slow request fails without losing pending status', async ctx => {
	const requests = []
	const { state, errors } = pollingForm(ctx, { get: () => {
		const request = deferred()
		requests.push(request)
		return request.promise
	} })
	state.rag.sources = [{ id: 101, status: 'pending' }]
	state.startPolling()
	ctx.mock.timers.tick(2000)
	ctx.mock.timers.tick(5000)
	assert.equal(requests.length, 1)
	requests[0].reject(new Error('Temporary network failure'))
	await settle()
	assert.equal(state.rag.loading, false)
	assert.equal(state.rag.sources[0].status, 'pending')
	assert.equal(errors.length, 1)
	ctx.mock.timers.tick(1000)
	assert.equal(requests.length, 2)
	ctx.mock.timers.tick(5000)
	assert.equal(requests.length, 2)
	requests[1].resolve({ data: { sources: [{ id: 101, status: 'ready' }] } })
	await settle()
	ctx.mock.timers.tick(2000)
	assert.equal(state.rag.sources[0].status, 'ready')
	assert.equal(state.rag.loading, false)
	assert.equal(state.rag.pollingInterval, null)
	assert.equal(errors.length, 1)
})

test('explicit source refresh after reindex supersedes an in-flight poll', async ctx => {
	const requests = [], posts = []
	const { state, errors } = pollingForm(ctx, {
		get: () => {
			const request = deferred()
			requests.push(request)
			return request.promise
		},
		post: async url => { posts.push(url) },
	})
	state.rag.sources = [{ id: 101, status: 'pending' }]
	state.startPolling()
	ctx.mock.timers.tick(2000)
	const reindex = state.reindexSource({ id: 101 })
	await settle()
	assert.deepEqual(posts, ['/apps/educai/api/v1/bots/1/sources/101/reindex'])
	assert.equal(requests.length, 2, 'source mutations must still request fresh status immediately')
	requests[0].resolve({ data: { sources: [{ id: 101, status: 'pending' }] } })
	await settle()
	assert.equal(state.rag.loading, true, 'old request must not unlock the newer request')
	ctx.mock.timers.tick(5000)
	assert.equal(requests.length, 2)
	requests[1].resolve({ data: { sources: [{ id: 101, status: 'ready' }] } })
	await reindex
	assert.equal(state.rag.sources[0].status, 'ready')
	assert.equal(state.rag.loading, false)
	assert.equal(state.rag.reindexing[101], undefined)
	ctx.mock.timers.tick(2000)
	assert.equal(state.rag.pollingInterval, null)
	assert.equal(errors.length, 0)
})

for (const transition of ['switch', 'close']) {
	for (const rejectOld of [false, true]) {
		test(`source poll ${rejectOld ? 'failure' : 'success'} is ignored after form ${transition}`, async ctx => {
			const old = deferred(), current = deferred()
			let requests = 0
			const { state, errors, setProps, unmount } = pollingForm(ctx, { get: url => {
				if (url.endsWith('/tools')) return Promise.resolve({ data: { tools: [] } })
				requests++
				return url.includes('/1/') ? old.promise : current.promise
			} })
			state.rag.sources = [{ id: 101, status: 'pending' }]
			state.startPolling()
			ctx.mock.timers.tick(2000)
			if (transition === 'switch') setProps({ bot: bot(2) })
			else unmount()
			await settle()
			assert.equal(state.rag.pollingInterval, null)
			const previousSources = plain(state.rag.sources)
			const previousLoading = state.rag.loading
			if (rejectOld) old.reject(new Error('Stale source failure'))
			else old.resolve({ data: { sources: [{ id: 101, status: 'pending' }] } })
			await settle()
			assert.deepEqual(plain(state.rag.sources), previousSources)
			assert.equal(state.rag.loading, previousLoading)
			assert.equal(state.rag.pollingInterval, null)
			assert.equal(errors.length, 0)
			if (transition === 'switch') {
				assert.equal(requests, 2)
				assert.equal(state.rag.loading, true)
				current.resolve({ data: { sources: [{ id: 202, status: 'ready' }] } })
				await settle()
				assert.equal(state.rag.sources[0].id, 202)
				assert.equal(state.rag.loading, false)
			}
			ctx.mock.timers.tick(10000)
			assert.equal(requests, transition === 'switch' ? 2 : 1)
		})
	}
}

const activity = (ctx, axios) => component(ctx, 'views/PersonalActivity', axios)
const detail = (id, status = 'success') => ({ data: { trace: { id, status }, events: [{ id: `${id}-${status}`, status }] } })
const traceList = ids => ({ data: { traces: ids.map(id => ({ id, status: 'success' })), total: ids.length, offset: 0 } })

test('refresh reloads both the currently selected trace and its events', async ctx => {
	const calls = []
	const { state } = activity(ctx, { get: async url => {
		calls.push(url)
		return url.endsWith('/traces') ? traceList([1, 2]) : detail(2)
	} })
	state.selectedTraceId = 2
	state.selectedTrace = { id: 2, status: 'running' }
	state.events = [{ id: 'old-event', status: 'running' }]
	await state.loadTraces()
	assert.equal(state.selectedTraceId, 2)
	assert.equal(state.selectedTrace.status, 'success')
	assert.deepEqual(plain(state.events), detail(2).data.events)
	assert.deepEqual(calls, ['/apps/educai/api/v1/traces', '/apps/educai/api/v1/traces/2'])
	assert.equal(state.loading, false)
	assert.equal(state.detailLoading, false)
})

test('refresh follows the new selection when the user changes it before the list response', async ctx => {
	const list = deferred()
	const { state } = activity(ctx, { get: url => url.endsWith('/traces') ? list.promise : Promise.resolve(detail(2)) })
	state.selectedTraceId = 1
	const refresh = state.loadTraces()
	await state.selectTrace(2)
	list.resolve(traceList([1, 2]))
	await refresh
	assert.equal(state.selectedTraceId, 2)
	assert.equal(state.selectedTrace.id, 2)
})

for (const rejectOld of [false, true]) {
	test(`stale selected detail ${rejectOld ? 'error' : 'success'} cannot replace the new selection or its loading state`, async ctx => {
		const old = deferred(), current = deferred()
		const { state, errors } = activity(ctx, { get: url => url.endsWith('/1') ? old.promise : current.promise })
		const first = state.selectTrace(1), second = state.selectTrace(2)
		if (rejectOld) old.reject(new Error('Old request failed'))
		else old.resolve(detail(1))
		await first
		assert.equal(state.selectedTraceId, 2)
		assert.equal(state.selectedTrace, null)
		assert.equal(state.events.length, 0)
		assert.equal(state.detailLoading, true)
		assert.equal(state.detailError, '')
		assert.equal(errors.length, 0)
		current.resolve(detail(2))
		await second
		assert.equal(state.selectedTrace.id, 2)
		assert.equal(state.events[0].id, '2-success')
		assert.equal(state.detailLoading, false)
	})
}

test('older detail cannot overwrite a later refresh of the same selected trace', async ctx => {
	const old = deferred()
	let calls = 0
	const { state } = activity(ctx, { get: () => ++calls === 1 ? old.promise : Promise.resolve(detail(1)) })
	const first = state.selectTrace(1)
	await state.selectTrace(1)
	old.resolve(detail(1, 'running'))
	await first
	assert.equal(state.selectedTrace.status, 'success')
})

test('clearSelection cancels pending detail updates and resets loading', async ctx => {
	const pending = deferred()
	const { state } = activity(ctx, { get: () => pending.promise })
	const request = state.selectTrace(1)
	state.clearSelection()
	pending.resolve(detail(1))
	await request
	assert.equal(state.selectedTraceId, null)
	assert.equal(state.selectedTrace, null)
	assert.equal(state.events.length, 0)
	assert.equal(state.detailLoading, false)
})

test('refresh removal clears the selected trace and a failed detail refresh hides stale data', async ctx => {
	let omit = false
	const { state, errors } = activity(ctx, { get: async url => {
		if (url.endsWith('/traces')) return traceList(omit ? [] : [1])
		throw new Error('No longer available')
	} })
	state.selectedTraceId = 1
	state.selectedTrace = { id: 1, status: 'running' }
	state.events = [{ id: 'obsolete' }]
	await state.loadTraces()
	assert.equal(state.selectedTrace, null)
	assert.equal(state.events.length, 0)
	assert.match(state.detailError, /Activity details unavailable/)
	assert.equal(errors.length, 1)
	omit = true
	await state.loadTraces()
	assert.equal(state.selectedTraceId, null)
	assert.equal(state.detailError, '')
})

for (const rejectOld of [false, true]) {
	test(`older activity list ${rejectOld ? 'error' : 'success'} cannot replace a newer filter result`, async ctx => {
		const old = deferred(), latest = deferred()
		let requests = 0
		const { state, errors } = activity(ctx, { get: url => {
			if (!url.endsWith('/traces')) return Promise.resolve(detail(2))
			return ++requests === 1 ? old.promise : latest.promise
		} })
		const first = state.loadTraces()
		state.filters.status = 'success'
		const second = state.loadTraces()
		latest.resolve(traceList([2]))
		await second
		if (rejectOld) old.reject(new Error('Old failed filter'))
		else old.resolve(traceList([1]))
		await first
		assert.equal(state.traces[0].id, 2)
		assert.equal(state.selectedTraceId, 2)
		assert.equal(state.selectedTrace.id, 2)
		assert.equal(state.loading, false)
		assert.equal(state.error, '')
		assert.equal(errors.length, 0)
	})
}

test('destroying activity view invalidates in-flight details', async ctx => {
	const pending = deferred()
	const { state, unmount } = activity(ctx, { get: () => pending.promise })
	const request = state.selectTrace(1)
	unmount()
	pending.resolve(detail(1))
	await request
	assert.equal(state.selectedTrace, null)
	assert.equal(state.events.length, 0)
})

test('a delayed bot tool response does not overwrite a newer explicit wiki choice', async ctx => {
	const pending = deferred()
	const { state } = form(ctx, { get: () => pending.promise })
	state.wikiLocationsLoaded = true
	state.availableCollectives = locations().data.collectives
	const request = state.loadBotTools()
	state.formData.personalWikiEnabled = true
	state.formData.personalWikiLocation = 'collective'
	state.formData.personalWikiCollectiveId = '8'
	state.markPersonalWikiChanged()
	pending.resolve({ data: { tools: wikiTools(7) } })
	await request
	assert.equal(state.formData.personalWikiCollectiveId, '8')
	assert.equal(state.personalWikiChanged, true)
})

test('closing the form invalidates tool, collective and source requests without restarting polling', async ctx => {
	const pendingTools = deferred(), pendingLists = deferred(), pendingSources = deferred()
	const { state, errors, unmount } = form(ctx, { get: url => url.endsWith('/tools') ? pendingTools.promise : url.endsWith('/locations') ? pendingLists.promise : pendingSources.promise })
	const toolsRequest = state.loadBotTools(), listRequest = state.loadWikiLocations(), sourcesRequest = state.loadRagSources()
	unmount()
	pendingTools.resolve({ data: { tools: wikiTools(7) } })
	pendingLists.resolve(locations())
	pendingSources.resolve({ data: { sources: [{ id: 1, status: 'pending' }] } })
	await Promise.all([toolsRequest, listRequest, sourcesRequest])
	assert.equal(state.formData.personalWikiCollectiveId, '')
	assert.equal(state.availableCollectives.length, 0)
	assert.equal(state.rag.sources.length, 0)
	assert.equal(state.rag.pollingInterval, null)
	assert.equal(errors.length, 0)
})
