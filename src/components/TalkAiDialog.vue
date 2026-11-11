<template>
	<NcDialog :name="name"
		:size="size"
		:open="true"
		dialog-classes="talkai talkai-dialog"
		close-on-click-outside
		@update:open="onOpenChange">
		<div class="talkai-dialog__body">
			<div v-if="$slots.head" class="talkai-dialog__head">
				<slot name="head" />
			</div>
			<slot />
		</div>
		<template v-if="$slots.actions" #actions>
			<slot name="actions" />
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'

// The dialog frame (title, close button, focus handling) comes from Nextcloud, the content is styled like the app page.
export default {
	name: 'TalkAiDialog',
	components: { NcDialog },
	props: {
		name: { type: String, required: true },
		size: { type: String, default: 'normal' },
	},
	emits: ['close'],
	methods: {
		onOpenChange(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>
