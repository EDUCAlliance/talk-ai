<template>
	<div class="bot-management">
		<div class="header">
			<h2>{{ APP_DISPLAY_NAME }} - Multi-Bot Manager</h2>
			<button class="button primary" @click="showCreateForm = true">
				<span class="icon-add" />
				Create New Bot
			</button>
		</div>

		<!-- My Bots Section -->
		<div v-if="bots.length > 0" class="bot-grid">
			<BotCard
				v-for="bot in bots"
				:key="bot.id"
				:bot="bot"
				:user-permissions="userPermissions"
				@edit="editBot"
				@delete="deleteBot"
				@submit="submitForApproval" />
		</div>

		<div v-if="bots.length === 0 && !loading" class="empty-state">
			<span class="icon-comment" />
			<h3>No bots created yet</h3>
			<p>Create your first AI bot to get started with Nextcloud Talk integration</p>
			<button class="button primary" @click="showCreateForm = true">
				Create Your First Bot
			</button>
		</div>

		<div v-if="loading" class="loading">
			<span class="icon-loading" />
			<p>Loading bots...</p>
		</div>

		<!-- Approval Queue Section (only visible to users with approval rights) -->
		<div v-if="userPermissions.hasApprovalRights" class="approval-section">
			<div class="section-header">
				<h3>Pending Approvals</h3>
				<span v-if="pendingApprovals.length > 0" class="badge">{{ pendingApprovals.length }}</span>
			</div>

			<div v-if="loadingApprovals" class="loading-small">
				<span class="icon-loading" />
				<span>Loading pending approvals...</span>
			</div>

			<div v-else-if="pendingApprovals.length === 0" class="empty-approvals">
				<p>No bots are pending approval.</p>
			</div>

			<div v-else class="approval-grid">
				<div
					v-for="bot in pendingApprovals"
					:key="'pending-' + bot.id"
					class="approval-card">
					<div class="approval-info">
						<h4>{{ getReviewTarget(bot).bot_name }}</h4>
						<span class="mention-badge">{{ bot.mention_name }}</span>
						<p class="owner">
							Submitted by {{ bot.owner_name }}
						</p>
						<p class="visibility">
							<span class="visibility-badge" :class="getReviewTarget(bot).visibility || 'groups'">
								{{ formatVisibility(getReviewTarget(bot).visibility) }}
							</span>
							<span v-if="getReviewTarget(bot).is_update" class="update-badge">
								Update to existing bot
							</span>
							<span v-else class="new-badge">
								New bot
							</span>
						</p>
						<p v-if="getReviewTarget(bot).is_update" class="review-note">
							Reviewing the submitted pending version. The currently approved version stays live until approval.
						</p>
						<div class="questionnaire">
							<p v-if="bot.approval_reason">
								<strong>Why share:</strong>
								{{ bot.approval_reason }}
							</p>
							<p v-if="bot.bot_capabilities">
								<strong>What it does well:</strong>
								{{ bot.bot_capabilities }}
							</p>
							<p v-if="bot.rag_source_description">
								<strong>RAG sources:</strong>
								{{ bot.rag_source_description }}
							</p>
							<p v-if="bot.testing_description">
								<strong>Testing done:</strong>
								{{ bot.testing_description }}
							</p>
						</div>
						<p v-if="bot.submitted_at" class="submitted-time">
							Submitted {{ formatDate(bot.submitted_at) }}
						</p>
					</div>
					<div class="approval-actions">
						<button class="button" @click="enableTest(bot)">
							<span class="icon-play" /> Test Bot
						</button>
						<button class="button" @click="previewBot(bot)">
							<span class="icon-details" /> Preview
						</button>
						<button class="button primary" @click="approveBot(bot.id)">
							<span class="icon-checkmark" /> Approve
						</button>
						<button class="button error" @click="rejectBot(bot.id)">
							<span class="icon-close" /> Reject
						</button>
					</div>
				</div>
			</div>
		</div>

		<BotForm
			v-if="showCreateForm || editingBot"
			:bot="editingBot"
			:user-permissions="userPermissions"
			@save="saveBot"
			@cancel="closeForm" />

		<!-- Approval submission modal -->
		<div v-if="showSubmitModal" class="modal-mask" @click.self="closeSubmitModal">
			<div class="modal-container">
				<div class="modal-header">
					<h2>Submit for Approval</h2>
					<button class="close-button" @click="closeSubmitModal">
						<span class="icon-close" />
					</button>
				</div>
				<div class="modal-body">
					<p class="hint">
						You can test the bot yourself in Nextcloud Talk before submitting. Mention <strong>{{ submitForm.mentionName }}</strong> in any conversation.
					</p>
					<div class="form-group">
						<label>Why do you want to share your bot with this specific group (or global)?</label>
						<textarea v-model="submitForm.approvalReason" rows="3" />
					</div>
					<div class="form-group">
						<label>What is your bot good at?</label>
						<textarea v-model="submitForm.botCapabilities" rows="3" />
					</div>
					<div class="form-group">
						<label>What is/are the source(s) of the information you fed the RAG?</label>
						<textarea v-model="submitForm.ragSourceDescription" rows="3" />
					</div>
					<div class="form-group">
						<label>Describe fully how you tested your bot so we can reproduce its behaviour.</label>
						<textarea v-model="submitForm.testingDescription" rows="3" />
					</div>
				</div>
				<div class="modal-footer">
					<button class="button" @click="closeSubmitModal">
						Cancel
					</button>
					<button class="button primary" @click="confirmSubmitForApproval">
						Submit
					</button>
				</div>
			</div>
		</div>

		<div v-if="showReviewPreview && reviewPreviewBot" class="modal-mask" @click.self="closeReviewPreview">
			<div class="modal-container review-preview-modal">
				<div class="modal-header">
					<h2>{{ getReviewTarget(reviewPreviewBot).bot_name }}</h2>
					<button class="close-button" @click="closeReviewPreview">
						<span class="icon-close" />
					</button>
				</div>
				<div class="modal-body">
					<p class="hint">
						Review target for <strong>{{ reviewPreviewBot.mention_name }}</strong>
						<span v-if="getReviewTarget(reviewPreviewBot).is_update">
							. This is the pending version that owner and enabled reviewer can test in Talk.
						</span>
					</p>
					<div class="form-group">
						<label>Description</label>
						<p class="preview-block">
							{{ getReviewTarget(reviewPreviewBot).description || 'No description provided' }}
						</p>
					</div>
					<div class="form-group">
						<label>Visibility</label>
						<p class="preview-block">
							{{ formatVisibility(getReviewTarget(reviewPreviewBot).visibility) }}
						</p>
					</div>
					<div class="form-group">
						<label>Temperature</label>
						<p class="preview-block">
							{{ getReviewTarget(reviewPreviewBot).temperature === null || getReviewTarget(reviewPreviewBot).temperature === undefined
								? 'Uses global default'
								: formatTemperature(getReviewTarget(reviewPreviewBot).temperature) }}
						</p>
					</div>
					<div
						v-if="getReviewTarget(reviewPreviewBot).allowed_groups?.length || getReviewTarget(reviewPreviewBot).allowed_teams?.length"
						class="form-group">
						<label>Target Audience</label>
						<p v-if="getReviewTarget(reviewPreviewBot).allowed_groups?.length" class="preview-block">
							Groups: {{ getReviewTarget(reviewPreviewBot).allowed_groups.join(', ') }}
						</p>
						<p v-if="getReviewTarget(reviewPreviewBot).allowed_teams?.length" class="preview-block">
							Teams: {{ reviewTeamNames(getReviewTarget(reviewPreviewBot)).join(', ') }}
						</p>
					</div>
					<div v-if="getReviewTarget(reviewPreviewBot).rag_enabled" class="form-group">
						<label>RAG Sources</label>
						<p v-if="reviewSourcesLoading" class="preview-block">
							Loading sources...
						</p>
						<p v-else-if="reviewSourcesError" class="preview-block error-text">
							{{ reviewSourcesError }}
						</p>
						<p v-else-if="reviewSources.length === 0" class="preview-block">
							No knowledge sources attached
						</p>
						<ul v-else class="preview-source-list">
							<li v-for="source in reviewSources" :key="`${reviewPreviewBot.id}-source-${source.id}`">
								<span>
									<strong>{{ describeReviewSource(source) }}</strong>
									<span v-if="source.status"> - {{ formatSourceStatus(source.status) }}</span>
								</span>
								<a
									v-if="sourceOpenUrl(source)"
									class="source-open-link"
									:href="sourceOpenUrl(source)"
									target="_blank"
									rel="noopener noreferrer">
									Open
								</a>
							</li>
						</ul>
					</div>
					<div class="form-group">
						<label>System Prompt</label>
						<pre class="preview-code">{{ getReviewTarget(reviewPreviewBot).system_prompt }}</pre>
					</div>
					<div class="form-group">
						<label>Enabled Tools</label>
						<ul v-if="getReviewTarget(reviewPreviewBot).tools && getReviewTarget(reviewPreviewBot).tools.length > 0" class="preview-tool-list">
							<li v-for="(tool, index) in getReviewTarget(reviewPreviewBot).tools" :key="`${reviewPreviewBot.id}-tool-${index}`">
								<strong>{{ tool.name }}</strong>
								<span v-if="tool.description"> - {{ tool.description }}</span>
							</li>
						</ul>
						<p v-else class="preview-block">
							No tools configured
						</p>
					</div>
				</div>
				<div class="modal-footer">
					<button class="button" @click="closeReviewPreview">
						Close
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { APP_DISPLAY_NAME } from '../branding.js'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import BotCard from '../components/BotCard.vue'
import BotForm from '../components/BotForm.vue'

export default {
	name: 'PersonalBots',
	components: { BotCard, BotForm },
	data() {
		return {
			APP_DISPLAY_NAME,
			bots: [],
			showCreateForm: false,
			editingBot: null,
			loading: false,
			userPermissions: {
				isAdmin: false,
				isGroupAdmin: false,
				isTeamAdmin: false,
				hasApprovalRights: false,
				adminGroups: [],
				adminTeams: [],
			},
			pendingApprovals: [],
			loadingApprovals: false,
			showSubmitModal: false,
			showReviewPreview: false,
			reviewPreviewBot: null,
			reviewSources: [],
			reviewSourcesLoading: false,
			reviewSourcesError: '',
			submitForm: {
				botId: null,
				mentionName: '',
				approvalReason: '',
				botCapabilities: '',
				ragSourceDescription: '',
				testingDescription: '',
			},
		}
	},
	mounted() {
		this.loadPermissions()
		this.loadBots()
	},
	methods: {
		async loadPermissions() {
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/permissions'))
				// eslint-disable-next-line no-console
				console.log('[TalkAI] Permissions response:', response.data)
				this.userPermissions = response.data.permissions || this.userPermissions
				// eslint-disable-next-line no-console
				console.log('[TalkAI] userPermissions set to:', this.userPermissions)
				// Load pending approvals if user has approval rights
				if (this.userPermissions.hasApprovalRights) {
					this.loadPendingApprovals()
				}
			} catch (error) {
				console.error('Failed to load permissions:', error)
			}
		},
		async loadPendingApprovals() {
			if (!this.userPermissions.hasApprovalRights) {
				return
			}
			this.loadingApprovals = true
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/approvals'))
				this.pendingApprovals = response.data.bots || []
			} catch (error) {
				console.error('Failed to load pending approvals:', error)
			} finally {
				this.loadingApprovals = false
			}
		},
		async loadBots() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/bots'))
				this.bots = response.data
			} catch (error) {
				console.error('Failed to load bots:', error)
				showError('Failed to load bots')
			} finally {
				this.loading = false
			}
		},
		async saveBot(botData) {
			try {
				if (botData.id) {
					const response = await axios.put(
						generateUrl(`/apps/educai/api/v1/bots/${botData.id}`),
						botData,
					)
					const updatedBot = response.data
					const status = updatedBot.approval_status || 'approved'
					if (status === 'draft') {
						showSuccess('Bot updated and saved as draft. Submit for approval when ready.')
					} else if (status === 'pending') {
						showSuccess('Bot updated. Approval is required before the new shared version goes live.')
					} else if (status === 'personal') {
						showSuccess('Personal bot updated successfully')
					} else {
						showSuccess('Bot updated successfully')
					}
				} else {
					const response = await axios.post(
						generateUrl('/apps/educai/api/v1/bots'),
						botData,
					)
					const newBot = response.data
					const status = newBot.approval_status || 'approved'
					if (status === 'draft') {
						showSuccess('Bot saved as draft. Submit for approval when ready.')
					} else if (status === 'personal') {
						showSuccess('Personal bot created successfully')
					} else {
						showSuccess('Bot created successfully')
					}
				}
				await this.loadBots()
				this.closeForm()
			} catch (error) {
				console.error('Failed to save bot:', error)
				showError(error.response?.data?.error || 'Failed to save bot')
			}
		},
		submitForApproval(botId) {
			const bot = this.bots.find(b => b.id === botId)
			if (!bot) {
				showError('Bot not found')
				return
			}
			this.submitForm.botId = botId
			this.submitForm.mentionName = bot.mention_name
			this.submitForm.approvalReason = ''
			this.submitForm.botCapabilities = ''
			this.submitForm.ragSourceDescription = ''
			this.submitForm.testingDescription = ''
			this.showSubmitModal = true
		},
		async confirmSubmitForApproval() {
			if (!this.submitForm.botId) {
				return
			}
			try {
				await axios.post(generateUrl(`/apps/educai/api/v1/bots/${this.submitForm.botId}/submit`), {
					approval_reason: this.submitForm.approvalReason,
					bot_capabilities: this.submitForm.botCapabilities,
					rag_source_description: this.submitForm.ragSourceDescription,
					testing_description: this.submitForm.testingDescription,
				})
				showSuccess('Bot submitted for approval')
				await this.loadBots()
				this.closeSubmitModal()
			} catch (error) {
				console.error('Failed to submit bot for approval:', error)
				showError(error.response?.data?.error || 'Failed to submit for approval')
			}
		},
		closeSubmitModal() {
			this.showSubmitModal = false
			this.submitForm.botId = null
		},
		async approveBot(botId) {
			try {
				await axios.post(generateUrl(`/apps/educai/api/v1/bots/${botId}/approve`))
				showSuccess('Bot approved successfully')
				await this.loadPendingApprovals()
			} catch (error) {
				console.error('Failed to approve bot:', error)
				showError(error.response?.data?.error || 'Failed to approve bot')
			}
		},
		async rejectBot(botId) {
			const reason = prompt('Optional: Enter a reason for rejection')
			try {
				await axios.post(generateUrl(`/apps/educai/api/v1/bots/${botId}/reject`), { reason })
				showSuccess('Bot rejected and returned to draft')
				await this.loadPendingApprovals()
			} catch (error) {
				console.error('Failed to reject bot:', error)
				showError(error.response?.data?.error || 'Failed to reject bot')
			}
		},
		getReviewTarget(bot) {
			return bot.review_target || {
				bot_name: bot.bot_name,
				description: bot.description,
				system_prompt: bot.system_prompt,
				temperature: bot.temperature,
				visibility: bot.visibility || (bot.is_public ? 'global' : 'groups'),
				allowed_groups: bot.allowed_groups || [],
				allowed_teams: bot.allowed_teams || [],
				allowed_team_names: bot.allowed_team_names || [],
				tools: [],
				is_update: !!bot.has_pending_changes,
			}
		},
		previewBot(bot) {
			this.reviewPreviewBot = bot
			this.showReviewPreview = true
			this.reviewSources = []
			this.reviewSourcesError = ''
			if (this.getReviewTarget(bot).rag_enabled) {
				this.loadReviewSources(bot)
			}
		},
		closeReviewPreview() {
			this.showReviewPreview = false
			this.reviewPreviewBot = null
			this.reviewSources = []
			this.reviewSourcesError = ''
			this.reviewSourcesLoading = false
		},
		async loadReviewSources(bot) {
			this.reviewSourcesLoading = true
			this.reviewSourcesError = ''
			try {
				const response = await axios.get(generateUrl(`/apps/educai/api/v1/bots/${bot.id}/sources`))
				const sources = response.data?.sources
				this.reviewSources = Array.isArray(sources) ? sources : []
			} catch (error) {
				console.error('Failed to load RAG sources for review:', error)
				this.reviewSourcesError = error.response?.data?.error || 'Failed to load RAG sources'
			} finally {
				this.reviewSourcesLoading = false
			}
		},
		reviewTeamNames(target) {
			if (Array.isArray(target.allowed_team_names) && target.allowed_team_names.length > 0) {
				return target.allowed_team_names
			}
			return Array.isArray(target.allowed_teams) ? target.allowed_teams : []
		},
		describeReviewSource(source) {
			if (source.display_name) {
				return source.display_name
			}
			if (source.path) {
				return source.path
			}
			if (source.source_url) {
				return source.source_url
			}
			return `Source #${source.id}`
		},
		sourceOpenUrl(source) {
			if (!source) {
				return null
			}
			if (source.node_type === 'url' && source.source_url) {
				return source.source_url
			}
			if (source.node_id && Number(source.node_id) > 0) {
				return generateUrl(`/f/${source.node_id}`)
			}
			return null
		},
		formatSourceStatus(status) {
			if (status === 'ready') {
				return 'Ready'
			}
			if (status === 'error') {
				return 'Error'
			}
			if (status === 'pending') {
				return 'Pending'
			}
			return status || 'Unknown'
		},
		async enableTest(bot) {
			try {
				await axios.post(generateUrl(`/apps/educai/api/v1/bots/${bot.id}/enable-test`))
				showSuccess(`Testing enabled. Mention ${bot.mention_name} in Nextcloud Talk to try the pending version.`)
			} catch (error) {
				console.error('Failed to enable testing:', error)
				showError(error.response?.data?.error || 'Failed to enable testing')
			}
		},
		async deleteBot(botId) {
			if (!confirm('Delete this bot? All conversation history will be lost.')) {
				return
			}

			try {
				await axios.delete(generateUrl(`/apps/educai/api/v1/bots/${botId}`))
				showSuccess('Bot deleted successfully')
				await this.loadBots()
			} catch (error) {
				console.error('Failed to delete bot:', error)
				showError(error.response?.data?.error || 'Failed to delete bot')
			}
		},
		editBot(bot) {
			this.editingBot = bot
		},
		closeForm() {
			this.showCreateForm = false
			this.editingBot = null
		},
		formatVisibility(visibility) {
			if (visibility === 'global') {
				return 'Global'
			}
			if (visibility === 'personal') {
				return 'Personal'
			}
			if (visibility === 'teams') {
				return 'Team access'
			}
			return 'Group access'
		},
		formatDate(timestamp) {
			if (!timestamp) {
				return ''
			}
			const date = new Date(timestamp * 1000)
			return date.toLocaleDateString(undefined, {
				day: 'numeric',
				month: 'short',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
		formatTemperature(value) {
			const numeric = Number(value)
			if (!Number.isFinite(numeric)) {
				return 'Uses global default'
			}
			return numeric.toFixed(2)
		},
	},
}
</script>

<style scoped>
.bot-management { padding: 20px; max-width: 1200px; margin: 0 auto; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
.header h2 { margin: 0; font-size: 24px; font-weight: 600; }
.bot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-bottom: 40px; }
.empty-state { text-align: center; padding: 60px 20px; }
.loading { text-align: center; padding: 40px; }
.button { padding: 8px 16px; border: 1px solid var(--color-border); background: var(--color-main-background); border-radius: 3px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 6px; }
.button:hover { background: var(--color-background-hover); }
.button.primary { background-color: var(--color-primary); color: white; border: none; padding: 10px 20px; border-radius: 3px; cursor: pointer; font-size: 14px; }
.button.primary:hover { background-color: var(--color-primary-element-light); }
.button.error { color: var(--color-error); }
.button.error:hover { background: var(--color-error); color: white; border-color: var(--color-error); }

/* Approval Section */
.approval-section {
	margin-top: 40px;
	padding-top: 30px;
	border-top: 2px solid var(--color-border);
}

.section-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
}

.section-header h3 {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.section-header .badge {
	background: var(--color-warning);
	color: #000;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 13px;
	font-weight: 600;
}

.loading-small {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
	padding: 20px 0;
}

.empty-approvals {
	color: var(--color-text-lighter);
	padding: 20px 0;
}

.approval-grid {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.approval-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 16px 20px;
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 20px;
}

.approval-info h4 {
	margin: 0 0 6px 0;
	font-size: 16px;
	font-weight: 600;
}

.approval-info .mention-badge {
	display: inline-block;
	background: var(--color-primary-element);
	color: white;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
}

.approval-info .owner {
	margin: 8px 0 4px 0;
	font-size: 13px;
	color: var(--color-text-lighter);
}

.approval-info .visibility {
	margin: 4px 0;
}

.visibility-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
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

.update-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-primary-element);
	color: #fff;
	margin-left: 6px;
}

.new-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-success);
	color: #000;
	margin-left: 6px;
}

.approval-info .submitted-time {
	margin: 4px 0 0 0;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.review-note {
	margin: 8px 0;
	font-size: 13px;
	color: var(--color-text-lighter);
}

.approval-actions {
	display: flex;
	gap: 8px;
	flex-shrink: 0;
}

/* Submit modal */
.modal-mask {
	position: fixed;
	z-index: 9998;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
}

.modal-container {
	background: var(--color-main-background);
	border-radius: 8px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.33);
	max-width: 640px;
	width: 90%;
	max-height: 90vh;
	overflow-y: auto;
}

.modal-header,
.modal-footer {
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.modal-footer {
	border-top: 1px solid var(--color-border);
	border-bottom: none;
	justify-content: flex-end;
	gap: 10px;
}

.modal-body {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.modal-header h2 {
	margin: 0;
	font-size: 18px;
}

.close-button {
	background: none;
	border: none;
	cursor: pointer;
	padding: 8px;
	font-size: 20px;
	opacity: 0.7;
}

.close-button:hover {
	opacity: 1;
}

.modal-body .form-group label {
	font-weight: 600;
}

.modal-body textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 8px;
	font-family: inherit;
}

.questionnaire p {
	margin: 4px 0;
	font-size: 13px;
	color: var(--color-text-lighter);
}

.review-preview-modal {
	max-width: 760px;
}

.preview-block {
	margin: 0;
	padding: 10px 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	white-space: pre-wrap;
}

.preview-code {
	margin: 0;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	white-space: pre-wrap;
	font-family: var(--font-face-monospace);
	font-size: 13px;
}

.preview-tool-list {
	margin: 0;
	padding-left: 18px;
}

.preview-tool-list li {
	margin: 6px 0;
}

.preview-source-list {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.preview-source-list li {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
}

.source-open-link {
	flex-shrink: 0;
}

.error-text {
	color: var(--color-error);
}
</style>
