import { t } from '../l10n.js'

export const ADMIN_TABS = ['overview', 'models', 'knowledge', 'capabilities', 'system', 'bots']

export function normalizeAdminTab(value) {
	return ADMIN_TABS.includes(value) ? value : 'overview'
}

export const SECRET_FIELDS = ['apiKey', 'secondaryApiKey', 'webhookSecret', 'embeddingApiKey', 'doclingApiKey', 'visionApiKey', 'speechApiKey']

export const CLEARABLE_SECRETS = ['secondaryApiKey', 'embeddingApiKey', 'visionApiKey', 'speechApiKey', 'doclingApiKey']

// API field names that report a stored secret as "***".
export const STORED_SECRET_FIELDS = {
	apiKey: 'api_key',
	secondaryApiKey: 'secondary_api_key',
	webhookSecret: 'webhook_secret',
	embeddingApiKey: 'embedding_api_key',
	doclingApiKey: 'docling_api_key',
	visionApiKey: 'vision_api_key',
	speechApiKey: 'speech_api_key',
}

export const SETTING_TABS = {
	apiEndpoint: 'models',
	apiKey: 'models',
	secondaryApiEndpoint: 'models',
	secondaryApiKey: 'models',
	defaultModel: 'models',
	fallbackModel: 'models',
	allowMultipleModels: 'models',
	allowedModels: 'models',
	defaultTemperature: 'models',
	maxOutputTokens: 'models',
	outputTokenOverrides: 'models',
	conversationContextTokens: 'models',
	ragEnabled: 'knowledge',
	embeddingApiEndpoint: 'knowledge',
	embeddingApiKey: 'knowledge',
	embeddingModel: 'knowledge',
	embeddingRateLimitMode: 'knowledge',
	embeddingRateLimitSecond: 'knowledge',
	embeddingRateLimitMinute: 'knowledge',
	embeddingRateLimitHour: 'knowledge',
	embeddingRateLimitDay: 'knowledge',
	ragChunkSize: 'knowledge',
	ragChunkOverlap: 'knowledge',
	doclingEnabled: 'knowledge',
	doclingApiEndpoint: 'knowledge',
	doclingApiKey: 'knowledge',
	doclingApiProfile: 'knowledge',
	doclingAuthMode: 'knowledge',
	catalogueEnabled: 'knowledge',
	catalogueApiEndpoint: 'knowledge',
	catalogueReindexHours: 'knowledge',
	visionApiEndpoint: 'capabilities',
	visionApiKey: 'capabilities',
	visionModel: 'capabilities',
	speechApiEndpoint: 'capabilities',
	speechApiKey: 'capabilities',
	speechModel: 'capabilities',
	webhookSecret: 'system',
	rateLimitEnabled: 'system',
	rateLimitSecond: 'system',
	rateLimitMinute: 'system',
	rateLimitHour: 'system',
	rateLimitDay: 'system',
	rateLimitQueueMessage: 'system',
	llmChatTimeout: 'system',
	llmStreamTimeout: 'system',
	llmModelsTimeout: 'system',
	appIconMode: 'system',
	appIconBlackUrl: 'system',
	appIconWhiteUrl: 'system',
}

const NUMBER_FIELDS = new Set([
	'defaultTemperature', 'maxOutputTokens', 'conversationContextTokens',
	'embeddingRateLimitSecond', 'embeddingRateLimitMinute', 'embeddingRateLimitHour', 'embeddingRateLimitDay',
	'ragChunkSize', 'ragChunkOverlap', 'catalogueReindexHours',
	'rateLimitSecond', 'rateLimitMinute', 'rateLimitHour', 'rateLimitDay',
	'llmChatTimeout', 'llmStreamTimeout', 'llmModelsTimeout',
])

/**
 * @param {string} key Setting key
 * @return {string}
 */
export function settingLabel(key) {
	switch (key) {
	case 'apiEndpoint': return t('educai', 'Primary endpoint')
	case 'apiKey': return t('educai', 'Primary API key')
	case 'secondaryApiEndpoint': return t('educai', 'Secondary endpoint')
	case 'secondaryApiKey': return t('educai', 'Secondary API key')
	case 'defaultModel': return t('educai', 'Default model')
	case 'fallbackModel': return t('educai', 'Fallback model')
	case 'allowMultipleModels':
	case 'allowedModels': return t('educai', 'Released models')
	case 'defaultTemperature': return t('educai', 'Creativity')
	case 'maxOutputTokens': return t('educai', 'Maximum response length')
	case 'outputTokenOverrides': return t('educai', 'Model exceptions')
	case 'conversationContextTokens': return t('educai', 'Conversation memory')
	case 'ragEnabled': return t('educai', 'Knowledge sources')
	case 'embeddingApiEndpoint':
	case 'embeddingApiKey': return t('educai', 'Embedding provider')
	case 'embeddingModel': return t('educai', 'Embedding model')
	case 'embeddingRateLimitMode':
	case 'embeddingRateLimitSecond':
	case 'embeddingRateLimitMinute':
	case 'embeddingRateLimitHour':
	case 'embeddingRateLimitDay': return t('educai', 'Embedding rate limits')
	case 'ragChunkSize':
	case 'ragChunkOverlap': return t('educai', 'Text chunks')
	case 'doclingEnabled':
	case 'doclingApiEndpoint':
	case 'doclingApiKey':
	case 'doclingApiProfile':
	case 'doclingAuthMode': return t('educai', 'Document conversion')
	case 'catalogueEnabled':
	case 'catalogueApiEndpoint':
	case 'catalogueReindexHours': return t('educai', 'Course catalogue')
	case 'visionApiEndpoint':
	case 'visionApiKey':
	case 'visionModel': return t('educai', 'Image understanding')
	case 'speechApiEndpoint':
	case 'speechApiKey':
	case 'speechModel': return t('educai', 'Speech to text')
	case 'webhookSecret': return t('educai', 'Webhook secret')
	case 'rateLimitEnabled':
	case 'rateLimitSecond':
	case 'rateLimitMinute':
	case 'rateLimitHour':
	case 'rateLimitDay':
	case 'rateLimitQueueMessage': return t('educai', 'Rate limits')
	case 'llmChatTimeout':
	case 'llmStreamTimeout':
	case 'llmModelsTimeout': return t('educai', 'Time limits')
	case 'appIconMode':
	case 'appIconBlackUrl':
	case 'appIconWhiteUrl': return t('educai', 'App icon')
	default: return key
	}
}

function clone(value) {
	return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

function comparable(key, value) {
	if (key === 'allowedModels') {
		return JSON.stringify((Array.isArray(value) ? value : []).map(item => String(item).trim()).sort())
	}
	if (key === 'outputTokenOverrides') {
		return JSON.stringify((Array.isArray(value) ? value : []).map(row => [String(row?.model ?? '').trim(), row?.tokens ?? null]))
	}
	if (typeof value === 'boolean') {
		return value
	}
	if (NUMBER_FIELDS.has(key)) {
		return value === '' || value === null || value === undefined ? null : Number(value)
	}
	return value === null || value === undefined ? '' : String(value).trim()
}

/**
 * @param {object} settings Form settings
 * @param {Array} outputTokenOverrides Model-specific output limits
 * @return {object}
 */
export function snapshotSettings(settings, outputTokenOverrides = []) {
	const snapshot = {}
	for (const key of Object.keys(SETTING_TABS)) {
		if (SECRET_FIELDS.includes(key)) continue
		snapshot[key] = clone(key === 'outputTokenOverrides' ? outputTokenOverrides : settings[key])
	}
	return snapshot
}

/**
 * @param {object} settings Form settings
 * @param {Array} outputTokenOverrides Model-specific output limits
 * @param {object|null} baseline Snapshot taken after loading or saving
 * @param {string[]} clearCredentials Secrets marked for removal
 * @return {string[]}
 */
export function changedSettingKeys(settings, outputTokenOverrides, baseline, clearCredentials = []) {
	if (!baseline) return []
	return Object.keys(SETTING_TABS).filter(key => {
		if (SECRET_FIELDS.includes(key)) {
			return String(settings[key] ?? '').trim() !== '' || clearCredentials.includes(key)
		}
		const current = key === 'outputTokenOverrides' ? outputTokenOverrides : settings[key]
		return comparable(key, current) !== comparable(key, baseline[key])
	})
}

/**
 * @param {object} settings Form settings, changed in place
 * @param {object} baseline Snapshot
 * @return {Array}
 */
export function restoreSettings(settings, baseline) {
	for (const [key, value] of Object.entries(baseline)) {
		if (key !== 'outputTokenOverrides') settings[key] = clone(value)
	}
	for (const key of SECRET_FIELDS) settings[key] = ''
	return clone(baseline.outputTokenOverrides || [])
}

export function changedTabs(keys) {
	return ADMIN_TABS.filter(tab => keys.some(key => SETTING_TABS[key] === tab))
}

export function changedLabels(keys) {
	return [...new Set(keys.map(settingLabel))]
}

export const RATE_LIMIT_WINDOWS = ['second', 'minute', 'hour', 'day']

// GWDG / AcademicCloud quotas. Other providers use their own values.
export const RATE_LIMIT_PRESETS = {
	chat: { second: null, minute: 30, hour: 200, day: 1000 },
	embeddings: { second: null, minute: 100, hour: 2000, day: 4000 },
}

function limitValue(value) {
	const numeric = Number(value)
	return value === '' || value === null || value === undefined || !Number.isFinite(numeric) || numeric <= 0 ? null : numeric
}

export function rateLimitPreset(values, kind) {
	const preset = RATE_LIMIT_PRESETS[kind]
	return RATE_LIMIT_WINDOWS.every(window => limitValue(values?.[window]) === preset[window]) ? 'gwdg' : 'custom'
}

export const TEMPERATURE_PRESETS = [
	{ id: 'precise', value: 0.2 },
	{ id: 'balanced', value: 0.4 },
	{ id: 'creative', value: 0.6 },
]

export function temperaturePreset(value) {
	const numeric = Math.round(Number(value) * 100) / 100
	return TEMPERATURE_PRESETS.find(preset => preset.value === numeric)?.id || 'custom'
}

/**
 * @param {object} state Saved and typed values of one capability
 * @param {string} state.endpoint Own endpoint, empty to use the primary one
 * @param {boolean} state.hasStoredKey A separate key is stored
 * @param {string} state.typedKey A new key was typed but not saved
 * @param {boolean} state.clearPending The stored key will be removed
 * @return {'inherit'|'custom'}
 */
export function serviceSourceMode({ endpoint = '', hasStoredKey = false, typedKey = '', clearPending = false } = {}) {
	return String(endpoint ?? '').trim() !== '' || String(typedKey ?? '').trim() !== '' || (hasStoredKey && !clearPending)
		? 'custom'
		: 'inherit'
}

export function endpointHost(value) {
	const raw = String(value ?? '').trim()
	if (!raw) return ''
	try {
		return new URL(raw).host
	} catch {
		return raw
	}
}

// 32 random bytes as hex, the same strength the server uses for generated secrets.
export function generateSecret(cryptoSource = globalThis.crypto) {
	const bytes = new Uint8Array(32)
	cryptoSource.getRandomValues(bytes)
	return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
}
