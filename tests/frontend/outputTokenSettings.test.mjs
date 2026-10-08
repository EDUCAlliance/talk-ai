import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { getApiErrorMessage } from '../../src/utils/apiError.js'
import { t, n, getCanonicalLocale } from '../../src/l10n.js'

function admin(axios) {
	const source = readFileSync(new URL('../../src/components/AdminSettings.vue', import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .+$/gm, '').replace('export default', 'globalThis.component =')
	const errors = []
	const scope = { axios, APP_DISPLAY_NAME: 'Talk AI', BotForm: {}, EmbeddingMaintenance: {},
		generateUrl: value => value, t, n, getCanonicalLocale, getApiErrorMessage,
		applyEducAiRuntimeIconPayload() {}, showSuccess() {}, showError: error => errors.push(error), confirm: () => true,
		console: { ...console, error: (...args) => errors.push(args) } }
	vm.runInNewContext(script, scope)
	const component = scope.component
	const state = component.data()
	state.errors = errors
	for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state)
	for (const [name, computed] of Object.entries(component.computed)) Object.defineProperty(state, name, { get: computed.bind(state) })
	return state
}

test('output limits save and reload independently of conversation memory', async () => {
	let saved = {}
	const state = admin({ get: async () => ({ data: {
		conversation_context_tokens: 8000, default_model: 'primary:model',
		max_output_tokens: saved.maxOutputTokens ?? 32768,
		model_output_token_limits: saved.modelOutputTokenLimits ?? {},
	} }), put: async (_url, payload) => { saved = payload; return { data: {} } } })
	await state.loadSettings()
	assert.equal(state.settings.maxOutputTokens, 32768)
	state.settings.maxOutputTokens = 8192
	state.addOutputTokenOverride()
	Object.assign(state.outputTokenOverrides[0], { model: 'secondary:reasoning', tokens: 32768 })
	await state.saveSettings()
	assert.equal(saved.maxOutputTokens, 8192)
	assert.equal(saved.modelOutputTokenLimits['secondary:reasoning'], 32768)
	assert.equal(saved.conversationContextTokens, 8000)
	await state.loadSettings()
	assert.equal(state.settings.maxOutputTokens, 8192)
	assert.equal(state.outputTokenOverrides[0].model, 'secondary:reasoning')
	assert.equal(state.outputTokenOverrides[0].tokens, 32768)
	state.outputTokenOverrides.splice(0, 1)
	await state.saveSettings()
	assert.equal(Object.keys(saved.modelOutputTokenLimits).length, 0)
	assert.equal(state.errors.length, 0)
})

test('invalid output limits prevent saving and open the relevant section', async () => {
	let calls = 0
	const state = admin({ put: async () => { calls++; return { data: {} } } })
	for (const value of [0, -1, 131073, 4096.5, true, '']) {
		state.settings.maxOutputTokens = value
		await state.saveSettings()
		assert.equal(state.saving, false)
		assert.equal(state.openSections.outputLimits, true)
	}
	assert.equal(calls, 0)
	assert.equal(state.errors.length, 6)
})

test('model overrides require qualified unique references and integer limits', () => {
	const state = admin({})
	const invalidRows = [
		[{ model: '', tokens: 4096 }],
		[{ model: 'model', tokens: 4096 }],
		[{ model: 'primary:', tokens: 4096 }],
		[{ model: 'primary:model name', tokens: 4096 }],
		[{ model: 'primary:model\u0000', tokens: 4096 }],
		[{ model: 'primary:model', tokens: 1.5 }],
		[{ model: 'primary:model', tokens: true }],
		[{ model: 'primary:model', tokens: 4096 }, { model: 'primary:model', tokens: 8192 }],
	]
	for (const rows of invalidRows) {
		state.outputTokenOverrides = rows
		assert.equal(state.buildOutputTokenPayload(), null)
	}
	state.outputTokenOverrides = [{ model: 'primary:model', tokens: 1 }, { model: 'secondary:model', tokens: 131072 }]
	const payload = state.buildOutputTokenPayload()
	assert.equal(payload.modelOutputTokenLimits['primary:model'], 1)
	assert.equal(payload.modelOutputTokenLimits['secondary:model'], 131072)
})
