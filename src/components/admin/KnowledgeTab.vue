<template>
	<div class="ta-panel">
		<AdminSection anchor="educai-admin-knowledge"
			:title="t('educai', 'Knowledge sources')"
			:description="t('educai', 'Bots search released files, folders and web pages (RAG).')">
			<template #badge>
				<span class="ta-chip" :class="admin.settings.ragEnabled ? 'ta-chip--ok' : ''">{{ admin.settings.ragEnabled ? t('educai', 'On') : t('educai', 'Off') }}</span>
			</template>
			<template #actions>
				<ToggleSwitch id="rag-enabled"
					v-model="admin.settings.ragEnabled"
					:label="t('educai', 'Enable Retrieval-Augmented Generation')"
					hide-label />
			</template>
			<ServiceSource name="embedding-source"
				:value="admin.serviceModes.embedding"
				:legend="t('educai', 'Embeddings')"
				:inherit-title="t('educai', 'Like the primary provider')"
				:inherit-detail="primaryDetail"
				:custom-title="t('educai', 'Own endpoint')"
				:custom-detail="t('educai', 'Own address and key for embeddings')"
				@input="admin.setServiceMode('embedding', $event)" />
			<p v-if="admin.serviceModes.embedding === 'inherit' && admin.clearCredentials.includes('embeddingApiKey')" class="ta-note ta-note--warn" role="status">
				{{ t('educai', 'The separate embedding key will be removed when saving.') }}
			</p>
			<div v-if="admin.serviceModes.embedding === 'custom'" class="ta-grid ta-service-fields">
				<div class="ta-field">
					<label for="embedding-endpoint" class="ta-label">{{ t('educai', 'Embedding API Endpoint') }}</label>
					<input id="embedding-endpoint"
						v-model="admin.settings.embeddingApiEndpoint"
						class="ta-input ta-input--mono"
						type="url"
						name="educai-embedding-endpoint"
						autocomplete="off"
						placeholder="https://chat-ai.academiccloud.de/v1/embeddings">
					<p class="ta-hint">
						{{ t('educai', 'Leave empty to send this key to the primary endpoint.') }}
					</p>
				</div>
				<div class="ta-field">
					<label for="embedding-key" class="ta-label">{{ t('educai', 'Embedding API Key') }}</label>
					<SecretField id="embedding-key"
						name="educai-embedding-key"
						:label="t('educai', 'Embedding API Key')"
						:value="admin.settings.embeddingApiKey"
						:stored="admin.storedSecrets.embeddingApiKey"
						removable
						:removing="admin.clearCredentials.includes('embeddingApiKey')"
						:reset-key="admin.secretResetKey"
						placeholder="sk-..."
						@input="admin.setSecret('embeddingApiKey', $event)"
						@remove="admin.setSecretRemoval('embeddingApiKey', $event)" />
				</div>
			</div>
			<div class="ta-grid">
				<div class="ta-field">
					<label for="embedding-model" class="ta-label">{{ t('educai', 'Embedding Model') }}</label>
					<input id="embedding-model"
						v-model="admin.settings.embeddingModel"
						class="ta-input ta-input--mono"
						type="text"
						:list="admin.serviceModes.embedding === 'inherit' ? 'educai-primary-models' : null"
						placeholder="multilingual-e5-large-instruct">
					<p class="ta-hint">
						{{ t('educai', 'After a change, every source has to be indexed again.') }}
					</p>
				</div>
			</div>
			<div v-if="admin.hasPendingEmbeddingConfigChange" class="ta-note ta-note--warn" role="status">
				<strong>{{ t('educai', 'Embedding settings changed.') }}</strong>
				<p>{{ t('educai', 'Save first, then run "Reindex All Embeddings" to rebuild bot vectors for the active embedding model.') }}</p>
			</div>
			<EmbeddingMaintenance id="educai-admin-index"
				:pending-changes="admin.hasPendingIndexSettings"
				:saving="admin.saving"
				:active="admin.activeTab === 'knowledge'"
				:refresh-key="admin.indexRefreshKey"
				@save-settings="admin.saveSettings()"
				@status="admin.onEmbeddingStatus" />
			<details class="ta-details"
				:open="admin.openDetails.knowledgeAdvanced"
				@toggle="admin.openDetails.knowledgeAdvanced = $event.target.open">
				<summary>
					{{ t('educai', 'Advanced') }}
					<span>· {{ advancedSummary }}</span>
				</summary>
				<div class="ta-details__body">
					<div class="ta-grid">
						<div class="ta-field">
							<label for="rag-chunk-size" class="ta-label">{{ t('educai', 'Chunk Size (tokens)') }}</label>
							<input id="rag-chunk-size"
								v-model.number="admin.settings.ragChunkSize"
								class="ta-input"
								type="number"
								min="100"
								step="10"
								placeholder="750">
							<p class="ta-hint">
								{{ t('educai', 'Larger chunks index faster but find fewer exact passages.') }}
							</p>
						</div>
						<div class="ta-field">
							<label for="rag-chunk-overlap" class="ta-label">{{ t('educai', 'Chunk Overlap (tokens)') }}</label>
							<input id="rag-chunk-overlap"
								v-model.number="admin.settings.ragChunkOverlap"
								class="ta-input"
								type="number"
								min="0"
								:max="Math.max(0, (admin.settings.ragChunkSize || 750) - 1)"
								step="5"
								placeholder="50"
								:aria-invalid="chunkOverlapInvalid ? 'true' : 'false'">
							<p v-if="chunkOverlapInvalid" class="ta-hint" role="alert">
								{{ t('educai', 'The chunk overlap must be smaller than the chunk size.') }}
							</p>
							<p v-else class="ta-hint">
								{{ t('educai', 'Overlap keeps context between neighbouring chunks.') }}
							</p>
						</div>
					</div>
					<div class="ta-field ta-field--spaced">
						<label for="embedding-rate-limit-mode" class="ta-label">{{ t('educai', 'Embedding Rate Limit Mode') }}</label>
						<select id="embedding-rate-limit-mode" v-model="admin.settings.embeddingRateLimitMode" class="ta-input">
							<option value="inherit">
								{{ t('educai', 'Inherit Chat Limits') }}
							</option>
							<option value="disabled">
								{{ t('educai', 'Disabled') }}
							</option>
							<option value="custom">
								{{ t('educai', 'Custom') }}
							</option>
						</select>
						<p class="ta-hint">
							{{ t('educai', 'Disable for self-hosted embedding servers without quotas.') }}
						</p>
					</div>
					<template v-if="admin.settings.embeddingRateLimitMode === 'custom'">
						<div class="ta-segmented ta-preset" role="group" :aria-label="t('educai', 'Embedding rate limits')">
							<button type="button"
								:aria-pressed="String(embeddingPreset === 'gwdg')"
								@click="admin.applyRateLimitPreset('embeddings')">
								{{ t('educai', 'GWDG / AcademicCloud') }} <small>100 / 2000 / 4000</small>
							</button>
							<button type="button" :aria-pressed="String(embeddingPreset === 'custom')" @click="customEmbeddingLimits = true">
								{{ t('educai', 'Custom values') }}
							</button>
						</div>
						<RateLimitFields v-if="embeddingPreset === 'custom' || customEmbeddingLimits"
							id-prefix="embedding-rate-limit"
							:values="embeddingLimits"
							:placeholders="presets.embeddings"
							@update="(window, value) => admin.setRateLimit('embeddings', window, value)" />
					</template>
				</div>
			</details>
		</AdminSection>

		<AdminSection anchor="educai-admin-docling"
			:title="t('educai', 'Document conversion')"
			:description="t('educai', 'Converts PDF, Office and image files to text for knowledge search.')">
			<template #badge>
				<span class="ta-chip" :class="admin.settings.doclingEnabled ? 'ta-chip--ok' : ''">{{ admin.settings.doclingEnabled ? t('educai', 'On') : t('educai', 'Off') }}</span>
			</template>
			<template #actions>
				<ToggleSwitch id="docling-enabled"
					v-model="admin.settings.doclingEnabled"
					:label="t('educai', 'Enable Document Conversion')"
					hide-label />
			</template>
			<div class="ta-grid">
				<div class="ta-field">
					<label for="docling-endpoint" class="ta-label">{{ t('educai', 'Docling server') }}</label>
					<input id="docling-endpoint"
						v-model="admin.settings.doclingApiEndpoint"
						class="ta-input ta-input--mono"
						type="url"
						name="educai-docling-endpoint"
						autocomplete="off"
						placeholder="https://docling.example.org"
						@input="admin.resetDoclingResults()">
					<p class="ta-hint">
						{{ t('educai', 'The address is enough: profile and authentication are detected. Leave empty for the AcademicCloud default.') }}
					</p>
				</div>
				<div class="ta-field">
					<label for="docling-key" class="ta-label">{{ t('educai', 'API key') }} <small>{{ t('educai', '(if required)') }}</small></label>
					<SecretField id="docling-key"
						name="educai-docling-key"
						:label="t('educai', 'Docling API Key')"
						:value="admin.settings.doclingApiKey"
						:stored="admin.storedSecrets.doclingApiKey"
						removable
						:removing="admin.clearCredentials.includes('doclingApiKey')"
						:reset-key="admin.secretResetKey"
						@input="onDoclingKey"
						@remove="admin.setSecretRemoval('doclingApiKey', $event)" />
					<p class="ta-hint">
						{{ admin.doclingKeyHint }}
					</p>
				</div>
			</div>
			<TestAction button-id="docling-detect"
				primary
				:label="t('educai', 'Detect and test')"
				:busy-label="t('educai', 'Detecting…')"
				:busy="admin.doclingDetecting"
				:disabled="admin.doclingTesting"
				@run="admin.detectDocling()" />
			<div v-if="detection"
				id="docling-detection"
				class="ta-detect"
				:class="detection.tone === 'ok' ? '' : `ta-detect--${detection.tone}`"
				role="status">
				<strong>
					<span class="ta-result__icon" aria-hidden="true">{{ detection.tone === 'ok' ? '✓' : '!' }}</span>
					{{ detection.headline }}
				</strong>
				<span v-for="line in detection.lines" :key="line">{{ line }}</span>
			</div>
			<details class="ta-details"
				:open="admin.openDetails.doclingManual"
				@toggle="admin.openDetails.doclingManual = $event.target.open">
				<summary>
					{{ t('educai', 'Set profile manually') }}
					<span>· {{ profileSummary }}</span>
				</summary>
				<div class="ta-details__body">
					<div class="ta-grid">
						<div class="ta-field">
							<label for="docling-profile" class="ta-label">{{ t('educai', 'Docling API profile') }}</label>
							<select id="docling-profile"
								v-model="admin.settings.doclingApiProfile"
								class="ta-input"
								@change="admin.changeDoclingProfile()">
								<option value="docling_serve">
									{{ t('educai', 'Docling Serve (official)') }}
								</option>
								<option value="legacy">
									{{ t('educai', 'AcademicCloud / EDUC (compatibility)') }}
								</option>
							</select>
						</div>
						<div class="ta-field">
							<label for="docling-auth" class="ta-label">{{ t('educai', 'Docling authentication') }}</label>
							<select id="docling-auth"
								v-model="admin.settings.doclingAuthMode"
								class="ta-input"
								@change="admin.resetDoclingResults()">
								<option value="x_api_key">
									X-Api-Key
								</option>
								<option value="bearer">
									Bearer
								</option>
								<option value="none">
									{{ t('educai', 'No authentication') }}
								</option>
							</select>
						</div>
					</div>
					<TestAction :label="t('educai', 'Test PDF conversion')"
						:busy-label="t('educai', 'Testing…')"
						:busy="admin.doclingTesting"
						:disabled="admin.doclingDetecting"
						:result="doclingTestDisplay"
						@run="admin.testDoclingConnection()" />
				</div>
			</details>
			<p class="ta-hint ta-formats">
				{{ t('educai', 'Supported formats:') }} PDF, DOCX, DOC, PPTX, PPT, XLSX, XLS, PNG, JPG, TIFF, BMP
			</p>
		</AdminSection>

		<AdminSection anchor="educai-admin-catalogue"
			:title="t('educai', 'Course catalogue')"
			:description="t('educai', 'Optional: makes the courses of an EDUC catalogue searchable for bots.')"
			:compact="!admin.settings.catalogueEnabled">
			<template #badge>
				<span class="ta-chip" :class="admin.settings.catalogueEnabled ? 'ta-chip--ok' : ''">{{ admin.settings.catalogueEnabled ? t('educai', 'On') : t('educai', 'Off') }}</span>
			</template>
			<template #actions>
				<ToggleSwitch id="catalogue-enabled"
					v-model="admin.settings.catalogueEnabled"
					:label="t('educai', 'Enable course catalogue integration')"
					hide-label />
			</template>
			<template v-if="admin.settings.catalogueEnabled">
				<div class="ta-grid">
					<div class="ta-field">
						<label for="catalogue-endpoint" class="ta-label">{{ t('educai', 'Catalogue API endpoint') }}</label>
						<input id="catalogue-endpoint"
							v-model="admin.settings.catalogueApiEndpoint"
							class="ta-input ta-input--mono"
							type="url"
							placeholder="https://catalogue.example.org/api"
							autocomplete="off">
					</div>
					<div class="ta-field">
						<label for="catalogue-interval" class="ta-label">{{ t('educai', 'Automatic refresh interval (hours)') }}</label>
						<input id="catalogue-interval"
							v-model.number="admin.settings.catalogueReindexHours"
							class="ta-input"
							type="number"
							min="1"
							step="1">
					</div>
				</div>
				<TestAction :label="t('educai', 'Test catalogue connection')"
					:busy-label="t('educai', 'Testing…')"
					:busy="admin.catalogueTesting"
					:disabled="!String(admin.settings.catalogueApiEndpoint || '').trim()"
					:result="admin.catalogueTestResult"
					@run="admin.testCatalogue()" />
				<p class="ta-hint">
					{{ t('educai', 'The test contacts the address above without saving. Catalogue indexing runs together with the knowledge index.') }}
				</p>
			</template>
		</AdminSection>
	</div>
</template>

<script>
import { t, n, getCanonicalLocale } from '../../l10n.js'
import { RATE_LIMIT_PRESETS, endpointHost, rateLimitPreset } from '../../utils/adminSettings.js'
import { doclingAuthLabel, doclingProfileLabel } from '../../utils/adminOverview.js'
import AdminSection from './AdminSection.vue'
import RateLimitFields from './RateLimitFields.vue'
import SecretField from './SecretField.vue'
import ServiceSource from './ServiceSource.vue'
import TestAction from './TestAction.vue'
import ToggleSwitch from './ToggleSwitch.vue'
import EmbeddingMaintenance from '../EmbeddingMaintenance.vue'

export default {
	name: 'KnowledgeTab',
	components: { AdminSection, EmbeddingMaintenance, RateLimitFields, SecretField, ServiceSource, TestAction, ToggleSwitch },
	inject: ['admin'],
	data() {
		return { customEmbeddingLimits: false, presets: RATE_LIMIT_PRESETS }
	},
	computed: {
		chunkOverlapInvalid() {
			const { ragChunkSize, ragChunkOverlap } = this.admin.settings
			return Number.isFinite(ragChunkSize) && Number.isFinite(ragChunkOverlap) && ragChunkOverlap >= ragChunkSize
		},
		primaryDetail() {
			const host = endpointHost(this.admin.settings.apiEndpoint)
			return host ? t('educai', '{host} · same key', { host }) : t('educai', 'Uses the primary endpoint and key')
		},
		embeddingLimits() {
			const settings = this.admin.settings
			return { second: settings.embeddingRateLimitSecond, minute: settings.embeddingRateLimitMinute, hour: settings.embeddingRateLimitHour, day: settings.embeddingRateLimitDay }
		},
		embeddingPreset() {
			return this.customEmbeddingLimits ? 'custom' : rateLimitPreset(this.embeddingLimits, 'embeddings')
		},
		advancedSummary() {
			const settings = this.admin.settings
			const mode = { inherit: t('educai', 'rate limit like chat'), disabled: t('educai', 'no rate limit'), custom: t('educai', 'own rate limit') }[settings.embeddingRateLimitMode] || ''
			return t('educai', 'chunks {size}, overlap {overlap}, {mode}', { size: this.formatNumber(settings.ragChunkSize), overlap: this.formatNumber(settings.ragChunkOverlap), mode })
		},
		profileSummary() {
			return `${doclingProfileLabel(this.admin.settings.doclingApiProfile)}, ${doclingAuthLabel(this.admin.settings.doclingAuthMode)}`
		},
		detection() {
			const result = this.admin.doclingDetection
			if (!result) return null
			const service = result.service ? [result.service, result.version].filter(Boolean).join(' ') : ''
			const lines = []
			if (result.auth_mode) lines.push(t('educai', 'Authentication: {mode}', { mode: doclingAuthLabel(result.auth_mode) }))
			const path = this.pathOf(result.conversion_url)
			if (path) lines.push(t('educai', 'Upload via {path}', { path }))
			if (result.success) {
				if (result.duration_ms >= 1000) {
					lines.push(t('educai', 'Test PDF converted in {seconds} s', {
						seconds: new Intl.NumberFormat(getCanonicalLocale(), { maximumFractionDigits: 1 }).format(result.duration_ms / 1000),
					}))
				} else if (result.duration_ms > 0) {
					lines.push(t('educai', 'Test PDF converted in {milliseconds} ms', { milliseconds: this.formatNumber(result.duration_ms) }))
				}
				return { tone: 'ok', headline: t('educai', '{service} detected', { service }), lines }
			}
			if (result.profile && service) {
				return { tone: 'warn', headline: t('educai', '{service} detected, but the test failed', { service }), lines: [result.error, ...lines, t('educai', 'The settings were not changed.')].filter(Boolean) }
			}
			return { tone: 'error', headline: t('educai', 'No Docling service detected'), lines: [result.error].filter(Boolean) }
		},
		doclingTestDisplay() {
			const result = this.admin.doclingTestResult
			if (!result) return null
			return { success: !!result.success, message: result.success ? t('educai', 'Test PDF converted successfully') : result.error }
		},
	},
	watch: {
		'admin.secretResetKey'() {
			this.customEmbeddingLimits = false
		},
	},
	methods: {
		t,
		n,
		formatNumber(value) {
			return new Intl.NumberFormat(getCanonicalLocale()).format(Number(value) || 0)
		},
		pathOf(url) {
			try {
				return url ? new URL(url).pathname : ''
			} catch {
				return ''
			}
		},
		onDoclingKey(value) {
			this.admin.setSecret('doclingApiKey', value)
			this.admin.resetDoclingResults()
		},
	},
}
</script>
