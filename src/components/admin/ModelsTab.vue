<template>
	<div class="ta-panel">
		<AdminSection anchor="educai-admin-providers"
			:title="t('educai', 'Providers')"
			:description="t('educai', 'Up to two OpenAI-compatible providers. Every model stays bound to its provider.')">
			<template #actions>
				<button type="button"
					class="ta-btn"
					:disabled="admin.loadingModels || admin.hasUnsavedProviderSettings || !admin.modelSettingsLoaded"
					@click="admin.loadModels(true)">
					<svg width="16"
						height="16"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2.4"
						stroke-linecap="round"
						aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6" /></svg>
					{{ admin.loadingModels ? t('educai', 'Updating model lists…') : t('educai', 'Refresh model lists') }}
				</button>
			</template>
			<p v-if="admin.hasUnsavedProviderSettings" class="ta-note ta-note--warn" role="status">
				{{ t('educai', 'Save provider settings first. The model list and the test use saved endpoints and credentials.') }}
			</p>
			<p v-if="admin.modelLoadError" class="ta-note ta-note--warn" role="alert">
				{{ admin.modelLoadError }}
			</p>
			<div class="ta-providers" aria-live="polite">
				<div v-for="provider in providerCards"
					:key="provider.id"
					class="ta-provider model-provider-card"
					:class="[`model-provider-card--${provider.status}`, { 'ta-provider--warn': provider.warn }]">
					<div class="ta-provider__head">
						<strong>
							{{ provider.title }}<small v-if="provider.id === 'secondary'">{{ t('educai', 'optional') }}</small>
						</strong>
						<span class="ta-chip model-provider-status" :class="provider.chipClass">{{ provider.chip }}</span>
					</div>
					<div class="ta-field">
						<label :for="provider.endpointId" class="ta-label">{{ t('educai', 'Endpoint') }}</label>
						<input :id="provider.endpointId"
							v-model="admin.settings[provider.endpointKey]"
							class="ta-input ta-input--mono"
							type="url"
							:name="`educai-${provider.endpointId}`"
							autocomplete="off"
							:placeholder="provider.placeholder"
							:required="provider.id === 'primary'">
					</div>
					<div class="ta-field">
						<label :for="provider.keyId" class="ta-label">{{ t('educai', 'API key') }}</label>
						<SecretField :id="provider.keyId"
							:name="`educai-${provider.keyId}`"
							:label="provider.keyLabel"
							:model-value="admin.settings[provider.keyKey]"
							:stored="admin.storedSecrets[provider.keyKey]"
							:removable="provider.id === 'secondary'"
							:removing="admin.clearCredentials.includes(provider.keyKey)"
							:reset-key="admin.secretResetKey"
							placeholder="sk-..."
							@update:model-value="admin.setSecret(provider.keyKey, $event)"
							@remove="admin.setSecretRemoval(provider.keyKey, $event)" />
					</div>
					<p class="ta-provider__meta">
						{{ provider.meta }}
					</p>
				</div>
			</div>
			<p class="ta-hint">
				{{ t('educai', 'A retrieved model list does not verify inference access, model availability, or account credit. Use the test below for that.') }}
			</p>
		</AdminSection>

		<AdminSection anchor="educai-admin-model-choice"
			:title="t('educai', 'Model choice')"
			:description="t('educai', 'Which model bots use by default and which models may be chosen per bot.')">
			<div class="ta-grid">
				<div class="ta-field">
					<label for="default-model" class="ta-label">{{ t('educai', 'Default Model') }}</label>
					<ModelSelector id="default-model"
						v-model="admin.settings.defaultModel"
						:options="admin.availableModelOptions"
						:unlisted-endpoints="unlistedEndpoints"
						:empty-label="t('educai', 'Choose a default model')"
						allow-manual />
					<p class="ta-hint">
						{{ t('educai', 'Used by every bot without its own model choice.') }}
					</p>
				</div>
				<div class="ta-field">
					<label for="fallback-model" class="ta-label">{{ t('educai', 'Fallback on failure') }}</label>
					<ModelSelector id="fallback-model"
						v-model="admin.settings.fallbackModel"
						:options="admin.availableModelOptions"
						:unlisted-endpoints="unlistedEndpoints"
						:empty-label="t('educai', 'No fallback')"
						allow-manual />
					<p class="ta-hint">
						{{ t('educai', 'One second attempt after a timeout or connection failure.') }}
					</p>
				</div>
			</div>
			<div class="ta-field ta-allow-switch">
				<ToggleSwitch id="allow-multiple-models"
					v-model="admin.settings.allowMultipleModels"
					:label="t('educai', 'Allow Multiple Models (per-bot selection)')" />
				<p class="ta-hint">
					{{ admin.settings.allowMultipleModels
						? t('educai', 'Whoever creates or edits a bot can choose one of the models released below in the bot settings. New models are never released automatically. Withdrawing a release does not change bots that already use the model.')
						: t('educai', 'All bots use the default model. Bots that already have their own model keep it.') }}
				</p>
			</div>
			<ModelAllowList v-if="admin.settings.allowMultipleModels"
				v-model="admin.settings.allowedModels"
				:options="admin.availableModelOptions"
				:default-model="admin.settings.defaultModel"
				:fallback-model="admin.settings.fallbackModel"
				:unlisted-endpoints="unlistedEndpoints"
				:provider-hosts="admin.providerHosts" />
		</AdminSection>

		<AdminSection anchor="educai-admin-behaviour"
			:title="t('educai', 'Response behaviour')"
			:description="t('educai', 'Defaults for all bots. Single bots can adjust the creativity.')">
			<div class="ta-field">
				<span id="default-temperature-label" class="ta-label">{{ t('educai', 'Creativity') }}</span>
				<div class="ta-segmented" role="group" aria-labelledby="default-temperature-label">
					<button v-for="preset in temperaturePresets"
						:key="preset.id"
						type="button"
						:aria-pressed="String(!showCustomTemperature && currentTemperaturePreset === preset.id)"
						@click="setTemperature(preset.value)">
						{{ preset.label }} <small>{{ formatTemperature(preset.value) }}</small>
					</button>
					<button type="button"
						:aria-pressed="String(showCustomTemperature)"
						@click="customTemperature = true">
						{{ t('educai', 'Custom value') }}
					</button>
				</div>
				<div v-if="showCustomTemperature" class="ta-unit ta-temperature">
					<input id="default-temperature"
						v-model.number="admin.settings.defaultTemperature"
						class="ta-input"
						type="number"
						min="0"
						max="1"
						step="0.01"
						placeholder="0.20"
						:aria-label="t('educai', 'Default Temperature')">
					<span>{{ t('educai', 'between 0.0 and 1.0') }}</span>
				</div>
				<p class="ta-hint">
					{{ t('educai', 'Low values suit tools, knowledge and workflows; higher values suit creative writing.') }}
				</p>
			</div>
			<div class="ta-grid">
				<div class="ta-field">
					<label for="max-output-tokens" class="ta-label">{{ t('educai', 'Maximum response length') }}</label>
					<div class="ta-unit">
						<input id="max-output-tokens"
							v-model.number="admin.settings.maxOutputTokens"
							class="ta-input"
							type="number"
							min="1"
							max="131072"
							step="1">
						<span>{{ t('educai', 'tokens per answer') }}</span>
					</div>
					<p class="ta-hint">
						{{ t('educai', 'Default 32768. Reasoning models share this budget.') }}
					</p>
				</div>
				<div class="ta-field">
					<label for="conversation-context-tokens" class="ta-label">{{ t('educai', 'Conversation memory') }}</label>
					<div class="ta-unit">
						<input id="conversation-context-tokens"
							v-model.number="admin.settings.conversationContextTokens"
							class="ta-input"
							type="number"
							min="1000"
							step="1"
							placeholder="8000">
						<span>{{ t('educai', 'tokens of history') }}</span>
					</div>
					<p class="ta-hint">
						{{ t('educai', 'How much of the previous conversation is sent with each request.') }}
					</p>
				</div>
			</div>
			<details id="educai-admin-output-overrides"
				class="ta-details"
				:open="admin.openDetails.outputOverrides"
				@toggle="admin.openDetails.outputOverrides = $event.target.open">
				<summary>
					{{ t('educai', 'Exceptions per model') }}
					<span>· {{ overrideSummary }}</span>
				</summary>
				<div class="ta-details__body">
					<p class="ta-hint ta-details__intro">
						{{ t('educai', 'An exception matches the exact model reference including its provider. Reported model limits can lower the effective budget.') }}
					</p>
					<div v-for="(override, index) in admin.outputTokenOverrides" :key="index" class="ta-override output-token-limit-row">
						<div class="ta-field">
							<label :for="`output-limit-model-${index}`" class="ta-label">{{ t('educai', 'Model reference') }}</label>
							<ModelSelector :id="`output-limit-model-${index}`"
								v-model="override.model"
								:options="admin.availableModelOptions"
								:unlisted-endpoints="unlistedEndpoints"
								:empty-label="t('educai', 'Choose a model')"
								allow-manual />
						</div>
						<div class="ta-field">
							<label :for="`output-limit-tokens-${index}`" class="ta-label">{{ t('educai', 'Maximum output tokens') }}</label>
							<input :id="`output-limit-tokens-${index}`"
								v-model.number="override.tokens"
								class="ta-input"
								type="number"
								min="1"
								max="131072"
								step="1">
						</div>
						<button type="button"
							class="ta-btn ta-btn--ghost"
							:aria-label="t('educai', 'Remove model override')"
							@click="admin.outputTokenOverrides.splice(index, 1)">
							{{ t('educai', 'Remove') }}
						</button>
					</div>
					<button type="button" class="ta-btn" @click="admin.addOutputTokenOverride()">
						{{ t('educai', 'Add model override') }}
					</button>
				</div>
			</details>
		</AdminSection>

		<AdminSection anchor="educai-admin-model-test"
			:title="t('educai', 'Test a model response')"
			:description="t('educai', 'Sends a short real request with the saved credentials, with at most one retry for model compatibility. This may incur a small provider charge. Nothing is sent automatically.')">
			<div class="ta-field">
				<label for="test-model" class="ta-label">{{ t('educai', 'Model to test') }}</label>
				<ModelSelector id="test-model"
					v-model="admin.modelTestSelection"
					:options="admin.availableModelOptions"
					:unlisted-endpoints="unlistedEndpoints"
					:empty-label="t('educai', 'Choose a model to test')"
					allow-manual
					:disabled="admin.modelTesting" />
			</div>
			<TestAction :label="t('educai', 'Send test request')"
				:busy-label="t('educai', 'Testing response…')"
				:busy="admin.modelTesting"
				:disabled="!admin.canTestModel"
				:result="modelTestDisplay"
				:idle-text="modelTestIdle"
				@run="admin.testModelResponse()" />
		</AdminSection>
	</div>
</template>

<script>
import { t, n, getCanonicalLocale } from '../../l10n.js'
import { TEMPERATURE_PRESETS, temperaturePreset } from '../../utils/adminSettings.js'
import { formatWhen, providerState } from '../../utils/adminOverview.js'
import { unlistedModelEndpoints } from '../../utils/modelSelection.js'
import AdminSection from './AdminSection.vue'
import ModelAllowList from './ModelAllowList.vue'
import SecretField from './SecretField.vue'
import TestAction from './TestAction.vue'
import ToggleSwitch from './ToggleSwitch.vue'
import ModelSelector from '../ModelSelector.vue'

export default {
	name: 'ModelsTab',
	components: { AdminSection, ModelAllowList, ModelSelector, SecretField, TestAction, ToggleSwitch },
	inject: ['admin'],
	data() {
		return { customTemperature: false }
	},
	computed: {
		unlistedEndpoints() {
			return unlistedModelEndpoints(this.admin.modelProviders)
		},
		providerCards() {
			return ['primary', 'secondary'].map(id => {
				const status = this.admin.modelProviders.find(provider => provider.id === id) || null
				const endpointKey = id === 'primary' ? 'apiEndpoint' : 'secondaryApiEndpoint'
				const configured = String(this.admin.settings[endpointKey] || '').trim() !== ''
				const state = configured ? (status?.status || 'unknown') : 'unconfigured'
				const count = status?.model_count || 0
				let chip
				let chipClass = ''
				let meta
				if (state === 'ready' && status.lists_models === false) {
					chip = t('educai', 'No model list')
					meta = t('educai', 'Reachable, but this provider does not list its models individually. Enter the model IDs manually.')
				} else if (state === 'ready') {
					chip = n('educai', '{count} model · {state}', '{count} models · {state}', count, { count: this.formatNumber(count), state: providerState(status) })
					chipClass = 'ta-chip--ok'
					meta = status.next_refresh_at
						? t('educai', 'Updated {time} · next check {next}', { time: formatWhen(status.last_success_at), next: formatWhen(status.next_refresh_at) })
						: t('educai', 'Updated {time}', { time: formatWhen(status.last_success_at) })
				} else if (state === 'stale') {
					chip = n('educai', '{count} model · {state}', '{count} models · {state}', count, { count: this.formatNumber(count), state: providerState(status) })
					chipClass = 'ta-chip--warn'
					meta = t('educai', 'Model list unreachable since {time}. Known models stay selectable; the other provider is unaffected.', { time: formatWhen(status.checked_at || status.last_success_at) })
				} else if (state === 'error') {
					chip = t('educai', 'Model list unavailable')
					chipClass = 'ta-chip--error'
					meta = status.error || t('educai', 'The model list could not be loaded.')
				} else if (state === 'unconfigured') {
					chip = t('educai', 'Not configured')
					meta = id === 'primary'
						? t('educai', 'Enter the endpoint and API key, then save.')
						: t('educai', 'Optional. Bots can then use models of both providers.')
				} else {
					chip = t('educai', 'Not checked')
					meta = t('educai', 'Save to load the model list.')
				}
				return {
					id,
					status: state,
					warn: state === 'stale' || state === 'error',
					title: id === 'primary' ? t('educai', 'Primary') : t('educai', 'Secondary'),
					endpointKey,
					keyKey: id === 'primary' ? 'apiKey' : 'secondaryApiKey',
					endpointId: id === 'primary' ? 'api-endpoint' : 'secondary-api-endpoint',
					keyId: id === 'primary' ? 'api-key' : 'secondary-api-key',
					keyLabel: id === 'primary' ? t('educai', 'Primary API Key') : t('educai', 'Secondary API Key'),
					placeholder: id === 'primary' ? 'https://chat-ai.academiccloud.de/v1/chat/completions' : 'https://example.org/v1/chat/completions',
					chip,
					chipClass,
					meta,
				}
			})
		},
		temperaturePresets() {
			const labels = { precise: t('educai', 'Precise'), balanced: t('educai', 'Balanced'), creative: t('educai', 'Creative') }
			return TEMPERATURE_PRESETS.map(preset => ({ ...preset, label: labels[preset.id] }))
		},
		currentTemperaturePreset() {
			return temperaturePreset(this.admin.settings.defaultTemperature)
		},
		showCustomTemperature() {
			return this.customTemperature || this.currentTemperaturePreset === 'custom'
		},
		overrideSummary() {
			const count = this.admin.outputTokenOverrides.length
			return count === 0
				? t('educai', 'none set')
				: n('educai', '{count} exception set', '{count} exceptions set', count, { count: this.formatNumber(count) })
		},
		modelTestDisplay() {
			const result = this.admin.modelTestResult
			if (!result) return null
			return {
				success: !!result.success,
				message: result.success
					? t('educai', 'Answer received from {model} in {latency} ms · {time}', {
						model: this.admin.formatModelLabel(result.model),
						latency: this.formatNumber(result.latency_ms || 0),
						time: formatWhen(result.checked_at),
					})
					: result.error,
			}
		},
		modelTestIdle() {
			const check = this.admin.adminStatus?.checks?.model
			if (check?.current && check.success) {
				return t('educai', 'Last successful test: {summary} · {time}', { summary: check.summary || '', time: formatWhen(check.checked_at) })
			}
			return t('educai', 'Inference not tested in this session.')
		},
	},
	watch: {
		'admin.secretResetKey'() {
			this.customTemperature = false
		},
	},
	methods: {
		t,
		formatNumber(value) {
			return new Intl.NumberFormat(getCanonicalLocale()).format(value)
		},
		formatTemperature(value) {
			return new Intl.NumberFormat(getCanonicalLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 2 }).format(value)
		},
		setTemperature(value) {
			this.customTemperature = false
			this.admin.applyDefaultTemperaturePreset(value)
		},
	},
}
</script>
