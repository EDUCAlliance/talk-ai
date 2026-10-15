import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { getApiErrorMessage } from '../../src/utils/apiError.js'
import { normalizeToolSelectionAliases } from '../../src/utils/toolAliases.js'
import * as modelSelection from '../../src/utils/modelSelection.js'
import * as adminSettings from '../../src/utils/adminSettings.js'
import { deriveOverview } from '../../src/utils/adminOverview.js'
import { t, n, getCanonicalLocale } from '../../src/l10n.js'

const primary = { id: 'primary:shared', model: 'shared', endpoint: 'primary', availability: 'available' }
const secondary = { id: 'secondary:secondary-only', model: 'secondary-only', endpoint: 'secondary', availability: 'available' }
const providers = [{ id: 'primary', status: 'ready' }, { id: 'secondary', status: 'ready' }]
const catalogue = (options = [primary, secondary], statuses = providers) => ({ data: { model_options: options, providers: statuses, refresh_interval_seconds: 300 } })

function component(name, axios = {}, props = {}, globals = {}) {
	const source = readFileSync(new URL(`../../src/components/${name}.vue`, import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .+$/gm, '').replace('export default', 'globalThis.component =')
	const errors = []
	const emitted = []
	const scope = { axios, APP_DISPLAY_NAME: 'Talk AI', WIKI_ROOT_FOLDER: 'Talk AI',
		BotForm: {}, EmbeddingMaintenance: {}, ModelSelector: {}, NcProgressBar: {}, adminComponents: {}, adminSettings, deriveOverview,
		generateUrl: value => value, t, n, getCanonicalLocale, getApiErrorMessage, modelSelection, normalizeToolSelectionAliases,
		applyEducAiRuntimeIconPayload() {}, showSuccess() {}, showError: error => errors.push(error), confirm: () => true,
		console: { error: (...args) => errors.push(args), log() {}, warn() {} }, ...globals }
	vm.runInNewContext(script, scope)
	const definition = scope.component
	const state = { ...definition.data(), ...props, errors, emitted, $refs: {}, $nextTick: callback => callback(), $emit: (...args) => emitted.push(args) }
	for (const [name, method] of Object.entries(definition.methods)) state[name] = method.bind(state)
	for (const [name, computed] of Object.entries(definition.computed || {})) Object.defineProperty(state, name,
		typeof computed === 'function' ? { get: computed.bind(state) } : { get: computed.get.bind(state), set: computed.set.bind(state) })
	return { state, definition }
}

function admin(axios, globals) {
	return component('AdminSettings', axios, {}, globals).state
}

function readyAdmin(axios, globals) {
	const state = admin(axios, globals)
	state.settings.apiEndpoint = 'https://primary.example/v1'
	state.settings.secondaryApiEndpoint = 'https://secondary.example/v1'
	state.captureProviderSettingsBaseline()
	state.modelSettingsLoaded = true
	return state
}

test('secondary selection survives save and a failed discovery without endpoint reassignment', async () => {
	const { state } = component('BotForm', { get: async () => { throw new Error('Primary unavailable') } }, { bot: null, userPermissions: {} })
	state.settings.allowMultipleModels = true
	state.settings.allowedModels = ['secondary:secondary-only']
	state.formData.model = 'secondary:secondary-only'
	await state.loadModelOptions()
	assert.equal(state.formData.model, 'secondary:secondary-only')
	assert.equal(state.selectableModelOptions[0].id, 'secondary:secondary-only')
	assert.equal(state.toStoredModelReference(state.formData.model), 'secondary:secondary-only')
	state.submitForm()
	assert.equal(state.emitted.at(-1)[1].model, 'secondary:secondary-only')
	assert.match(state.modelLoadError, /Saved model choices/)
})

test('new and inherited bots stay on the administrator default when model lists arrive', async () => {
	const { state } = component('BotForm', { get: async url => url.endsWith('/settings')
		? { data: { allow_multiple_models: true, allowed_models: ['secondary:secondary-only'], secondary_api_endpoint: 'https://secondary.example' } }
		: catalogue() }, { bot: { id: 4, model: null }, userPermissions: {} })
	await state.loadSettings()
	assert.equal(state.formData.model, '')
	assert.equal(state.selectableModelOptions.length, 1)
	state.submitForm()
	assert.equal(state.emitted.at(-1)[1].model, '', 'An update explicitly clears the model override')
	state.bot = null
	state.submitForm()
	assert.equal(state.emitted.at(-1)[1].model, null, 'A new bot inherits without a model override')
})

test('resetting an explicit model to inherit uses the update API clear sentinel', () => {
	const { state } = component('BotForm', {}, { bot: { id: 5, model: 'secondary:secondary-only' }, userPermissions: {} })
	state.initialModel = 'secondary:secondary-only'
	state.formData.model = ''
	state.submitForm()
	assert.equal(state.emitted.at(-1)[1].model, '', 'Null would preserve the previous explicit model')
})

test('catalogue discovery never expands the bot allowlist or enables per-bot selection', async () => {
	const { state } = component('BotForm', { get: async () => catalogue() })
	state.settings.secondaryEndpointEnabled = true
	state.settings.allowedModels = ['primary:shared']
	await state.loadModelOptions()
	assert.equal(state.selectableModelOptions.length, 0)
	state.settings.allowMultipleModels = true
	assert.deepEqual(state.selectableModelOptions.map(option => option.id), ['primary:shared'])
	state.formData.model = 'secondary:previously-saved'
	assert.deepEqual(state.selectableModelOptions.map(option => option.id), ['primary:shared', 'secondary:previously-saved'])
})

test('refresh keeps admin default, fallback, allowed models and overrides unchanged', async () => {
	let response = catalogue()
	const state = readyAdmin({ get: async () => response })
	Object.assign(state.settings, { defaultModel: 'secondary:missing', fallbackModel: 'legacy-shared', allowedModels: [] })
	state.outputTokenOverrides = [{ model: 'secondary:missing', tokens: 8192 }]
	await state.loadModels()
	assert.equal(state.settings.defaultModel, 'secondary:missing')
	assert.equal(state.settings.fallbackModel, 'legacy-shared')
	assert.equal(state.settings.allowedModels.length, 0, 'Do not auto-release the default model')
	assert.equal(state.outputTokenOverrides[0].model, 'secondary:missing')
	response = catalogue([primary, { ...secondary, availability: 'stale' }], [providers[0], { id: 'secondary', status: 'stale' }])
	await state.loadModels()
	assert.equal(state.availableModelOptions.length, 2)
	assert.equal(state.modelProviders[1].status, 'stale')
	const missing = modelSelection.modelOptionsWithCurrent(state.availableModelOptions, state.settings.defaultModel).find(option => option.id === 'secondary:missing')
	assert.equal(missing.availability, 'missing')
	assert.match(missing.label, /not in current catalogue/)
})

test('full HTTP failure retains prior model choices and exposes a useful status', async () => {
	const state = readyAdmin({ get: async () => { throw { response: { data: { error: 'Catalogue unavailable', errorCode: 'catalogue_unavailable' } } } } })
	state.availableModelOptions = modelSelection.normalizeModelOptions([primary, secondary])
	await state.loadModels()
	assert.equal(state.availableModelOptions.length, 2)
	assert.equal(state.availableModelOptions[0].availability, 'stale')
	assert.equal(state.loadingModels, false)
	assert.equal(state.modelLoadError, 'Catalogue unavailable')
})

test('unqualified legacy values are neither assigned to primary nor stripped on unrelated save', async () => {
	let saved
	const state = readyAdmin({ put: async (_url, payload) => { saved = payload; return { data: {} } } })
	state.availableModelOptions = modelSelection.normalizeModelOptions([primary, secondary, { id: 'secondary:shared', model: 'shared' }])
	Object.assign(state.settings, { defaultModel: 'shared', fallbackModel: 'unknown', allowedModels: ['secondary:secondary-only', 'shared'] })
	await state.saveSettings()
	assert.equal(saved.defaultModel, 'shared')
	assert.equal(saved.fallbackModel, 'unknown')
	assert.deepEqual(Array.from(saved.allowedModels), ['secondary:secondary-only', 'shared'])
	assert.equal(state.errors.length, 0)
	const legacy = modelSelection.modelOptionsWithCurrent(state.availableModelOptions, 'shared').find(option => option.id === 'shared')
	assert.equal(legacy.endpoint, null)
	assert.match(legacy.label, /provider not selected/)
})

test('manual models always carry the explicitly selected provider; invalid IDs do not emit', () => {
	const { state } = component('ModelSelector', {}, { value: '', options: [], id: 'model', disabled: false })
	state.manualEndpoint = 'secondary'
	state.manualModel = 'my/model:version'
	state.useManualModel()
	assert.deepEqual(state.emitted.at(-1), ['input', 'secondary:my/model:version'])
	for (const value of ['', 'two words', 'bad\u0000id']) {
		state.manualModel = value
		state.useManualModel()
	}
	assert.equal(state.emitted.length, 1)
	assert.match(state.manualError, /without spaces/)
})

test('tests require saved provider settings and are never invoked by catalogue loading', async () => {
	const calls = []
	const state = readyAdmin({ get: async () => catalogue(), post: async (url, payload) => {
		calls.push({ url, payload })
		return { data: { success: true, model: payload.model, latency_ms: 25, checked_at: 1 } }
	} })
	state.modelTestSelection = 'secondary:secondary-only'
	await state.loadModels()
	assert.equal(calls.length, 0)
	state.settings.secondaryApiKey = 'unsaved-test-value'
	await state.testModelResponse()
	await state.loadModels(true)
	assert.equal(calls.length, 0)
	state.settings.secondaryApiKey = ''
	await state.testModelResponse()
	assert.equal(calls.length, 1)
	assert.equal(calls[0].url, '/apps/educai/api/v1/models/test')
	assert.deepEqual(Object.keys(calls[0].payload), ['model'], 'No credential fields in test request')
	assert.equal(state.modelTestResult.success, true)
	assert.equal(state.modelTesting, false)
})

test('admin tests unapproved models and displays the safe parameter error from the server', async () => {
	const message = 'The provider rejected the test request. Check model parameter compatibility and the saved endpoint.'
	const calls = []
	const state = readyAdmin({ post: async (_url, payload) => {
		calls.push(payload)
		throw { response: { data: { errorCode: 'model_test_request_rejected', error: message } } }
	} })
	state.settings.allowMultipleModels = true
	state.settings.allowedModels = ['primary:shared']
	state.modelTestSelection = 'secondary:microsoft/gpt-6-luna'
	assert.equal(state.canTestModel, true)
	await state.testModelResponse()
	assert.equal(calls.length, 1)
	assert.equal(calls[0].model, 'secondary:microsoft/gpt-6-luna')
	assert.equal(state.modelTestResult.success, false)
	assert.equal(state.modelTestResult.error, message)
	assert.equal(state.modelTesting, false)
})

test('stale inference result is discarded if settings are edited while the request runs', async () => {
	let resolve
	const state = readyAdmin({ post: () => new Promise(done => { resolve = done }) })
	state.modelTestSelection = 'primary:shared'
	const request = state.testModelResponse()
	state.settings.apiEndpoint = 'https://different.example'
	resolve({ data: { success: true, model: 'primary:shared' } })
	await request
	assert.equal(state.modelTestResult, null)
	assert.equal(state.modelTesting, false)
})

test('saving provider changes forces refresh and does not reset model choices', async () => {
	const calls = []
	const state = readyAdmin({ put: async () => ({ data: {} }), post: async url => { calls.push(url); return catalogue() } })
	state.settings.apiEndpoint = 'https://new-provider.example/v1'
	state.settings.apiKey = 'unsaved-test-value'
	state.settings.defaultModel = 'secondary:secondary-only'
	await state.saveSettings()
	assert.deepEqual(calls, ['/apps/educai/api/v1/models/refresh'])
	assert.equal(state.hasUnsavedProviderSettings, false)
	assert.equal(state.settings.apiKey, '')
	assert.equal(state.settings.defaultModel, 'secondary:secondary-only')
})

test('polling respects server cache interval and is torn down with the component', async () => {
	const timers = []
	const cleared = []
	const { state, definition } = component('AdminSettings', { get: async () => catalogue() }, {}, {
		setTimeout: (callback, delay) => { timers.push({ callback, delay }); return timers.length },
		clearTimeout: id => cleared.push(id),
	})
	state.modelSettingsLoaded = true
	state.captureProviderSettingsBaseline()
	state.modelPollingActive = true
	await state.loadModels()
	assert.equal(timers.length, 1)
	assert.equal(timers[0].delay, 300000)
	definition.beforeDestroy.call(state)
	assert.equal(cleared.at(-1), 1)
	state.scheduleModelRefresh()
	assert.equal(timers.length, 1)
})

test('provider save during discovery discards the old response and forces the new catalogue', async () => {
	let finishOldRequest
	const calls = []
	const state = readyAdmin({
		get: url => url.endsWith('/settings') ? Promise.resolve({ data: {} }) : new Promise(resolve => { finishOldRequest = resolve }),
		put: async () => ({ data: {} }),
		post: async url => { calls.push(url); return catalogue([{ id: 'primary:new-model', model: 'new-model' }]) },
	})
	const oldRequest = state.loadModels()
	state.settings.apiEndpoint = 'https://changed.example/v1'
	await state.saveSettings()
	finishOldRequest(catalogue())
	await oldRequest
	assert.equal(calls.length, 1)
	assert.deepEqual(state.availableModelOptions.map(option => option.id), ['primary:new-model'])
	assert.equal(state.settings.defaultModel, 'llama-3.3-70b-instruct')
})

test('server-confirmed legacy migration updates pristine fields without changing dirty model choices', async () => {
	const state = readyAdmin({ get: async url => url.endsWith('/settings')
		? { data: { default_model: 'primary:shared', fallback_model: 'secondary:secondary-only', allowed_models: ['primary:shared'] } }
		: catalogue() })
	Object.assign(state.settings, { defaultModel: 'shared', fallbackModel: 'secondary-only', allowedModels: ['shared'] })
	state.captureModelSettingsBaseline()
	state.settings.defaultModel = 'secondary:dirty-selection'
	await state.loadModels()
	assert.equal(state.settings.defaultModel, 'secondary:dirty-selection')
	assert.equal(state.settings.fallbackModel, 'secondary:secondary-only')
	assert.deepEqual(Array.from(state.settings.allowedModels), ['primary:shared'])
	state.settings.defaultModel = 'shared'
	await state.loadModels()
	assert.equal(state.settings.defaultModel, 'primary:shared')
})

test('bot legacy migration adopts only the saved equivalent and leaves edited choices intact', async () => {
	const { state } = component('BotForm', { get: async url => {
		if (url.endsWith('/settings')) return { data: { allowed_models: ['secondary:secondary-only'] } }
		if (url.endsWith('/bots/5')) return { data: { id: 5, model: 'secondary:secondary-only' } }
		return catalogue()
	} }, { bot: { id: 5 }, userPermissions: {} })
	state.initialModel = 'secondary-only'
	state.formData.model = 'primary:dirty-selection'
	state.settings.allowedModels = ['secondary-only']
	await state.loadModelOptions()
	assert.equal(state.formData.model, 'primary:dirty-selection')
	assert.deepEqual(Array.from(state.settings.allowedModels), ['secondary:secondary-only'])
	state.formData.model = 'secondary-only'
	await state.loadModelOptions()
	assert.equal(state.formData.model, 'secondary:secondary-only')
})

test('changing only stored credentials invalidates an in-flight inference result', async () => {
	let finishTest
	const state = readyAdmin({
		post: url => url.endsWith('/test') ? new Promise(resolve => { finishTest = resolve }) : Promise.resolve(catalogue()),
		put: async () => ({ data: {} }),
		get: async () => ({ data: {} }),
	})
	state.modelTestSelection = 'primary:shared'
	const request = state.testModelResponse()
	state.settings.apiKey = 'new-test-credential'
	await state.saveSettings()
	finishTest({ data: { success: true, model: 'primary:shared' } })
	await request
	assert.equal(state.hasUnsavedProviderSettings, false)
	assert.equal(state.modelTestResult, null)
})


test('empty global default is rejected before settings are submitted', async () => {
 const state = readyAdmin({ put: async () => { assert.fail('An empty default must not be submitted') } })
 state.settings.defaultModel = ''
 await state.saveSettings()
 assert.match(state.errors[0], /Choose a default model/)
 assert.equal(state.activeTab, 'models')
 assert.equal(state.saving, false)
})

test('qualifying the inherited embedding model does not demand a reindex', () => {
 const state = readyAdmin({})
 Object.assign(state.embeddingConfigBaseline, { apiProvider: 'custom', apiEndpoint: 'https://example.test/v1', defaultModel: 'alpha', embeddingModel: '' })
 const payload = { apiProvider: 'custom', apiEndpoint: 'https://example.test/v1', defaultModel: 'primary:alpha', embeddingModel: '' }
 assert.equal(state.hasEmbeddingConfigChange(payload), false)
 assert.equal(state.hasEmbeddingConfigChange({ ...payload, defaultModel: 'secondary:alpha' }), false)
 assert.equal(state.hasEmbeddingConfigChange({ ...payload, defaultModel: 'primary:beta' }), true)
 assert.equal(state.hasEmbeddingConfigChange({ ...payload, embeddingModel: 'primary:alpha' }), true, 'Explicit embedding IDs remain literal')
})


test('selector value follows a newly appended manual option and emits user changes', () => {
 const { state } = component('ModelSelector', {}, { value: 'secondary:unlisted', options: [primary], id: 'choice' })
 assert.equal(state.selectedValue, 'secondary:unlisted')
 assert(state.choices.some(option => option.id === state.selectedValue))
 state.selectedValue = ''
 assert.deepEqual(state.emitted.at(-1), ['input', ''])
})

test('stored models of a provider without a model list are manual entries, not missing', () => {
	const statuses = [{ id: 'primary', status: 'ready', lists_models: false }, { id: 'secondary', status: 'ready', lists_models: true }, { id: 'evil', lists_models: false }]
	const unlisted = modelSelection.unlistedModelEndpoints(statuses)
	assert.deepEqual(unlisted, ['primary'])
	assert.deepEqual(modelSelection.unlistedModelEndpoints(null), [])
	const choices = modelSelection.modelOptionsWithCurrent([secondary], ['primary:microsoft/gpt-5.4', 'secondary:gone'], unlisted)
	const byId = Object.fromEntries(choices.map(option => [option.id, option]))
	assert.equal(byId['primary:microsoft/gpt-5.4'].availability, 'manual')
	assert.equal(byId['primary:microsoft/gpt-5.4'].label, 'Primary · microsoft/gpt-5.4')
	assert.equal(byId['secondary:gone'].availability, 'missing')
	assert.match(byId['secondary:gone'].label, /not in current catalogue/)
})

test('a bot with an unqualified model can pin it to a provider without a release', () => {
	const { state } = component('BotForm', {}, { bot: { id: 6, model: 'gpt-4o' }, userPermissions: {} })
	state.settings.allowMultipleModels = false
	state.formData.model = 'gpt-4o'
	state.modelProviders = [{ id: 'primary', status: 'ready' }, { id: 'secondary', status: 'unconfigured' }]
	assert.deepEqual(Array.from(state.selectableModelOptions, option => option.id), ['gpt-4o', 'primary:gpt-4o'])
	state.modelProviders = [{ id: 'primary', status: 'ready' }, { id: 'secondary', status: 'stale' }]
	assert.deepEqual(Array.from(state.selectableModelOptions, option => option.id), ['gpt-4o', 'primary:gpt-4o', 'secondary:gpt-4o'])
	state.formData.model = 'secondary:gpt-4o'
	assert.deepEqual(Array.from(state.selectableModelOptions, option => option.id), ['secondary:gpt-4o'], 'A qualified choice offers no other providers')
})
