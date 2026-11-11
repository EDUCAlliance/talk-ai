import assert from 'node:assert/strict'
import test from 'node:test'

import { createTalkAiStore } from '../../src/store/createTalkAiStore.js'
import { assistantExamples, assistantFirst, assistantOf, assistantPayload } from '../../src/utils/assistant.js'

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

test('there are a few short example questions', () => {
	const examples = assistantExamples()
	assert.equal(examples.length, 3)
	assert.ok(examples.every(example => example.length > 10 && example.length < 70))
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
