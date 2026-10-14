import { t, n, getCanonicalLocale } from '../l10n.js'
import { modelLabel, splitModelReference } from './modelSelection.js'
import { endpointHost, rateLimitPreset } from './adminSettings.js'

function formatNumber(value) {
	return new Intl.NumberFormat(getCanonicalLocale()).format(Number(value) || 0)
}

/**
 * Short timestamp for status rows: "today 10:42" or a short date.
 *
 * @param {number|null} unix Seconds since epoch
 * @param {number} now Seconds since epoch
 * @return {string}
 */
export function formatWhen(unix, now = Math.floor(Date.now() / 1000)) {
	const seconds = Number(unix)
	if (!Number.isFinite(seconds) || seconds <= 0) return t('educai', 'never')
	const date = new Date(seconds * 1000)
	const today = new Date(now * 1000)
	const time = new Intl.DateTimeFormat(getCanonicalLocale(), { hour: '2-digit', minute: '2-digit' }).format(date)
	if (date.toDateString() === today.toDateString()) return t('educai', 'today {time}', { time })
	return new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'short', timeStyle: 'short' }).format(date)
}

export function formatAgo(unix, now = Math.floor(Date.now() / 1000)) {
	const seconds = Number(unix)
	if (!Number.isFinite(seconds) || seconds <= 0) return t('educai', 'never')
	const diff = Math.max(0, now - seconds)
	const format = new Intl.RelativeTimeFormat(getCanonicalLocale(), { numeric: 'auto', style: 'short' })
	if (diff < 3600) return format.format(-Math.floor(diff / 60), 'minute')
	if (diff < 86400) return format.format(-Math.floor(diff / 3600), 'hour')
	return format.format(-Math.floor(diff / 86400), 'day')
}

export function providerState(provider) {
	switch (provider?.status) {
	case 'ready': return t('educai', 'up to date')
	case 'stale': return t('educai', 'out of date')
	case 'error': return t('educai', 'unreachable')
	default: return t('educai', 'not configured')
	}
}

export function doclingProfileLabel(profile) {
	return profile === 'docling_serve' ? t('educai', 'Docling Serve') : t('educai', 'AcademicCloud / EDUC compatible')
}

export function doclingAuthLabel(mode) {
	if (mode === 'x_api_key') return 'X-Api-Key'
	if (mode === 'bearer') return 'Bearer'
	return t('educai', 'none')
}

export function formatDuration(milliseconds) {
	const value = Number(milliseconds)
	if (value < 1000) return `${formatNumber(value)} ms`
	return `${new Intl.NumberFormat(getCanonicalLocale(), { maximumFractionDigits: 1 }).format(value / 1000)} s`
}

function checkValue(check, now) {
	if (!check) return { value: t('educai', 'not tested'), tone: 'muted' }
	if (!check.current) return { value: t('educai', 'last test used other settings'), tone: 'muted' }
	if (!check.success) return { value: t('educai', 'failed {time}', { time: formatWhen(check.checked_at, now) }), tone: 'warn' }
	return {
		value: check.duration_ms > 0
			? t('educai', '{duration} · {time}', { duration: formatDuration(check.duration_ms), time: formatWhen(check.checked_at, now) })
			: formatWhen(check.checked_at, now),
	}
}

const chip = {
	ready: () => t('educai', 'Ready'),
	limited: () => t('educai', 'Limited'),
	off: () => t('educai', 'Off'),
	setup: () => t('educai', 'Not set up'),
}

/**
 * Derive the overview from saved settings and live status data.
 *
 * Only saved values are shown: unsaved form edits are not active yet.
 *
 * @param {object} input Status sources
 * @param {object} input.saved Snapshot of the saved settings
 * @param {object} input.stored Which secrets are stored on the server
 * @param {Array} input.providers Model catalogue status per provider
 * @param {object} input.checks Remembered connection checks
 * @param {object|null} input.embedding Embedding index status
 * @param {object|null} input.queue Rate-limit queue status
 * @param {Array|null} input.bots All bots, or null while loading
 * @param {Array} input.tools Registered MCP tools
 * @param {number} input.now Current time in seconds
 * @param {string} input.appName Product name shown to users
 * @return {{setup: object|null, attention: Array, cards: Array}}
 */
export function deriveOverview({
	saved = {},
	stored = {},
	providers = [],
	checks = {},
	embedding = null,
	queue = null,
	bots = null,
	tools = [],
	now = Math.floor(Date.now() / 1000),
	appName = 'Talk AI',
} = {}) {
	const primary = providers.find(provider => provider.id === 'primary') || null
	const secondary = providers.find(provider => provider.id === 'secondary') || null
	const secondaryConfigured = String(saved.secondaryApiEndpoint ?? '').trim() !== ''
	const pendingBots = Array.isArray(bots) ? bots.filter(bot => bot.approval_status === 'pending').length : 0
	const modelCheck = checks?.model || null
	const attention = []

	for (const provider of [primary, secondaryConfigured ? secondary : null]) {
		if (!provider || !['stale', 'error'].includes(provider.status)) continue
		const name = provider.id === 'secondary' ? t('educai', 'secondary provider') : t('educai', 'primary provider')
		attention.push({
			id: `provider-${provider.id}`,
			tone: provider.status === 'error' ? 'error' : 'warn',
			text: provider.status === 'stale'
				? t('educai', 'The model catalogue of the {provider} has been unreachable since {time}. The last known list is still used.', { provider: name, time: formatWhen(provider.checked_at ?? provider.last_success_at, now) })
				: t('educai', 'The model catalogue of the {provider} is unavailable. No models are known for it.', { provider: name }),
			action: { tab: 'models', target: 'educai-admin-providers', label: t('educai', 'Check provider') },
		})
	}
	if (modelCheck && modelCheck.current && !modelCheck.success) {
		attention.push({
			id: 'model-test',
			tone: 'warn',
			text: t('educai', 'The last test request did not receive an answer. Check access and credit.'),
			action: { tab: 'models', target: 'educai-admin-model-test', label: t('educai', 'Test again') },
		})
	}
	const rag = embedding?.rag || null
	if (saved.ragEnabled && rag?.error > 0) {
		attention.push({
			id: 'rag-errors',
			tone: 'warn',
			text: n('educai', '{count} knowledge source could not be indexed.', '{count} knowledge sources could not be indexed.', rag.error, { count: formatNumber(rag.error) }),
			action: { tab: 'knowledge', target: 'educai-admin-index', label: t('educai', 'Show errors') },
		})
	}
	if (saved.ragEnabled && rag?.needs_reindex) {
		attention.push({
			id: 'rag-reindex',
			tone: 'warn',
			text: t('educai', 'The knowledge index uses an older embedding configuration and should be rebuilt.'),
			action: { tab: 'knowledge', target: 'educai-admin-index', label: t('educai', 'Open index') },
		})
	}
	if (saved.catalogueEnabled && embedding?.catalogue?.last_error) {
		attention.push({
			id: 'catalogue-error',
			tone: 'warn',
			text: t('educai', 'The last course catalogue indexing failed.'),
			action: { tab: 'knowledge', target: 'educai-admin-catalogue', label: t('educai', 'Open catalogue') },
		})
	}
	for (const [id, enabled, target, text] of [
		['docling', saved.doclingEnabled, 'educai-admin-docling', t('educai', 'The last document conversion test failed.')],
		['vision', !!String(saved.visionModel ?? '').trim(), 'educai-admin-vision', t('educai', 'The last image understanding test failed.')],
		['speech', !!String(saved.speechModel ?? '').trim(), 'educai-admin-speech', t('educai', 'The last speech-to-text test failed.')],
	]) {
		const check = checks?.[id]
		if (enabled && check?.current && !check.success) {
			attention.push({ id: `${id}-test`, tone: 'warn', text, action: { tab: id === 'docling' ? 'knowledge' : 'capabilities', target, label: t('educai', 'Test again') } })
		}
	}
	const queueStats = queue?.queue_stats || null
	const chatCanProcess = typeof queue?.chat_status?.can_process === 'boolean' ? queue.chat_status.can_process : !!queue?.can_process
	if (saved.rateLimitEnabled && queueStats?.pending > 0 && !chatCanProcess) {
		attention.push({
			id: 'queue',
			tone: 'warn',
			text: n('educai', '{count} request is waiting for free provider capacity.', '{count} requests are waiting for free provider capacity.', queueStats.pending, { count: formatNumber(queueStats.pending) }),
			action: { tab: 'system', target: 'educai-admin-rate-limits', label: t('educai', 'Open queue') },
		})
	}
	if (!stored.webhookSecret) {
		attention.push({
			id: 'webhook',
			tone: 'warn',
			text: t('educai', 'No webhook secret is stored. Talk cannot deliver bot messages securely.'),
			action: { tab: 'system', target: 'educai-admin-talk', label: t('educai', 'Open Talk connection') },
		})
	}
	if (pendingBots > 0) {
		attention.push({
			id: 'approvals',
			tone: 'info',
			text: n('educai', '{count} bot is waiting for approval.', '{count} bots are waiting for approval.', pendingBots, { count: formatNumber(pendingBots) }),
			action: { tab: 'bots', target: 'educai-admin-bots', label: t('educai', 'Show bots') },
		})
	}

	// Cards
	const primaryUsable = primary && ['ready', 'stale'].includes(primary.status)
	const providerRow = provider => ({
		value: provider && ['ready', 'stale'].includes(provider.status)
			? (provider.lists_models === false
				? t('educai', 'no model list · {state}', { state: providerState(provider) })
				: n('educai', '{count} model · {state}', '{count} models · {state}', provider.model_count || 0, { count: formatNumber(provider.model_count || 0), state: providerState(provider) }))
			: providerState(provider),
		tone: provider?.status === 'ready' ? undefined : 'warn',
	})
	const modelsRows = [{ label: t('educai', 'Primary'), ...providerRow(primary) }]
	if (secondaryConfigured) modelsRows.push({ label: t('educai', 'Secondary'), ...providerRow(secondary) })
	modelsRows.push({ label: t('educai', 'Default'), value: splitModelReference(saved.defaultModel)?.model || modelLabel(saved.defaultModel) || t('educai', 'not selected') })
	modelsRows.push({ label: t('educai', 'Test answer'), ...checkValue(modelCheck, now) })
	const degraded = [primary, secondaryConfigured ? secondary : null].some(provider => provider && provider.status !== 'ready')
	const cards = [{
		id: 'models',
		title: t('educai', 'Language models'),
		tone: !primaryUsable ? 'error' : (degraded ? 'warn' : 'ok'),
		chip: !primaryUsable ? chip.setup() : (degraded ? chip.limited() : chip.ready()),
		rows: modelsRows,
		action: { tab: 'models', target: 'educai-admin-providers', label: t('educai', 'Open models') },
	}]

	cards.push({
		id: 'talk',
		title: t('educai', 'Talk connection'),
		tone: stored.webhookSecret ? 'ok' : 'warn',
		chip: stored.webhookSecret ? chip.ready() : chip.setup(),
		rows: [
			{ label: t('educai', 'Webhook secret'), value: stored.webhookSecret ? t('educai', 'stored') : t('educai', 'missing'), tone: stored.webhookSecret ? undefined : 'warn' },
			{ label: t('educai', 'Bots'), value: Array.isArray(bots) ? formatNumber(bots.length) : '…' },
			{ label: t('educai', 'Approvals'), value: pendingBots > 0 ? n('educai', '{count} waiting', '{count} waiting', pendingBots, { count: formatNumber(pendingBots) }) : t('educai', 'none open') },
		],
		action: { tab: 'bots', target: 'educai-admin-bots', label: t('educai', 'Open bots') },
	})

	const ragProblems = !!rag && (rag.error > 0 || rag.needs_reindex)
	cards.push({
		id: 'knowledge',
		title: t('educai', 'Knowledge'),
		tone: !saved.ragEnabled ? 'off' : (ragProblems ? 'warn' : 'ok'),
		chip: !saved.ragEnabled
			? chip.off()
			: (rag?.error > 0
				? n('educai', '{count} error', '{count} errors', rag.error, { count: formatNumber(rag.error) })
				: (rag?.needs_reindex ? t('educai', 'Reindex needed') : chip.ready())),
		rows: saved.ragEnabled
			? [
				{ label: t('educai', 'Sources'), value: rag ? t('educai', '{count} ready', { count: formatNumber(rag.ready) }) : '…' },
				{ label: t('educai', 'Embeddings'), value: String(saved.embeddingModel ?? '').trim() || t('educai', 'default model name') },
				{ label: t('educai', 'Last indexed'), value: rag ? formatAgo(rag.last_indexed, now) : '…' },
				{ label: t('educai', 'Course catalogue'), value: saved.catalogueEnabled ? t('educai', 'on') : t('educai', 'off') },
			]
			: [{ label: t('educai', 'Knowledge sources'), value: t('educai', 'off'), tone: 'muted' }],
		action: { tab: 'knowledge', target: saved.ragEnabled ? 'educai-admin-index' : 'educai-admin-knowledge', label: saved.ragEnabled ? t('educai', 'Open knowledge') : t('educai', 'Set up') },
	})

	const doclingCheck = checks?.docling || null
	const doclingWorks = !!doclingCheck?.current && !!doclingCheck.success
	cards.push({
		id: 'documents',
		title: t('educai', 'Documents'),
		tone: !saved.doclingEnabled ? 'off' : (doclingCheck?.current && !doclingCheck.success ? 'warn' : (doclingWorks ? 'ok' : 'info')),
		chip: !saved.doclingEnabled ? chip.off() : (doclingCheck?.current && !doclingCheck.success ? t('educai', 'Test failed') : (doclingWorks ? chip.ready() : t('educai', 'Not checked'))),
		rows: saved.doclingEnabled
			? [
				{ label: t('educai', 'Service'), value: doclingProfileLabel(saved.doclingApiProfile) },
				{ label: t('educai', 'Authentication'), value: doclingAuthLabel(saved.doclingAuthMode) },
				{ label: t('educai', 'Last checked'), ...checkValue(doclingCheck, now) },
			]
			: [{ label: t('educai', 'Document conversion'), value: t('educai', 'off'), tone: 'muted' }],
		action: { tab: 'knowledge', target: 'educai-admin-docling', label: saved.doclingEnabled ? t('educai', 'Open conversion') : t('educai', 'Set up') },
	})

	const vision = String(saved.visionModel ?? '').trim()
	const speech = String(saved.speechModel ?? '').trim()
	const activeTools = (Array.isArray(tools) ? tools : []).filter(tool => tool.enabled).length
	const mediaCount = (vision ? 1 : 0) + (speech ? 1 : 0)
	cards.push({
		id: 'media',
		title: t('educai', 'Images & audio'),
		tone: mediaCount === 2 ? 'ok' : 'off',
		chip: mediaCount === 2 ? chip.ready() : (mediaCount === 1 ? t('educai', 'Partly active') : chip.off()),
		rows: [
			{ label: t('educai', 'Images'), value: vision || t('educai', 'not set up'), tone: vision ? undefined : 'muted' },
			{ label: t('educai', 'Speech'), value: speech || t('educai', 'not set up'), tone: speech ? undefined : 'muted' },
			{ label: t('educai', 'MCP tools'), value: t('educai', '{count} active', { count: formatNumber(activeTools) }) },
		],
		action: {
			tab: 'capabilities',
			target: !vision ? 'educai-admin-vision' : (!speech ? 'educai-admin-speech' : 'educai-admin-vision'),
			label: !vision ? t('educai', 'Set up image understanding') : (!speech ? t('educai', 'Set up speech to text') : t('educai', 'Open capabilities')),
		},
	})

	const chatState = queue?.chat_status?.state || queue?.state || null
	const queueRows = saved.rateLimitEnabled
		? [
			{ label: t('educai', 'Waiting'), value: queueStats ? formatNumber(queueStats.pending) : '…' },
			{
				label: t('educai', 'Limit'),
				value: rateLimitPreset({ second: saved.rateLimitSecond, minute: saved.rateLimitMinute, hour: saved.rateLimitHour, day: saved.rateLimitDay }, 'chat') === 'gwdg'
					? t('educai', '{limit}/min · GWDG preset', { limit: formatNumber(saved.rateLimitMinute) })
					: t('educai', '{limit}/min · custom values', { limit: formatNumber(saved.rateLimitMinute) }),
			},
		]
		: [{ label: t('educai', 'Rate limit queue'), value: t('educai', 'off'), tone: 'muted' }]
	if (saved.rateLimitEnabled && chatState?.limit_minute) {
		queueRows.push({ label: t('educai', 'Remaining'), value: t('educai', '{remaining} of {limit}', { remaining: formatNumber(chatState.remaining_minute), limit: formatNumber(chatState.limit_minute) }) })
	}
	cards.push({
		id: 'queue',
		title: t('educai', 'Queue'),
		tone: !saved.rateLimitEnabled ? 'off' : (queue && !chatCanProcess ? 'warn' : 'ok'),
		chip: !saved.rateLimitEnabled ? chip.off() : (queue && !chatCanProcess ? chip.limited() : t('educai', 'Free')),
		rows: queueRows,
		action: { tab: 'system', target: 'educai-admin-rate-limits', label: t('educai', 'Open system') },
	})

	// First-run checklist until the essentials and a first bot exist.
	const providerDone = String(saved.apiEndpoint ?? '').trim() !== '' && !!stored.apiKey
	const modelDone = !!splitModelReference(saved.defaultModel)
	const testDone = !!modelCheck?.success && !!modelCheck.current
	const botDone = Array.isArray(bots) && bots.length > 0
	const showSetup = !providerDone || !modelDone || (Array.isArray(bots) && bots.length === 0)
	const setup = showSetup
		? {
			steps: [
				{
					id: 'provider',
					done: providerDone,
					title: t('educai', 'Connect a language model provider'),
					detail: providerDone
						? (primaryUsable
							? (primary.lists_models === false
								? t('educai', 'Primary: {host} · no model list', { host: endpointHost(saved.apiEndpoint) })
								: n('educai', 'Primary: {host} · {count} model found', 'Primary: {host} · {count} models found', primary.model_count || 0, { host: endpointHost(saved.apiEndpoint), count: formatNumber(primary.model_count || 0) }))
							: t('educai', 'Primary: {host}', { host: endpointHost(saved.apiEndpoint) }))
						: t('educai', 'Enter an OpenAI-compatible endpoint and API key.'),
					action: { tab: 'models', target: 'educai-admin-providers', label: providerDone ? t('educai', 'Change') : t('educai', 'Connect') },
				},
				{
					id: 'model',
					done: modelDone,
					title: t('educai', 'Choose a default model'),
					detail: modelDone ? splitModelReference(saved.defaultModel).model : t('educai', 'Bots without their own choice use this model.'),
					action: { tab: 'models', target: 'educai-admin-model-choice', label: modelDone ? t('educai', 'Change') : t('educai', 'Choose') },
				},
				{
					id: 'test',
					done: testDone,
					title: t('educai', 'Send a test request'),
					detail: testDone
						? checkValue(modelCheck, now).value
						: t('educai', 'Checks with one short real request that access and credit are available.'),
					action: { tab: 'models', target: 'educai-admin-model-test', label: testDone ? t('educai', 'Test again') : t('educai', 'Test now') },
				},
				{
					id: 'bot',
					done: botDone,
					title: t('educai', 'Create the first bot'),
					detail: botDone
						? n('educai', '{count} bot exists', '{count} bots exist', bots.length, { count: formatNumber(bots.length) })
						: t('educai', 'Create a bot in the {app} app and mention it in a Talk conversation.', { app: appName }),
					action: { app: true, label: t('educai', 'Open app') },
				},
			],
		}
		: null
	if (setup) setup.doneCount = setup.steps.filter(step => step.done).length

	return { setup, attention, cards }
}
