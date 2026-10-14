<template>
	<label class="ta-switch">
		<input :id="id"
			type="checkbox"
			role="switch"
			:checked="value"
			:disabled="disabled"
			:aria-label="hideLabel ? label : null"
			@change="$emit('input', $event.target.checked)">
		<span class="ta-switch__track" aria-hidden="true" />
		<span v-if="!hideLabel">{{ label }}</span>
	</label>
</template>

<script>
export default {
	name: 'ToggleSwitch',
	props: {
		id: { type: String, required: true },
		value: { type: Boolean, default: false },
		label: { type: String, required: true },
		hideLabel: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
	},
}
</script>
