<template>
	<div class="modal-overlay" @click.self="$emit('close')">
		<div class="modal-container">
			<div class="modal-header">
				<div class="header-content">
					<h2>{{ bot.bot_name }}</h2>
					<span class="mention-badge">{{ bot.mention_name }}</span>
				</div>
				<button class="close-button" @click="$emit('close')">
					<span class="icon-close" />
				</button>
			</div>

			<div v-if="loading" class="modal-loading">
				<span class="icon-loading" />
				<p>Loading bot details...</p>
			</div>

			<div v-else class="modal-body">
				<!-- Description Section -->
				<section class="detail-section">
					<h3>Description</h3>
					<p v-if="details.description" class="description-text">{{ details.description }}</p>
					<p v-else class="no-content">No description provided</p>
				</section>

				<!-- Visibility & Access -->
				<section class="detail-section">
					<h3>Visibility</h3>
					<div class="visibility-info">
						<span class="visibility-badge" :class="visibilityClass">{{ visibilityLabel }}</span>
						<span v-if="accessReasonText" class="access-reason">{{ accessReasonText }}</span>
					</div>
				</section>

				<!-- Creator Info -->
				<section class="detail-section">
					<h3>Created By</h3>
					<div class="creator-info">
						<span class="creator-name">{{ details.owner_display_name || 'Unknown' }}</span>
						<span class="creation-date">{{ formatDate(details.created_at) }}</span>
					</div>
				</section>

				<!-- System Prompt -->
				<section class="detail-section">
					<h3>System Prompt</h3>
					<div class="system-prompt-container">
						<pre class="system-prompt">{{ details.system_prompt }}</pre>
					</div>
				</section>

				<!-- Tools -->
				<section v-if="details.tools && details.tools.length > 0" class="detail-section">
					<h3>Enabled Tools</h3>
					<ul class="tools-list">
						<li v-for="(tool, index) in details.tools" :key="index" class="tool-item">
							<div class="tool-header">
								<span class="tool-name">{{ tool.name }}</span>
								<span v-if="tool.is_builtin" class="tool-badge builtin">Built-in</span>
							</div>
							<p v-if="tool.description" class="tool-description">{{ tool.description }}</p>
						</li>
					</ul>
				</section>

				<!-- RAG Status -->
				<section v-if="details.rag_enabled" class="detail-section">
					<h3>Knowledge Base</h3>
					<div class="rag-status">
						<span class="rag-icon">📚</span>
						<span v-if="details.rag_source_count > 0">
							This bot has access to {{ details.rag_source_count }} knowledge source{{ details.rag_source_count !== 1 ? 's' : '' }}
						</span>
						<span v-else>
							Knowledge base enabled but no sources attached yet
						</span>
					</div>
				</section>
			</div>

			<div class="modal-footer">
				<button class="button" @click="$emit('close')">Close</button>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'BotDetailModal',
	props: {
		bot: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			loading: true,
			details: {},
		}
	},
	computed: {
		visibilityLabel() {
			const v = this.details.visibility || (this.details.is_public ? 'global' : 'groups')
			if (v === 'personal') return 'Personal'
			if (v === 'global') return 'Global (available to all users)'
			if (v === 'teams') return 'Team access'
			return 'Group access'
		},
		visibilityClass() {
			const v = this.details.visibility || (this.details.is_public ? 'global' : 'groups')
			if (v === 'personal') return 'personal'
			if (v === 'global') return 'global'
			if (v === 'teams') return 'teams'
			return 'groups'
		},
		accessReasonText() {
			const reason = this.details.access_reason
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
	mounted() {
		this.loadDetails()
	},
	methods: {
		async loadDetails() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl(`/apps/educai/api/v1/public-bots/${this.bot.id}`))
				this.details = response.data
			} catch (error) {
				console.error('Failed to load bot details:', error)
				showError('Failed to load bot details')
				this.details = this.bot // Fallback to basic info
			} finally {
				this.loading = false
			}
		},
		formatDate(timestamp) {
			if (!timestamp) return 'Unknown'
			const date = new Date(timestamp * 1000)
			return date.toLocaleDateString(undefined, {
				day: 'numeric',
				month: 'long',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.modal-overlay {
	position: fixed;
	z-index: 9999;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background: rgba(0, 0, 0, 0.6);
	display: flex;
	align-items: center;
	justify-content: center;
	backdrop-filter: blur(2px);
}

.modal-container {
	background: var(--color-main-background);
	border-radius: 12px;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.24);
	max-width: 700px;
	width: 90%;
	max-height: 85vh;
	display: flex;
	flex-direction: column;
}

.modal-header {
	padding: 20px 24px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.header-content {
	display: flex;
	align-items: center;
	gap: 16px;
}

.header-content h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.mention-badge {
	background: var(--color-primary-element);
	color: white;
	padding: 4px 12px;
	border-radius: 16px;
	font-size: 14px;
	font-weight: 600;
}

.close-button {
	background: none;
	border: none;
	cursor: pointer;
	padding: 8px;
	font-size: 20px;
	opacity: 0.7;
	border-radius: 50%;
	transition: opacity 0.2s, background 0.2s;
}

.close-button:hover {
	opacity: 1;
	background: var(--color-background-hover);
}

.modal-loading {
	padding: 60px 20px;
	text-align: center;
}

.modal-loading .icon-loading {
	font-size: 32px;
	display: block;
	margin-bottom: 16px;
}

.modal-body {
	padding: 24px;
	overflow-y: auto;
	flex: 1;
}

.detail-section {
	margin-bottom: 28px;
}

.detail-section:last-child {
	margin-bottom: 0;
}

.detail-section h3 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.description-text {
	margin: 0;
	font-size: 15px;
	line-height: 1.6;
	color: var(--color-main-text);
}

.no-content {
	margin: 0;
	font-style: italic;
	color: var(--color-text-lighter);
}

.visibility-info {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.visibility-badge {
	padding: 6px 14px;
	border-radius: 20px;
	font-size: 13px;
	font-weight: 600;
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

.access-reason {
	font-size: 13px;
	color: var(--color-text-lighter);
	padding: 6px 12px;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.creator-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.creator-name {
	font-size: 15px;
	font-weight: 500;
	color: var(--color-main-text);
}

.creation-date {
	font-size: 13px;
	color: var(--color-text-lighter);
}

.system-prompt-container {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	max-height: 250px;
	overflow-y: auto;
}

.system-prompt {
	margin: 0;
	font-family: 'Menlo', 'Monaco', 'Consolas', monospace;
	font-size: 13px;
	line-height: 1.6;
	white-space: pre-wrap;
	word-wrap: break-word;
	color: var(--color-main-text);
}

.tools-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.tool-item {
	padding: 12px 16px;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.tool-header {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 4px;
}

.tool-name {
	font-weight: 600;
	font-size: 14px;
}

.tool-badge {
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.tool-badge.builtin {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-dark);
}

.tool-description {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-lighter);
	line-height: 1.4;
}

.rag-status {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px 16px;
	background: var(--color-background-dark);
	border-radius: 8px;
	font-size: 14px;
}

.rag-icon {
	font-size: 20px;
}

.modal-footer {
	padding: 16px 24px;
	border-top: 1px solid var(--color-border);
	display: flex;
	justify-content: flex-end;
}

.button {
	padding: 10px 20px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	border-radius: 20px;
	cursor: pointer;
	font-size: 14px;
	transition: background 0.2s;
}

.button:hover {
	background: var(--color-background-hover);
}
</style>
