<template>
	<span class="material-design-icon talkai-sparkle-icon" aria-hidden="true" role="img">
		<svg class="material-design-icon__svg"
			:width="size"
			:height="size"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			stroke-width="1.7"
			stroke-linecap="round"
			stroke-linejoin="round">
			<path d="M12 3c.6 4.2 2.8 6.4 7 7-4.2.6-6.4 2.8-7 7-.6-4.2-2.8-6.4-7-7 4.2-.6 6.4-2.8 7-7zM19 16c.3 1.6 1 2.3 2.6 2.6-1.6.3-2.3 1-2.6 2.6-.3-1.6-1-2.3-2.6-2.6 1.6-.3 2.3-1 2.6-2.6z" />
		</svg>
	</span>
</template>

<script>
// The symbol of the personal assistant, drawn as in the design. Same interface as the Material Design icons.
export default {
	name: 'SparkleIcon',
	props: {
		size: { type: Number, default: 24 },
	},
}
</script>
