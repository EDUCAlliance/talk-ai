<template>
	<section :id="anchor"
		class="ta-section"
		tabindex="-1"
		:aria-labelledby="`${anchor}-title`">
		<div class="ta-section__head" :class="{ 'ta-section__head--compact': compact, 'ta-section__head--inline': inline }">
			<div>
				<h3 :id="`${anchor}-title`" class="ta-section__title">
					{{ title }}
					<slot name="badge" />
				</h3>
				<p v-if="description" class="ta-section__desc">
					{{ description }}
				</p>
			</div>
			<div v-if="$scopedSlots.actions" class="ta-section__actions">
				<slot name="actions" />
			</div>
		</div>
		<slot />
	</section>
</template>

<script>
export default {
	name: 'AdminSection',
	props: {
		anchor: { type: String, required: true },
		title: { type: String, required: true },
		description: { type: String, default: '' },
		compact: { type: Boolean, default: false },
		inline: { type: Boolean, default: false },
	},
}
</script>
