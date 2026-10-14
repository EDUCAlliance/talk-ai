<template>
	<fieldset class="ta-choice">
		<legend>{{ legend }}</legend>
		<label>
			<input type="radio"
				:name="name"
				value="inherit"
				:checked="value === 'inherit'"
				@change="$emit('input', 'inherit')">
			<span>
				<strong>{{ inheritTitle }}</strong>
				<small>{{ inheritDetail }}</small>
			</span>
		</label>
		<label>
			<input type="radio"
				:name="name"
				value="custom"
				:checked="value === 'custom'"
				@change="$emit('input', 'custom')">
			<span>
				<strong>{{ customTitle }}</strong>
				<small>{{ customDetail }}</small>
			</span>
		</label>
	</fieldset>
</template>

<script>
export default {
	name: 'ServiceSource',
	props: {
		name: { type: String, required: true },
		value: { type: String, default: 'inherit' },
		legend: { type: String, required: true },
		inheritTitle: { type: String, required: true },
		inheritDetail: { type: String, default: '' },
		customTitle: { type: String, required: true },
		customDetail: { type: String, default: '' },
	},
}
</script>
