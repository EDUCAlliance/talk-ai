<template>
	<label class="ta-switch">
		<input :id="id"
			type="checkbox"
			role="switch"
			:checked="modelValue"
			:disabled="disabled"
			:aria-label="hideLabel ? label : null"
			@change="$emit('update:modelValue', $event.target.checked)">
		<span class="ta-switch__track" aria-hidden="true" />
		<span v-if="!hideLabel">{{ label }}</span>
	</label>
</template>

<script>
export default {
	name: 'ToggleSwitch',
	props: {
		id: { type: String, required: true },
		modelValue: { type: Boolean, default: false },
		label: { type: String, required: true },
		hideLabel: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
}
</script>
