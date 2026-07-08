<template>
	<div class="bot-picker-modal-overlay" @click.self="$emit('close')">
		<div class="bot-picker-modal">
			<div class="modal-header">
				<div class="header-content">
					<div class="bot-icon">
						<IconRobot :size="28" />
					</div>
					<div class="header-text">
						<h2>{{ bot.bot_name }}</h2>
						<span class="mention-badge">{{ formatMention(bot.mention_name) }}</span>
					</div>
				</div>
				<NcButton type="tertiary" @click="$emit('close')">
					<template #icon>
						<IconClose :size="20" />
					</template>
				</NcButton>
			</div>

			<div class="modal-body">
				<!-- Description -->
				<div v-if="bot.description" class="detail-section">
					<p class="description">
						{{ bot.description }}
					</p>
				</div>

				<!-- Visibility & Access -->
				<div class="detail-section meta-info">
					<div class="meta-item">
						<span class="meta-label">{{ t('educai', 'Visibility') }}</span>
						<NcChip
							:type="visibilityChipType"
							:text="visibilityLabel"
							no-close />
					</div>
					<div v-if="bot.owner_display_name" class="meta-item">
						<span class="meta-label">{{ t('educai', 'Created by') }}</span>
						<span class="meta-value">{{ bot.owner_display_name }}</span>
					</div>
				</div>

				<!-- Access Reason -->
				<div v-if="accessReasonText" class="detail-section">
					<NcNoteCard type="info">
						{{ accessReasonText }}
					</NcNoteCard>
				</div>

				<!-- Tools Section -->
				<div v-if="bot.tools && bot.tools.length > 0" class="detail-section">
					<h3>{{ t('educai', 'Capabilities') }}</h3>
					<ul class="tools-list">
						<li v-for="(tool, index) in bot.tools" :key="index" class="tool-item">
							<span class="tool-name">{{ tool.name }}</span>
							<NcChip
								v-if="tool.is_builtin"
								type="tertiary"
								:text="t('educai', 'Built-in')"
								no-close />
						</li>
					</ul>
				</div>

				<!-- RAG Status -->
				<div v-if="bot.rag_enabled && bot.rag_source_count > 0" class="detail-section">
					<div class="rag-info">
						<IconBookOpenVariant :size="18" />
						<span>{{ t('educai', 'Has access to {count} knowledge source(s)', { count: bot.rag_source_count }) }}</span>
					</div>
				</div>

				<!-- Warning for non-moderator when bot not enabled -->
				<div v-if="!canUseBot" class="detail-section">
					<NcNoteCard type="warning">
						{{ unavailableMessage }}
					</NcNoteCard>
				</div>
			</div>

			<div class="modal-footer">
				<NcButton type="secondary" @click="$emit('close')">
					{{ t('educai', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="canUseBot"
					type="primary"
					:disabled="enabling"
					@click="handleUseBot">
					<template v-if="enabling" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ useButtonLabel }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { APP_DISPLAY_NAME } from '../branding.js'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcChip from '@nextcloud/vue/dist/Components/NcChip.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import IconRobot from 'vue-material-design-icons/Robot.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconBookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'

export default {
	name: 'BotPickerModal',

	components: {
		NcButton,
		NcChip,
		NcNoteCard,
		NcLoadingIcon,
		IconRobot,
		IconClose,
		IconBookOpenVariant,
	},

	props: {
		bot: {
			type: Object,
			required: true,
		},
		roomToken: {
			type: String,
			default: null,
		},
		isModerator: {
			type: Boolean,
			default: false,
		},
		botEnabled: {
			type: Boolean,
			default: false,
		},
		roomStatusLoaded: {
			type: Boolean,
			default: false,
		},
		roomStatusError: {
			type: String,
			default: null,
		},
	},

	emits: ['close', 'use-bot', 'bot-enabled'],

	data() {
		return {
			enabling: false,
		}
	},

	computed: {
		visibilityLabel() {
			const v = this.bot.visibility || (this.bot.is_public ? 'global' : 'groups')
			if (v === 'global') return t('educai', 'Global')
			if (v === 'teams') return t('educai', 'Team')
			if (v === 'personal') return t('educai', 'Personal')
			return t('educai', 'Group')
		},

		visibilityChipType() {
			const v = this.bot.visibility || (this.bot.is_public ? 'global' : 'groups')
			if (v === 'global') return 'success'
			if (v === 'personal') return 'tertiary'
			return 'primary'
		},

		accessReasonText() {
			const reason = this.bot.access_reason
			if (!reason) return null
			if (reason.type === 'global') return null
			if (reason.type === 'owner') return t('educai', 'You are the owner of this bot')
			if (reason.type === 'group' && reason.names?.length > 0) {
				return t('educai', 'Access via group: {groups}', { groups: reason.names.join(', ') })
			}
			if (reason.type === 'team' && reason.names?.length > 0) {
				return t('educai', 'Access via team: {teams}', { teams: reason.names.join(', ') })
			}
			return null
		},

		canUseBot() {
			if (this.botEnabled) {
				return true
			}

			if (this.roomStatusError) {
				return false
			}

			return this.roomStatusLoaded && this.isModerator
		},

		useButtonLabel() {
			if (this.enabling) {
				return t('educai', 'Enabling...')
			}
			if (!this.botEnabled && this.isModerator) {
				return t('educai', 'Enable & Use Bot')
			}
			return t('educai', 'Use Bot')
		},

		unavailableMessage() {
			if (this.roomStatusLoaded && !this.botEnabled && !this.isModerator) {
				return t('educai', 'In this chat room, only moderators can activate an {name} bot. Please ask a moderator to activate it first.', { name: APP_DISPLAY_NAME })
			}

			if (this.roomStatusError) {
				return this.roomStatusError
			}

			return t('educai', 'The {name} bot is not activated in this conversation. Please ask a moderator to activate it first.', { name: APP_DISPLAY_NAME })
		},
	},

	methods: {
		formatMention(mentionName) {
			const name = mentionName.startsWith('@') ? mentionName.substring(1) : mentionName
			return `@${name}`
		},

		async handleUseBot() {
			if (!this.canUseBot) {
				showError(this.unavailableMessage)
				return
			}

			// If bot is not enabled and user is moderator, enable it first
			if (!this.botEnabled && this.isModerator && this.roomToken) {
				this.enabling = true
				try {
					await axios.post(generateUrl(`/apps/educai/api/v1/talk/enable-bot/${this.roomToken}`))
					showSuccess(t('educai', '{name} bot has been enabled in this conversation', { name: APP_DISPLAY_NAME }))
					this.$emit('bot-enabled')
				} catch (error) {
					console.error('Failed to enable bot:', error)
					const errorMessage = error.response?.data?.error
						?? t('educai', 'Failed to enable {name} bot', { name: APP_DISPLAY_NAME })
					showError(errorMessage)
					this.enabling = false
					return
				}
				this.enabling = false
			}

			// Emit the use-bot event with the mention
			const mentionName = this.bot.mention_name.startsWith('@')
				? this.bot.mention_name.substring(1)
				: this.bot.mention_name

			this.$emit('use-bot', `@${mentionName}`)
		},
	},
}
</script>

<style scoped lang="scss">
.bot-picker-modal-overlay {
	position: fixed;
	z-index: 10000;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	backdrop-filter: blur(2px);
}

.bot-picker-modal {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.24);
	max-width: 500px;
	width: 90%;
	max-height: 80vh;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.modal-header {
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.header-content {
	display: flex;
	align-items: center;
	gap: 12px;
}

.bot-icon {
	width: 44px;
	height: 44px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.header-text {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.header-text h2 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
	line-height: 1.2;
}

.mention-badge {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-family: var(--font-monospace);
}

.modal-body {
	padding: 20px;
	overflow-y: auto;
	flex: 1;
}

.detail-section {
	margin-bottom: 16px;

	&:last-child {
		margin-bottom: 0;
	}

	h3 {
		margin: 0 0 8px 0;
		font-size: 13px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		text-transform: uppercase;
		letter-spacing: 0.3px;
	}
}

.description {
	margin: 0;
	font-size: 15px;
	line-height: 1.5;
	color: var(--color-main-text);
}

.meta-info {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.meta-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.meta-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.3px;
}

.meta-value {
	font-size: 14px;
	color: var(--color-main-text);
}

.tools-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.tool-item {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
	font-size: 13px;
}

.tool-name {
	font-weight: 500;
}

.rag-info {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.modal-footer {
	padding: 16px 20px;
	border-top: 1px solid var(--color-border);
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
