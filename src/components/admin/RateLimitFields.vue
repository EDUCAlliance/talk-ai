<template>
	<div class="ta-grid ta-grid--four">
		<div v-for="window in windows" :key="window" class="ta-field">
			<label :for="`${idPrefix}-${window}`" class="ta-label">{{ labels[window] }}</label>
			<input :id="`${idPrefix}-${window}`"
				class="ta-input"
				type="number"
				min="1"
				step="1"
				:value="values[window] ?? ''"
				:placeholder="window === 'second' ? t('educai', 'Optional') : String(placeholders[window] ?? '')"
				@input="$emit('update', window, toLimit($event.target.value))">
		</div>
	</div>
</template>

<script>
import { t } from '../../l10n.js'
import { RATE_LIMIT_WINDOWS } from '../../utils/adminSettings.js'

export default {
	name: 'RateLimitFields',
	props: {
		idPrefix: { type: String, required: true },
		values: { type: Object, required: true },
		placeholders: { type: Object, default: () => ({}) },
	},
	computed: {
		windows() {
			return RATE_LIMIT_WINDOWS
		},
		labels() {
			return {
				second: t('educai', 'Requests per Second'),
				minute: t('educai', 'Requests per Minute'),
				hour: t('educai', 'Requests per Hour'),
				day: t('educai', 'Requests per Day'),
			}
		},
	},
	methods: {
		t,
		toLimit(value) {
			const numeric = Number(value)
			return value === '' || !Number.isFinite(numeric) ? null : numeric
		},
	},
}
</script>
