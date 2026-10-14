<template>
	<div class="ta-panel">
		<AdminSection v-for="service in services"
			:key="service.id"
			:anchor="service.anchor"
			:title="service.title"
			:description="service.description">
			<template #badge>
				<span class="ta-chip" :class="service.configured ? 'ta-chip--ok' : ''">{{ service.configured ? t('educai', 'Active') : t('educai', 'Not set up') }}</span>
			</template>
			<ServiceSource :name="`${service.id}-source`"
				:value="admin.serviceModes[service.id]"
				:legend="t('educai', 'Provider')"
				:inherit-title="t('educai', 'Like the primary provider')"
				:inherit-detail="service.inheritDetail"
				:custom-title="t('educai', 'Own endpoint')"
				:custom-detail="service.customDetail"
				@input="admin.setServiceMode(service.id, $event)" />
			<p v-if="admin.serviceModes[service.id] === 'inherit' && admin.clearCredentials.includes(service.keyKey)" class="ta-note ta-note--warn" role="status">
				{{ t('educai', 'The separate key will be removed when saving.') }}
			</p>
			<div v-if="admin.serviceModes[service.id] === 'custom'" class="ta-grid ta-service-fields">
				<div class="ta-field">
					<label :for="`${service.id}-endpoint`" class="ta-label">{{ service.endpointLabel }}</label>
					<input :id="`${service.id}-endpoint`"
						v-model="admin.settings[service.endpointKey]"
						class="ta-input ta-input--mono"
						type="url"
						:name="`educai-${service.id}-endpoint`"
						autocomplete="off"
						:placeholder="service.endpointPlaceholder">
					<p class="ta-hint">
						{{ service.endpointHint }}
					</p>
				</div>
				<div class="ta-field">
					<label :for="`${service.id}-key`" class="ta-label">{{ service.keyLabel }}</label>
					<SecretField :id="`${service.id}-key`"
						:name="`educai-${service.id}-key`"
						:label="service.keyLabel"
						:value="admin.settings[service.keyKey]"
						:stored="admin.storedSecrets[service.keyKey]"
						removable
						:removing="admin.clearCredentials.includes(service.keyKey)"
						:reset-key="admin.secretResetKey"
						placeholder="sk-..."
						@input="admin.setSecret(service.keyKey, $event)"
						@remove="admin.setSecretRemoval(service.keyKey, $event)" />
				</div>
			</div>
			<div class="ta-grid">
				<div class="ta-field">
					<label :for="`${service.id}-model`" class="ta-label">{{ service.modelLabel }}</label>
					<input :id="`${service.id}-model`"
						v-model="admin.settings[service.modelKey]"
						class="ta-input ta-input--mono"
						type="text"
						:list="admin.serviceModes[service.id] === 'inherit' ? 'educai-primary-models' : null"
						:placeholder="service.modelPlaceholder">
					<p class="ta-hint">
						{{ service.modelHint }}
					</p>
				</div>
			</div>
			<TestAction :label="service.testLabel"
				:busy-label="t('educai', 'Testing…')"
				:busy="service.testing"
				:result="service.result"
				:idle-text="service.idle"
				@run="service.test()" />
		</AdminSection>

		<AdminSection anchor="educai-admin-tools"
			:title="t('educai', 'MCP tools')"
			:description="t('educai', 'Register Model Context Protocol servers. Bot creators can attach enabled tools to their bots. Changes here apply immediately.')">
			<template #actions>
				<button type="button" class="ta-btn ta-btn--primary" @click="admin.openCreateTool()">
					{{ t('educai', 'Register Tool') }}
				</button>
			</template>
			<p v-if="admin.loadingTools" class="ta-hint">
				{{ t('educai', 'Loading tools…') }}
			</p>
			<p v-else-if="admin.tools.length === 0" class="ta-empty">
				{{ t('educai', 'No tools registered yet.') }}
			</p>
			<div v-else class="ta-list">
				<div v-for="tool in admin.tools"
					:key="tool.id"
					class="ta-list__row tool-row"
					:class="{ 'ta-list__row--muted': !tool.enabled }">
					<div class="ta-list__main">
						<p class="ta-list__title">
							{{ tool.name }}
							<span class="ta-chip" :class="tool.enabled ? 'ta-chip--ok' : ''">{{ tool.enabled ? t('educai', 'Enabled') : t('educai', 'Disabled') }}</span>
						</p>
						<p v-if="tool.description" class="ta-list__meta">
							{{ tool.description }}
						</p>
						<p class="ta-list__meta">
							<code class="ta-code">{{ tool.mcp_endpoint_url }}</code>
						</p>
					</div>
					<div class="ta-list__actions">
						<button type="button"
							class="ta-btn"
							:disabled="admin.toolSaving"
							@click="admin.toggleToolEnabled(tool)">
							{{ tool.enabled ? t('educai', 'Disable') : t('educai', 'Enable') }}
						</button>
						<button type="button"
							class="ta-btn"
							:disabled="admin.toolSaving"
							@click="admin.editTool(tool)">
							{{ t('educai', 'Edit') }}
						</button>
						<button type="button"
							class="ta-btn ta-btn--danger"
							:disabled="admin.toolSaving"
							@click="admin.deleteTool(tool)">
							{{ t('educai', 'Delete') }}
						</button>
					</div>
				</div>
			</div>
		</AdminSection>
	</div>
</template>

<script>
import { t } from '../../l10n.js'
import { endpointHost } from '../../utils/adminSettings.js'
import { formatWhen } from '../../utils/adminOverview.js'
import AdminSection from './AdminSection.vue'
import SecretField from './SecretField.vue'
import ServiceSource from './ServiceSource.vue'
import TestAction from './TestAction.vue'

export default {
	name: 'CapabilitiesTab',
	components: { AdminSection, SecretField, ServiceSource, TestAction },
	inject: ['admin'],
	computed: {
		services() {
			const admin = this.admin
			const host = endpointHost(admin.settings.apiEndpoint)
			const display = (result, success) => result
				? { success: !!result.success, message: result.success ? success : result.error }
				: null
			const idle = check => check?.current && check.success
				? t('educai', 'Last successful test: {time}', { time: formatWhen(check.checked_at) })
				: ''
			return [{
				id: 'vision',
				anchor: 'educai-admin-vision',
				title: t('educai', 'Image Understanding'),
				description: t('educai', 'Bots describe and read images in Talk when their image tool is enabled.'),
				configured: !!String(admin.settings.visionModel || '').trim(),
				inheritDetail: host ? t('educai', '{host} · same key', { host }) : t('educai', 'Uses the primary endpoint and key'),
				customDetail: t('educai', 'A separate multimodal endpoint'),
				endpointKey: 'visionApiEndpoint',
				keyKey: 'visionApiKey',
				modelKey: 'visionModel',
				endpointLabel: t('educai', 'Vision API Endpoint'),
				endpointPlaceholder: 'https://chat-ai.academiccloud.de/v1/chat/completions',
				endpointHint: t('educai', 'Leave empty to send this key to the primary endpoint.'),
				keyLabel: t('educai', 'Vision API Key'),
				modelLabel: t('educai', 'Vision Model'),
				modelPlaceholder: 'llama-4-scout-17b-16e-instruct',
				modelHint: t('educai', 'A model that accepts image input. Leave empty to switch image understanding off.'),
				testLabel: t('educai', 'Test Vision'),
				testing: admin.visionTesting,
				result: display(admin.visionTestResult, t('educai', 'Vision connection successful')),
				idle: idle(admin.adminStatus?.checks?.vision),
				test: () => admin.testVisionConnection(),
			}, {
				id: 'speech',
				anchor: 'educai-admin-speech',
				title: t('educai', 'Speech To Text'),
				description: t('educai', 'Bots transcribe audio files and voice messages in Talk when their audio tool is enabled.'),
				configured: !!String(admin.settings.speechModel || '').trim(),
				inheritDetail: host ? t('educai', '{host} · transcription endpoint derived, same key', { host }) : t('educai', 'Derived from the primary endpoint and key'),
				customDetail: t('educai', 'A separate transcription endpoint'),
				endpointKey: 'speechApiEndpoint',
				keyKey: 'speechApiKey',
				modelKey: 'speechModel',
				endpointLabel: t('educai', 'Speech API Endpoint'),
				endpointPlaceholder: 'https://chat-ai.academiccloud.de/v1/audio/transcriptions',
				endpointHint: t('educai', 'Leave empty to derive the transcription endpoint from the primary endpoint.'),
				keyLabel: t('educai', 'Speech API Key'),
				modelLabel: t('educai', 'Speech Model'),
				modelPlaceholder: 'whisper-large-v3',
				modelHint: t('educai', 'A Whisper-compatible model. Leave empty to switch transcription off.'),
				testLabel: t('educai', 'Test Speech'),
				testing: admin.speechTesting,
				result: display(admin.speechTestResult, t('educai', 'Speech connection successful')),
				idle: idle(admin.adminStatus?.checks?.speech),
				test: () => admin.testSpeechConnection(),
			}]
		},
	},
	methods: { t },
}
</script>
