<template>
	<div class="educai-admin-dialog" @click.self="$emit('close')" @keydown.esc.stop="$emit('close')">
		<div ref="box"
			class="educai-admin-dialog__box"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="`${id}-title`">
			<h3 :id="`${id}-title`">
				{{ title }}
			</h3>
			<slot />
		</div>
	</div>
</template>

<script>
export default {
	name: 'AdminDialog',
	props: {
		id: { type: String, required: true },
		title: { type: String, required: true },
	},
	mounted() {
		this.previousFocus = document.activeElement
		this.$nextTick(() => this.$refs.box?.querySelector('input, textarea, select, button')?.focus())
	},
	beforeDestroy() {
		this.previousFocus?.focus?.()
	},
}
</script>
