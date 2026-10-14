<template>
	<div class="ta-test">
		<button :id="buttonId || null"
			type="button"
			class="ta-btn"
			:class="{ 'ta-btn--primary': primary }"
			:disabled="disabled || busy"
			@click="$emit('run')">
			{{ busy ? busyLabel : label }}
		</button>
		<p v-if="result"
			class="ta-result"
			:class="result.success ? 'ta-result--ok' : 'ta-result--error'"
			role="status">
			<span class="ta-result__icon" aria-hidden="true">{{ result.success ? '✓' : '!' }}</span>
			<span>{{ result.message }}</span>
		</p>
		<p v-else-if="idleText" class="ta-result ta-result--idle" role="status">
			{{ idleText }}
		</p>
	</div>
</template>

<script>
export default {
	name: 'TestAction',
	props: {
		label: { type: String, required: true },
		busyLabel: { type: String, required: true },
		busy: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
		primary: { type: Boolean, default: false },
		buttonId: { type: String, default: '' },
		result: { type: Object, default: null },
		idleText: { type: String, default: '' },
	},
}
</script>
