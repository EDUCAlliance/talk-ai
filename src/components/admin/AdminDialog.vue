<template>
	<div class="educai-admin-dialog"
		@click.self="$emit('close')"
		@keydown.esc.stop="$emit('close')"
		@keydown.tab="keepFocusInside">
		<div ref="box"
			class="educai-admin-dialog__box"
			role="dialog"
			aria-modal="true"
			tabindex="-1"
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
	emits: ['close'],

	mounted() {
		this.previousFocus = document.activeElement
		this.$nextTick(() => this.$refs.box?.querySelector('input, textarea, select, button')?.focus())
	},
	beforeUnmount() {
		this.previousFocus?.focus?.()
	},
	methods: {
		/**
		 * Tab and Shift+Tab cycle inside the dialog instead of reaching the page behind it.
		 *
		 * @param {KeyboardEvent} event the Tab key press
		 */
		keepFocusInside(event) {
			const box = this.$refs.box
			const focusable = [...box.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])')]
				.filter(element => !element.disabled && element.getClientRects().length > 0)
			if (focusable.length === 0) {
				event.preventDefault()
				return
			}
			const first = focusable[0]
			const last = focusable[focusable.length - 1]
			const outside = !box.contains(document.activeElement) || document.activeElement === box
			if (event.shiftKey && (outside || document.activeElement === first)) {
				event.preventDefault()
				last.focus()
			} else if (!event.shiftKey && (outside || document.activeElement === last)) {
				event.preventDefault()
				first.focus()
			}
		},
	},
}
</script>
