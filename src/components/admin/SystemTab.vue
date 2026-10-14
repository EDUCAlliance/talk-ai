<template>
	<div class="ta-panel">
		<AdminSection anchor="educai-admin-talk"
			:title="t('educai', 'Talk connection')"
			:description="t('educai', '{app} registers its Talk bot automatically. Talk signs every message for the bot with the webhook secret.', { app: appName })">
			<template #badge>
				<span class="ta-chip" :class="admin.storedSecrets.webhookSecret ? 'ta-chip--ok' : 'ta-chip--warn'">
					{{ admin.storedSecrets.webhookSecret ? t('educai', 'Secret stored') : t('educai', 'No secret') }}
				</span>
			</template>
			<div class="ta-grid">
				<div class="ta-field">
					<label for="webhook-secret" class="ta-label">{{ t('educai', 'Webhook Secret') }}</label>
					<SecretField id="webhook-secret"
						name="educai-webhook-secret"
						:label="t('educai', 'Webhook Secret')"
						:value="admin.settings.webhookSecret"
						:stored="admin.storedSecrets.webhookSecret"
						generate
						:reset-key="admin.secretResetKey"
						@input="admin.setSecret('webhookSecret', $event)" />
					<p class="ta-hint">
						{{ t('educai', 'Saving a new secret registers the Talk bot again with it.') }}
					</p>
				</div>
				<div class="ta-field">
					<label for="webhook-url" class="ta-label">{{ t('educai', 'Webhook URL') }}</label>
					<div class="ta-secret">
						<input id="webhook-url"
							class="ta-input ta-input--mono"
							type="text"
							readonly
							:value="admin.webhookUrl">
						<button type="button" class="ta-btn" @click="admin.copyText(admin.webhookUrl)">
							{{ t('educai', 'Copy') }}
						</button>
					</div>
					<p class="ta-hint">
						{{ t('educai', 'Only needed to register the bot manually with occ talk:bot:install.') }}
					</p>
				</div>
			</div>
		</AdminSection>

		<AdminSection anchor="educai-admin-rate-limits"
			:title="t('educai', 'Rate limits and queue')"
			:description="t('educai', 'Requests above the provider quota wait in a queue that background jobs process, instead of failing.')">
			<template #badge>
				<span class="ta-chip" :class="admin.settings.rateLimitEnabled ? 'ta-chip--ok' : ''">{{ admin.settings.rateLimitEnabled ? t('educai', 'On') : t('educai', 'Off') }}</span>
			</template>
			<template #actions>
				<ToggleSwitch id="rate-limit-enabled"
					v-model="admin.settings.rateLimitEnabled"
					:label="t('educai', 'Enable Rate Limit Queue')"
					hide-label />
			</template>
			<template v-if="admin.settings.rateLimitEnabled">
				<div class="ta-field">
					<span id="rate-limit-preset-label" class="ta-label">{{ t('educai', 'Limits') }}</span>
					<div class="ta-segmented" role="group" aria-labelledby="rate-limit-preset-label">
						<button type="button"
							:aria-pressed="String(chatPreset === 'gwdg')"
							@click="applyChatPreset">
							{{ t('educai', 'GWDG / AcademicCloud') }} <small>30 / 200 / 1000</small>
						</button>
						<button type="button" :aria-pressed="String(chatPreset === 'custom')" @click="customChatLimits = true">
							{{ t('educai', 'Custom values') }}
						</button>
					</div>
				</div>
				<RateLimitFields v-if="chatPreset === 'custom'"
					id-prefix="rate-limit"
					:values="chatLimits"
					:placeholders="presets.chat"
					@update="(window, value) => admin.setRateLimit('chat', window, value)" />
				<div class="ta-panel-box ta-queue">
					<dl class="ta-stats">
						<div>
							<dt>{{ t('educai', 'Queued') }}</dt>
							<dd>{{ formatNumber(admin.chatQueueStats.pending) }}</dd>
						</div>
						<div>
							<dt>{{ t('educai', 'Processing') }}</dt>
							<dd>{{ formatNumber(admin.chatQueueStats.processing) }}</dd>
						</div>
						<div v-if="admin.rateLimitStatus" :class="{ 'ta-stat--error': !admin.chatCanProcess }">
							<dt>{{ t('educai', 'Chat') }}</dt>
							<dd>{{ admin.chatCanProcess ? t('educai', 'Available') : t('educai', 'Rate Limited') }}</dd>
						</div>
						<div v-for="window in observedWindows" :key="window.id">
							<dt>{{ window.label }}</dt>
							<dd>{{ window.value }}</dd>
						</div>
					</dl>
					<div class="ta-row">
						<button type="button"
							class="ta-btn"
							:disabled="admin.rateLimitLoading"
							@click="admin.loadRateLimitStatus()">
							{{ admin.rateLimitLoading ? t('educai', 'Loading…') : t('educai', 'Refresh Status') }}
						</button>
						<button type="button"
							class="ta-btn ta-btn--primary"
							:disabled="admin.queueProcessing || !admin.chatQueueStats.pending"
							@click="admin.processQueueNow()">
							{{ admin.queueProcessing ? t('educai', 'Processing…') : t('educai', 'Process Queue Now') }}
						</button>
					</div>
				</div>
				<p v-if="admin.rateLimitStatus" class="ta-hint">
					{{ embeddingStatusText }}
				</p>
				<details class="ta-details"
					:open="admin.openDetails.queueMessage"
					@toggle="admin.openDetails.queueMessage = $event.target.open">
					<summary>
						{{ t('educai', 'Queue Message') }}
						<span>· {{ String(admin.settings.rateLimitQueueMessage || '').trim() ? t('educai', 'custom text') : t('educai', 'default text') }}</span>
					</summary>
					<div class="ta-details__body">
						<textarea id="rate-limit-queue-message"
							v-model="admin.settings.rateLimitQueueMessage"
							class="ta-input"
							rows="3"
							:aria-label="t('educai', 'Queue Message')"
							:placeholder="t('educai', '⏳ Your request has been queued. Position: {position}, estimated wait: ~{wait} seconds.', { position: '{position}', wait: '{wait}' })" />
						<p class="ta-hint">
							{{ t('educai', 'Use {position} for the queue position and {wait} for the estimated wait in seconds. Leave empty for the default text.', { position: '{position}', wait: '{wait}' }) }}
						</p>
					</div>
				</details>
			</template>
		</AdminSection>

		<AdminSection anchor="educai-admin-timeouts"
			:title="t('educai', 'Time limits')"
			:description="t('educai', 'How long requests to the language model may take.')">
			<div class="ta-grid ta-grid--three">
				<div class="ta-field">
					<label for="llm-chat-timeout" class="ta-label">{{ t('educai', 'Chat Timeout (seconds)') }}</label>
					<input id="llm-chat-timeout"
						v-model.number="admin.settings.llmChatTimeout"
						class="ta-input"
						type="number"
						min="1"
						step="1">
					<p class="ta-hint">
						{{ t('educai', 'Regular answers without streaming.') }}
					</p>
				</div>
				<div class="ta-field">
					<label for="llm-stream-timeout" class="ta-label">{{ t('educai', 'Streaming Timeout (seconds)') }}</label>
					<input id="llm-stream-timeout"
						v-model.number="admin.settings.llmStreamTimeout"
						class="ta-input"
						type="number"
						min="1"
						step="1">
					<p class="ta-hint">
						{{ t('educai', 'Streaming answers while the model writes.') }}
					</p>
				</div>
				<div class="ta-field">
					<label for="llm-models-timeout" class="ta-label">{{ t('educai', 'Model-List Timeout (seconds)') }}</label>
					<input id="llm-models-timeout"
						v-model.number="admin.settings.llmModelsTimeout"
						class="ta-input"
						type="number"
						min="1"
						step="1">
					<p class="ta-hint">
						{{ t('educai', 'Loading the model catalogues.') }}
					</p>
				</div>
			</div>
		</AdminSection>

		<AdminSection anchor="educai-admin-appearance"
			:title="t('educai', 'Appearance')"
			:description="t('educai', 'App icon for settings and navigation.')">
			<div class="ta-segmented" role="group" :aria-label="t('educai', 'App icon mode')">
				<button type="button"
					:aria-pressed="String(admin.settings.appIconMode === 'default')"
					@click="admin.setAppIconMode('default')">
					{{ t('educai', 'Default') }}
				</button>
				<button type="button"
					:aria-pressed="String(admin.settings.appIconMode === 'custom')"
					@click="admin.setAppIconMode('custom')">
					{{ t('educai', 'Custom') }}
				</button>
			</div>
			<div class="ta-icons">
				<div class="ta-icon-preview">
					<span>{{ t('educai', 'Black on light') }}</span>
					<span>
						<img v-if="admin.appIconBlackPreviewUrl"
							:src="admin.appIconBlackPreviewUrl"
							:alt="t('educai', '{name} black app icon preview', { name: appName })">
						<template v-else>{{ t('educai', 'Missing') }}</template>
					</span>
				</div>
				<div class="ta-icon-preview ta-icon-preview--dark">
					<span>{{ t('educai', 'White on dark') }}</span>
					<span>
						<img v-if="admin.appIconWhitePreviewUrl"
							:src="admin.appIconWhitePreviewUrl"
							:alt="t('educai', '{name} white app icon preview', { name: appName })">
						<template v-else>{{ t('educai', 'Missing') }}</template>
					</span>
				</div>
			</div>
			<p v-if="admin.settings.appIconMode === 'default'" class="ta-hint">
				{{ t('educai', 'Uses the bundled {name} icon pair: dark icon for light settings surfaces, white icon for app navigation.', { name: appName }) }}
			</p>
			<div v-else class="ta-grid">
				<div v-for="variant in iconVariants" :key="variant.id" class="ta-field">
					<label :for="`app-icon-${variant.id}-url`" class="ta-label">{{ variant.label }}</label>
					<div class="ta-secret">
						<input :id="`app-icon-${variant.id}-url`"
							v-model="admin.settings[variant.key]"
							class="ta-input"
							type="text"
							:name="`educai-app-icon-${variant.id}-url`"
							autocomplete="off"
							:placeholder="`https://example.org/educai-${variant.id}.svg`">
						<button type="button"
							class="ta-btn"
							:disabled="admin.uploadingAppIconVariant === variant.id"
							@click="triggerUpload(variant.id)">
							{{ admin.uploadingAppIconVariant === variant.id ? t('educai', 'Uploading...') : t('educai', 'Upload SVG') }}
						</button>
						<input :ref="`file-${variant.id}`"
							class="ta-hidden-file"
							type="file"
							accept="image/svg+xml,.svg"
							@change="admin.uploadAppIconSvg(variant.id, $event)">
					</div>
					<p class="ta-hint">
						{{ variant.hint }}
					</p>
				</div>
			</div>
			<div class="ta-row ta-icon-actions">
				<button type="button" class="ta-btn ta-btn--ghost" @click="admin.resetAppIconConfig()">
					{{ t('educai', 'Reset to default icon') }}
				</button>
			</div>
		</AdminSection>
	</div>
</template>

<script>
import { t, getCanonicalLocale } from '../../l10n.js'
import { APP_DISPLAY_NAME } from '../../branding.js'
import { RATE_LIMIT_PRESETS, rateLimitPreset } from '../../utils/adminSettings.js'
import AdminSection from './AdminSection.vue'
import RateLimitFields from './RateLimitFields.vue'
import SecretField from './SecretField.vue'
import ToggleSwitch from './ToggleSwitch.vue'

export default {
	name: 'SystemTab',
	components: { AdminSection, RateLimitFields, SecretField, ToggleSwitch },
	inject: ['admin'],
	data() {
		return { appName: APP_DISPLAY_NAME, customChatLimits: false, presets: RATE_LIMIT_PRESETS }
	},
	computed: {
		chatLimits() {
			const settings = this.admin.settings
			return { second: settings.rateLimitSecond, minute: settings.rateLimitMinute, hour: settings.rateLimitHour, day: settings.rateLimitDay }
		},
		chatPreset() {
			return this.customChatLimits ? 'custom' : rateLimitPreset(this.chatLimits, 'chat')
		},
		observedWindows() {
			const state = this.admin.chatRateLimitState
			if (!state) return []
			return [
				['second', t('educai', 'Per Second')],
				['minute', t('educai', 'Per Minute')],
				['hour', t('educai', 'Per Hour')],
				['day', t('educai', 'Per Day')],
			].filter(([window]) => state[`limit_${window}`]).map(([window, label]) => ({
				id: window,
				label,
				value: `${this.formatNumber(state[`remaining_${window}`])}/${this.formatNumber(state[`limit_${window}`])}`,
			}))
		},
		embeddingStatusText() {
			if (this.admin.embeddingRateLimitModeStatus === 'disabled') {
				return t('educai', 'Embedding rate limiting is disabled. Requests go straight to the configured embedding endpoint.')
			}
			const state = this.admin.embeddingRateLimitState
			if (state?.limit_minute) {
				return t('educai', 'Embeddings: {remaining}/{limit} per minute left, tracked separately from chat.', {
					remaining: this.formatNumber(state.remaining_minute),
					limit: this.formatNumber(state.limit_minute),
				})
			}
			return this.admin.embeddingConfiguredHint
		},
		iconVariants() {
			return [
				{ id: 'black', key: 'appIconBlackUrl', label: t('educai', 'Black icon'), hint: t('educai', 'Used on light settings surfaces. Enter a URL/path or upload an SVG from your computer.') },
				{ id: 'white', key: 'appIconWhiteUrl', label: t('educai', 'White icon'), hint: t('educai', 'Used in the dark app navigation/header context. Enter a URL/path or upload an SVG from your computer.') },
			]
		},
	},
	watch: {
		'admin.secretResetKey'() {
			this.customChatLimits = false
		},
	},
	methods: {
		t,
		formatNumber(value) {
			return new Intl.NumberFormat(getCanonicalLocale()).format(Number(value) || 0)
		},
		applyChatPreset() {
			this.customChatLimits = false
			this.admin.applyRateLimitPreset('chat')
		},
		triggerUpload(variant) {
			const input = [].concat(this.$refs[`file-${variant}`])[0]
			if (input) {
				input.value = ''
				input.click()
			}
		},
	},
}
</script>
