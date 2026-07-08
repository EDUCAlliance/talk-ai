<template>
	<div class="educai-personal-settings">
		<header class="personal-header">
			<div>
				<h2>{{ APP_DISPLAY_NAME }}</h2>
				<p>Manage your bots and review your own AI activity.</p>
			</div>
			<nav class="personal-tabs" :aria-label="APP_DISPLAY_NAME + ' personal settings'">
				<button
					type="button"
					class="tab-button"
					:class="{ active: activeView === 'bots' }"
					@click="setView('bots')">
					<span class="icon-comment" />
					My bots
				</button>
				<button
					type="button"
					class="tab-button"
					:class="{ active: activeView === 'activity' }"
					@click="setView('activity')">
					<span class="icon-search" />
					Activity
				</button>
			</nav>
		</header>

		<PersonalBots v-if="activeView === 'bots'" />
		<PersonalActivity v-else />
	</div>
</template>

<script>
import PersonalActivity from './PersonalActivity.vue'
import { APP_DISPLAY_NAME } from '../branding.js'
import PersonalBots from './PersonalBots.vue'

export default {
	name: 'PersonalSettings',

	components: {
		PersonalActivity,
		PersonalBots,
	},

	data() {
		return {
			APP_DISPLAY_NAME,
			activeView: this.initialView(),
		}
	},

	methods: {
		initialView() {
			const params = new URLSearchParams(window.location.search)
			return params.get('view') === 'activity' ? 'activity' : 'bots'
		},

		setView(view) {
			this.activeView = view
			const url = new URL(window.location.href)
			if (view === 'activity') {
				url.searchParams.set('view', 'activity')
			} else {
				url.searchParams.delete('view')
			}
			window.history.replaceState({}, '', url.toString())
		},
	},
}
</script>

<style scoped>
.educai-personal-settings {
	max-width: 1280px;
	margin: 0 auto;
	padding: 24px;
}

.personal-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 20px;
}

.personal-header h2 {
	margin: 0 0 4px;
	font-size: 24px;
}

.personal-header p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.personal-tabs {
	display: inline-flex;
	gap: 4px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.tab-button {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	min-height: 36px;
	padding: 0 12px;
	border: 0;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-main-text);
	font-weight: 600;
	cursor: pointer;
}

.tab-button.active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.tab-button span {
	filter: var(--background-invert-if-dark);
}

.tab-button.active span {
	filter: none;
}

@media (max-width: 700px) {
	.educai-personal-settings {
		padding: 16px;
	}

	.personal-header {
		flex-direction: column;
	}

	.personal-tabs {
		width: 100%;
	}

	.tab-button {
		flex: 1;
		justify-content: center;
	}
}
</style>
