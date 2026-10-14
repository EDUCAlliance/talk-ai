import assert from 'node:assert/strict'
import test from 'node:test'
import * as adminSettings from '../../src/utils/adminSettings.js'

const baseSettings = () => ({
	apiEndpoint: 'https://primary.example/v1/chat/completions',
	apiKey: '',
	secondaryApiEndpoint: '',
	secondaryApiKey: '',
	defaultModel: 'primary:alpha',
	fallbackModel: '',
	allowMultipleModels: true,
	allowedModels: ['primary:alpha', 'secondary:beta'],
	defaultTemperature: 0.2,
	maxOutputTokens: 32768,
	conversationContextTokens: 8000,
	ragEnabled: true,
	embeddingApiEndpoint: '',
	embeddingApiKey: '',
	embeddingModel: 'e5',
	embeddingRateLimitMode: 'inherit',
	embeddingRateLimitSecond: null,
	embeddingRateLimitMinute: 100,
	embeddingRateLimitHour: 2000,
	embeddingRateLimitDay: 4000,
	ragChunkSize: 750,
	ragChunkOverlap: 50,
	doclingEnabled: false,
	doclingApiEndpoint: '',
	doclingApiKey: '',
	doclingApiProfile: 'docling_serve',
	doclingAuthMode: 'x_api_key',
	catalogueEnabled: false,
	catalogueApiEndpoint: '',
	catalogueReindexHours: 24,
	visionApiEndpoint: '',
	visionApiKey: '',
	visionModel: '',
	speechApiEndpoint: '',
	speechApiKey: '',
	speechModel: '',
	webhookSecret: '',
	rateLimitEnabled: false,
	rateLimitSecond: null,
	rateLimitMinute: 30,
	rateLimitHour: 200,
	rateLimitDay: 1000,
	rateLimitQueueMessage: '',
	llmChatTimeout: 90,
	llmStreamTimeout: 240,
	llmModelsTimeout: 20,
	appIconMode: 'default',
	appIconBlackUrl: '',
	appIconWhiteUrl: '',
})

test('unknown tabs fall back to the overview', () => {
	assert.equal(adminSettings.normalizeAdminTab('models'), 'models')
	assert.equal(adminSettings.normalizeAdminTab('bots'), 'bots')
	for (const value of ['', null, undefined, 'MODELS', '../system', 'essentials']) {
		assert.equal(adminSettings.normalizeAdminTab(value), 'overview')
	}
})

test('every saved setting has a tab and a readable label', () => {
	for (const [key, tab] of Object.entries(adminSettings.SETTING_TABS)) {
		assert.ok(adminSettings.ADMIN_TABS.includes(tab), key)
		assert.notEqual(adminSettings.settingLabel(key), key, `${key} needs a label`)
	}
	for (const key of adminSettings.SECRET_FIELDS) assert.ok(adminSettings.SETTING_TABS[key], key)
	for (const key of adminSettings.CLEARABLE_SECRETS) assert.ok(adminSettings.SECRET_FIELDS.includes(key), key)
	assert.ok(!adminSettings.CLEARABLE_SECRETS.includes('apiKey'), 'The primary key can only be replaced')
	assert.ok(!adminSettings.CLEARABLE_SECRETS.includes('webhookSecret'), 'Talk needs a webhook secret')
})

test('a fresh snapshot has no unsaved changes', () => {
	const settings = baseSettings()
	const overrides = [{ model: 'primary:alpha', tokens: 4096 }]
	const baseline = adminSettings.snapshotSettings(settings, overrides)
	assert.deepEqual(adminSettings.changedSettingKeys(settings, overrides, baseline), [])
	assert.deepEqual(adminSettings.changedSettingKeys(settings, overrides, null), [], 'No baseline means nothing loaded yet')
	assert.equal('apiKey' in baseline, false, 'Secrets are never copied into the baseline')
})

test('changes are detected per field without false positives from form normalisation', () => {
	const settings = baseSettings()
	const baseline = adminSettings.snapshotSettings(settings, [])
	settings.allowedModels = ['secondary:beta', 'primary:alpha']
	settings.rateLimitSecond = ''
	settings.embeddingModel = ' e5 '
	assert.deepEqual(adminSettings.changedSettingKeys(settings, [], baseline), [], 'Order, empty numbers and whitespace are not changes')

	settings.defaultTemperature = 0.4
	settings.allowedModels = ['primary:alpha']
	settings.ragChunkSize = '800'
	assert.deepEqual(adminSettings.changedSettingKeys(settings, [], baseline), ['allowedModels', 'defaultTemperature', 'ragChunkSize'])
})

test('typed secrets and pending removals count as unsaved changes', () => {
	const settings = baseSettings()
	const baseline = adminSettings.snapshotSettings(settings, [])
	settings.apiKey = '   '
	assert.deepEqual(adminSettings.changedSettingKeys(settings, [], baseline), [], 'Whitespace is not a new key')
	settings.secondaryApiKey = 'sk-new'
	assert.deepEqual(adminSettings.changedSettingKeys(settings, [], baseline, ['visionApiKey']), ['secondaryApiKey', 'visionApiKey'])
})

test('output limit exceptions are compared by content', () => {
	const settings = baseSettings()
	const baseline = adminSettings.snapshotSettings(settings, [{ model: 'primary:alpha', tokens: 4096 }])
	assert.deepEqual(adminSettings.changedSettingKeys(settings, [{ model: 'primary:alpha ', tokens: 4096 }], baseline), [])
	assert.deepEqual(adminSettings.changedSettingKeys(settings, [{ model: 'primary:alpha', tokens: 8192 }], baseline), ['outputTokenOverrides'])
	assert.deepEqual(adminSettings.changedSettingKeys(settings, [], baseline), ['outputTokenOverrides'])
})

test('discarding restores the snapshot, clears typed secrets and returns independent copies', () => {
	const settings = baseSettings()
	const baseline = adminSettings.snapshotSettings(settings, [{ model: 'primary:alpha', tokens: 4096 }])
	settings.defaultModel = 'secondary:beta'
	settings.allowedModels.push('primary:gamma')
	settings.apiKey = 'typed'
	settings.webhookSecret = 'typed'
	const overrides = adminSettings.restoreSettings(settings, baseline)
	assert.equal(settings.defaultModel, 'primary:alpha')
	assert.deepEqual(settings.allowedModels, ['primary:alpha', 'secondary:beta'])
	assert.equal(settings.apiKey, '')
	assert.equal(settings.webhookSecret, '')
	assert.deepEqual(overrides, [{ model: 'primary:alpha', tokens: 4096 }])
	settings.allowedModels.push('changed')
	overrides[0].tokens = 1
	assert.deepEqual(baseline.allowedModels, ['primary:alpha', 'secondary:beta'], 'The baseline is not shared with the form')
	assert.equal(baseline.outputTokenOverrides[0].tokens, 4096)
	assert.deepEqual(adminSettings.changedSettingKeys(settings, [{ model: 'primary:alpha', tokens: 4096 }], baseline), ['allowedModels'])
})

test('changed keys map to tabs and grouped labels', () => {
	const keys = ['rateLimitMinute', 'rateLimitHour', 'defaultModel', 'doclingApiProfile', 'doclingAuthMode']
	assert.deepEqual(adminSettings.changedTabs(keys), ['models', 'knowledge', 'system'])
	assert.deepEqual(adminSettings.changedLabels(keys), ['Rate limits', 'Default model', 'Document conversion'])
})

test('rate-limit presets are recognised from the stored values', () => {
	assert.equal(adminSettings.rateLimitPreset({ second: null, minute: 30, hour: 200, day: 1000 }, 'chat'), 'gwdg')
	assert.equal(adminSettings.rateLimitPreset({ second: '', minute: '30', hour: 200, day: 1000 }, 'chat'), 'gwdg')
	assert.equal(adminSettings.rateLimitPreset({ second: 5, minute: 30, hour: 200, day: 1000 }, 'chat'), 'custom')
	assert.equal(adminSettings.rateLimitPreset({ second: null, minute: 100, hour: 2000, day: 4000 }, 'embeddings'), 'gwdg')
	assert.equal(adminSettings.rateLimitPreset({ second: null, minute: 100, hour: 2000, day: 4000 }, 'chat'), 'custom')
})

test('temperature presets match rounded values only', () => {
	assert.equal(adminSettings.temperaturePreset(0.2), 'precise')
	assert.equal(adminSettings.temperaturePreset('0.40'), 'balanced')
	assert.equal(adminSettings.temperaturePreset(0.6), 'creative')
	assert.equal(adminSettings.temperaturePreset(0.35), 'custom')
	assert.equal(adminSettings.temperaturePreset(''), 'custom')
})

test('a capability inherits the primary provider until it has its own endpoint or key', () => {
	assert.equal(adminSettings.serviceSourceMode({}), 'inherit')
	assert.equal(adminSettings.serviceSourceMode({ endpoint: ' https://own.example ' }), 'custom')
	assert.equal(adminSettings.serviceSourceMode({ hasStoredKey: true }), 'custom', 'A stored separate key is still used')
	assert.equal(adminSettings.serviceSourceMode({ hasStoredKey: true, clearPending: true }), 'inherit')
	assert.equal(adminSettings.serviceSourceMode({ typedKey: 'sk-new', clearPending: true }), 'custom')
})

test('endpoint hosts and generated secrets', () => {
	assert.equal(adminSettings.endpointHost('https://chat-ai.academiccloud.de/v1/chat/completions'), 'chat-ai.academiccloud.de')
	assert.equal(adminSettings.endpointHost('http://127.0.0.1:18136/primary/v1'), '127.0.0.1:18136')
	assert.equal(adminSettings.endpointHost(''), '')
	assert.equal(adminSettings.endpointHost('not a url'), 'not a url')
	let calls = 0
	const secret = adminSettings.generateSecret({ getRandomValues: bytes => { calls++; bytes.fill(171); return bytes } })
	assert.equal(calls, 1)
	assert.equal(secret, 'ab'.repeat(32))
	assert.match(adminSettings.generateSecret(), /^[0-9a-f]{64}$/)
	assert.notEqual(adminSettings.generateSecret(), adminSettings.generateSecret())
})
