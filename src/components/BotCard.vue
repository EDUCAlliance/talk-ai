<template>
	<div class="bot-card" :class="{ inactive: !bot.is_active }">
		<!-- Approval status badge (top right) -->
		<div v-if="approvalStatus !== 'approved'" class="approval-badge" :class="approvalStatus">
			{{ approvalStatusLabel }}
		</div>

		<div class="bot-header">
			<h3>{{ bot.bot_name }}</h3>
			<span class="mention-badge">{{ bot.mention_name }}</span>
		</div>

		<div class="bot-prompt">
			<p>{{ truncatedPrompt }}</p>
		</div>

		<div class="bot-visibility">
			<span class="visibility-badge" :class="visibilityClass">{{ visibilityLabel }}</span>
		</div>

		<div class="bot-meta">
			<span class="icon-calendar" />
			<span>Created {{ formatDate(bot.created_at) }}</span>
		</div>

		<div v-if="!readonly" class="bot-actions">
			<button
				v-if="canEdit"
				class="button"
				@click="$emit('edit', bot)">
				<span class="icon-rename" /> Edit
			</button>
			<button
				v-else-if="editBlockedReason"
				class="button disabled"
				:title="editBlockedReason"
				disabled>
				<span class="icon-lock" /> View Only
			</button>
			<button
				v-if="approvalStatus === 'draft'"
				class="button primary"
				@click="$emit('submit', bot.id)">
				<span class="icon-checkmark" /> Submit for Approval
			</button>
			<button class="button error" @click="$emit('delete', bot.id)">
				<span class="icon-delete" /> Delete
			</button>
		</div>

		<div v-if="!bot.is_active && approvalStatus === 'approved'" class="bot-status">
			<span class="icon-close" /> Inactive
		</div>
	</div>
</template>

<script>
export default {
	name: 'BotCard',
	props: {
		bot: {
			type: Object,
			required: true,
		},
		readonly: {
			type: Boolean,
			default: false,
		},
		userPermissions: {
			type: Object,
			default: () => ({
				hasApprovalRights: false,
			}),
		},
	},
	computed: {
		truncatedPrompt() {
			const prompt = this.bot.system_prompt || ''
			return prompt.length > 150
				? prompt.substring(0, 150) + '...'
				: prompt
		},
		approvalStatus() {
			return this.bot.approval_status || 'approved'
		},
		approvalStatusLabel() {
			const status = this.approvalStatus
			if (status === 'draft') {
				return 'Draft'
			}
			if (status === 'pending') {
				return 'Pending Approval'
			}
			if (status === 'personal') {
				return 'Personal'
			}
			return ''
		},
		visibilityLabel() {
			const v = this.bot.visibility ? this.bot.visibility : (this.bot.is_public ? 'global' : 'groups')
			if (v === 'global') {
				return 'Global'
			}
			if (v === 'personal') {
				return 'Personal'
			}
			if (v === 'teams') {
				return 'Team access'
			}
			return 'Group access'
		},
		visibilityClass() {
			const v = this.bot.visibility ? this.bot.visibility : (this.bot.is_public ? 'global' : 'groups')
			if (v === 'global') {
				return 'global'
			}
			if (v === 'personal') {
				return 'personal'
			}
			if (v === 'teams') {
				return 'teams'
			}
			return 'groups'
		},
		canEdit() {
			// Readonly mode disables editing
			if (this.readonly) {
				return false
			}
			const status = this.approvalStatus
			// Drafts and personal bots can always be edited by owner
			if (status === 'draft' || status === 'personal') {
				return true
			}
			// Pending bots can be edited by owner (to make corrections)
			if (status === 'pending') {
				return true
			}
			if (status === 'approved') {
				return true
			}
			return true
		},
		editBlockedReason() {
			return null
		},
	},
	methods: {
		formatDate(timestamp) {
			const date = new Date(timestamp * 1000)
			const formatted = date.toLocaleDateString(undefined, {
				day: 'numeric',
				month: 'long',
				year: 'numeric',
			})
			return `on ${formatted}`
		},
	},
}
</script>

<style scoped>
.bot-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 20px;
	position: relative;
	transition: box-shadow 0.2s;
}

.bot-card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.bot-card.inactive {
	opacity: 0.6;
}

.bot-header {
	margin-bottom: 12px;
}

.bot-header h3 {
	margin: 0 0 8px 0;
	font-size: 18px;
	font-weight: 600;
}

.mention-badge {
	display: inline-block;
	background: var(--color-primary-element);
	color: white;
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 13px;
	font-weight: 600;
}

.bot-prompt {
	margin-bottom: 16px;
	min-height: 60px;
}
.bot-visibility {
	margin-bottom: 12px;
}

.visibility-badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
}

.visibility-badge.global {
	background: var(--color-success);
	color: #000;
}

.visibility-badge.teams {
	background: var(--color-primary-element);
	color: #fff;
}

.visibility-badge.groups {
	background: var(--color-warning);
	color: #000;
}

.visibility-badge.personal {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-dark);
}

/* Approval status badge (top right corner) */
.approval-badge {
	position: absolute;
	top: 10px;
	right: 10px;
	padding: 4px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.approval-badge.draft {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
	border: 1px solid var(--color-border);
}

.approval-badge.pending {
	background: var(--color-warning);
	color: #000;
}

.approval-badge.personal {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-dark);
}

.button.primary {
	background-color: var(--color-primary);
	color: white;
	border-color: var(--color-primary);
}

.button.primary:hover {
	background-color: var(--color-primary-element-light);
}

.button.disabled {
	opacity: 0.6;
	cursor: not-allowed;
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
}

.bot-prompt p {
	margin: 0;
	color: var(--color-text-lighter);
	font-size: 14px;
	line-height: 1.5;
}

.bot-meta {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-lighter);
	font-size: 13px;
	margin-bottom: 16px;
}

.bot-actions {
	display: flex;
	gap: 10px;
}

.button {
	flex: 1;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	border-radius: 3px;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	font-size: 14px;
	transition: all 0.2s;
}

.button:hover {
	background: var(--color-background-hover);
}

.button.error {
	color: var(--color-error);
}

.button.error:hover {
	background: var(--color-error);
	color: white;
	border-color: var(--color-error);
}

.bot-status {
	position: absolute;
	top: 10px;
	right: 10px;
	background: var(--color-error);
	color: white;
	padding: 4px 10px;
	border-radius: 12px;
	font-size: 12px;
	display: flex;
	align-items: center;
	gap: 4px;
}
</style>
