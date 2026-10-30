<template>
	<AdminDialog id="educai-api-connection-dialog"
		:title="connection ? t('educai', 'Edit API connection') : t('educai', 'Add API connection')"
		@close="$emit('close')">
		<form class="ta-dialog-form" @submit.prevent="submit">
			<div class="ta-field">
				<label for="api-connection-name" class="ta-label">{{ t('educai', 'Name') }}</label>
				<input id="api-connection-name"
					v-model="form.name"
					class="ta-input"
					type="text"
					maxlength="128"
					required>
				<p class="ta-hint">
					{{ t('educai', 'Shown to bot creators and in tool labels, for example "Course catalogue".') }}
				</p>
			</div>
			<div class="ta-field">
				<label for="api-connection-description" class="ta-label">{{ t('educai', 'Description') }}</label>
				<textarea id="api-connection-description"
					v-model="form.description"
					class="ta-input"
					rows="2"
					maxlength="1000" />
			</div>
			<div class="ta-field">
				<label for="api-connection-base" class="ta-label">{{ t('educai', 'API base address') }}</label>
				<input id="api-connection-base"
					v-model="form.baseUrl"
					class="ta-input ta-input--mono"
					type="url"
					name="educai-api-connection-base"
					autocomplete="off"
					placeholder="https://api.example.org/v1">
				<p class="ta-hint">
					{{ t('educai', 'Every request goes to this HTTPS address and below it. The servers listed in the document are only a suggestion.') }}
				</p>
			</div>
			<div class="ta-field">
				<label for="api-connection-auth" class="ta-label">{{ t('educai', 'Authentication') }}</label>
				<select id="api-connection-auth" v-model="form.authMode" class="ta-input">
					<option v-for="mode in authModes" :key="mode" :value="mode">
						{{ authModeLabel(mode) }}
					</option>
				</select>
			</div>
			<div v-if="form.authMode === 'api_key_header'" class="ta-field">
				<label for="api-connection-header" class="ta-label">{{ t('educai', 'Header name') }}</label>
				<input id="api-connection-header"
					v-model="form.authHeader"
					class="ta-input ta-input--mono"
					type="text"
					autocomplete="off"
					placeholder="X-API-Key">
			</div>
			<div v-if="form.authMode !== 'none'" class="ta-field">
				<label for="api-connection-credential" class="ta-label">{{ form.authMode === 'bearer' ? t('educai', 'Token') : t('educai', 'API key') }}</label>
				<SecretField id="api-connection-credential"
					v-model="form.credential"
					name="educai-api-connection-credential"
					:label="t('educai', 'API credential')"
					:stored="form.credentialSet"
					removable
					:removing="form.removeCredential"
					@remove="form.removeCredential = $event" />
				<p class="ta-hint">
					{{ t('educai', 'Stored encrypted and only sent to the API base address. It is never shown again.') }}
				</p>
			</div>
			<p v-if="connection && connection.active_revision_id && authOrBaseChanged" class="ta-note ta-note--warn" role="status">
				{{ t('educai', 'Changing the base address or authentication pauses the approved tools until you approve a revision again.') }}
			</p>

			<fieldset class="ta-choice">
				<legend>{{ t('educai', 'Who sees the data') }}</legend>
				<label class="ta-check">
					<input v-model="form.sharedForBotAudience" type="checkbox">
					<span>{{ t('educai', 'The data this API returns may be shown to everyone who can read a bot answer: room members, guests, federated participants and anyone who reads the conversation later.') }}</span>
				</label>
				<p class="ta-hint">
					{{ t('educai', 'Required before approving or enabling. Do not connect APIs whose data depends on the asking user or is not meant for this audience; who may assign the tools does not limit who reads the answers.') }}
				</p>
			</fieldset>

			<fieldset class="ta-choice">
				<legend>{{ t('educai', 'Who may give these tools to bots') }}</legend>
				<label v-for="policy in assignPolicies" :key="policy.id">
					<input v-model="form.assignPolicy"
						type="radio"
						name="api-connection-assign"
						:value="policy.id">
					<span>{{ policy.label }}</span>
				</label>
			</fieldset>
			<div v-if="form.assignPolicy === 'groups'" class="ta-field">
				<span class="ta-label">{{ t('educai', 'Groups') }}</span>
				<p v-if="groups.length === 0" class="ta-hint">
					{{ t('educai', 'No groups found.') }}
				</p>
				<label v-for="group in groups" :key="group.id" class="ta-check">
					<input v-model="form.assignGroups" type="checkbox" :value="group.id">
					{{ group.displayName }}
				</label>
			</div>

			<fieldset class="ta-choice">
				<legend>{{ t('educai', 'Allowed for bots with visibility') }}</legend>
				<label v-for="visibility in botVisibilities" :key="visibility" class="ta-check">
					<input v-model="form.botVisibilities" type="checkbox" :value="visibility">
					{{ visibilityLabel(visibility) }}
				</label>
			</fieldset>

			<p v-if="error" class="ta-note ta-note--error" role="alert">
				{{ error }}
			</p>
			<div class="ta-row ta-dialog-actions">
				<span class="ta-dialog-actions__spacer" />
				<button type="button"
					class="ta-btn ta-btn--ghost"
					:disabled="saving"
					@click="$emit('close')">
					{{ t('educai', 'Cancel') }}
				</button>
				<button type="submit" class="ta-btn ta-btn--primary" :disabled="saving || form.botVisibilities.length === 0">
					{{ saving ? t('educai', 'Saving…') : t('educai', 'Save') }}
				</button>
			</div>
		</form>
	</AdminDialog>
</template>

<script>
import { t } from '../../l10n.js'
import { BOT_VISIBILITIES, authModeLabel, connectionForm, connectionPayload, emptyConnectionForm, visibilityLabel } from '../../utils/apiConnections.js'
import AdminDialog from './AdminDialog.vue'
import SecretField from './SecretField.vue'

export default {
	name: 'ApiConnectionDialog',
	components: { AdminDialog, SecretField },
	props: {
		connection: { type: Object, default: null },
		groups: { type: Array, default: () => [] },
		saving: { type: Boolean, default: false },
		error: { type: String, default: '' },
	},
	emits: ['close', 'save'],

	data() {
		return {
			form: this.connection ? connectionForm(this.connection) : emptyConnectionForm(),
			authModes: ['none', 'bearer', 'api_key_header'],
			botVisibilities: BOT_VISIBILITIES,
		}
	},
	computed: {
		assignPolicies() {
			return [
				{ id: 'admins', label: t('educai', 'Only administrators') },
				{ id: 'groups', label: t('educai', 'Members of selected groups') },
				{ id: 'all_users', label: t('educai', 'Everyone who can create bots') },
			]
		},
		authOrBaseChanged() {
			const connection = this.connection
			return this.form.baseUrl.trim() !== (connection.base_url ?? '')
				|| this.form.authMode !== connection.auth_mode
				|| (this.form.authMode === 'api_key_header' && this.form.authHeader.trim() !== (connection.auth_header ?? ''))
		},
	},
	methods: {
		t,
		authModeLabel,
		visibilityLabel,
		submit() {
			this.$emit('save', connectionPayload(this.form))
		},
	},
}
</script>
