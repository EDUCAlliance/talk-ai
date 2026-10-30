<template>
	<div class="ta-savebar" role="region" :aria-label="t('educai', 'Unsaved changes')">
		<span class="ta-savebar__dot" aria-hidden="true" />
		<p class="ta-savebar__text" role="status">
			{{ n('educai', '{count} change not saved', '{count} changes not saved', labels.length, { count: labels.length }) }}
			<span>· {{ summary }}</span>
		</p>
		<button type="button"
			class="ta-btn ta-btn--ghost"
			:disabled="saving"
			@click="$emit('discard')">
			{{ t('educai', 'Discard') }}
		</button>
		<button id="educai-admin-save"
			type="button"
			class="ta-btn ta-btn--primary"
			:disabled="saving"
			@click="$emit('save')">
			{{ saving ? t('educai', 'Saving...') : t('educai', 'Save') }}
		</button>
	</div>
</template>

<script>
import { t, n } from '../../l10n.js'

export default {
	name: 'SaveBar',
	props: {
		labels: { type: Array, required: true },
		saving: { type: Boolean, default: false },
	},
	emits: ['discard', 'save'],

	computed: {
		summary() {
			const shown = this.labels.slice(0, 3).join(', ')
			return this.labels.length > 3 ? `${shown} …` : shown
		},
	},
	methods: { t, n },
}
</script>
