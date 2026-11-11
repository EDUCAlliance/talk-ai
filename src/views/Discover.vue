<template>
	<section class="talkai-screen">
		<header class="talkai-page-head">
			<div>
				<h2 class="talkai-page-head__title">
					{{ t('educai', 'Discover') }}
				</h2>
				<p class="talkai-page-head__lead">
					{{ t('educai', 'Bots you can use in Talk. Mention a bot with @ in any chat or start a conversation directly.') }}
				</p>
			</div>
		</header>

		<AssistantBanner v-if="assistantMissing" @setup="assistantSetup = true" />

		<div v-if="bots.length > 0" class="talkai-toolbar">
			<label class="talkai-search">
				<TalkAiIcon name="search" />
				<input v-model.trim="query"
					class="talkai-search__input"
					type="text"
					:placeholder="t('educai', 'Search bots by name or topic')"
					:aria-label="t('educai', 'Search bots')">
			</label>
			<div v-if="filters.length > 2"
				class="talkai-filters"
				role="group"
				:aria-label="t('educai', 'Filter by who can use the bot')">
				<button v-for="filter in filters"
					:key="filter.key"
					type="button"
					class="talkai-filter"
					:aria-pressed="String(scopeFilter === filter.key)"
					@click="scopeFilter = filter.key">
					<i v-if="filter.key"
						class="talkai-swatch"
						:class="`talkai-swatch--${filter.key}`"
						aria-hidden="true" />{{ filter.label }}
					<span class="talkai-filter__count">{{ filter.count }}</span>
				</button>
			</div>
		</div>

		<div v-if="bots.length > 0" class="talkai-grid">
			<BotTile v-for="bot in visibleBots"
				:key="bot.id"
				:bot="bot"
				:directory="directory"
				:pinned="bot.is_assistant === true"
				@details="detailsBot = $event"
				@talk="talkBot = $event" />
			<p v-if="visibleBots.length === 0" class="talkai-grid__empty" role="status">
				{{ query ? t('educai', 'No bot matches your search.') : t('educai', 'No bots for this filter.') }}
			</p>
		</div>
		<div v-else-if="failed" class="talkai-state" role="alert">
			<span class="talkai-state__icon"><TalkAiIcon name="alert" :size="26" /></span>
			<b class="talkai-state__title">{{ t('educai', 'The bots could not be loaded') }}</b>
			<button type="button" class="talkai-btn" @click="load">
				{{ t('educai', 'Try again') }}
			</button>
		</div>
		<div v-else-if="!loaded" class="talkai-state" role="status">
			<NcLoadingIcon :size="32" />
			<span>{{ t('educai', 'Loading bots…') }}</span>
		</div>
		<div v-else class="talkai-state">
			<span class="talkai-state__icon"><TalkAiIcon name="robot" :size="26" /></span>
			<b class="talkai-state__title">{{ t('educai', 'No bots yet') }}</b>
			<p class="talkai-state__text">
				{{ t('educai', 'Bots that are shared with you appear here, and so do the ones you create yourself.') }}
			</p>
		</div>

		<BotDetailsDialog v-if="detailsBot"
			:bot="detailsBot"
			:directory="directory"
			@close="detailsBot = null"
			@talk="openTalk" />
		<BotTalkDialog v-if="talkBot" :bot="talkBot" @close="talkBot = null" />
		<AssistantSetupDialog v-if="assistantSetup" @close="assistantSetup = false" @created="onAssistantCreated" />
	</section>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AssistantBanner from '../components/AssistantBanner.vue'
import AssistantSetupDialog from '../components/AssistantSetupDialog.vue'
import BotDetailsDialog from '../components/BotDetailsDialog.vue'
import BotTalkDialog from '../components/BotTalkDialog.vue'
import BotTile from '../components/BotTile.vue'
import TalkAiIcon from '../components/TalkAiIcon.vue'
import { t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { assistantFirst, assistantOf } from '../utils/assistant.js'
import { SCOPE_KEYS, scopeOf } from '../utils/botScope.js'

// Bots the user can use, including their own. Filtered by who can use them, searched by name, mention and description.
export default {
	name: 'Discover',
	components: { AssistantBanner, AssistantSetupDialog, BotDetailsDialog, BotTalkDialog, BotTile, NcLoadingIcon, TalkAiIcon },
	data() {
		return {
			query: '',
			scopeFilter: '',
			detailsBot: null,
			talkBot: null,
			assistantSetup: false,
		}
	},
	computed: {
		directory() {
			return store.state.directory
		},
		// The invitation stays until the assistant exists, whatever other bots the user has.
		// Only the list of own bots knows that, so nothing is shown before it has loaded.
		assistantMissing() {
			return store.state.loaded.ownBots && !store.state.failed.ownBots && assistantOf(store.state.ownBots) === null
		},
		loaded() {
			return store.state.loaded.publicBots
		},
		failed() {
			return store.state.failed.publicBots
		},
		// The personal assistant is pinned in first place. Then the own scope, then the wider ones; names in alphabetical order.
		bots() {
			return store.state.publicBots
				.map(bot => ({ bot, scope: scopeOf(bot).key }))
				.sort((a, b) => assistantFirst(a.bot, b.bot)
					|| SCOPE_KEYS.indexOf(a.scope) - SCOPE_KEYS.indexOf(b.scope)
					|| String(a.bot.bot_name).localeCompare(String(b.bot.bot_name)))
		},
		filters() {
			const labels = {
				personal: t('educai', 'Only me'),
				group: t('educai', 'My groups'),
				team: t('educai', 'Teams'),
				everyone: t('educai', 'Everyone'),
			}
			const scopes = SCOPE_KEYS
				.map(key => ({ key, label: labels[key], count: this.bots.filter(entry => entry.scope === key).length }))
				.filter(filter => filter.count > 0)
			return [{ key: '', label: t('educai', 'All'), count: this.bots.length }, ...scopes]
		},
		visibleBots() {
			const query = this.query.toLowerCase()
			return this.bots
				.filter(entry => this.scopeFilter === '' || entry.scope === this.scopeFilter)
				.map(entry => entry.bot)
				.filter(bot => query === '' || [bot.bot_name, bot.mention_name, bot.description]
					.some(text => String(text ?? '').toLowerCase().includes(query)))
		},
	},
	watch: {
		// A filter that lost its last bot must not hide everything.
		filters(filters) {
			if (!filters.some(filter => filter.key === this.scopeFilter)) {
				this.scopeFilter = ''
			}
		},
	},
	created() {
		// The frame loads the list once; coming back to this page refreshes it.
		if (store.state.loaded.publicBots) {
			this.load()
		}
	},
	methods: {
		t,
		load() {
			store.loadPublicBots().catch(error => console.error('Failed to load bots', error))
		},
		openTalk(bot) {
			this.detailsBot = null
			this.talkBot = bot
		},
		// The assistant exists now; its folders are chosen on its page.
		onAssistantCreated(bot) {
			this.assistantSetup = false
			this.$router.push(`/bots/${bot.id}/knowledge`)
		},
	},
}
</script>
