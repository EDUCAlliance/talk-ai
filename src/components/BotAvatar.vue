<template>
	<span class="talkai-ava"
		:class="[`talkai-ava--${scope}`, size ? `talkai-ava--${size}` : null]"
		aria-hidden="true">
		<slot>{{ initials }}</slot>
	</span>
</template>

<script>
import { initialsOf } from '../utils/botInitials.js'

// Decorative: the name stands next to it, and the scope is always given as icon and text as well.
export default {
	name: 'BotAvatar',
	props: {
		name: { type: String, default: '' },
		// Scope key from scopeOf(): personal, group, team or everyone
		scope: { type: String, required: true },
		// '' (44 px), 'lg' (56 px) or 'sm' (32 px)
		size: { type: String, default: '' },
	},
	computed: {
		initials() {
			return initialsOf(this.name)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/scope';

// The avatar carries its own colours, so it also works outside the app page, e.g. in Talk.
.talkai-ava {
	@include scope.tokens;
	display: grid;
	flex: none;
	place-items: center;
	width: 44px;
	height: 44px;
	// One shape for every bot: flat towards the start, round towards the end. Talk avatars stay round.
	border-start-end-radius: 50%;
	border-end-end-radius: 50%;
	font-size: 15px;
	font-weight: 700;
	line-height: 1;
}

[data-theme-dark] .talkai-ava,
[data-theme-dark-highcontrast] .talkai-ava {
	@include scope.tokens-dark;
}

@media (prefers-color-scheme: dark) {

	[data-theme-default] .talkai-ava {
		@include scope.tokens-dark;
	}
}

.talkai-ava--lg {
	width: 56px;
	height: 56px;
	font-size: 19px;
}

.talkai-ava--sm {
	width: 32px;
	height: 32px;
	font-size: 12px;
}

@each $key in personal, group, team, everyone {

	.talkai-ava--#{$key} {
		background-color: var(--talkai-scope-#{$key});
		color: var(--talkai-scope-#{$key}-text);
	}
}
</style>
