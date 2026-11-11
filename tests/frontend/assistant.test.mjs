import assert from 'node:assert/strict'
import test from 'node:test'

import { createTalkAiStore } from '../../src/store/createTalkAiStore.js'
import { assistantFirst, assistantOf, assistantPayload } from '../../src/utils/assistant.js'

const builtin = name => ({ id: null, name, is_builtin: true, builtin_name: name })
const API = '/apps/educai/api/v1'

function setup(routes = {}) {
	const calls = []
	const answer = (method, url, payload) => {
		calls.push([method, url, payload])
		const route = routes[`${method} ${url}`]
		if (route === undefined) {
			return Promise.resolve({ data: {} })
		}
		return typeof route === 'function' ? route(payload) : Promise.resolve({ data: route })
	}
	const http = {
		get: url => answer('GET', url),
		post: (url, payload) => answer('POST', url, payload),
		put: (url, payload) => answer('PUT', url, payload),
		delete: url => answer('DELETE', url),
	}
	return { store: createTalkAiStore({ http, url: path => path }), calls }
}

test('the assistant is the bot the server marks, not any personal bot', () => {
	const personal = { id: 1, visibility: 'personal', bot_name: 'Notes' }
	const assistant = { id: 2, visibility: 'personal', bot_name: 'Personal assistant', is_assistant: true }

	assert.equal(assistantOf([personal]), null, 'other personal bots do not replace the assistant')
	assert.equal(assistantOf([personal, assistant]), assistant)
	assert.equal(assistantOf([{ id: 3, is_assistant: 'true' }, { id: 4, is_assistant: 1 }]), null, 'only the boolean counts')
	assert.equal(assistantOf([]), null)
	assert.equal(assistantOf(undefined), null)
})

test('the assistant is pinned in first place and the rest keeps its order', () => {
	const bots = [
		{ id: 1, bot_name: 'Alpha' },
		{ id: 2, bot_name: 'Beta', is_assistant: false },
		{ id: 3, bot_name: 'Zeta', is_assistant: true },
		{ id: 4, bot_name: 'Gamma' },
	]
	const sorted = [...bots].sort((a, b) => assistantFirst(a, b) || a.bot_name.localeCompare(b.bot_name))

	assert.deepEqual(sorted.map(bot => bot.id), [3, 1, 2, 4])
	assert.equal(assistantFirst(bots[0], bots[1]), 0)
	assert.ok(assistantFirst(bots[2], bots[0]) < 0)
	assert.ok(assistantFirst(bots[0], bots[2]) > 0)
})

test('the assistant is created as a personal bot with the offered chat capabilities', () => {
	const payload = assistantPayload([
		builtin('rag_search_documents'),
		builtin('room_search_documents'),
		builtin('room_search_images'),
		builtin('attachment_analyze_image'),
		builtin('attachment_transcribe_audio'),
		builtin('wiki_search'),
		builtin('catalogue_search'),
		{ id: 7, name: 'Room booking', is_builtin: false, builtin_name: null },
	])

	assert.equal(payload.assistant, true)
	assert.equal(payload.visibility, 'personal')
	assert.deepEqual(payload.allowedGroups, [])
	assert.deepEqual(payload.allowedTeams, [])
	assert.equal(payload.botName, 'Personal assistant')
	assert.match(payload.mentionName, /^[a-z0-9_-]+$/)
	assert.ok(payload.systemPrompt.length > 40)
	assert.ok(payload.description.length > 20)
	assert.equal(payload.ragEnabled, false, 'the knowledge search starts with the first folder')
	assert.equal(payload.model, null)
	assert.equal(payload.temperature, null)
	assert.deepEqual(payload.tools, [
		{ is_builtin: true, builtin_name: 'room_search_documents' },
		{ is_builtin: true, builtin_name: 'room_search_images' },
		{ is_builtin: true, builtin_name: 'attachment_analyze_image' },
	])
})

test('the assistant starts without tools when the administration offers none', () => {
	assert.deepEqual(assistantPayload([]).tools, [])
	assert.deepEqual(assistantPayload(undefined).tools, [])
	assert.deepEqual(assistantPayload([builtin('catalogue_search')]).tools, [])
})

test('setting up the assistant refreshes both lists that show it', async () => {
	const { store, calls } = setup({
		[`POST ${API}/bots`]: payload => Promise.resolve({ data: { id: 31, bot_name: payload.botName, is_assistant: true } }),
		[`GET ${API}/bots`]: [{ id: 31, is_assistant: true }],
		[`GET ${API}/public-bots`]: [{ id: 31, is_assistant: true }, { id: 40 }],
	})

	const bot = await store.createAssistant({ botName: 'Personal assistant', assistant: false })

	assert.deepEqual(bot, { id: 31, bot_name: 'Personal assistant', is_assistant: true })
	assert.deepEqual(calls[0], ['POST', `${API}/bots`, { botName: 'Personal assistant', assistant: true }])
	assert.deepEqual(calls.slice(1).map(call => call[1]).sort(), [`${API}/bots`, `${API}/public-bots`])
	assert.equal(assistantOf(store.state.ownBots).id, 31)
	assert.equal(assistantOf(store.state.publicBots).id, 31)
})

test('a refused set-up still refreshes the lists and passes the error on', async () => {
	const refusal = Object.assign(new Error('conflict'), { response: { status: 409, data: { errorCode: 'assistant_exists' } } })
	const { store, calls } = setup({
		[`POST ${API}/bots`]: () => Promise.reject(refusal),
		[`GET ${API}/bots`]: [{ id: 31, is_assistant: true }],
	})

	await assert.rejects(store.createAssistant({ botName: 'Personal assistant' }), refusal)

	assert.deepEqual(calls.slice(1).map(call => call[1]).sort(), [`${API}/bots`, `${API}/public-bots`])
	assert.equal(assistantOf(store.state.ownBots).id, 31)
})

test('offered tools come from the tool list and default to none', async () => {
	const offered = setup({ [`GET ${API}/tools`]: { tools: [builtin('room_search_documents')] } })
	assert.deepEqual(await offered.store.fetchOfferedTools(), [builtin('room_search_documents')])

	const empty = setup({ [`GET ${API}/tools`]: { tools: null } })
	assert.deepEqual(await empty.store.fetchOfferedTools(), [])
})

const dropped = status => Object.assign(new Error('dropped'), status ? { response: { status, data: '<html>Bad Gateway</html>' } } : {})

test('a set-up request that a gateway dropped is sent once more', async () => {
	for (const failure of [dropped(502), dropped(503), dropped()]) {
		let attempts = 0
		const { store, calls } = setup({
			[`POST ${API}/bots`]: payload => ++attempts === 1
				? Promise.reject(failure)
				: Promise.resolve({ data: { id: 31, bot_name: payload.botName, is_assistant: true } }),
			[`GET ${API}/bots`]: [{ id: 31, is_assistant: true }],
		})

		const bot = await store.createAssistant({ botName: 'Personal assistant' })

		assert.equal(bot.id, 31)
		assert.equal(calls.filter(call => call[0] === 'POST').length, 2)
		assert.deepEqual(calls[1], ['POST', `${API}/bots`, { botName: 'Personal assistant', assistant: true }])
	}
})

test('when the dropped request did arrive, the refused second one still ends with the assistant', async () => {
	let attempts = 0
	const exists = Object.assign(new Error('conflict'), { response: { status: 409, data: { errorCode: 'assistant_exists' } } })
	const { store, calls } = setup({
		[`POST ${API}/bots`]: () => Promise.reject(++attempts === 1 ? dropped(502) : exists),
		[`GET ${API}/bots`]: [{ id: 7 }, { id: 31, is_assistant: true }],
	})

	const bot = await store.createAssistant({ botName: 'Personal assistant' })

	assert.deepEqual(bot, { id: 31, is_assistant: true })
	assert.equal(calls.filter(call => call[0] === 'POST').length, 2)
})

test('a set-up the server refuses is not repeated, and a second dropped request is reported', async () => {
	for (const status of [400, 403, 409, 500, 504]) {
		const refusal = Object.assign(new Error('refused'), { response: { status, data: { errorCode: 'bot_create_failed' } } })
		const { store, calls } = setup({ [`POST ${API}/bots`]: () => Promise.reject(refusal) })

		await assert.rejects(store.createAssistant({ botName: 'Personal assistant' }), refusal)
		assert.equal(calls.filter(call => call[0] === 'POST').length, 1, `status ${status}`)
	}

	const failure = dropped(502)
	const { store, calls } = setup({ [`POST ${API}/bots`]: () => Promise.reject(failure) })
	await assert.rejects(store.createAssistant({ botName: 'Personal assistant' }), failure)
	assert.equal(calls.filter(call => call[0] === 'POST').length, 2)
})
