<template>
	<div class="educai-admin">
		<header class="educai-admin__head">
			<div class="educai-admin__title">
				<span class="educai-admin__app" aria-hidden="true">
					<img v-if="appIconWhitePreviewUrl" :src="appIconWhitePreviewUrl" alt="">
				</span>
				<div>
					<h2>{{ APP_DISPLAY_NAME }}</h2>
					<p>{{ t('educai', 'Administration · Bots, models and knowledge sources for Nextcloud Talk') }}</p>
				</div>
			</div>
			<a class="educai-admin__doc"
				:href="documentationUrl"
				target="_blank"
				rel="noopener noreferrer">{{ t('educai', 'Documentation') }} ↗</a>
		</header>

		<div class="ta-tabs"
			role="tablist"
			:aria-label="t('educai', '{app} administration', { app: APP_DISPLAY_NAME })"
			@keydown="onTabKeydown">
			<button v-for="tab in adminTabs"
				:id="`educai-admin-tab-${tab.id}`"
				:key="tab.id"
				type="button"
				role="tab"
				class="ta-tab"
				:class="{ 'ta-tab--active': activeTab === tab.id }"
				:aria-selected="String(activeTab === tab.id)"
				:aria-controls="`educai-admin-panel-${tab.id}`"
				:tabindex="activeTab === tab.id ? 0 : -1"
				@click="setTab(tab.id)">
				{{ tab.label }}
				<span v-if="tab.count !== null" class="ta-tab__count">{{ tab.count }}</span>
				<span v-if="dirtyTabs.includes(tab.id)" class="ta-tab__dot" :title="t('educai', 'Unsaved changes')">
					<span class="hidden-visually">{{ t('educai', 'Unsaved changes') }}</span>
				</span>
			</button>
		</div>

		<OverviewTab v-show="activeTab === 'overview'"
			id="educai-admin-panel-overview"
			role="tabpanel"
			aria-labelledby="educai-admin-tab-overview" />
		<ModelsTab v-show="activeTab === 'models'"
			id="educai-admin-panel-models"
			role="tabpanel"
			aria-labelledby="educai-admin-tab-models" />
		<KnowledgeTab v-show="activeTab === 'knowledge'"
			id="educai-admin-panel-knowledge"
			role="tabpanel"
			aria-labelledby="educai-admin-tab-knowledge" />
		<CapabilitiesTab v-show="activeTab === 'capabilities'"
			id="educai-admin-panel-capabilities"
			role="tabpanel"
			aria-labelledby="educai-admin-tab-capabilities" />
		<SystemTab v-show="activeTab === 'system'"
			id="educai-admin-panel-system"
			role="tabpanel"
			aria-labelledby="educai-admin-tab-system" />
		<BotsTab v-show="activeTab === 'bots'"
			id="educai-admin-panel-bots"
			role="tabpanel"
			aria-labelledby="educai-admin-tab-bots" />

		<datalist id="educai-primary-models">
			<option v-for="model in primaryModelIds" :key="model" :value="model" />
		</datalist>

		<SaveBar v-if="hasUnsavedChanges"
			:labels="unsavedLabels"
			:saving="saving"
			@save="saveSettings"
			@discard="discardChanges" />

		<AdminDialog v-if="toolFormVisible"
			id="educai-tool-dialog"
			:title="toolForm.id ? t('educai', 'Edit Tool') : t('educai', 'Register Tool')"
			@close="closeToolForm">
			<form class="ta-dialog-form" @submit.prevent="saveTool">
				<div class="ta-field">
					<label for="tool-name" class="ta-label">{{ t('educai', 'Tool Name') }}</label>
					<input id="tool-name"
						v-model="toolForm.name"
						class="ta-input"
						type="text"
						required>
				</div>
				<div class="ta-field">
					<label for="tool-endpoint" class="ta-label">{{ t('educai', 'MCP Endpoint URL') }}</label>
					<input id="tool-endpoint"
						v-model="toolForm.mcpEndpointUrl"
						class="ta-input ta-input--mono"
						type="url"
						name="educai-mcp-endpoint"
						autocomplete="off"
						required>
					<p class="ta-hint">
						{{ t('educai', 'HTTP or WebSocket URL exposing the MCP server.') }}
					</p>
				</div>
				<div class="ta-field">
					<label for="tool-description" class="ta-label">{{ t('educai', 'Description') }}</label>
					<textarea id="tool-description"
						v-model="toolForm.description"
						class="ta-input"
						rows="3"
						:placeholder="t('educai', 'Short human-readable summary')" />
				</div>
				<div class="ta-field">
					<label for="tool-auth" class="ta-label">{{ t('educai', 'Authentication JSON') }}</label>
					<textarea id="tool-auth"
						v-model="toolForm.authentication"
						class="ta-input ta-input--mono"
						rows="4"
						placeholder="{&quot;headers&quot;:{&quot;Authorization&quot;:&quot;Bearer &lt;token&gt;&quot;}}" />
					<p class="ta-hint">
						{{ toolHasStoredAuth
							? t('educai', 'Provide JSON describing auth headers or tokens. Stored credentials are kept unless you enter new values (re-enter to test or rotate).')
							: t('educai', 'Provide JSON describing auth headers or tokens. Leave blank when no authentication is required.') }}
					</p>
				</div>
				<label class="ta-check">
					<input v-model="toolForm.enabled" type="checkbox">
					{{ t('educai', 'Enabled by default') }}
				</label>
				<div v-if="toolTestResult" class="ta-note ta-note--ok" role="status">
					{{ n('educai', 'Connection works. The server offers {count} tool.', 'Connection works. The server offers {count} tools.', toolTestNames.length, { count: toolTestNames.length }) }}
					<p v-if="toolTestNames.length" class="ta-tool-names">
						{{ toolTestNames.join(', ') }}
					</p>
					<details>
						<summary>{{ t('educai', 'Show server response') }}</summary>
						<pre class="ta-pre">{{ toolTestResult }}</pre>
					</details>
				</div>
				<div class="ta-row ta-dialog-actions">
					<button type="button"
						class="ta-btn"
						:disabled="toolTesting"
						@click="testToolConnection">
						{{ toolTesting ? t('educai', 'Testing…') : t('educai', 'Test Connection') }}
					</button>
					<span class="ta-dialog-actions__spacer" />
					<button type="button"
						class="ta-btn ta-btn--ghost"
						:disabled="toolSaving"
						@click="closeToolForm">
						{{ t('educai', 'Cancel') }}
					</button>
					<button type="submit"
						class="ta-btn ta-btn--primary"
						:disabled="toolSaving">
						{{ toolForm.id ? t('educai', 'Save Changes') : t('educai', 'Create Tool') }}
					</button>
				</div>
			</form>
		</AdminDialog>

		<BotForm v-if="adminEditingBot"
			:bot="adminEditingBot"
			:user-permissions="{ isAdmin: true, isGroupAdmin: true, isTeamAdmin: true, hasApprovalRights: true, adminGroups: [], adminTeams: [] }"
			@save="saveBotAdmin"
			@cancel="closeAdminEditor" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { APP_DISPLAY_NAME } from '../branding.js'
import { generateUrl, imagePath } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { t, n, getCanonicalLocale } from '../l10n.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import BotForm from './BotForm.vue'
import { applyEducAiRuntimeIconPayload } from '../utils/appIconRuntime.js'
import * as modelSelection from '../utils/modelSelection.js'
import * as adminSettings from '../utils/adminSettings.js'
import { deriveOverview } from '../utils/adminOverview.js'
import * as adminComponents from './admin/index.js'

const SERVICE_FIELDS = {
	embedding: { endpoint: 'embeddingApiEndpoint', key: 'embeddingApiKey' },
	vision: { endpoint: 'visionApiEndpoint', key: 'visionApiKey' },
	speech: { endpoint: 'speechApiEndpoint', key: 'speechApiKey' },
}

const RATE_LIMIT_KEYS = {
	chat: { second: 'rateLimitSecond', minute: 'rateLimitMinute', hour: 'rateLimitHour', day: 'rateLimitDay' },
	embeddings: { second: 'embeddingRateLimitSecond', minute: 'embeddingRateLimitMinute', hour: 'embeddingRateLimitHour', day: 'embeddingRateLimitDay' },
}

function initialTab() {
	if (typeof window === 'undefined' || !window.location) return 'overview'
	return adminSettings.normalizeAdminTab(new URLSearchParams(window.location.search).get('tab'))
}

export default {
	name: 'AdminSettings',
	components: {
		BotForm,
		...adminComponents,
	},
	provide() {
		return { admin: this }
	},
	data() {
		return {
			APP_DISPLAY_NAME,
			saving: false,
			settings: {
				apiKey: '',
				apiProvider: 'custom',
				apiEndpoint: '',
				secondaryApiEndpoint: '',
				secondaryApiKey: '',
				defaultModel: 'llama-3.3-70b-instruct',
				defaultTemperature: 0.2,
				fallbackModel: '',
				appIconUrl: '',
				appIconMode: 'default',
				appIconBlackUrl: '',
				appIconWhiteUrl: '',
				llmChatTimeout: 90,
				llmStreamTimeout: 240,
				llmModelsTimeout: 20,
				webhookSecret: '',
				allowMultipleModels: false,
				allowedModels: [],
				embeddingApiEndpoint: '',
				embeddingApiKey: '',
				embeddingModel: '',
				embeddingRateLimitMode: 'inherit',
				embeddingRateLimitSecond: null,
				embeddingRateLimitMinute: 100,
				embeddingRateLimitHour: 2000,
				embeddingRateLimitDay: 4000,
				ragChunkSize: 750,
				ragChunkOverlap: 50,
				ragEnabled: false,
				catalogueEnabled: false,
				catalogueApiEndpoint: '',
				catalogueReindexHours: 24,
				doclingEnabled: false,
				doclingApiEndpoint: '',
				doclingApiKey: '',
				doclingApiProfile: 'docling_serve',
				doclingAuthMode: 'x_api_key',
				visionApiEndpoint: '',
				visionApiKey: '',
				visionModel: '',
				speechApiEndpoint: '',
				speechApiKey: '',
				speechModel: '',
				rateLimitEnabled: false,
				rateLimitSecond: null,
				rateLimitMinute: 30,
				rateLimitHour: 200,
				rateLimitDay: 1000,
				rateLimitQueueMessage: '',
				conversationContextTokens: 8000,
				maxOutputTokens: 32768,
			},
			outputTokenOverrides: [],
			activeTab: initialTab(),
			settingsBaseline: null,
			storedSecrets: {
				apiKey: false,
				secondaryApiKey: false,
				webhookSecret: false,
				embeddingApiKey: false,
				doclingApiKey: false,
				visionApiKey: false,
				speechApiKey: false,
			},
			clearCredentials: [],
			serviceModes: { embedding: 'inherit', vision: 'inherit', speech: 'inherit' },
			serviceStash: {},
			secretResetKey: 0,
			openDetails: { outputOverrides: false, knowledgeAdvanced: false, doclingManual: false, queueMessage: false },
			adminStatus: null,
			adminStatusLoading: false,
			embeddingStatus: null,
			doclingDetecting: false,
			doclingDetection: null,
			doclingRevision: 0,
			availableModelOptions: [],
			loadingModels: false,
			modelLoadError: '',
			modelProviders: [],
			modelRefreshInterval: 300,
			modelRefreshTimer: null,
			modelReloadPending: false,
			modelPollingActive: false,
			modelSettingsLoaded: false,
			providerSettingsBaseline: null,
			modelProviderRevision: 0,
			modelSettingsBaseline: null,
			modelTestSelection: '',
			modelTesting: false,
			modelTestResult: null,
			loadingTools: false,
			toolSaving: false,
			toolTesting: false,
			toolFormVisible: false,
			tools: [],
			toolForm: {
				id: null,
				name: '',
				mcpEndpointUrl: '',
				description: '',
				authentication: '',
				enabled: false,
			},
			toolHasStoredAuth: false,
			toolTestResult: '',
			doclingTesting: false,
			doclingTestResult: null,
			doclingHasStoredApiKey: false,
			visionTesting: false,
			visionTestResult: null,
			speechTesting: false,
			speechTestResult: null,
			rateLimitLoading: false,
			rateLimitStatus: null,
			queueProcessing: false,
			indexRefreshKey: 0,
			indexSettingsBaseline: { ragEnabled: false, catalogueEnabled: false, catalogueApiEndpoint: '', ragChunkSize: 750, ragChunkOverlap: 50 },
			catalogueTesting: false,
			catalogueTestResult: null,
			embeddingConfigBaseline: {
				apiProvider: 'custom',
				apiEndpoint: '',
				embeddingApiEndpoint: '',
				embeddingModel: '',
				defaultModel: '',
				hasApiKey: false,
				hasEmbeddingApiKey: false,
			},
			loadingAllBots: false,
			allBotsLoaded: false,
			allBots: [],
			adminEditingBot: null,
			uploadingAppIconVariant: '',
			appIconUploadUrls: {
				black: '',
				white: '',
			},
			appIconPreviewUrls: {
				black: '',
				white: '',
			},
			appIconPreviewVersion: {
				black: 0,
				white: 0,
			},
		}
	},
	computed: {
		changedKeys() {
			return adminSettings.changedSettingKeys(this.settings, this.outputTokenOverrides, this.settingsBaseline, this.clearCredentials)
		},
		hasUnsavedChanges() {
			return this.changedKeys.length > 0
		},
		dirtyTabs() {
			return adminSettings.changedTabs(this.changedKeys)
		},
		unsavedLabels() {
			return adminSettings.changedLabels(this.changedKeys)
		},
		adminTabs() {
			return [
				{ id: 'overview', label: t('educai', 'Overview'), count: null },
				{ id: 'models', label: t('educai', 'Models'), count: null },
				{ id: 'knowledge', label: t('educai', 'Knowledge'), count: null },
				{ id: 'capabilities', label: t('educai', 'Capabilities'), count: null },
				{ id: 'system', label: t('educai', 'System'), count: null },
				{ id: 'bots', label: t('educai', 'Bots'), count: this.allBots.length ? this.formatNumber(this.allBots.length) : null },
			]
		},
		overview() {
			if (!this.settingsBaseline) return null
			return deriveOverview({
				saved: this.settingsBaseline,
				stored: this.storedSecrets,
				providers: this.modelProviders,
				checks: this.adminStatus?.checks || {},
				embedding: this.embeddingStatus,
				queue: this.rateLimitStatus,
				bots: this.allBotsLoaded ? this.allBots : null,
				tools: this.tools,
				unqualifiedModels: this.adminStatus?.unqualified_models || [],
				appName: APP_DISPLAY_NAME,
			})
		},
		primaryModelIds() {
			return [...new Set(this.availableModelOptions.filter(option => option.endpoint === 'primary').map(option => option.model))]
				.sort((a, b) => a.localeCompare(b))
		},
		providerHosts() {
			return {
				primary: adminSettings.endpointHost(this.settings.apiEndpoint),
				secondary: adminSettings.endpointHost(this.settings.secondaryApiEndpoint),
			}
		},
		webhookUrl() {
			if (this.adminStatus?.talk?.webhook_url) return this.adminStatus.talk.webhook_url
			const origin = typeof window !== 'undefined' ? window.location?.origin || '' : ''
			return origin + generateUrl('/apps/educai/webhook/talk')
		},
		documentationUrl() {
			return 'https://github.com/EDUCAlliance/talk-ai/blob/main/docs/ADMIN_SETTINGS.md'
		},
		toolTestNames() {
			try {
				const tools = JSON.parse(this.toolTestResult || '[]')
				return Array.isArray(tools) ? tools.map(tool => tool?.name).filter(Boolean) : []
			} catch {
				return []
			}
		},
		hasUnsavedProviderSettings() {
			if (!this.providerSettingsBaseline) return false
			return ['apiProvider', 'apiEndpoint', 'secondaryApiEndpoint', 'llmModelsTimeout'].some(key => String(this.settings[key] || '').trim() !== String(this.providerSettingsBaseline[key] || '').trim())
				|| !!(this.settings.apiKey || '').trim() || !!(this.settings.secondaryApiKey || '').trim()
				|| this.clearCredentials.includes('secondaryApiKey')
		},
		canTestModel() {
			return this.modelSettingsLoaded && !this.hasUnsavedProviderSettings && !this.modelTesting && !this.saving && !!modelSelection.splitModelReference(this.modelTestSelection)
		},
		hasPendingIndexSettings() {
			return this.hasPendingEmbeddingConfigChange || Object.keys(this.indexSettingsBaseline).some(key => String(this.settings[key]).trim() !== String(this.indexSettingsBaseline[key]).trim())
		},
		appIconBlackPreviewUrl() {
			return this.resolveAppIconPreviewUrl('black')
		},
		appIconWhitePreviewUrl() {
			return this.resolveAppIconPreviewUrl('white')
		},
		hasPendingEmbeddingConfigChange() {
			const payload = {
				apiKey: this.settings.apiKey || '',
				apiProvider: this.settings.apiProvider || 'custom',
				apiEndpoint: this.settings.apiEndpoint || '',
				embeddingApiEndpoint: (this.settings.embeddingApiEndpoint || '').trim(),
				embeddingApiKey: this.settings.embeddingApiKey ? this.settings.embeddingApiKey : null,
				embeddingModel: (this.settings.embeddingModel || '').trim(),
				defaultModel: this.toEndpointModelId(this.settings.defaultModel),
			}
			return this.hasEmbeddingConfigChange(payload)
		},
		chatQueueStats() {
			return this.rateLimitStatus?.queue_stats || {
				pending: 0,
				processing: 0,
				completed: 0,
				failed: 0,
				total: 0,
			}
		},
		chatRateLimitState() {
			return this.rateLimitStatus?.chat_status?.state || this.rateLimitStatus?.state || null
		},
		chatCanProcess() {
			if (typeof this.rateLimitStatus?.chat_status?.can_process === 'boolean') {
				return this.rateLimitStatus.chat_status.can_process
			}
			return !!this.rateLimitStatus?.can_process
		},
		embeddingRateLimitState() {
			return this.rateLimitStatus?.embedding_status?.state || null
		},
		embeddingRateLimitModeStatus() {
			return this.rateLimitStatus?.embedding_status?.mode || this.settings.embeddingRateLimitMode || 'inherit'
		},
		embeddingStatusSource() {
			return this.rateLimitStatus?.embedding_status?.source || 'configured'
		},
		embeddingConfiguredHint() {
			if (this.embeddingRateLimitModeStatus === 'custom') {
				return t('educai', 'Using the configured custom embedding limits until the provider returns dedicated headers.')
			}
			return t('educai', 'Using inherited chat limits until the embedding endpoint returns its own rate-limit headers.')
		},
		embeddingCanProcess() {
			if (typeof this.rateLimitStatus?.embedding_status?.can_process === 'boolean') {
				return this.rateLimitStatus.embedding_status.can_process
			}
			return false
		},
		doclingKeyHint() {
			if (this.settings.doclingAuthMode === 'none') {
				return t('educai', 'The current profile sends no key. A stored key is kept.')
			}
			if (this.doclingHasStoredApiKey) {
				return t('educai', 'A stored key stays in use until you replace or remove it.')
			}
			return this.settings.doclingApiProfile === 'legacy' && this.settings.doclingAuthMode === 'bearer'
				? t('educai', 'Leave empty to use the primary API key.')
				: t('educai', 'Only needed if the server requires a key. The primary API key is not sent.')
		},

	},
	watch: {
		hasUnsavedProviderSettings(changed) {
			if (changed) this.modelTestResult = null
		},
		modelTestSelection() {
			this.modelTestResult = null
		},
		modelTestResult(value) { if (value) this.loadAdminStatus() },
		doclingTestResult(value) { if (value) this.loadAdminStatus() },
		doclingDetection(value) { if (value) this.loadAdminStatus() },
		visionTestResult(value) { if (value) this.loadAdminStatus() },
		speechTestResult(value) { if (value) this.loadAdminStatus() },
		catalogueTestResult(value) { if (value) this.loadAdminStatus() },
		settingsBaseline(value, previous) { if (previous) this.loadAdminStatus() },
	},
	mounted() {
		this.modelPollingActive = true
		this.loadSettings()
		this.loadTools()
		this.loadAllBots()
		this.loadAdminStatus()
		this.loadEmbeddingStatus()
		window.addEventListener('beforeunload', this.onBeforeUnload)
	},
	beforeDestroy() {
		this.modelPollingActive = false
		clearTimeout(this.modelRefreshTimer)
		if (typeof window !== 'undefined') window.removeEventListener('beforeunload', this.onBeforeUnload)
	},
	methods: {
		t,
		n,
		setTab(tab, target = '') {
			this.activeTab = adminSettings.normalizeAdminTab(tab)
			if (typeof window !== 'undefined' && window.history?.replaceState && window.location) {
				const url = new URL(window.location.href)
				if (this.activeTab === 'overview') {
					url.searchParams.delete('tab')
				} else {
					url.searchParams.set('tab', this.activeTab)
				}
				window.history.replaceState(window.history.state, '', url.toString())
			}
			if (this.activeTab === 'overview' && this.settingsBaseline) this.refreshOverview()
			if (target) this.$nextTick(() => this.scrollToTarget(target))
		},
		scrollToTarget(id) {
			const element = typeof document !== 'undefined' ? document.getElementById(id) : null
			if (!element) return
			if (element.tagName === 'DETAILS') element.open = true
			element.scrollIntoView({ behavior: 'smooth', block: 'start' })
			element.focus?.({ preventScroll: true })
		},
		onTabKeydown(event) {
			const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End']
			if (!keys.includes(event.key)) return
			event.preventDefault()
			const tabs = adminSettings.ADMIN_TABS
			const index = tabs.indexOf(this.activeTab)
			const next = event.key === 'Home'
				? 0
				: event.key === 'End'
					? tabs.length - 1
					: (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length
			this.setTab(tabs[next])
			this.$nextTick(() => document.getElementById(`educai-admin-tab-${tabs[next]}`)?.focus())
		},
		goTo(action) {
			if (!action) return
			if (action.app) {
				window.location.href = generateUrl('/apps/educai/')
				return
			}
			this.setTab(action.tab, action.target)
		},
		refreshOverview() {
			this.loadAdminStatus()
			this.loadEmbeddingStatus()
			if (this.settings.rateLimitEnabled) this.loadRateLimitStatus()
		},
		captureSettingsBaseline() {
			this.settingsBaseline = adminSettings.snapshotSettings(this.settings, this.outputTokenOverrides)
		},
		discardChanges() {
			if (!this.settingsBaseline) return
			this.outputTokenOverrides = adminSettings.restoreSettings(this.settings, this.settingsBaseline)
			this.clearCredentials = []
			this.secretResetKey++
			this.syncServiceModes()
			this.resetDoclingResults()
			this.visionTestResult = null
			this.speechTestResult = null
			this.catalogueTestResult = null
		},
		onBeforeUnload(event) {
			if (!this.hasUnsavedChanges) return
			event.preventDefault()
			event.returnValue = ''
		},
		setSecret(key, value) {
			this.settings[key] = value
			if (String(value || '').trim() !== '') this.setSecretRemoval(key, false)
		},
		setSecretRemoval(key, remove) {
			if (!adminSettings.CLEARABLE_SECRETS.includes(key)) return
			const others = this.clearCredentials.filter(item => item !== key)
			this.clearCredentials = remove ? [...others, key] : others
			if (remove) this.settings[key] = ''
		},
		setServiceMode(service, mode) {
			const fields = SERVICE_FIELDS[service]
			if (!fields) return
			if (mode === 'inherit') {
				const endpoint = String(this.settings[fields.endpoint] || '').trim()
				if (endpoint) this.serviceStash = { ...this.serviceStash, [service]: endpoint }
				this.settings[fields.endpoint] = ''
				this.settings[fields.key] = ''
				if (this.storedSecrets[fields.key]) this.setSecretRemoval(fields.key, true)
			} else {
				if (!String(this.settings[fields.endpoint] || '').trim() && this.serviceStash[service]) {
					this.settings[fields.endpoint] = this.serviceStash[service]
				}
				this.setSecretRemoval(fields.key, false)
			}
			this.serviceModes = { ...this.serviceModes, [service]: mode === 'inherit' ? 'inherit' : 'custom' }
		},
		capabilityTestBlocked(service) {
			const fields = SERVICE_FIELDS[service]
			if (!fields || !this.settingsBaseline) return false
			const switchedToInherit = !String(this.settings[fields.endpoint] || '').trim() && !!String(this.settingsBaseline[fields.endpoint] || '').trim()
			return switchedToInherit || this.clearCredentials.includes(fields.key)
		},
		syncServiceModes() {
			const modes = {}
			for (const [service, fields] of Object.entries(SERVICE_FIELDS)) {
				modes[service] = adminSettings.serviceSourceMode({
					endpoint: this.settings[fields.endpoint],
					hasStoredKey: this.storedSecrets[fields.key],
					typedKey: this.settings[fields.key],
					clearPending: this.clearCredentials.includes(fields.key),
				})
			}
			this.serviceModes = modes
		},
		setRateLimit(kind, window, value) {
			const key = RATE_LIMIT_KEYS[kind]?.[window]
			if (key) this.settings[key] = value
		},
		applyRateLimitPreset(kind) {
			const preset = adminSettings.RATE_LIMIT_PRESETS[kind]
			if (!preset) return
			for (const window of adminSettings.RATE_LIMIT_WINDOWS) this.setRateLimit(kind, window, preset[window])
		},
		async loadAdminStatus() {
			if (this.adminStatusLoading) return
			this.adminStatusLoading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/educai/api/v1/admin/status'))
				this.adminStatus = data && (data.checks || data.talk) ? data : null
			} catch (error) {
				// Remembered checks are optional; the overview works without them.
			} finally {
				this.adminStatusLoading = false
			}
		},
		async loadEmbeddingStatus() {
			try {
				const { data } = await axios.get(generateUrl('/apps/educai/api/v1/admin/embeddings/status'))
				if (data?.rag) this.embeddingStatus = data
			} catch (error) {
				// The index section reports its own loading errors.
			}
		},
		onEmbeddingStatus(status) {
			if (status?.rag) this.embeddingStatus = status
		},
		resetDoclingResults() {
			this.doclingRevision++
			this.doclingDetection = null
			this.doclingTestResult = null
		},
		async detectDocling() {
			if (this.doclingDetecting) return
			this.doclingDetecting = true
			this.resetDoclingResults()
			const revision = this.doclingRevision
			try {
				const typedKey = (this.settings.doclingApiKey || '').trim()
				const { data } = await axios.post(generateUrl('/apps/educai/api/v1/admin/docling/detect'), {
					doclingApiEndpoint: (this.settings.doclingApiEndpoint || '').trim(),
					doclingApiKey: typedKey || null,
					ignoreStoredKey: !typedKey && this.clearCredentials.includes('doclingApiKey'),
				})
				if (revision === this.doclingRevision) this.applyDoclingDetection(data)
			} catch (error) {
				if (revision !== this.doclingRevision) return
				this.doclingDetection = {
					success: false,
					profile: null,
					error: getApiErrorMessage(error, t('educai', 'The Docling server could not be detected. Check the address and try again.')),
				}
			} finally {
				this.doclingDetecting = false
			}
		},
		applyDoclingDetection(result) {
			const data = result && typeof result === 'object' ? result : {}
			this.doclingDetection = { ...data, success: !!data.success }
			if (!data.success) return
			if (['docling_serve', 'legacy'].includes(data.profile)) this.settings.doclingApiProfile = data.profile
			if (['x_api_key', 'bearer', 'none'].includes(data.auth_mode)) this.settings.doclingAuthMode = data.auth_mode
			if (typeof data.endpoint === 'string' && data.endpoint.trim() !== (this.settings.doclingApiEndpoint || '').trim()) {
				this.settings.doclingApiEndpoint = data.endpoint.trim()
			}
		},
		async copyText(value) {
			try {
				await navigator.clipboard.writeText(value)
				showSuccess(t('educai', 'Copied to the clipboard'))
			} catch (error) {
				showError(t('educai', 'Copying failed. Select the text and copy it manually.'))
			}
		},
		normalizeTemperatureValue(value) {
			const numeric = Number(value)
			if (!Number.isFinite(numeric) || numeric < 0 || numeric > 1) {
				return null
			}

			return Math.round(numeric * 100) / 100
		},
		normalizeAppIconUrl(value) {
			const url = (value || '').trim()
			if (url === '') {
				return ''
			}
			if (url.length > 1024) {
				return null
			}
			if (this.isUploadedAppIconReference(url)) {
				return this.isValidUploadedAppIconReference(url) ? url : null
			}
			if (/^https?:\/\//i.test(url)) {
				try {
					const parsed = new URL(url)
					return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? url : null
				} catch {
					return null
				}
			}
			if (url.startsWith('/') && !url.startsWith('//')) {
				return url
			}
			return null
		},
		isUploadedAppIconReference(value) {
			return /^educai-upload:\/\/(black|white)$/i.test(String(value || '').trim())
		},
		isServerBackedAppIconReference(value) {
			return this.isUploadedAppIconReference(value)
		},
		isValidUploadedAppIconReference(value) {
			try {
				const parsed = new URL(String(value || '').trim())
				return parsed.protocol === 'educai-upload:' && ['black', 'white'].includes(parsed.hostname)
			} catch {
				return false
			}
		},
		normalizeLoadedAppIconValue(value) {
			const raw = String(value || '').trim()
			return /^nextcloud-file(id)?:\/\//i.test(raw) ? '' : raw
		},
		async uploadAppIconSvg(variant, event) {
			const input = event?.target
			const file = input?.files?.[0] || null
			if (!file) {
				return
			}

			if (!this.isLocalSvgUpload(file)) {
				showError(t('educai', 'Please upload an SVG file up to 1 MB'))
				input.value = ''
				return
			}

			this.uploadingAppIconVariant = variant
			try {
				const formData = new FormData()
				formData.append('icon', file)
				const response = await axios.post(
					this.resolveAppIconUploadUrl(variant),
					formData,
				)
				const value = response.data?.value || `educai-upload://${variant}`
				if (variant === 'white') {
					this.settings.appIconWhiteUrl = value
				} else {
					this.settings.appIconBlackUrl = value
				}
				this.appIconPreviewVersion[variant] = Date.now()
				showSuccess(t('educai', 'App icon SVG uploaded'))
			} catch (error) {
				console.error('Failed to upload app icon SVG:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to upload app icon SVG')))
			} finally {
				this.uploadingAppIconVariant = ''
				input.value = ''
			}
		},
		isLocalSvgUpload(file) {
			return file
				&& file.size > 0
				&& file.size <= 1024 * 1024
				&& (file.type === 'image/svg+xml' || file.name.toLowerCase().endsWith('.svg'))
		},
		resolveAppIconUploadUrl(variant) {
			return this.appIconUploadUrls[variant] || generateUrl(`/apps/educai/api/v1/admin/app-icon-upload/${variant}`)
		},
		setAppIconMode(mode) {
			if (!['default', 'custom'].includes(mode)) {
				return
			}
			this.settings.appIconMode = mode
			if (mode === 'default') {
				this.settings.appIconBlackUrl = ''
				this.settings.appIconWhiteUrl = ''
			}
		},
		resetAppIconConfig() {
			this.settings.appIconMode = 'default'
			this.settings.appIconBlackUrl = ''
			this.settings.appIconWhiteUrl = ''
			this.settings.appIconUrl = ''
		},
		resolveAppIconPreviewUrl(variant) {
			if (this.settings.appIconMode === 'default') {
				return imagePath('educai', variant === 'white' ? 'app.svg' : 'app-dark.svg')
			}

			if (this.settings.appIconMode === 'custom') {
				const url = this.normalizeAppIconUrl(
					variant === 'white' ? this.settings.appIconWhiteUrl : this.settings.appIconBlackUrl,
				)
				if (this.isServerBackedAppIconReference(url)) {
					const version = this.appIconPreviewVersion[variant] || 0
					const previewUrl = this.appIconPreviewUrls[variant] || generateUrl(`/apps/educai/api/v1/admin/app-icon-preview/${variant}`)
					return `${previewUrl}?source=${encodeURIComponent(url)}&v=${version}`
				}

				return url || ''
			}

			return ''
		},
		buildAppIconPayload() {
			const mode = ['default', 'custom'].includes(this.settings.appIconMode)
				? this.settings.appIconMode
				: 'default'

			if (mode === 'default') {
				return {
					appIconMode: 'default',
					appIconBlackUrl: null,
					appIconWhiteUrl: null,
					appIconUrl: '',
				}
			}

			const blackUrl = this.normalizeAppIconUrl(this.settings.appIconBlackUrl)
			if (blackUrl === null) {
				showError(t('educai', 'Black app icon must be an http(s) URL, an absolute Nextcloud path, or an uploaded SVG'))
				return null
			}
			const whiteUrl = this.normalizeAppIconUrl(this.settings.appIconWhiteUrl)
			if (whiteUrl === null) {
				showError(t('educai', 'White app icon must be an http(s) URL, an absolute Nextcloud path, or an uploaded SVG'))
				return null
			}

			if (!blackUrl || !whiteUrl) {
				showError(t('educai', 'Custom app icon mode requires both black and white icons'))
				return null
			}

			return {
				appIconMode: 'custom',
				appIconBlackUrl: blackUrl,
				appIconWhiteUrl: whiteUrl,
				appIconUrl: blackUrl || '',
			}
		},
		formatTemperature(value) {
			const temperature = this.normalizeTemperatureValue(value)
			return new Intl.NumberFormat(getCanonicalLocale(), {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			}).format(temperature === null ? 0.2 : temperature)
		},
		formatNumber(value) {
			return new Intl.NumberFormat(getCanonicalLocale()).format(value)
		},
		toEndpointModelId(model) {
			return modelSelection.modelReference(model)
		},
		formatModelLabel(model) {
			return modelSelection.modelLabel(model)
		},
		captureProviderSettingsBaseline(saved = this.settings) {
			this.modelProviderRevision++
			this.providerSettingsBaseline = Object.fromEntries(['apiProvider', 'apiEndpoint', 'secondaryApiEndpoint', 'llmModelsTimeout'].map(key => [key, saved[key]]))
		},
		captureModelSettingsBaseline(saved = this.settings) {
			this.modelSettingsBaseline = { defaultModel: saved.defaultModel, fallbackModel: saved.fallbackModel, allowedModels: [...saved.allowedModels] }
		},
		adoptSavedModelBindings(saved) {
			if (!this.modelSettingsBaseline || this.hasUnsavedProviderSettings) return
			for (const [key, apiKey] of [['defaultModel', 'default_model'], ['fallbackModel', 'fallback_model']]) {
				if (this.settings[key] !== this.modelSettingsBaseline[key]) continue
				const previous = this.settings[key]
				this.settings[key] = modelSelection.adoptSavedModelBinding(previous, saved[apiKey])
				this.modelSettingsBaseline[key] = this.settings[key]
				if (this.settingsBaseline?.[key] === previous) this.settingsBaseline[key] = this.settings[key]
				if (key === 'defaultModel' && this.embeddingConfigBaseline.defaultModel === previous) this.embeddingConfigBaseline.defaultModel = this.settings[key]
			}
			if (JSON.stringify(this.settings.allowedModels) === JSON.stringify(this.modelSettingsBaseline.allowedModels)) {
				const previous = JSON.stringify(this.settings.allowedModels)
				this.settings.allowedModels = modelSelection.adoptSavedModelBindings(this.settings.allowedModels, saved.allowed_models)
				this.modelSettingsBaseline.allowedModels = [...this.settings.allowedModels]
				if (this.settingsBaseline && JSON.stringify(this.settingsBaseline.allowedModels) === previous) {
					this.settingsBaseline.allowedModels = [...this.settings.allowedModels]
				}
			}
		},
		async refreshSavedModelBindings() {
			if (!this.modelSettingsBaseline || this.hasUnsavedProviderSettings) return
			const references = [this.settings.defaultModel, this.settings.fallbackModel, ...this.settings.allowedModels]
			if (!references.some(value => value && !modelSelection.splitModelReference(value))) return
			const baseline = this.modelProviderRevision
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/settings'))
				if (baseline === this.modelProviderRevision) this.adoptSavedModelBindings(response.data)
			} catch (error) {
				// This optional reconciliation must not hide a successfully loaded catalogue.
			}
		},
		scheduleModelRefresh() {
			if (!this.modelPollingActive) return
			clearTimeout(this.modelRefreshTimer)
			this.modelRefreshTimer = setTimeout(() => {
				if (typeof document !== 'undefined' && document.hidden) {
					this.scheduleModelRefresh()
					return
				}
				this.loadModels()
			}, modelSelection.catalogueRefreshDelay(this.modelRefreshInterval))
		},
		async testModelResponse() {
			if (!this.canTestModel) return
			this.modelTesting = true
			this.modelTestResult = null
			const model = this.modelTestSelection
			const baseline = this.modelProviderRevision
			try {
				const response = await axios.post(generateUrl('/apps/educai/api/v1/models/test'), { model })
				if (!this.hasUnsavedProviderSettings && baseline === this.modelProviderRevision && model === this.modelTestSelection) {
					this.modelTestResult = response.data
				}
			} catch (error) {
				if (!this.hasUnsavedProviderSettings && baseline === this.modelProviderRevision && model === this.modelTestSelection) {
					this.modelTestResult = { success: false, model, error: getApiErrorMessage(error, t('educai', 'The model test failed. Check the saved endpoint and the Nextcloud logs for details.')) }
				}
			} finally {
				this.modelTesting = false
			}
		},
		applyDefaultTemperaturePreset(value) {
			this.settings.defaultTemperature = value
		},
		toOptionalPositiveInteger(value) {
			const numeric = Number(value)
			return Number.isFinite(numeric) && numeric > 0 ? numeric : null
		},
		addOutputTokenOverride() {
			this.outputTokenOverrides.push({ model: '', tokens: this.settings.maxOutputTokens })
		},
		buildOutputTokenPayload() {
			const validLimit = value => Number.isInteger(value) && value >= 1 && value <= 131072
			if (!validLimit(this.settings.maxOutputTokens)) {
				showError(t('educai', 'Output token limits must be whole numbers between 1 and 131072.'))
				return null
			}
			const modelOutputTokenLimits = {}
			for (const { model, tokens } of this.outputTokenOverrides) {
				const reference = model.trim()
				const hasControlCharacter = [...reference].some(character => character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127)
				if (reference.length > 512 || hasControlCharacter || !/^(primary|secondary):\S+$/u.test(reference)) {
					showError(t('educai', 'Each model override needs an exact primary: or secondary: model reference.'))
					return null
				}
				if (Object.hasOwn(modelOutputTokenLimits, reference)) {
					showError(t('educai', 'Each model may have only one output limit override.'))
					return null
				}
				if (!validLimit(tokens)) {
					showError(t('educai', 'Output token limits must be whole numbers between 1 and 131072.'))
					return null
				}
				modelOutputTokenLimits[reference] = tokens
			}
			return { maxOutputTokens: this.settings.maxOutputTokens, modelOutputTokenLimits }
		},
		toPositiveInteger(value, fallback) {
			const numeric = Number(value)
			return Number.isFinite(numeric) && numeric > 0 ? numeric : fallback
		},
		async loadSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/settings'))
				// Don't overwrite password fields with masked values
				const data = response.data
				this.settings.apiProvider = data.api_provider || 'custom'
				this.settings.apiEndpoint = data.api_endpoint || ''
				this.settings.secondaryApiEndpoint = data.secondary_api_endpoint || ''
				this.settings.defaultModel = this.toEndpointModelId(data.default_model || 'llama-3.3-70b-instruct')
				this.settings.defaultTemperature = this.normalizeTemperatureValue(data.default_temperature) ?? 0.2
				this.settings.fallbackModel = this.toEndpointModelId(data.fallback_model || '')
				this.appIconUploadUrls = {
					black: data.app_icon_upload_urls?.black || '',
					white: data.app_icon_upload_urls?.white || '',
				}
				this.appIconPreviewUrls = {
					black: data.app_icon_preview_urls?.black || '',
					white: data.app_icon_preview_urls?.white || '',
				}
				this.settings.appIconUrl = this.normalizeLoadedAppIconValue(data.app_icon_url || '')
				this.settings.appIconMode = data.app_icon_mode === 'custom' || data.app_icon_url ? 'custom' : 'default'
				this.settings.appIconBlackUrl = this.normalizeLoadedAppIconValue(data.app_icon_black_url || (this.settings.appIconMode === 'custom' ? data.app_icon_url || '' : ''))
				this.settings.appIconWhiteUrl = this.normalizeLoadedAppIconValue(data.app_icon_white_url || (this.settings.appIconMode === 'custom' ? data.app_icon_url || '' : ''))
				applyEducAiRuntimeIconPayload(data)
				this.settings.llmChatTimeout = typeof data.llm_chat_timeout === 'number' && data.llm_chat_timeout > 0 ? data.llm_chat_timeout : 90
				this.settings.llmStreamTimeout = typeof data.llm_stream_timeout === 'number' && data.llm_stream_timeout > 0 ? data.llm_stream_timeout : 240
				this.settings.llmModelsTimeout = typeof data.llm_models_timeout === 'number' && data.llm_models_timeout > 0 ? data.llm_models_timeout : 20
				this.settings.allowMultipleModels = !!data.allow_multiple_models
				this.settings.allowedModels = Array.isArray(data.allowed_models) ? data.allowed_models.map((model) => this.toEndpointModelId(model)) : []
				this.settings.embeddingApiEndpoint = data.embedding_api_endpoint || ''
				this.settings.embeddingModel = data.embedding_model || ''
				this.settings.embeddingRateLimitMode = data.embedding_rate_limit_mode || 'inherit'
				this.settings.embeddingRateLimitSecond = typeof data.embedding_rate_limit_second === 'number' && data.embedding_rate_limit_second > 0 ? data.embedding_rate_limit_second : null
				this.settings.embeddingRateLimitMinute = typeof data.embedding_rate_limit_minute === 'number' && data.embedding_rate_limit_minute > 0 ? data.embedding_rate_limit_minute : 100
				this.settings.embeddingRateLimitHour = typeof data.embedding_rate_limit_hour === 'number' && data.embedding_rate_limit_hour > 0 ? data.embedding_rate_limit_hour : 2000
				this.settings.embeddingRateLimitDay = typeof data.embedding_rate_limit_day === 'number' && data.embedding_rate_limit_day > 0 ? data.embedding_rate_limit_day : 4000
				this.embeddingConfigBaseline = {
					apiProvider: this.settings.apiProvider,
					apiEndpoint: this.settings.apiEndpoint,
					embeddingApiEndpoint: this.settings.embeddingApiEndpoint,
					embeddingModel: this.settings.embeddingModel,
					defaultModel: this.toEndpointModelId(data.default_model),
					hasApiKey: data.api_key === '***',
					hasEmbeddingApiKey: data.embedding_api_key === '***',
				}
				this.settings.ragChunkSize = typeof data.rag_chunk_size === 'number' ? data.rag_chunk_size : 750
				this.settings.ragChunkOverlap = typeof data.rag_chunk_overlap === 'number' ? data.rag_chunk_overlap : 50
				this.settings.ragEnabled = !!data.rag_enabled
				this.settings.catalogueEnabled = !!data.catalogue_enabled
				this.settings.catalogueApiEndpoint = data.catalogue_api_endpoint || ''
				this.settings.catalogueReindexHours = typeof data.catalogue_reindex_hours === 'number' ? data.catalogue_reindex_hours : 24
				this.captureIndexSettingsBaseline()
				this.settings.doclingEnabled = !!data.docling_enabled
				this.settings.doclingApiEndpoint = data.docling_api_endpoint || ''
				this.settings.doclingApiProfile = data.docling_api_profile || 'legacy'
				this.settings.doclingAuthMode = data.docling_auth_mode || 'bearer'
				this.doclingHasStoredApiKey = data.docling_api_key === '***'
				this.storedSecrets = Object.fromEntries(Object.entries(adminSettings.STORED_SECRET_FIELDS).map(([key, field]) => [key, data[field] === '***']))
				this.settings.visionApiEndpoint = data.vision_api_endpoint || ''
				this.settings.visionModel = data.vision_model || ''
				this.settings.speechApiEndpoint = data.speech_api_endpoint || ''
				this.settings.speechModel = data.speech_model || ''
				this.settings.rateLimitEnabled = !!data.rate_limit_enabled
				this.settings.rateLimitSecond = typeof data.rate_limit_second === 'number' && data.rate_limit_second > 0 ? data.rate_limit_second : null
				this.settings.rateLimitMinute = typeof data.rate_limit_minute === 'number' && data.rate_limit_minute > 0 ? data.rate_limit_minute : 30
				this.settings.rateLimitHour = typeof data.rate_limit_hour === 'number' && data.rate_limit_hour > 0 ? data.rate_limit_hour : 200
				this.settings.rateLimitDay = typeof data.rate_limit_day === 'number' && data.rate_limit_day > 0 ? data.rate_limit_day : 1000
				this.settings.rateLimitQueueMessage = data.rate_limit_queue_message || ''
				this.settings.conversationContextTokens = typeof data.conversation_context_tokens === 'number' ? data.conversation_context_tokens : 8000
				this.settings.maxOutputTokens = data.max_output_tokens ?? 32768
				this.outputTokenOverrides = Object.entries(data.model_output_token_limits || {}).map(([model, tokens]) => ({ model, tokens }))
				this.captureProviderSettingsBaseline()
				this.captureModelSettingsBaseline()
				this.clearCredentials = []
				this.syncServiceModes()
				this.captureSettingsBaseline()
				if (!this.modelTestSelection && modelSelection.splitModelReference(this.settings.defaultModel)) {
					this.modelTestSelection = this.settings.defaultModel
				}
				this.modelSettingsLoaded = true
				await this.loadModels()
				if (this.settings.rateLimitEnabled) {
					this.loadRateLimitStatus()
				}
				// Leave apiKey and webhookSecret empty to avoid showing masked values
			} catch (error) {
				console.error('Failed to load settings:', error)
			}
		},
		async loadModels(force = false) {
			if (this.loadingModels) {
				if (force === true) this.modelReloadPending = true
				return
			}
			if (this.hasUnsavedProviderSettings || !this.modelSettingsLoaded) {
				this.scheduleModelRefresh()
				return
			}
			this.loadingModels = true
			this.modelLoadError = ''
			const baseline = this.modelProviderRevision
			try {
				const response = force === true
					? await axios.post(generateUrl('/apps/educai/api/v1/models/refresh'))
					: await axios.get(generateUrl('/apps/educai/api/v1/models'))
				if (baseline !== this.modelProviderRevision) return
				this.availableModelOptions = modelSelection.normalizeModelOptions(response.data?.model_options, response.data?.models)
				this.modelProviders = Array.isArray(response.data?.providers) ? response.data.providers : []
				this.modelRefreshInterval = response.data?.refresh_interval_seconds || 300
				await this.refreshSavedModelBindings()
			} catch (error) {
				if (baseline !== this.modelProviderRevision) return
				this.availableModelOptions = this.availableModelOptions.map(option => ({ ...option, availability: 'stale' }))
				this.modelProviders = this.modelProviders.map(provider => ({ ...provider, status: provider.status === 'ready' ? 'stale' : provider.status }))
				this.modelLoadError = getApiErrorMessage(error, t('educai', 'Could not update model lists. Previous choices have been preserved.'))
			} finally {
				this.loadingModels = false
				if (this.modelReloadPending) {
					this.modelReloadPending = false
					await this.loadModels(true)
				} else {
					this.scheduleModelRefresh()
				}
			}
		},
		async saveSettings() {
			const providerSettingsChanged = this.hasUnsavedProviderSettings
			this.saving = true
			try {
				if (!this.toEndpointModelId(this.settings.defaultModel)) {
					showError(t('educai', 'Choose a default model before saving.'))
					this.setTab('models', 'educai-admin-model-choice')
					return
				}
				const defaultTemperature = this.normalizeTemperatureValue(this.settings.defaultTemperature)
				if (defaultTemperature === null) {
					showError(t('educai', 'Default temperature must be between 0.0 and 1.0'))
					this.setTab('models', 'educai-admin-behaviour')
					return
				}
				const appIconPayload = this.buildAppIconPayload()
				if (appIconPayload === null) {
					this.setTab('system', 'educai-admin-appearance')
					return
				}
				const outputTokenPayload = this.buildOutputTokenPayload()
				if (outputTokenPayload === null) {
					const maxValid = Number.isInteger(this.settings.maxOutputTokens) && this.settings.maxOutputTokens >= 1 && this.settings.maxOutputTokens <= 131072
					if (!maxValid) {
						this.setTab('models', 'educai-admin-behaviour')
					} else {
						this.openDetails.outputOverrides = true
						this.setTab('models', 'educai-admin-output-overrides')
					}
					return
				}

				// Build payload explicitly to avoid sending empty secret values
				const payload = {
					apiKey: this.settings.apiKey || '',
					apiEndpoint: (this.settings.apiEndpoint || '').trim(),
					secondaryApiEndpoint: (this.settings.secondaryApiEndpoint || '').trim(),
					secondaryApiKey: this.settings.secondaryApiKey ? this.settings.secondaryApiKey : null,
					defaultModel: this.toEndpointModelId(this.settings.defaultModel),
					defaultTemperature,
					fallbackModel: this.toEndpointModelId(this.settings.fallbackModel),
					...appIconPayload,
					...outputTokenPayload,
					llmChatTimeout: this.toPositiveInteger(this.settings.llmChatTimeout, 90),
					llmStreamTimeout: this.toPositiveInteger(this.settings.llmStreamTimeout, 240),
					llmModelsTimeout: this.toPositiveInteger(this.settings.llmModelsTimeout, 20),
					apiProvider: this.settings.apiProvider || 'custom',
					allowMultipleModels: this.settings.allowMultipleModels,
					allowedModels: this.settings.allowedModels.map((model) => this.toEndpointModelId(model)),
					embeddingApiEndpoint: (this.settings.embeddingApiEndpoint || '').trim(),
					embeddingApiKey: this.settings.embeddingApiKey ? this.settings.embeddingApiKey : null,
					embeddingModel: (this.settings.embeddingModel || '').trim(),
					embeddingRateLimitMode: this.settings.embeddingRateLimitMode || 'inherit',
					embeddingRateLimitSecond: this.toOptionalPositiveInteger(this.settings.embeddingRateLimitSecond),
					embeddingRateLimitMinute: this.toPositiveInteger(this.settings.embeddingRateLimitMinute, 100),
					embeddingRateLimitHour: this.toPositiveInteger(this.settings.embeddingRateLimitHour, 2000),
					embeddingRateLimitDay: this.toPositiveInteger(this.settings.embeddingRateLimitDay, 4000),
					ragChunkSize: Number.isFinite(this.settings.ragChunkSize) ? Number(this.settings.ragChunkSize) : null,
					ragChunkOverlap: Number.isFinite(this.settings.ragChunkOverlap) ? Number(this.settings.ragChunkOverlap) : null,
					ragEnabled: this.settings.ragEnabled,
					catalogueEnabled: this.settings.catalogueEnabled,
					catalogueApiEndpoint: (this.settings.catalogueApiEndpoint || '').trim(),
					catalogueReindexHours: Number.isFinite(this.settings.catalogueReindexHours) ? Number(this.settings.catalogueReindexHours) : 24,
					doclingEnabled: this.settings.doclingEnabled,
					doclingApiEndpoint: (this.settings.doclingApiEndpoint || '').trim(),
					doclingApiKey: this.settings.doclingAuthMode !== 'none' ? this.settings.doclingApiKey || null : null,
					doclingApiProfile: this.settings.doclingApiProfile,
					doclingAuthMode: this.settings.doclingAuthMode,
					visionApiEndpoint: (this.settings.visionApiEndpoint || '').trim(),
					visionApiKey: this.settings.visionApiKey ? this.settings.visionApiKey : null,
					visionModel: (this.settings.visionModel || '').trim(),
					speechApiEndpoint: (this.settings.speechApiEndpoint || '').trim(),
					speechApiKey: this.settings.speechApiKey ? this.settings.speechApiKey : null,
					speechModel: (this.settings.speechModel || '').trim(),
					rateLimitEnabled: this.settings.rateLimitEnabled,
					rateLimitSecond: this.toOptionalPositiveInteger(this.settings.rateLimitSecond),
					rateLimitMinute: this.toPositiveInteger(this.settings.rateLimitMinute, 30),
					rateLimitHour: this.toPositiveInteger(this.settings.rateLimitHour, 200),
					rateLimitDay: this.toPositiveInteger(this.settings.rateLimitDay, 1000),
					rateLimitQueueMessage: (this.settings.rateLimitQueueMessage || '').trim(),
					conversationContextTokens: Number.isFinite(this.settings.conversationContextTokens) ? Number(this.settings.conversationContextTokens) : 8000,
				}
				// Only include webhookSecret when user provided a non-empty value
				if (this.settings.webhookSecret && this.settings.webhookSecret.trim() !== '') {
					payload.webhookSecret = this.settings.webhookSecret
				}
				// Removing an optional credential is explicit; an empty field always keeps it.
				if (this.clearCredentials.length > 0) {
					payload.clearCredentials = [...this.clearCredentials]
				}

				// Edits made while the request runs stay unsaved.
				const submitted = adminSettings.snapshotSettings(this.settings, this.outputTokenOverrides)
				const submittedClears = [...this.clearCredentials]
				const embeddingConfigChanged = this.hasEmbeddingConfigChange(payload)
				if (embeddingConfigChanged) {
					const proceed = confirm(
						t('educai', 'Embedding configuration changes were detected (model/endpoint/key). You must run "Reindex All Embeddings" after saving, otherwise retrieval quality will be degraded. Continue saving settings?'),
					)
					if (!proceed) {
						this.saving = false
						return
					}
				}

				const response = await axios.put(
					generateUrl('/apps/educai/api/v1/settings'),
					payload,
				)
				applyEducAiRuntimeIconPayload(response.data, { refreshNavigation: true })
				this.captureIndexSettingsBaseline(payload)
				this.indexRefreshKey++
				if (embeddingConfigChanged) this.openIndexMaintenance()
				if (embeddingConfigChanged) {
					showSuccess(t('educai', 'Settings saved. Run "Reindex All Embeddings" now to rebuild vectors for the new embedding configuration.'))
				} else {
					showSuccess(t('educai', 'Settings saved successfully'))
				}
				// Keep baseline in sync after successful save
				const apiKeyUpdated = !!(payload.apiKey && payload.apiKey.trim() !== '')
				const embeddingKeyUpdated = !!(payload.embeddingApiKey && payload.embeddingApiKey.trim() !== '')
				const doclingKeyUpdated = !!(payload.doclingApiKey && payload.doclingApiKey.trim() !== '')
				const cleared = payload.clearCredentials || []
				for (const key of adminSettings.SECRET_FIELDS) {
					if (typeof payload[key] === 'string' && payload[key].trim() !== '') this.storedSecrets[key] = true
				}
				for (const key of cleared) this.storedSecrets[key] = false
				this.doclingHasStoredApiKey = (this.doclingHasStoredApiKey || doclingKeyUpdated) && !cleared.includes('doclingApiKey')
				this.clearSecretInputs(payload)
				if (payload.doclingAuthMode === 'none') this.settings.doclingApiKey = ''
				this.embeddingConfigBaseline = {
					apiProvider: payload.apiProvider || 'custom',
					apiEndpoint: payload.apiEndpoint,
					embeddingApiEndpoint: payload.embeddingApiEndpoint,
					embeddingModel: payload.embeddingModel,
					defaultModel: payload.defaultModel,
					hasApiKey: this.embeddingConfigBaseline.hasApiKey || apiKeyUpdated,
					hasEmbeddingApiKey: (this.embeddingConfigBaseline.hasEmbeddingApiKey || embeddingKeyUpdated) && !cleared.includes('embeddingApiKey'),
				}
				this.captureProviderSettingsBaseline(payload)
				this.captureModelSettingsBaseline(payload)
				this.settingsBaseline = submitted
				this.adoptSavedModelBindings(response.data)
				this.clearCredentials = this.clearCredentials.filter(key => !submittedClears.includes(key))
				this.secretResetKey++
				this.syncServiceModes()
				this.modelSettingsLoaded = true
				if (providerSettingsChanged) {
					this.modelTestResult = null
					this.availableModelOptions = []
					this.modelProviders = []
					await this.loadModels(true)
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to save settings')))
			} finally {
				this.saving = false
			}
		},
		clearSecretInputs(saved) {
			for (const key of ['apiKey', 'secondaryApiKey', 'webhookSecret', 'embeddingApiKey', 'doclingApiKey', 'visionApiKey', 'speechApiKey']) {
				if ((this.settings[key] || '').trim() === (saved[key] || '').trim()) this.settings[key] = ''
			}
		},
		openCreateTool() {
			this.resetToolForm()
			this.toolFormVisible = true
		},
		resetToolForm() {
			this.toolForm = {
				id: null,
				name: '',
				mcpEndpointUrl: '',
				description: '',
				authentication: '',
				enabled: false,
			}
			this.toolHasStoredAuth = false
			this.toolTestResult = ''
		},
		closeToolForm() {
			this.resetToolForm()
			this.toolFormVisible = false
		},
		async loadTools() {
			this.loadingTools = true
			try {
				const resp = await axios.get(generateUrl('/apps/educai/api/v1/admin/tools'))
				this.tools = Array.isArray(resp.data?.tools) ? resp.data.tools : []
			} catch (error) {
				console.error('Failed to load tools', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to load tools')))
			} finally {
				this.loadingTools = false
			}
		},
		editTool(tool) {
			this.toolForm = {
				id: tool.id,
				name: tool.name,
				mcpEndpointUrl: tool.mcp_endpoint_url,
				description: tool.description || '',
				authentication: '',
				enabled: !!tool.enabled,
			}
			this.toolHasStoredAuth = tool.authentication === '***'
			this.toolFormVisible = true
			this.toolTestResult = ''
		},
		async saveTool() {
			if (!this.toolForm.name || !this.toolForm.mcpEndpointUrl) {
				showError(t('educai', 'Tool name and endpoint are required'))
				return
			}
			const authProvided = this.toolForm.authentication && this.toolForm.authentication.trim() !== ''
			let authPayload = null
			if (authProvided) {
				try {
					authPayload = JSON.parse(this.toolForm.authentication)
				} catch (error) {
					showError(t('educai', 'Authentication must be valid JSON'))
					return
				}
			}
			const payload = {
				name: this.toolForm.name,
				mcpEndpointUrl: this.toolForm.mcpEndpointUrl,
				description: this.toolForm.description || null,
				enabled: this.toolForm.enabled,
			}
			if (authProvided) {
				payload.authentication = authPayload
			} else if (!this.toolForm.id) {
				payload.authentication = null
			}
			this.toolSaving = true
			try {
				if (this.toolForm.id) {
					await axios.put(generateUrl(`/apps/educai/api/v1/admin/tools/${this.toolForm.id}`), payload)
				} else {
					await axios.post(generateUrl('/apps/educai/api/v1/admin/tools'), payload)
				}
				showSuccess(t('educai', 'Tool saved'))
				await this.loadTools()
				this.closeToolForm()
			} catch (error) {
				console.error('Failed to save tool', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to save tool')))
			} finally {
				this.toolSaving = false
			}
		},
		async deleteTool(tool) {
			if (!confirm(t('educai', 'Delete tool "{name}"? Bots that use it lose access immediately.', { name: tool.name }))) {
				return
			}
			this.toolSaving = true
			try {
				await axios.delete(generateUrl(`/apps/educai/api/v1/admin/tools/${tool.id}`))
				showSuccess(t('educai', 'Tool deleted'))
				await this.loadTools()
			} catch (error) {
				console.error('Failed to delete tool', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to delete tool')))
			} finally {
				this.toolSaving = false
			}
		},
		async toggleToolEnabled(tool) {
			this.toolSaving = true
			try {
				await axios.put(generateUrl(`/apps/educai/api/v1/admin/tools/${tool.id}`), {
					enabled: !tool.enabled,
				})
				await this.loadTools()
			} catch (error) {
				console.error('Failed to toggle tool', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to update tool state')))
			} finally {
				this.toolSaving = false
			}
		},
		async testToolConnection() {
			if (!this.toolForm.mcpEndpointUrl) {
				showError(t('educai', 'Endpoint URL required to test'))
				return
			}
			const authProvided = this.toolForm.authentication && this.toolForm.authentication.trim() !== ''
			let authPayload = null
			if (authProvided) {
				try {
					authPayload = JSON.parse(this.toolForm.authentication)
				} catch (error) {
					showError(t('educai', 'Authentication must be valid JSON'))
					return
				}
			}
			this.toolTestResult = ''
			this.toolTesting = true
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/tools/test'), {
					mcpEndpointUrl: this.toolForm.mcpEndpointUrl,
					authentication: authPayload,
				})
				this.toolTestResult = JSON.stringify(resp.data?.tools ?? [], null, 2)
			} catch (error) {
				console.error('Tool test failed', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to test tool connection')))
			} finally {
				this.toolTesting = false
			}
		},
		hasEmbeddingConfigChange(payload) {
			const inheritedModel = reference => (reference || '').trim().replace(/^(primary|secondary):/, '')
			const nextProvider = (payload.apiProvider || 'custom').trim()
			const nextApiEndpoint = (payload.apiEndpoint || '').trim()
			const nextEmbeddingEndpoint = (payload.embeddingApiEndpoint || '').trim()
			const nextEmbeddingModel = payload.embeddingModel ? payload.embeddingModel.trim() : inheritedModel(payload.defaultModel)
			const nextEffectiveEndpoint = this.resolveEffectiveEmbeddingEndpoint(nextApiEndpoint, nextEmbeddingEndpoint)

			const baseProvider = (this.embeddingConfigBaseline.apiProvider || 'custom').trim()
			const baseApiEndpoint = (this.embeddingConfigBaseline.apiEndpoint || '').trim()
			const baseEmbeddingEndpoint = (this.embeddingConfigBaseline.embeddingApiEndpoint || '').trim()
			const baseEmbeddingModel = this.embeddingConfigBaseline.embeddingModel ? this.embeddingConfigBaseline.embeddingModel.trim() : inheritedModel(this.embeddingConfigBaseline.defaultModel)
			const baseEffectiveEndpoint = this.resolveEffectiveEmbeddingEndpoint(baseApiEndpoint, baseEmbeddingEndpoint)

			const embeddingKeyUpdated = !!(payload.embeddingApiKey && payload.embeddingApiKey.trim() !== '')
			const embeddingKeyRemoved = !embeddingKeyUpdated && this.embeddingConfigBaseline.hasEmbeddingApiKey
				&& (payload.clearCredentials || []).includes('embeddingApiKey')
			const mainKeyUpdated = !!(payload.apiKey && payload.apiKey.trim() !== '')
			const usesMainKeyForEmbeddings = (!this.embeddingConfigBaseline.hasEmbeddingApiKey || embeddingKeyRemoved) && !embeddingKeyUpdated

			return (
				nextProvider !== baseProvider
				|| nextEmbeddingModel !== baseEmbeddingModel
				|| nextEffectiveEndpoint !== baseEffectiveEndpoint
				|| embeddingKeyUpdated
				|| embeddingKeyRemoved
				|| (usesMainKeyForEmbeddings && mainKeyUpdated)
			)
		},
		openIndexMaintenance() {
			this.setTab('knowledge', 'educai-admin-index')
		},
		captureIndexSettingsBaseline(saved = this.settings) {
			Object.keys(this.indexSettingsBaseline).forEach(key => { this.indexSettingsBaseline[key] = saved[key] })
		},
		async testCatalogue() {
			this.catalogueTesting = true
			this.catalogueTestResult = null
			try {
				const { data } = await axios.post(generateUrl('/apps/educai/api/v1/admin/catalogue/test'), { catalogueApiEndpoint: this.settings.catalogueApiEndpoint.trim() })
				this.catalogueTestResult = data.success
					? { success: true, message: t('educai', 'Catalogue connection successful') }
					: { success: false, message: getApiErrorMessage(data, t('educai', 'Catalogue connection failed')) }
			} catch (error) {
				this.catalogueTestResult = { success: false, message: getApiErrorMessage(error, t('educai', 'Catalogue connection failed')) }
			} finally {
				this.catalogueTesting = false
			}
		},
		resolveEffectiveEmbeddingEndpoint(apiEndpoint, embeddingApiEndpoint) {
			const embeddingEndpoint = (embeddingApiEndpoint || '').trim()
			if (embeddingEndpoint !== '') {
				return embeddingEndpoint
			}
			return (apiEndpoint || '').trim()
		},
		changeDoclingProfile() {
			this.settings.doclingAuthMode = this.settings.doclingApiProfile === 'docling_serve' ? 'x_api_key' : 'bearer'
			this.doclingTestResult = null
		},
		async testDoclingConnection() {
			this.doclingTesting = true
			this.doclingTestResult = null
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/docling/test'), {
					doclingApiEndpoint: (this.settings.doclingApiEndpoint || '').trim(),
					doclingApiProfile: this.settings.doclingApiProfile,
					doclingAuthMode: this.settings.doclingAuthMode,
					doclingApiKey: this.settings.doclingAuthMode === 'none'
						? null
						: (this.settings.doclingApiKey
							|| (!this.doclingHasStoredApiKey && this.settings.doclingApiProfile === 'legacy' && this.settings.doclingAuthMode === 'bearer' ? this.settings.apiKey : null)
							|| null),
				})
				this.doclingTestResult = {
					success: resp.data?.success ?? false,
					error: getApiErrorMessage(resp, t('educai', 'Connection test failed')),
				}
			} catch (error) {
				console.error('Docling test failed', error)
				const errorMessage = getApiErrorMessage(error, t('educai', 'Failed to test Docling connection'))
				this.doclingTestResult = {
					success: false,
					error: errorMessage,
				}
			} finally {
				this.doclingTesting = false
			}
		},
		async testVisionConnection() {
			this.visionTesting = true
			this.visionTestResult = null
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/vision/test'), {
					visionApiEndpoint: this.settings.visionApiEndpoint || null,
					visionApiKey: this.settings.visionApiKey || null,
					visionModel: (this.settings.visionModel || '').trim() || null,
				})
				this.visionTestResult = {
					success: resp.data?.success ?? false,
					error: getApiErrorMessage(resp, t('educai', 'Vision connection test failed')),
				}
			} catch (error) {
				console.error('Vision test failed', error)
				const errorMessage = getApiErrorMessage(error, t('educai', 'Failed to test vision connection'))
				this.visionTestResult = {
					success: false,
					error: errorMessage,
				}
			} finally {
				this.visionTesting = false
			}
		},
		async testSpeechConnection() {
			this.speechTesting = true
			this.speechTestResult = null
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/speech/test'), {
					speechApiEndpoint: this.settings.speechApiEndpoint || null,
					speechApiKey: this.settings.speechApiKey || null,
					speechModel: (this.settings.speechModel || '').trim() || null,
				})
				this.speechTestResult = {
					success: resp.data?.success ?? false,
					error: getApiErrorMessage(resp, t('educai', 'Speech connection test failed')),
				}
			} catch (error) {
				console.error('Speech test failed', error)
				const errorMessage = getApiErrorMessage(error, t('educai', 'Failed to test speech connection'))
				this.speechTestResult = {
					success: false,
					error: errorMessage,
				}
			} finally {
				this.speechTesting = false
			}
		},
		async loadRateLimitStatus() {
			this.rateLimitLoading = true
			try {
				const resp = await axios.get(generateUrl('/apps/educai/api/v1/admin/ratelimit/status'))
				this.rateLimitStatus = resp.data ?? null
			} catch (error) {
				console.error('Failed to load rate limit status', error)
				this.rateLimitStatus = null
			} finally {
				this.rateLimitLoading = false
			}
		},
		async processQueueNow() {
			this.queueProcessing = true
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/ratelimit/process'))
				if (resp.data?.success) {
					const processed = resp.data.processed || 0
					const remaining = resp.data.remaining || 0
					showSuccess(n(
						'educai',
						'Processed {processed} request. {remaining} remaining.',
						'Processed {processed} requests. {remaining} remaining.',
						processed,
						{
							processed: this.formatNumber(processed),
							remaining: this.formatNumber(remaining),
						},
					))
					// Refresh status
					this.loadRateLimitStatus()
				} else {
					showError(getApiErrorMessage(resp, t('educai', 'Failed to process queue')))
				}
			} catch (error) {
				console.error('Failed to process queue', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to process queue')))
			} finally {
				this.queueProcessing = false
			}
		},
		async loadAllBots() {
			this.loadingAllBots = true
			try {
				const resp = await axios.get(generateUrl('/apps/educai/api/v1/admin/bots'))
				this.allBots = Array.isArray(resp.data) ? resp.data : []
				this.allBotsLoaded = true
			} catch (error) {
				console.error('Failed to load all bots', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to load bots')))
			} finally {
				this.loadingAllBots = false
			}
		},
		editBotAdmin(bot) {
			this.adminEditingBot = bot
		},
		closeAdminEditor() {
			this.adminEditingBot = null
		},
		async saveBotAdmin(botData) {
			if (!botData?.id) {
				showError(t('educai', 'Bot id is required'))
				return
			}
			try {
				await axios.put(generateUrl(`/apps/educai/api/v1/admin/bots/${botData.id}`), botData)
				showSuccess(t('educai', 'Bot updated'))
				this.adminEditingBot = null
				this.loadAllBots()
			} catch (error) {
				console.error('Failed to update bot as admin', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to update bot')))
			}
		},
		async deleteBotAdmin(bot) {
			if (!bot || !bot.id) {
				return
			}
			if (!confirm(t('educai', 'Delete bot "{name}"? This cannot be undone.', { name: bot.bot_name }))) {
				return
			}
			try {
				await axios.delete(generateUrl(`/apps/educai/api/v1/bots/${bot.id}`))
				showSuccess(t('educai', 'Bot deleted'))
				this.loadAllBots()
			} catch (error) {
				console.error('Failed to delete bot as admin', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to delete bot')))
			}
		},
		formatVisibility(visibility) {
			if (visibility === 'global') {
				return t('educai', 'Global')
			}
			if (visibility === 'personal') {
				return t('educai', 'Personal')
			}
			if (visibility === 'teams') {
				return t('educai', 'Teams')
			}
			return t('educai', 'Groups')
		},
		formatApprovalStatus(status) {
			if (status === 'draft') {
				return t('educai', 'Draft')
			}
			if (status === 'pending') {
				return t('educai', 'Pending approval')
			}
			if (status === 'personal') {
				return t('educai', 'Personal')
			}
			return t('educai', 'Approved')
		},
	},
}
</script>

<style src="./admin/adminSettings.css"></style>
