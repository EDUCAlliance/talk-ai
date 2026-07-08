<template>
	<div class="bot-picker">
		<div class="bot-picker__header">
			<NcTextField
				ref="searchInput"
				v-model="searchQuery"
				:label="t('educai', 'Search bots...')"
				:placeholder="t('educai', 'Type to search')"
				:show-trailing-button="searchQuery !== ''"
				trailing-button-icon="close"
				@trailing-button-click="searchQuery = ''"
				@keydown.enter="selectFirstBot"
				@keydown.arrow-down="focusNextBot"
				@keydown.arrow-up="focusPreviousBot" />
		</div>

		<div class="bot-picker__content">
			<div v-if="loading" class="bot-picker__loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('educai', 'Loading bots...') }}</span>
			</div>

			<div v-else-if="error" class="bot-picker__error">
				<NcNoteCard type="error">
					{{ error }}
				</NcNoteCard>
			</div>

			<div v-else-if="filteredBots.length === 0" class="bot-picker__empty">
				<NcEmptyContent
					:name="searchQuery ? t('educai', 'No bots found') : t('educai', 'No bots available')"
					:description="searchQuery ? t('educai', 'Try a different search term') : t('educai', 'Create a bot in the EducAI settings')">
					<template #icon>
						<IconRobot />
					</template>
				</NcEmptyContent>
			</div>

			<ul v-else class="bot-picker__list" role="listbox">
				<li
					v-for="(bot, index) in filteredBots"
					:key="bot.id"
					:ref="`bot-${index}`"
					class="bot-picker__item"
					:class="{ 'bot-picker__item--focused': focusedIndex === index }"
					role="option"
					tabindex="0"
					@click="openBotModal(bot)"
					@keydown.enter="openBotModal(bot)"
					@keydown.space.prevent="openBotModal(bot)"
					@focus="focusedIndex = index">
					<div class="bot-picker__item-content">
						<div class="bot-picker__item-header">
							<span class="bot-picker__item-name">{{ bot.bot_name }}</span>
							<span class="bot-picker__item-mention">{{ formatMention(bot.mention_name) }}</span>
						</div>
						<span v-if="bot.description" class="bot-picker__item-description">
							{{ truncateDescription(bot.description) }}
						</span>
						<div class="bot-picker__item-meta">
							<NcChip
								:type="getVisibilityChipType(bot)"
								:text="getVisibilityLabel(bot)"
								no-close />
							<span v-if="bot.owner_display_name" class="bot-picker__item-creator">
								{{ t('educai', 'by {name}', { name: bot.owner_display_name }) }}
							</span>
						</div>
					</div>
				</li>
			</ul>
		</div>

		<div class="bot-picker__footer">
			<span class="bot-picker__hint">
				{{ t('educai', 'Select a bot to see details and use it') }}
			</span>
		</div>

		<!-- Bot Detail Modal -->
		<BotPickerModal
			v-if="showModal && selectedBot"
			:bot="selectedBot"
			:room-token="roomToken"
			:is-moderator="isModerator"
			:bot-enabled="botEnabled"
			:room-status-loaded="roomStatusLoaded"
			:room-status-error="roomStatusError"
			@close="closeModal"
			@bot-enabled="handleBotEnabled"
			@use-bot="handleUseBot" />
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { APP_DISPLAY_NAME } from '../branding.js'
import axios from '@nextcloud/axios'

import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcChip from '@nextcloud/vue/dist/Components/NcChip.js'
import IconRobot from 'vue-material-design-icons/Robot.vue'
import BotPickerModal from './BotPickerModal.vue'

export default {
	name: 'BotPickerElement',

	components: {
		NcTextField,
		NcLoadingIcon,
		NcNoteCard,
		NcEmptyContent,
		NcChip,
		IconRobot,
		BotPickerModal,
	},

	props: {
		providerId: {
			type: String,
			required: true,
		},
		accessible: {
			type: Boolean,
			default: true,
		},
		roomToken: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			bots: [],
			searchQuery: '',
			loading: true,
			error: null,
			focusedIndex: -1,
			// Use Nextcloud's global current user (available via OC object)
			currentUser: window.OC?.currentUser || null,
			// Modal state
			showModal: false,
			selectedBot: null,
			// Talk room state
			isModerator: false,
			botEnabled: false,
			roomStatusLoaded: false,
			roomStatusError: null,
		}
	},

	computed: {
		filteredBots() {
			if (!this.searchQuery) {
				return this.bots
			}
			const query = this.searchQuery.toLowerCase()
			return this.bots.filter(bot => {
				return bot.bot_name.toLowerCase().includes(query)
					|| bot.mention_name.toLowerCase().includes(query)
					|| (bot.description && bot.description.toLowerCase().includes(query))
			})
		},
	},

	mounted() {
		this.loadBots()
		this.loadRoomStatus()
		// Focus search input when picker opens
		this.$nextTick(() => {
			this.$refs.searchInput?.$el?.querySelector('input')?.focus()
		})
	},

	methods: {
		async loadBots() {
			this.loading = true
			this.error = null

			try {
				// Fetch available bots (public bots accessible to user)
				const response = await axios.get(generateUrl('/apps/educai/api/v1/public-bots'))
				this.bots = response.data || []

				// Also fetch user's personal bots
				const personalResponse = await axios.get(generateUrl('/apps/educai/api/v1/bots'))
				const personalBots = personalResponse.data || []

				// Merge and deduplicate by ID
				const botMap = new Map()
				for (const bot of this.bots) {
					botMap.set(bot.id, bot)
				}
				for (const bot of personalBots) {
					if (!botMap.has(bot.id)) {
						botMap.set(bot.id, { ...bot, visibility: 'personal' })
					}
				}

				this.bots = Array.from(botMap.values()).sort((a, b) => {
					// Sort personal bots first, then by name
					if (a.visibility === 'personal' && b.visibility !== 'personal') return -1
					if (b.visibility === 'personal' && a.visibility !== 'personal') return 1
					return a.bot_name.localeCompare(b.bot_name)
				})
			} catch (err) {
				console.error('[EducAI] Failed to load bots:', err)
				this.error = t('educai', 'Failed to load bots. Please try again.')
			} finally {
				this.loading = false
			}
		},

		async loadRoomStatus() {
			if (!this.roomToken) {
				console.debug('[EducAI] Bot picker: No room token provided, skipping room status check')
				this.roomStatusLoaded = true
				this.roomStatusError = t('educai', 'Unable to determine the current chat room. Please refresh the page. In this chat room, only moderators can activate an {name} bot.', { name: APP_DISPLAY_NAME })
				return
			}

			try {
				this.roomStatusLoaded = false
				this.roomStatusError = null
				const response = await axios.get(generateUrl(`/apps/educai/api/v1/talk/bot-status/${this.roomToken}`))
				const data = response.data
				this.isModerator = data.isModerator ?? false
				this.botEnabled = data.botEnabled ?? false
				this.roomStatusLoaded = true
				console.debug('[EducAI] Bot picker: Room status loaded', {
					roomToken: this.roomToken,
					isModerator: this.isModerator,
					botEnabled: this.botEnabled,
				})
			} catch (err) {
				console.warn('[EducAI] Failed to load room status:', err)
				this.isModerator = false
				this.botEnabled = false
				this.roomStatusLoaded = true
				this.roomStatusError = t('educai', 'Unable to verify whether the {name} bot is active in this conversation. Please try again or ask a moderator to activate it.', { name: APP_DISPLAY_NAME })
			}
		},

		openBotModal(bot) {
			this.selectedBot = bot
			this.showModal = true
		},

		closeModal() {
			this.showModal = false
			this.selectedBot = null
		},

		handleUseBot(mention) {
			this.closeModal()
			// Dispatch the submit event that Smart Picker expects
			const event = new CustomEvent('submit', {
				bubbles: true,
				detail: mention,
			})
			this.$el.dispatchEvent(event)
		},

		handleBotEnabled() {
			this.botEnabled = true
			this.roomStatusError = null
		},

		selectFirstBot() {
			if (this.filteredBots.length > 0) {
				this.openBotModal(this.filteredBots[0])
			}
		},

		focusNextBot() {
			if (this.focusedIndex < this.filteredBots.length - 1) {
				this.focusedIndex++
				this.focusBotAtIndex(this.focusedIndex)
			}
		},

		focusPreviousBot() {
			if (this.focusedIndex > 0) {
				this.focusedIndex--
				this.focusBotAtIndex(this.focusedIndex)
			}
		},

		focusBotAtIndex(index) {
			const ref = this.$refs[`bot-${index}`]
			if (ref && ref[0]) {
				ref[0].focus()
			}
		},

		formatMention(mentionName) {
			// Display as @mention format
			const name = mentionName.startsWith('@') ? mentionName.substring(1) : mentionName
			return `@${name}`
		},

		truncateDescription(description, maxLength = 80) {
			if (description.length <= maxLength) {
				return description
			}
			return description.substring(0, maxLength).trim() + '...'
		},

		getVisibilityLabel(bot) {
			const v = bot.visibility || (bot.is_public ? 'global' : 'groups')
			if (v === 'global') return t('educai', 'Global')
			if (v === 'teams') return t('educai', 'Team')
			if (v === 'personal') return t('educai', 'Personal')
			return t('educai', 'Group')
		},

		getVisibilityChipType(bot) {
			const v = bot.visibility || (bot.is_public ? 'global' : 'groups')
			if (v === 'global') return 'success'
			if (v === 'personal') return 'tertiary'
			return 'primary'
		},
	},
}
</script>

<style scoped lang="scss">
.bot-picker {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	min-width: 400px;
	min-height: 300px;

	&__header {
		padding: 12px;
		border-bottom: 1px solid var(--color-border);
	}

	&__content {
		flex: 1;
		overflow-y: auto;
		min-height: 200px;
	}

	&__loading,
	&__error,
	&__empty {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 24px;
		gap: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 8px;
	}

	&__item {
		display: flex;
		align-items: flex-start;
		padding: 12px;
		border-radius: var(--border-radius-large);
		cursor: pointer;
		transition: background-color 0.1s ease;

		&:hover,
		&:focus,
		&--focused {
			background-color: var(--color-background-hover);
			outline: none;
		}

		&:focus-visible {
			box-shadow: 0 0 0 2px var(--color-primary);
		}
	}

	&__item-content {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__item-header {
		display: flex;
		align-items: baseline;
		gap: 8px;
	}

	&__item-name {
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__item-mention {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		font-family: var(--font-monospace);
	}

	&__item-description {
		font-size: 13px;
		color: var(--color-text-lighter);
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__item-meta {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-top: 2px;
	}

	&__item-creator {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__footer {
		padding: 8px 12px;
		border-top: 1px solid var(--color-border);
	}

	&__hint {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
