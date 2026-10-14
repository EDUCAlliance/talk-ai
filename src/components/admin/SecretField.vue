<template>
	<div class="ta-secret" :class="{ 'ta-secret--stacked': generate && !showStored }">
		<template v-if="showStored">
			<span :id="id"
				class="ta-secret__stored"
				:class="{ 'ta-secret__stored--removing': removing }"
				role="status">
				<svg width="14"
					height="14"
					viewBox="0 0 24 24"
					fill="currentColor"
					aria-hidden="true"><path d="M7 10V7a5 5 0 0 1 10 0v3h1a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h1Zm2 0h6V7a3 3 0 0 0-6 0v3Z" /></svg>
				{{ removing ? t('educai', 'Will be removed when saving') : t('educai', 'Stored') }}
			</span>
			<button v-if="removing"
				type="button"
				class="ta-btn"
				@click="$emit('remove', false)">
				{{ t('educai', 'Undo') }}
			</button>
			<template v-else>
				<button type="button"
					class="ta-btn"
					:aria-label="t('educai', 'Replace {name}', { name: label })"
					:disabled="disabled"
					@click="startEditing">
					{{ t('educai', 'Replace') }}
				</button>
				<button v-if="removable"
					type="button"
					class="ta-btn ta-btn--ghost"
					:aria-label="t('educai', 'Remove {name}', { name: label })"
					:disabled="disabled"
					@click="$emit('remove', true)">
					{{ t('educai', 'Remove') }}
				</button>
			</template>
		</template>
		<template v-else>
			<input :id="id"
				ref="input"
				class="ta-input ta-input--mono"
				:type="revealed ? 'text' : 'password'"
				:name="name || id"
				:value="value"
				:placeholder="placeholder"
				:disabled="disabled"
				autocomplete="new-password"
				spellcheck="false"
				@input="$emit('input', $event.target.value)">
			<button v-if="generate"
				type="button"
				class="ta-btn"
				:disabled="disabled"
				@click="generateValue">
				{{ t('educai', 'Generate') }}
			</button>
			<button v-if="generate && value"
				type="button"
				class="ta-btn ta-btn--ghost"
				:aria-pressed="String(revealed)"
				@click="revealed = !revealed">
				{{ revealed ? t('educai', 'Hide') : t('educai', 'Show') }}
			</button>
			<button v-if="stored"
				type="button"
				class="ta-btn ta-btn--ghost"
				@click="cancelEditing">
				{{ t('educai', 'Cancel') }}
			</button>
		</template>
	</div>
</template>

<script>
import { t } from '../../l10n.js'
import { generateSecret } from '../../utils/adminSettings.js'

export default {
	name: 'SecretField',
	props: {
		id: { type: String, required: true },
		name: { type: String, default: '' },
		label: { type: String, required: true },
		value: { type: String, default: '' },
		stored: { type: Boolean, default: false },
		removable: { type: Boolean, default: false },
		removing: { type: Boolean, default: false },
		placeholder: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
		generate: { type: Boolean, default: false },
		resetKey: { type: Number, default: 0 },
	},
	data() {
		return { editing: false, revealed: false }
	},
	computed: {
		showStored() {
			return (this.stored || this.removing) && !this.editing && !this.value
		},
	},
	watch: {
		resetKey() {
			this.editing = false
			this.revealed = false
		},
	},
	methods: {
		t,
		startEditing() {
			this.editing = true
			this.$nextTick(() => this.$refs.input?.focus())
		},
		cancelEditing() {
			this.editing = false
			this.revealed = false
			this.$emit('input', '')
		},
		generateValue() {
			this.editing = true
			this.revealed = true
			this.$emit('input', generateSecret())
		},
	},
}
</script>
