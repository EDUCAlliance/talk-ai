import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { t, n, getCanonicalLocale } from '../../src/l10n.js'
import { getApiErrorMessage } from '../../src/utils/apiError.js'

function activity(axios) {
	const source = readFileSync(new URL('../../src/views/PersonalActivity.vue', import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .+$/gm, '').replace('export default', 'globalThis.component =')
	const scope = { axios, APP_DISPLAY_NAME: 'Talk AI', generateUrl: value => value,
		t, n, getCanonicalLocale, getApiErrorMessage, showError() {}, showSuccess() {} }
	vm.runInNewContext(script, scope)
	const state = scope.component.data()
	for (const [name, method] of Object.entries(scope.component.methods)) state[name] = method.bind(state)
	return state
}

test('incomplete activity can be filtered and inspected without becoming a success or delivery failure', async () => {
	const trace = { id: 7, status: 'incomplete', completion_token_count: 800 }
	const event = { event_type: 'llm_response', status: 'incomplete', payload_json: {
		finish_reason: 'length', usage: { completion_tokens: 800, completion_tokens_details: { reasoning_tokens: 700 } },
	} }
	const calls = []
	const state = activity({ get: async (url, options) => {
		calls.push({ url, params: options?.params })
		return url.endsWith('/7') ? { data: { trace, events: [event] } }
			: { data: { traces: [trace], total: 1, limit: 25, offset: 0 } }
	} })
	state.filters.status = 'incomplete'
	await state.loadTraces()
	assert.equal(calls[0].params.status, 'incomplete')
	assert.equal(calls[0].params.onlyErrors, undefined)
	assert.equal(state.selectedTrace.status, 'incomplete')
	assert.equal(state.formatStatus(state.selectedTrace.status), 'Incomplete')
	assert.equal(state.formatStatus('partial'), 'Partial')
	assert.equal(state.formatStatus('success'), 'Success')
	assert.equal(state.events[0].payload_json.finish_reason, 'length')
	assert.equal(state.events[0].payload_json.usage.completion_tokens_details.reasoning_tokens, 700)
	assert.equal(state.error, '')
	assert.equal(state.detailError, '')
})
