import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { getApiErrorMessage } from '../../src/utils/apiError.js'
import { t, n, getCanonicalLocale } from '../../src/l10n.js'
import * as modelSelection from '../../src/utils/modelSelection.js'
import * as adminSettings from '../../src/utils/adminSettings.js'
import { deriveOverview } from '../../src/utils/adminOverview.js'

// Run the real AdminSettings controller methods without mounting the tab components.
function admin(axios = {}, globals = {}) {
	const source = readFileSync(new URL('../../src/components/AdminSettings.vue', import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .+$/gm, '').replace('export default', 'globalThis.component =')
	const errors = []
	const scope = { axios, URL, APP_DISPLAY_NAME: 'Talk AI', BotForm: {}, adminComponents: {}, modelSelection, adminSettings, deriveOverview,
		generateUrl: value => value, imagePath: (app, file) => `/apps/${app}/img/${file}`, t, n, getCanonicalLocale, getApiErrorMessage,
		applyEducAiRuntimeIconPayload() {}, showSuccess() {}, showError: error => errors.push(error), confirm: () => true,
		console: { ...console, error: (...args) => errors.push(args) }, ...globals }
	vm.runInNewContext(script, scope)
	const component = scope.component
	const state = component.data()
	state.errors = errors
	state.$nextTick = callback => callback()
	for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state)
	for (const [name, computed] of Object.entries(component.computed)) Object.defineProperty(state, name, { get: computed.bind(state) })
	return state
}

// Values created inside the VM have their own prototypes; compare plain data.
const plain = value => JSON.parse(JSON.stringify(value))

const savedSettings = (extra = {}) => ({
	api_provider: 'custom',
	api_key: '***',
	api_endpoint: 'https://primary.example/v1/chat/completions',
	secondary_api_endpoint: 'https://secondary.example/v1/chat/completions',
	secondary_api_key: '***',
	default_model: 'primary:alpha',
	fallback_model: 'secondary:beta',
	webhook_secret: '***',
	allow_multiple_models: true,
	allowed_models: ['primary:alpha', 'secondary:beta'],
	embedding_api_endpoint: '',
	embedding_api_key: '',
	embedding_model: 'e5',
	vision_api_endpoint: 'https://vision.example/v1/chat/completions',
	vision_api_key: '***',
	vision_model: 'vision-model',
	speech_api_endpoint: '',
	speech_api_key: '',
	speech_model: '',
	docling_api_profile: 'docling_serve',
	docling_auth_mode: 'x_api_key',
	docling_api_key: '',
	rag_enabled: true,
	max_output_tokens: 32768,
	model_output_token_limits: {},
	...extra,
})

const loadedAdmin = async (extra = {}, axios = {}) => {
	const puts = []
	const posts = []
	const state = admin({
		get: async url => url.endsWith('/settings') ? { data: savedSettings(extra) } : { data: { model_options: [], providers: [] } },
		put: async (url, payload) => { puts.push(payload); return { data: {} } },
		post: async (url, payload) => { posts.push({ url, payload }); return { data: {} } },
		...axios,
	})
	await state.loadSettings()
	return { state, puts, posts }
}

// The payload built by saveSettings() in acb7a82, before the redesign.
function legacyPayload(state) {
	const settings = state.settings
	const payload = {
		apiKey: settings.apiKey || '',
		apiEndpoint: (settings.apiEndpoint || '').trim(),
		secondaryApiEndpoint: (settings.secondaryApiEndpoint || '').trim(),
		secondaryApiKey: settings.secondaryApiKey ? settings.secondaryApiKey : null,
		defaultModel: state.toEndpointModelId(settings.defaultModel),
		defaultTemperature: state.normalizeTemperatureValue(settings.defaultTemperature),
		fallbackModel: state.toEndpointModelId(settings.fallbackModel),
		...state.buildAppIconPayload(),
		...state.buildOutputTokenPayload(),
		llmChatTimeout: state.toPositiveInteger(settings.llmChatTimeout, 90),
		llmStreamTimeout: state.toPositiveInteger(settings.llmStreamTimeout, 240),
		llmModelsTimeout: state.toPositiveInteger(settings.llmModelsTimeout, 20),
		apiProvider: settings.apiProvider || 'custom',
		allowMultipleModels: settings.allowMultipleModels,
		allowedModels: settings.allowedModels.map((model) => state.toEndpointModelId(model)),
		embeddingApiEndpoint: (settings.embeddingApiEndpoint || '').trim(),
		embeddingApiKey: settings.embeddingApiKey ? settings.embeddingApiKey : null,
		embeddingModel: (settings.embeddingModel || '').trim(),
		embeddingRateLimitMode: settings.embeddingRateLimitMode || 'inherit',
		embeddingRateLimitSecond: state.toOptionalPositiveInteger(settings.embeddingRateLimitSecond),
		embeddingRateLimitMinute: state.toPositiveInteger(settings.embeddingRateLimitMinute, 100),
		embeddingRateLimitHour: state.toPositiveInteger(settings.embeddingRateLimitHour, 2000),
		embeddingRateLimitDay: state.toPositiveInteger(settings.embeddingRateLimitDay, 4000),
		ragChunkSize: Number.isFinite(settings.ragChunkSize) ? Number(settings.ragChunkSize) : null,
		ragChunkOverlap: Number.isFinite(settings.ragChunkOverlap) ? Number(settings.ragChunkOverlap) : null,
		ragEnabled: settings.ragEnabled,
		catalogueEnabled: settings.catalogueEnabled,
		catalogueApiEndpoint: (settings.catalogueApiEndpoint || '').trim(),
		catalogueReindexHours: Number.isFinite(settings.catalogueReindexHours) ? Number(settings.catalogueReindexHours) : 24,
		doclingEnabled: settings.doclingEnabled,
		doclingApiEndpoint: (settings.doclingApiEndpoint || '').trim(),
		doclingApiKey: settings.doclingAuthMode !== 'none' ? settings.doclingApiKey || null : null,
		doclingApiProfile: settings.doclingApiProfile,
		doclingAuthMode: settings.doclingAuthMode,
		visionApiEndpoint: (settings.visionApiEndpoint || '').trim(),
		visionApiKey: settings.visionApiKey ? settings.visionApiKey : null,
		visionModel: (settings.visionModel || '').trim(),
		speechApiEndpoint: (settings.speechApiEndpoint || '').trim(),
		speechApiKey: settings.speechApiKey ? settings.speechApiKey : null,
		speechModel: (settings.speechModel || '').trim(),
		rateLimitEnabled: settings.rateLimitEnabled,
		rateLimitSecond: state.toOptionalPositiveInteger(settings.rateLimitSecond),
		rateLimitMinute: state.toPositiveInteger(settings.rateLimitMinute, 30),
		rateLimitHour: state.toPositiveInteger(settings.rateLimitHour, 200),
		rateLimitDay: state.toPositiveInteger(settings.rateLimitDay, 1000),
		rateLimitQueueMessage: (settings.rateLimitQueueMessage || '').trim(),
		conversationContextTokens: Number.isFinite(settings.conversationContextTokens) ? Number(settings.conversationContextTokens) : 8000,
	}
	if (settings.webhookSecret && settings.webhookSecret.trim() !== '') {
		payload.webhookSecret = settings.webhookSecret
	}
	return payload
}

const fixtures = {
	defaults: () => ({}),
	everything: () => ({
		apiKey: 'sk-primary', secondaryApiKey: 'sk-secondary', webhookSecret: 'webhook', embeddingApiKey: 'sk-embedding',
		visionApiKey: 'sk-vision', speechApiKey: 'sk-speech', doclingApiKey: 'docling-key',
		apiEndpoint: ' https://primary.example/v1 ', secondaryApiEndpoint: 'https://secondary.example/v1',
		defaultModel: 'secondary:beta', fallbackModel: 'primary:alpha', defaultTemperature: 0.4,
		allowMultipleModels: true, allowedModels: ['primary:alpha', 'secondary:beta', 'legacy'],
		appIconMode: 'custom', appIconBlackUrl: 'https://example.org/black.svg', appIconWhiteUrl: '/apps/theming/img/white.svg',
		maxOutputTokens: 8192, llmChatTimeout: 30, llmStreamTimeout: '', llmModelsTimeout: 5,
		embeddingApiEndpoint: ' https://embed.example/v1/embeddings ', embeddingModel: ' e5 ', embeddingRateLimitMode: 'custom',
		embeddingRateLimitSecond: 2, embeddingRateLimitMinute: 0, embeddingRateLimitHour: 300, embeddingRateLimitDay: 900,
		ragChunkSize: 900, ragChunkOverlap: '', ragEnabled: true, catalogueEnabled: true, catalogueApiEndpoint: ' https://catalogue.example/api ',
		catalogueReindexHours: 12, doclingEnabled: true, doclingApiEndpoint: 'https://docling.example', doclingApiProfile: 'docling_serve',
		doclingAuthMode: 'x_api_key', visionApiEndpoint: 'https://vision.example/v1', visionModel: 'vision', speechApiEndpoint: '',
		speechModel: 'whisper', rateLimitEnabled: true, rateLimitSecond: '', rateLimitMinute: 60, rateLimitHour: null, rateLimitDay: 5000,
		rateLimitQueueMessage: '  Please wait  ', conversationContextTokens: 16000,
	}),
	noAuthDocling: () => ({ doclingEnabled: true, doclingAuthMode: 'none', doclingApiKey: 'typed-but-unused', webhookSecret: '   ' }),
	legacyModels: () => ({ defaultModel: 'legacy-default', fallbackModel: '', allowedModels: ['legacy-one'] }),
}

for (const [name, fixture] of Object.entries(fixtures)) {
	test(`saving builds the same payload as before the redesign: ${name}`, async () => {
		let sent = null
		const state = admin({ put: async (_url, payload) => { sent = payload; return { data: {} } }, post: async () => ({ data: {} }) })
		state.settings.defaultModel = 'primary:alpha'
		Object.assign(state.settings, fixture())
		state.outputTokenOverrides = name === 'everything' ? [{ model: 'secondary:beta', tokens: 4096 }] : []
		const expected = JSON.parse(JSON.stringify(legacyPayload(state)))
		await state.saveSettings()
		assert.deepEqual(plain(state.errors), [])
		assert.deepEqual(plain(sent), expected)
		assert.equal('clearCredentials' in sent, false, 'Nothing is removed unless explicitly requested')
	})
}

test('loading reports stored secrets without placing them in the form', async () => {
	const { state } = await loadedAdmin()
	assert.deepEqual(plain(state.storedSecrets), {
		apiKey: true, secondaryApiKey: true, webhookSecret: true, embeddingApiKey: false,
		doclingApiKey: false, visionApiKey: true, speechApiKey: false,
	})
	for (const key of adminSettings.SECRET_FIELDS) assert.equal(state.settings[key], '', key)
	assert.deepEqual(plain(state.serviceModes), { embedding: 'inherit', vision: 'custom', speech: 'inherit' })
	assert.equal(state.modelTestSelection, 'primary:alpha', 'The saved default is preselected for the test')
	assert.deepEqual(plain(state.changedKeys), [])
	assert.equal(state.hasUnsavedChanges, false)
})

test('edits are tracked per tab and can be discarded', async () => {
	const { state } = await loadedAdmin()
	state.settings.defaultModel = 'secondary:beta'
	state.settings.rateLimitMinute = 99
	state.settings.webhookSecret = 'rotated'
	state.outputTokenOverrides.push({ model: 'primary:alpha', tokens: 1024 })
	assert.deepEqual(plain(state.changedKeys), ['defaultModel', 'outputTokenOverrides', 'webhookSecret', 'rateLimitMinute'])
	assert.deepEqual(plain(state.dirtyTabs), ['models', 'system'])
	assert.deepEqual(plain(state.unsavedLabels), ['Default model', 'Model exceptions', 'Webhook secret', 'Rate limits'])
	const resetKey = state.secretResetKey
	state.discardChanges()
	assert.equal(state.settings.defaultModel, 'primary:alpha')
	assert.equal(state.settings.rateLimitMinute, 30)
	assert.equal(state.settings.webhookSecret, '')
	assert.deepEqual(plain(state.outputTokenOverrides), [])
	assert.equal(state.secretResetKey, resetKey + 1, 'Secret fields leave their editing state')
	assert.equal(state.hasUnsavedChanges, false)
})

test('saving clears typed secrets and starts a new baseline', async () => {
	const { state, puts } = await loadedAdmin()
	state.settings.embeddingApiKey = 'sk-embedding'
	state.settings.speechApiKey = 'sk-speech'
	state.settings.ragChunkSize = 800
	await state.saveSettings()
	assert.equal(puts.length, 1)
	assert.equal(state.storedSecrets.embeddingApiKey, true)
	assert.equal(state.storedSecrets.speechApiKey, true)
	assert.equal(state.settings.embeddingApiKey, '')
	assert.deepEqual(plain(state.changedKeys), [])
	assert.equal(state.serviceModes.embedding, 'custom', 'A stored separate key means an own provider')
})

test('optional keys can be removed explicitly; required ones cannot', async () => {
	const { state, puts } = await loadedAdmin()
	state.setSecretRemoval('apiKey', true)
	state.setSecretRemoval('webhookSecret', true)
	assert.deepEqual(plain(state.clearCredentials), [])
	state.setSecretRemoval('secondaryApiKey', true)
	assert.deepEqual(plain(state.changedKeys), ['secondaryApiKey'])
	state.setSecret('secondaryApiKey', 'sk-replacement')
	assert.deepEqual(plain(state.clearCredentials), [], 'Typing a new key replaces instead of removing')
	state.setSecret('secondaryApiKey', '')
	state.setSecretRemoval('secondaryApiKey', true)
	await state.saveSettings()
	assert.deepEqual(plain(puts[0].clearCredentials), ['secondaryApiKey'])
	assert.equal(puts[0].secondaryApiKey, null)
	assert.equal(state.storedSecrets.secondaryApiKey, false)
	assert.deepEqual(plain(state.clearCredentials), [])
	assert.equal(state.hasUnsavedChanges, false)
})

test('switching a capability to the primary provider removes its own endpoint and key', async () => {
	const { state, puts } = await loadedAdmin()
	state.setServiceMode('vision', 'inherit')
	assert.equal(state.settings.visionApiEndpoint, '')
	assert.deepEqual(plain(state.clearCredentials), ['visionApiKey'])
	assert.deepEqual(plain(state.changedKeys), ['visionApiEndpoint', 'visionApiKey'])
	state.setServiceMode('vision', 'custom')
	assert.equal(state.settings.visionApiEndpoint, 'https://vision.example/v1/chat/completions', 'The previous endpoint comes back')
	assert.deepEqual(plain(state.clearCredentials), [])
	assert.equal(state.hasUnsavedChanges, false)
	state.setServiceMode('vision', 'inherit')
	await state.saveSettings()
	assert.equal(puts[0].visionApiEndpoint, '')
	assert.deepEqual(plain(puts[0].clearCredentials), ['visionApiKey'])
	assert.equal(state.serviceModes.vision, 'inherit')
})

test('removing a separate embedding key asks for reindexing', async () => {
	let confirmed = 0
	const state = admin({ put: async () => ({ data: {} }) }, { confirm: () => { confirmed++; return true } })
	state.settings.defaultModel = 'primary:alpha'
	state.embeddingConfigBaseline.hasEmbeddingApiKey = true
	state.storedSecrets.embeddingApiKey = true
	state.setSecretRemoval('embeddingApiKey', true)
	await state.saveSettings()
	assert.equal(confirmed, 1)
	assert.equal(state.embeddingConfigBaseline.hasEmbeddingApiKey, false)
})

test('Docling detection sends the typed values and applies the detected profile', async () => {
	const posts = []
	const state = admin({ post: async (url, payload) => {
		posts.push({ url, payload })
		return { data: { success: true, profile: 'legacy', auth_mode: 'bearer', service: 'AcademicCloud / EDUC', endpoint: 'https://docling.example/educ/v1/documents/convert', duration_ms: 1400 } }
	} })
	state.settings.doclingApiEndpoint = ' https://docling.example/educ '
	state.settings.doclingApiKey = 'typed-key'
	await state.detectDocling()
	assert.deepEqual(plain(posts), [{ url: '/apps/educai/api/v1/admin/docling/detect', payload: { doclingApiEndpoint: 'https://docling.example/educ', doclingApiKey: 'typed-key', ignoreStoredKey: false } }])
	assert.equal(state.settings.doclingApiProfile, 'legacy')
	assert.equal(state.settings.doclingAuthMode, 'bearer')
	assert.equal(state.settings.doclingApiEndpoint, 'https://docling.example/educ/v1/documents/convert')
	assert.equal(state.doclingDetection.success, true)
	assert.equal(state.doclingDetecting, false)
})

test('Docling detection ignores a stored key that is marked for removal', async () => {
	const posts = []
	const state = admin({ post: async (url, payload) => { posts.push(payload); return { data: { success: true, profile: 'docling_serve', auth_mode: 'none', endpoint: 'https://docling.example' } } } })
	state.storedSecrets.doclingApiKey = true
	state.settings.doclingApiEndpoint = 'https://docling.example'
	state.setSecretRemoval('doclingApiKey', true)
	await state.detectDocling()
	assert.equal(posts[0].ignoreStoredKey, true)
	assert.equal(posts[0].doclingApiKey, null)
	assert.equal(state.settings.doclingAuthMode, 'none')
	state.setSecret('doclingApiKey', 'replacement')
	await state.detectDocling()
	assert.equal(posts[1].ignoreStoredKey, false, 'A typed key replaces the stored one anyway')
	assert.equal(posts[1].doclingApiKey, 'replacement')
})

test('a failed Docling detection keeps the form and shows a safe message', async () => {
	const state = admin({ post: async () => { throw { response: { data: { success: false, errorCode: 'docling_detection_failed', error: 'Docling endpoint must be an HTTP(S) URL without embedded credentials or a fragment.' } } } } })
	state.settings.doclingApiEndpoint = 'ftp://docling.example'
	state.settings.doclingApiProfile = 'docling_serve'
	await state.detectDocling()
	assert.equal(state.doclingDetection.success, false)
	assert.match(state.doclingDetection.error, /HTTP\(S\) URL/)
	assert.equal(state.settings.doclingApiProfile, 'docling_serve')
	assert.equal(state.settings.doclingApiEndpoint, 'ftp://docling.example')
	state.applyDoclingDetection({ success: false, profile: 'unknown', auth_mode: 'cookie', endpoint: 42 })
	assert.equal(state.settings.doclingApiProfile, 'docling_serve', 'Unknown values from the server are ignored')
	assert.equal(state.settings.doclingAuthMode, 'x_api_key')
})

test('tabs are normalised and validation errors open the right place', async () => {
	const state = admin({ put: async () => assert.fail('Invalid settings must not be saved') })
	state.setTab('knowledge')
	assert.equal(state.activeTab, 'knowledge')
	state.setTab('nonsense')
	assert.equal(state.activeTab, 'overview')
	state.goTo({ tab: 'system', target: 'educai-admin-talk' })
	assert.equal(state.activeTab, 'system')
	state.settings.defaultModel = 'primary:alpha'
	state.settings.appIconMode = 'custom'
	state.settings.appIconBlackUrl = 'javascript:alert(1)'
	await state.saveSettings()
	assert.equal(state.activeTab, 'system')
	state.settings.appIconMode = 'default'
	state.settings.defaultTemperature = 2
	await state.saveSettings()
	assert.equal(state.activeTab, 'models')
})

test('rate-limit presets fill both chat and embedding limits', () => {
	const state = admin()
	Object.assign(state.settings, { rateLimitSecond: 5, rateLimitMinute: 1, rateLimitHour: 2, rateLimitDay: 3 })
	state.applyRateLimitPreset('chat')
	assert.deepEqual([state.settings.rateLimitSecond, state.settings.rateLimitMinute, state.settings.rateLimitHour, state.settings.rateLimitDay], [null, 30, 200, 1000])
	state.applyRateLimitPreset('embeddings')
	assert.deepEqual([state.settings.embeddingRateLimitMinute, state.settings.embeddingRateLimitHour, state.settings.embeddingRateLimitDay], [100, 2000, 4000])
	state.setRateLimit('chat', 'minute', 45)
	state.setRateLimit('chat', 'unknown', 1)
	assert.equal(state.settings.rateLimitMinute, 45)
})

test('adopting a server-confirmed model binding does not count as an unsaved change', async () => {
	const state = admin({ get: async url => url.endsWith('/settings')
		? { data: { default_model: 'primary:shared' } }
		: { data: { model_options: [], providers: [] } } })
	state.settings.defaultModel = 'shared'
	state.captureModelSettingsBaseline()
	state.captureSettingsBaseline()
	state.adoptSavedModelBindings({ default_model: 'primary:shared', fallback_model: '', allowed_models: [] })
	assert.equal(state.settings.defaultModel, 'primary:shared')
	assert.deepEqual(plain(state.changedKeys), [])
})

test('the catalogue test reports a structured result', async () => {
	const state = admin({ post: async () => ({ data: { success: true } }) })
	state.settings.catalogueApiEndpoint = 'https://catalogue.example/api'
	await state.testCatalogue()
	assert.deepEqual(plain(state.catalogueTestResult), { success: true, message: 'Catalogue connection successful' })
	const failing = admin({ post: async () => { throw { response: { data: { errorCode: 'catalogue_connection_failed', error: 'Catalogue unreachable' } } } } })
	failing.settings.catalogueApiEndpoint = 'https://catalogue.example/api'
	await failing.testCatalogue()
	assert.equal(failing.catalogueTestResult.success, false)
	assert.equal(failing.catalogueTestResult.message, 'Catalogue unreachable')
})

test('the overview is derived from saved settings only', async () => {
	const { state } = await loadedAdmin()
	state.allBotsLoaded = true
	state.allBots = [{ approval_status: 'approved' }]
	const before = JSON.stringify(state.overview.cards)
	state.settings.visionModel = ''
	assert.equal(JSON.stringify(state.overview.cards), before, 'Unsaved edits do not change the status')
})
