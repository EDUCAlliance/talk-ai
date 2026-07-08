<template>
	<div class="public-bot-list">
		<div class="header">
			<div class="header-content">
				<h2>All Available Bots</h2>
				<p class="subtitle">Browse bots you have access to and learn more about their capabilities</p>
			</div>
			<a :href="createBotsUrl" class="create-bots-button">
				<span class="icon">✏️</span>
				Create & Edit Bots
			</a>
		</div>

		<div v-if="bots.length > 0" class="bot-grid">
			<PublicBotCard
				v-for="bot in sortedBots"
				:key="bot.id"
				:bot="bot"
				@show-details="openDetailModal"
				@speak-in-talk="openTalkStartModal" />
		</div>

		<div v-if="bots.length === 0 && !loading" class="empty-state">
			<div class="empty-icon">🤖</div>
			<h3>No bots available</h3>
			<p>When bots are published and you have access, they will appear here.</p>
		</div>

		<div v-if="loading" class="loading-state">
			<span class="icon-loading" />
			<p>Loading available bots...</p>
		</div>

		<!-- Detail Modal -->
		<BotDetailModal
			v-if="selectedBot"
			:bot="selectedBot"
			@close="closeDetailModal" />

		<BotTalkStartModal
			v-if="talkStartBot"
			:bot="talkStartBot"
			@close="closeTalkStartModal" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import PublicBotCard from './PublicBotCard.vue'
import BotDetailModal from './BotDetailModal.vue'
import BotTalkStartModal from './BotTalkStartModal.vue'

export default {
	name: 'PublicBotList',
	components: {
		PublicBotCard,
		BotDetailModal,
		BotTalkStartModal,
	},
	data() {
		return {
			bots: [],
			loading: false,
			selectedBot: null,
			talkStartBot: null,
		}
	},
	computed: {
		createBotsUrl() {
			return generateUrl('/settings/user/educai')
		},
		sortedBots() {
			const copy = this.bots.slice()
			// Sort: personal first, then global, teams, groups
			return copy.sort((a, b) => {
				const av = a.visibility || (a.is_public ? 'global' : 'groups')
				const bv = b.visibility || (b.is_public ? 'global' : 'groups')
				const rank = (value) => {
					if (value === 'personal') return 0
					if (value === 'global') return 1
					if (value === 'teams') return 2
					if (value === 'groups') return 3
					return 4
				}
				return rank(av) - rank(bv)
			})
		},
	},
	mounted() {
		this.loadBots()
	},
	methods: {
		async loadBots() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/public-bots'))
				this.bots = response.data
			} catch (error) {
				console.error('Failed to load bots:', error)
			} finally {
				this.loading = false
			}
		},
		openDetailModal(bot) {
			this.selectedBot = bot
		},
		closeDetailModal() {
			this.selectedBot = null
		},
		openTalkStartModal(bot) {
			this.talkStartBot = bot
		},
		closeTalkStartModal() {
			this.talkStartBot = null
		},
	},
}
</script>

<style scoped>
.public-bot-list {
	padding: 24px;
	max-width: 1400px;
	margin: 0 auto;
}

.header {
	margin-bottom: 32px;
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
}

.header-content {
	flex: 1;
}

.header h2 {
	margin: 0 0 8px 0;
	font-size: 28px;
	font-weight: 700;
	color: var(--color-main-text);
}

.subtitle {
	margin: 0;
	font-size: 15px;
	color: var(--color-text-lighter);
}

.create-bots-button {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 10px 18px;
	background: var(--color-main-background);
	border: 2px solid var(--color-main-text);
	border-radius: 8px;
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 600;
	text-decoration: none;
	cursor: pointer;
	transition: all 0.15s ease;
	white-space: nowrap;
}

.create-bots-button:hover {
	background: var(--color-main-text);
	color: var(--color-main-background);
}

.create-bots-button .icon {
	font-size: 16px;
}

.bot-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
	gap: 24px;
}

.empty-state {
	text-align: center;
	padding: 80px 20px;
	background: var(--color-background-dark);
	border-radius: 12px;
}

.empty-icon {
	font-size: 64px;
	margin-bottom: 16px;
}

.empty-state h3 {
	margin: 0 0 8px 0;
	font-size: 20px;
	font-weight: 600;
}

.empty-state p {
	margin: 0;
	color: var(--color-text-lighter);
	font-size: 15px;
}

.loading-state {
	text-align: center;
	padding: 60px 20px;
}

.loading-state .icon-loading {
	display: inline-block;
	font-size: 32px;
	margin-bottom: 16px;
}

.loading-state p {
	margin: 0;
	color: var(--color-text-lighter);
}
</style>
