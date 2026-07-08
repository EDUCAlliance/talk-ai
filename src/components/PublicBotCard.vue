<template>
	<div class="public-bot-card" :class="{ inactive: !bot.is_active }">
		<div class="card-header">
			<div class="bot-identity">
				<h3 class="bot-name">{{ bot.bot_name }}</h3>
				<div class="mention-row">
					<span class="mention-badge">{{ bot.mention_name }}</span>
					<button
						class="copy-handle-button"
						type="button"
						:aria-label="`Copy ${formatMention(bot.mention_name)} handle`"
						:title="`Copy ${formatMention(bot.mention_name)} handle`"
						@click="copyHandle">
						<IconContentCopy :size="16" />
					</button>
				</div>
			</div>
			<span class="visibility-badge" :class="visibilityClass">{{ visibilityLabel }}</span>
		</div>

		<div class="bot-description">
			<p v-if="bot.description">{{ truncatedDescription }}</p>
			<p v-else class="no-description">No description provided</p>
		</div>

		<!-- Access reason for non-global bots -->
		<div v-if="accessReasonText" class="access-reason">
			<span class="access-icon">🔑</span>
			<span class="access-text">{{ accessReasonText }}</span>
		</div>

		<div class="bot-meta">
			<div class="meta-row">
				<span class="meta-label">Created by:</span>
				<span class="meta-value">{{ bot.owner_display_name || 'Unknown' }}</span>
			</div>
			<div class="meta-row">
				<span class="meta-label">Created:</span>
				<span class="meta-value">{{ formatDate(bot.created_at) }}</span>
			</div>
		</div>

		<div class="card-actions">
			<button class="button primary-button" @click="$emit('speak-in-talk', bot)">
				Use Bot
			</button>
			<button class="button secondary-button" @click="$emit('show-details', bot)">
				Details
			</button>
		</div>

		<div v-if="!bot.is_active" class="inactive-badge">
			<span class="icon-close" /> Inactive
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'

export default {
	name: 'PublicBotCard',
	components: {
		IconContentCopy,
	},
	props: {
		bot: {
			type: Object,
			required: true,
		},
	},
	computed: {
		truncatedDescription() {
			const desc = this.bot.description || ''
			return desc.length > 200 ? desc.substring(0, 200) + '...' : desc
		},
		visibilityLabel() {
			const v = this.bot.visibility || (this.bot.is_public ? 'global' : 'groups')
			if (v === 'personal') return 'Personal'
			if (v === 'global') return 'Global'
			if (v === 'teams') return 'Team access'
			return 'Group access'
		},
		visibilityClass() {
			const v = this.bot.visibility || (this.bot.is_public ? 'global' : 'groups')
			if (v === 'personal') return 'personal'
			if (v === 'global') return 'global'
			if (v === 'teams') return 'teams'
			return 'groups'
		},
		accessReasonText() {
			const reason = this.bot.access_reason
			if (!reason) return null
			if (reason.type === 'global') return null
			if (reason.type === 'owner') return 'You are the owner of this bot'
			if (reason.type === 'group' && reason.names?.length > 0) {
				return `Access via group: ${reason.names.join(', ')}`
			}
			if (reason.type === 'team' && reason.names?.length > 0) {
				return `Access via team: ${reason.names.join(', ')}`
			}
			return null
		},
	},
	methods: {
		formatMention(mentionName) {
			const name = mentionName.startsWith('@') ? mentionName.substring(1) : mentionName
			return `@${name}`
		},
		async copyHandle() {
			const mention = this.formatMention(this.bot.mention_name)
			try {
				if (navigator.clipboard?.writeText) {
					await navigator.clipboard.writeText(mention)
				} else {
					const textarea = document.createElement('textarea')
					textarea.value = mention
					textarea.setAttribute('readonly', '')
					textarea.style.position = 'fixed'
					textarea.style.opacity = '0'
					document.body.appendChild(textarea)
					textarea.select()
					document.execCommand('copy')
					document.body.removeChild(textarea)
				}
				showSuccess(`Copied ${mention}`)
			} catch (error) {
				console.error('Failed to copy bot handle:', error)
				showError('Failed to copy bot handle')
			}
		},
		formatDate(timestamp) {
			if (!timestamp) return 'Unknown'
			const date = new Date(timestamp * 1000)
			return date.toLocaleDateString(undefined, {
				day: 'numeric',
				month: 'long',
				year: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.public-bot-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 20px;
	position: relative;
	transition: box-shadow 0.2s, transform 0.2s;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.public-bot-card:hover {
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
	transform: translateY(-2px);
}

.public-bot-card.inactive {
	opacity: 0.6;
}

.card-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 12px;
}

.bot-identity {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.bot-name {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
}

.mention-row {
	display: flex;
	align-items: center;
	gap: 6px;
	width: fit-content;
}

.mention-badge {
	display: inline-block;
	background: var(--color-primary-element);
	color: white;
	padding: 4px 12px;
	border-radius: 16px;
	font-size: 13px;
	font-weight: 600;
	width: fit-content;
}

.copy-handle-button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	padding: 0;
	border: 1px solid transparent;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-lighter);
	cursor: pointer;
	transition: background 0.2s, color 0.2s, border-color 0.2s;
}

.copy-handle-button:hover,
.copy-handle-button:focus-visible {
	background: var(--color-background-hover);
	border-color: var(--color-border);
	color: var(--color-main-text);
}

.visibility-badge {
	padding: 4px 12px;
	border-radius: 16px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	white-space: nowrap;
}

.visibility-badge.global {
	background: var(--color-success);
	color: #000;
}

.visibility-badge.personal {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-dark);
}

.visibility-badge.teams {
	background: var(--color-primary-element);
	color: #fff;
}

.visibility-badge.groups {
	background: var(--color-warning);
	color: #000;
}

.bot-description {
	flex: 1;
	min-height: 48px;
}

.bot-description p {
	margin: 0;
	font-size: 14px;
	line-height: 1.5;
	color: var(--color-text-lighter);
}

.bot-description .no-description {
	font-style: italic;
	opacity: 0.7;
}

.access-reason {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-radius: 8px;
	font-size: 13px;
}

.access-icon {
	font-size: 14px;
}

.access-text {
	color: var(--color-text-lighter);
}

.bot-meta {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.meta-row {
	display: flex;
	gap: 8px;
	font-size: 13px;
}

.meta-label {
	color: var(--color-text-lighter);
}

.meta-value {
	color: var(--color-main-text);
	font-weight: 500;
}

.card-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 8px;
}

.button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 8px 16px;
	border-radius: 20px;
	cursor: pointer;
	font-size: 14px;
	font-weight: 500;
	transition: background 0.2s, border-color 0.2s;
}

.primary-button {
	background: var(--color-primary-element);
	color: white;
	border: 1px solid var(--color-primary-element);
}

.primary-button:hover {
	background: var(--color-primary-element-light);
}

.secondary-button {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.secondary-button:hover {
	background: var(--color-background-hover);
}

.inactive-badge {
	position: absolute;
	top: 12px;
	right: 12px;
	background: var(--color-error);
	color: white;
	padding: 4px 10px;
	border-radius: 12px;
	font-size: 11px;
	display: flex;
	align-items: center;
	gap: 4px;
}
</style>
