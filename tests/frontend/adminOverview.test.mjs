import assert from 'node:assert/strict'
import test from 'node:test'
import { getCanonicalLocale } from '../../src/l10n.js'
import { deriveOverview, formatDuration, formatWhen } from '../../src/utils/adminOverview.js'

const now = Math.floor(new Date('2026-10-14T10:45:00').getTime() / 1000)
const saved = (extra = {}) => ({
	apiEndpoint: 'https://chat-ai.academiccloud.de/v1/chat/completions',
	secondaryApiEndpoint: '',
	defaultModel: 'primary:llama-3.3-70b-instruct',
	ragEnabled: false,
	catalogueEnabled: false,
	doclingEnabled: false,
	doclingApiProfile: 'docling_serve',
	doclingAuthMode: 'x_api_key',
	visionModel: '',
	speechModel: '',
	rateLimitEnabled: false,
	rateLimitSecond: null,
	rateLimitMinute: 30,
	rateLimitHour: 200,
	rateLimitDay: 1000,
	embeddingModel: '',
	...extra,
})
const ready = { id: 'primary', status: 'ready', model_count: 27, last_success_at: now - 60, checked_at: now - 60 }
const healthy = (extra = {}) => ({
	saved: saved(),
	stored: { apiKey: true, webhookSecret: true },
	providers: [ready],
	checks: { model: { success: true, current: true, checked_at: now - 300, duration_ms: 820 } },
	bots: [{ id: 1, approval_status: 'approved' }],
	tools: [],
	now,
	...extra,
})
const card = (overview, id) => overview.cards.find(item => item.id === id)

test('a fresh installation shows the four setup steps', () => {
	const overview = deriveOverview({ saved: saved({ apiEndpoint: '', defaultModel: 'llama-3.3-70b-instruct' }), stored: { webhookSecret: true }, bots: [], now })
	assert.ok(overview.setup)
	assert.deepEqual(overview.setup.steps.map(step => [step.id, step.done]), [['provider', false], ['model', false], ['test', false], ['bot', false]])
	assert.equal(overview.setup.doneCount, 0)
	assert.equal(overview.setup.steps[3].action.app, true, 'Bots are created in the app, not in the settings')
})

test('the setup checklist tracks completed steps and disappears once a bot exists', () => {
	const pending = deriveOverview(healthy({ bots: [] }))
	assert.deepEqual(pending.setup.steps.map(step => step.done), [true, true, true, false])
	assert.match(pending.setup.steps[0].detail, /chat-ai\.academiccloud\.de · 27 models found/)
	assert.equal(deriveOverview(healthy()).setup, null)
	assert.ok(deriveOverview(healthy({ bots: null })).setup === null, 'Unknown bot list does not show the checklist')
})

test('a healthy installation has no attention items and ready cards', () => {
	const overview = deriveOverview(healthy())
	assert.deepEqual(overview.attention, [])
	assert.equal(card(overview, 'models').tone, 'ok')
	assert.equal(card(overview, 'talk').tone, 'ok')
	assert.equal(card(overview, 'knowledge').tone, 'off')
	assert.equal(card(overview, 'documents').tone, 'off')
	assert.equal(card(overview, 'queue').tone, 'off')
	const testRow = card(overview, 'models').rows.find(row => row.label === 'Test answer')
	assert.match(testRow.value, /^820 ms · today /)
})

test('provider outages are reported without blaming the healthy provider', () => {
	const overview = deriveOverview(healthy({
		saved: saved({ secondaryApiEndpoint: 'https://openrouter.ai/api/v1/chat/completions' }),
		providers: [ready, { id: 'secondary', status: 'stale', model_count: 312, checked_at: now - 7200 }],
	}))
	assert.deepEqual(overview.attention.map(item => item.id), ['provider-secondary'])
	assert.equal(overview.attention[0].action.tab, 'models')
	assert.equal(card(overview, 'models').tone, 'warn')
	const rows = Object.fromEntries(card(overview, 'models').rows.map(row => [row.label, row.value]))
	assert.equal(rows.Primary, '27 models · up to date')
	assert.equal(rows.Secondary, '312 models · out of date')
})

test('a reachable provider without a model list is not reported as having zero models', () => {
	const unlisted = { ...ready, model_count: 0, lists_models: false }
	const overview = deriveOverview(healthy({ providers: [unlisted] }))
	assert.deepEqual(overview.attention, [])
	const rows = Object.fromEntries(card(overview, 'models').rows.map(row => [row.label, row.value]))
	assert.equal(rows.Primary, 'no model list · up to date')
	const pending = deriveOverview(healthy({ providers: [unlisted], bots: [] }))
	assert.match(pending.setup.steps[0].detail, /chat-ai\.academiccloud\.de · no model list/)
})

test('an unconfigured secondary provider is ignored', () => {
	const overview = deriveOverview(healthy({ providers: [ready, { id: 'secondary', status: 'unconfigured' }] }))
	assert.deepEqual(overview.attention, [])
	assert.equal(card(overview, 'models').rows.some(row => row.label === 'Secondary'), false)
})

test('index errors, failed current checks, blocked queues and approvals need attention', () => {
	const overview = deriveOverview(healthy({
		saved: saved({ ragEnabled: true, doclingEnabled: true, visionModel: 'vision', rateLimitEnabled: true }),
		embedding: { rag: { enabled: true, ready: 1204, error: 2, needs_reindex: false, last_indexed: now - 3 * 3600 }, catalogue: { enabled: false } },
		checks: {
			model: { success: true, current: true, checked_at: now },
			docling: { success: false, current: true, checked_at: now },
			vision: { success: false, current: false, checked_at: now },
		},
		queue: { queue_stats: { pending: 4, processing: 0 }, chat_status: { can_process: false, state: { limit_minute: 30, remaining_minute: 0 } } },
		bots: [{ approval_status: 'pending' }, { approval_status: 'approved' }],
	}))
	assert.deepEqual(overview.attention.map(item => item.id), ['rag-errors', 'docling-test', 'queue', 'approvals'])
	assert.equal(overview.attention.at(-1).tone, 'info')
	assert.equal(card(overview, 'knowledge').chip, '2 errors')
	assert.equal(card(overview, 'documents').tone, 'warn')
	assert.equal(card(overview, 'queue').tone, 'warn')
	const queueRows = Object.fromEntries(card(overview, 'queue').rows.map(row => [row.label, row.value]))
	assert.equal(queueRows.Limit, '30/min · GWDG preset')
	assert.equal(queueRows.Remaining, '0 of 30')
})

test('checks for settings changed since the test are shown as outdated, not as failures', () => {
	const overview = deriveOverview(healthy({
		saved: saved({ doclingEnabled: true }),
		checks: { docling: { success: true, current: false, checked_at: now } },
	}))
	assert.deepEqual(overview.attention, [])
	assert.equal(card(overview, 'documents').tone, 'info')
	assert.equal(card(overview, 'documents').rows.at(-1).value, 'last test used other settings')
	assert.equal(card(overview, 'documents').chip, 'Not checked')
})

test('a missing webhook secret is flagged', () => {
	const overview = deriveOverview(healthy({ stored: { apiKey: true, webhookSecret: false } }))
	assert.deepEqual(overview.attention.map(item => item.id), ['webhook'])
	assert.equal(card(overview, 'talk').tone, 'warn')
})

test('timestamps read as today or as a short date', () => {
	assert.match(formatWhen(now - 600, now), /^today \d{2}:\d{2}/)
	assert.doesNotMatch(formatWhen(now - 3 * 86400, now), /today/)
	assert.equal(formatWhen(null, now), 'never')
	assert.equal(formatWhen(0, now), 'never')
})

test('durations switch from milliseconds to seconds', () => {
	assert.equal(formatDuration(820), '820 ms')
	assert.equal(formatDuration(2004), '2 s')
	assert.equal(formatDuration(12345), `${new Intl.NumberFormat(getCanonicalLocale(), { maximumFractionDigits: 1 }).format(12.3)} s`)
})

test('the test answer names the model that was actually tested', () => {
	const overview = deriveOverview(healthy({ checks: { model: { success: true, current: true, checked_at: now - 300, duration_ms: 299, summary: 'secondary:gemma-31b' } } }))
	const testRow = card(overview, 'models').rows.find(row => row.label === 'Test answer')
	assert.match(testRow.value, /^gemma-31b · 299 ms · today /)
	const outdated = deriveOverview(healthy({ checks: { model: { success: true, current: false, checked_at: now - 300, summary: 'primary:old' } } }))
	assert.equal(card(outdated, 'models').rows.find(row => row.label === 'Test answer').value, 'last test used other settings')
})

test('saved model names without a provider are listed with the place to fix them', () => {
	const inSettings = deriveOverview(healthy({ saved: saved({ defaultModel: 'gpt-4o' }), unqualifiedModels: ['gpt-4o', 'a', 'b', 'c'] }))
	const item = inSettings.attention.find(entry => entry.id === 'unqualified-models')
	assert.match(item.text, /gpt-4o, a, b, …/)
	assert.match(item.text, /keep working as before/)
	assert.equal(item.action.tab, 'models')
	const inBots = deriveOverview(healthy({ unqualifiedModels: ['bot-model'] }))
	assert.equal(inBots.attention.find(entry => entry.id === 'unqualified-models').action.tab, 'bots')
	assert.equal(deriveOverview(healthy({ unqualifiedModels: [] })).attention.length, 0)
})
