<template>
	<div class="modal-mask" @click.self="$emit('cancel')">
		<div class="modal-container">
			<div class="modal-header">
				<h2>{{ isEditing ? 'Edit Bot' : 'Create New Bot' }}</h2>
				<button class="close-button" @click="$emit('cancel')">
					<span class="icon-close" />
				</button>
			</div>

			<div class="modal-body">
				<!-- Pending Changes Notice -->
				<div v-if="hasPendingChanges" class="pending-changes-notice">
					<span class="icon-info" />
					<div class="notice-content">
						<strong>Pending Changes</strong>
						<p>
							This bot has changes awaiting approval. You are viewing/editing the pending version.
							The currently approved version remains live until these changes are approved.
						</p>
					</div>
				</div>

				<form @submit.prevent="submitForm">
					<!-- Bot Name -->
					<div class="form-group">
						<label for="bot-name">Bot Name</label>
						<input
							id="bot-name"
							v-model="formData.botName"
							type="text"
							placeholder="Support Helper"
							required>
					</div>

					<!-- Mention Name -->
					<div class="form-group">
						<label for="mention-name">Mention Name</label>
						<div class="input-group">
							<span class="prefix">@</span>
							<input
								id="mention-name"
								v-model="formData.mentionName"
								type="text"
								placeholder="supportbot"
								:disabled="isEditing"
								pattern="[a-zA-Z0-9_-]+"
								required>
						</div>
						<p class="hint">
							Lowercase letters, numbers, hyphens, and underscores only.
							{{ isEditing ? 'Cannot be changed after creation.' : '' }}
						</p>
					</div>

					<!-- Bot Description -->
					<div class="form-group">
						<label for="bot-description">Bot Description</label>
						<textarea
							id="bot-description"
							v-model="formData.description"
							rows="3"
							placeholder="A helpful assistant that answers questions about..." />
						<p class="hint">
							This description is shown in the public bot listing to help users understand what this bot does.
						</p>
					</div>

					<!-- System Prompt -->
					<div class="form-group">
						<label for="system-prompt">System Prompt</label>
						<textarea
							id="system-prompt"
							v-model="formData.systemPrompt"
							rows="10"
							placeholder="You are a helpful support assistant. Your role is to..."
							required />
						<p class="hint">
							This defines the bot's personality and behavior. Be specific about
							what the bot should do and how it should respond.
						</p>
					</div>

					<!-- Availability -->
					<div class="form-group">
						<label for="availability-select">Availability</label>
						<select
							id="availability-select"
							v-model="formData.visibility">
							<option value="personal">
								Just for me (personal)
							</option>
							<option value="global">
								Global (requires approval for non-admins)
							</option>
							<option value="groups">
								Specific groups {{ requiresApprovalForGroups ? '(requires approval)' : '' }}
							</option>
							<option value="teams">
								Specific teams {{ requiresApprovalForTeams ? '(requires approval)' : '' }}
							</option>
						</select>
						<p class="hint">
							Choose who can use this bot. Personal bots are only visible to you.
							<template v-if="userPermissions.isAdmin">
								Global makes it available to everyone.
							</template>
							Groups or teams restrict usage to selected audiences.
						</p>
						<div v-if="willRequireApproval && !isEditing" class="approval-notice">
							<span class="icon-info" />
							This bot will be saved as a draft. You'll need to submit it for approval before others can use it.
						</div>
					</div>

					<div v-if="formData.visibility === 'groups'" class="form-group">
						<label for="group-select">Allowed groups</label>
						<select
							id="group-select"
							v-model="formData.allowedGroups"
							multiple>
							<option
								v-for="g in availableGroups"
								:key="g.id"
								:value="g.id">
								{{ g.displayName || g.id }}
							</option>
						</select>
						<p class="hint">
							Only members of the selected groups (and the bot owner) can mention and use this bot.
						</p>
					</div>

					<div v-if="formData.visibility === 'teams'" class="form-group">
						<label for="team-select">Allowed teams</label>
						<select
							id="team-select"
							v-model="formData.allowedTeams"
							multiple>
							<option
								v-for="t in availableTeams"
								:key="t.id"
								:value="t.id">
								{{ t.displayName || t.id }}
							</option>
						</select>
						<p class="hint">
							Only members of the selected teams (and the bot owner) can mention and use this bot.
						</p>
					</div>

					<div v-if="selectableModelOptions.length > 0" class="form-group">
						<label for="model-select">Model</label>
						<select
							id="model-select"
							v-model="formData.model"
							:disabled="selectableModelOptions.length === 0">
							<option
								v-for="option in selectableModelOptions"
								:key="option.id"
								:value="option.id">
								{{ option.label }}
							</option>
						</select>
						<p v-if="selectableModelOptions.length === 0" class="hint">
							No models are allowed by the administrator yet.
						</p>
					</div>

					<div class="form-group">
						<label class="checkbox">
							<input
								v-model="formData.useCustomTemperature"
								type="checkbox"
								@change="onCustomTemperatureToggle">
							Use custom temperature
						</label>
						<div v-if="formData.useCustomTemperature" class="temperature-field">
							<label for="bot-temperature">Temperature</label>
							<input
								id="bot-temperature"
								v-model.number="formData.temperature"
								type="number"
								min="0"
								max="1"
								step="0.05"
								placeholder="0.20">
							<p class="hint">
								Lower temperature is usually better for agentic, tool-enabled, RAG, and workflow bots.
								Use higher temperature only when you want more variation or creativity.
							</p>
							<p class="hint">
								<code>0.2</code> precise and stable, <code>0.4</code> balanced, <code>0.6</code> creative.
							</p>
							<p v-if="temperatureValidationError" class="inline-error">
								{{ temperatureValidationError }}
							</p>
						</div>
						<div v-else class="temperature-readonly">
							Using the global default temperature: <strong>{{ inheritedTemperatureLabel }}</strong>
						</div>
					</div>

					<hr class="section-divider" role="presentation">

					<div class="form-group">
						<label class="checkbox">
							<input
								v-model="formData.ragEnabled"
								type="checkbox">
							Enable Retrieval-Augmented Responses
						</label>
						<p class="hint">
							When enabled, attach files so the bot can reference your documents during conversations.
						</p>
						<div v-if="!isEditing" class="hint">
							Save the bot before attaching files or folders.
						</div>
						<div v-else class="rag-source-panel">
							<p v-if="!formData.ragEnabled" class="hint muted">
								Attachments remain stored but are ignored until retrieval is enabled.
							</p>
							<div class="rag-actions">
								<button
									type="button"
									class="button"
									:disabled="rag.loading || rag.adding || !formData.ragEnabled"
									@click="promptAddSources">
									{{ rag.adding ? 'Adding…' : 'Attach Files or Folders' }}
								</button>
								<button
									type="button"
									class="button"
									:disabled="rag.loading || rag.addingUrl || !formData.ragEnabled"
									@click="showUrlModal = true">
									{{ rag.addingUrl ? 'Adding…' : 'Add Link' }}
								</button>
							</div>

							<!-- URL Input Modal -->
							<div v-if="showUrlModal" class="url-modal-overlay" @click.self="closeUrlModal">
								<div class="url-modal">
									<div class="url-modal-header">
										<h3>Add URL Source</h3>
										<button type="button" class="close-button" @click="closeUrlModal">
											<span class="icon-close" />
										</button>
									</div>
									<div class="url-modal-body">
										<div class="form-group">
											<label for="url-input">URL</label>
											<input
												id="url-input"
												v-model="urlInput"
												type="url"
												placeholder="https://example.com/document.pdf"
												:disabled="rag.addingUrl"
												@keyup.enter="submitUrl">
											<p class="hint">
												Enter a URL to fetch and index. Supports HTML pages, PDF documents, JSON, and plain text.
											</p>
										</div>
									</div>
									<div class="url-modal-footer">
										<button type="button" class="button" @click="closeUrlModal">
											Cancel
										</button>
										<button
											type="button"
											class="button primary"
											:disabled="!isValidUrl || rag.addingUrl"
											@click="submitUrl">
											{{ rag.addingUrl ? 'Adding…' : 'Add URL' }}
										</button>
									</div>
								</div>
							</div>
							<div v-if="rag.loading" class="hint">
								Loading sources…
							</div>
							<div v-else-if="rag.sources.length === 0" class="hint">
								No knowledge sources attached yet.
							</div>
							<ul v-else class="source-list">
								<li
									v-for="source in rag.sources"
									:key="source.id"
									class="source-item">
									<div class="source-main">
										<span class="source-path">
											<span
												class="source-type-icon"
												:class="getSourceTypeIconClass(source)" />
											{{ describeSource(source) }}
										</span>
										<!-- Show pill only for ready/error status -->
										<span
											v-if="source.status !== 'pending'"
											class="status-pill"
											:class="`status-${source.status}`">
											{{ formatSourceStatus(source.status) }}
										</span>
									</div>

									<!-- Progress bar for pending sources -->
									<div v-if="source.status === 'pending'" class="source-progress">
										<div class="progress-container">
											<NcProgressBar
												:value="source.progress || 0"
												size="small" />
										</div>
										<span class="progress-label">
											{{ formatProgressStage(source.progress_stage) }}
											<template v-if="source.progress_total > 0">
												({{ source.progress_current }}/{{ source.progress_total }})
											</template>
											<template v-else-if="source.progress > 0">
												{{ source.progress }}%
											</template>
										</span>
									</div>

									<div v-if="source.last_indexed_at && source.status === 'ready'" class="source-meta">
										Last indexed {{ formatTimestamp(source.last_indexed_at) }}
									</div>
									<div v-if="source.error_message" class="source-meta source-error">
										{{ source.error_message }}
									</div>
									<div class="source-actions">
										<button
											type="button"
											class="button subtle"
											:disabled="rag.reindexing[source.id] || source.status === 'pending'"
											@click="reindexSource(source)">
											{{ rag.reindexing[source.id] ? 'Reindexing…' : 'Reindex' }}
										</button>
										<button
											type="button"
											class="button danger"
											:disabled="rag.removing[source.id] || source.status === 'pending'"
											@click="removeSource(source)">
											{{ rag.removing[source.id] ? 'Removing…' : 'Remove' }}
										</button>
									</div>
								</li>
							</ul>
						</div>
					</div>

					<hr class="section-divider" role="presentation">

					<div v-if="isWikiCapableBot" class="form-group personal-wiki-section">
						<label class="checkbox">
							<input
								v-model="formData.personalWikiEnabled"
								type="checkbox"
								@change="markPersonalWikiChanged">
							Enable LLM Wiki
						</label>
						<p class="hint">
							<template v-if="isTeamBot">
								Let this team bot use a Markdown wiki in a Collective from one of the selected teams.
							</template>
							<template v-else>
								Let this personal bot maintain a visible Markdown wiki in your Nextcloud files for durable personal or bot knowledge.
								The wiki can be read, edited, or deleted from Files.
							</template>
						</p>
						<div v-if="formData.personalWikiEnabled" class="wiki-path-panel">
							<div class="form-group compact">
								<label for="personal-wiki-location">Wiki location</label>
								<select
									id="personal-wiki-location"
									v-model="formData.personalWikiLocation"
									:disabled="isTeamBot"
									@change="onPersonalWikiLocationChange">
									<option
										v-if="isPersonalBot"
										value="personal_files">
										Personal Files: {{ defaultPersonalWikiPath }}
									</option>
									<option value="collective">
										Existing Collective
									</option>
								</select>
							</div>
							<div v-if="formData.personalWikiLocation === 'personal_files'">
								<p class="hint">
									Default path:
									<code>{{ defaultPersonalWikiPath }}</code>
								</p>
								<details
									class="advanced-settings"
									:open="normalizePersonalWikiPath(formData.personalWikiPath) !== ''">
									<summary>Use a custom wiki path</summary>
									<div class="form-group compact">
										<label for="personal-wiki-path">Custom path</label>
										<input
											id="personal-wiki-path"
											v-model="formData.personalWikiPath"
											type="text"
											:placeholder="WIKI_ROOT_FOLDER + '/Personal Wikis/my-bot'"
											@input="markPersonalWikiChanged">
										<p class="hint">
											Optional. Leave blank to use the default path. Custom paths must be relative and start with <code>{{ WIKI_ROOT_FOLDER }}/</code>.
										</p>
									</div>
								</details>
							</div>
							<div v-else class="form-group compact">
								<label for="personal-wiki-collective">Collective</label>
								<select
									id="personal-wiki-collective"
									v-model="formData.personalWikiCollectiveId"
									:disabled="wikiLocationsLoading || filteredWikiCollectives.length === 0"
									@change="markPersonalWikiChanged">
									<option value="">
										Select a collective
									</option>
									<option
										v-for="collective in filteredWikiCollectives"
										:key="collective.id"
										:value="String(collective.id)">
										{{ collective.display_name }}
									</option>
								</select>
								<p v-if="wikiLocationsLoading" class="hint">
									Loading collectives...
								</p>
								<p v-else-if="availableCollectives.length === 0" class="hint">
									No admin-owned collectives available for this account.
								</p>
								<p v-else-if="filteredWikiCollectives.length === 0" class="hint">
									Select a team that matches one of your admin-owned collectives.
								</p>
								<p v-else class="hint">
									Wiki pages will be written to the selected collective and visible to its members.
								</p>
							</div>
						</div>
					</div>

					<hr
						v-if="isWikiCapableBot"
						class="section-divider"
						role="presentation">

					<div class="form-group">
						<label>Agent Tools</label>
						<div v-if="toolLoading" class="hint">
							Loading tools…
						</div>
						<div v-else-if="visibleTools.length === 0" class="hint">
							No tools are currently enabled by the administrator.
						</div>
						<div v-else class="tool-list">
							<label
								v-for="tool in visibleTools"
								:key="getToolKey(tool)"
								class="checkbox tool-row"
								:class="{ 'tool-row--disabled': isToolDisabled(tool) }">
								<input
									v-model="formData.selectedTools"
									type="checkbox"
									:value="getToolKey(tool)"
									:disabled="isToolDisabled(tool)">
								<span class="tool-info">
									<span class="tool-name">
										{{ tool.name }}
										<span v-if="tool.is_builtin" class="tool-badge builtin">Built-in</span>
									</span>
									<span v-if="tool.description" class="tool-description">{{ tool.description }}</span>
									<span v-if="isToolDisabled(tool)" class="tool-description tool-unavailable">
										Only available for personal bots.
									</span>
									<span v-if="tool.mcp_endpoint_url" class="tool-endpoint">{{ tool.mcp_endpoint_url }}</span>
								</span>
							</label>
						</div>
						<p class="hint">
							Selected tools allow the bot to call external capabilities during conversations.
						</p>
					</div>

					<hr class="section-divider" role="presentation">

					<!-- Onboarding Questions -->
					<div class="form-group">
						<label>Onboarding Questions</label>
						<p class="hint">
							Optional: Add questions that users will be asked when they first activate this bot in a chat room.
							Answers are stored and provided as context to the bot.
						</p>

						<div v-if="formData.onboardingQuestions.questions.length === 0" class="onboarding-empty">
							<p>No onboarding questions configured.</p>
							<button
								type="button"
								class="button"
								:disabled="formData.onboardingQuestions.questions.length >= 15"
								@click="addOnboardingQuestion">
								Add Question
							</button>
						</div>

						<div v-else class="onboarding-questions-list">
							<div
								v-for="(question, qIndex) in formData.onboardingQuestions.questions"
								:key="question.id"
								class="onboarding-question-card">
								<div class="question-header">
									<span class="question-badge">Q{{ qIndex + 1 }}</span>
									<span class="question-id">{{ question.id }}</span>
									<button
										type="button"
										class="button subtle small"
										@click="removeOnboardingQuestion(qIndex)">
										Remove
									</button>
								</div>

								<div class="question-body">
									<div class="form-group compact">
										<label :for="'q-text-' + question.id">Question Text</label>
										<input
											:id="'q-text-' + question.id"
											v-model="question.text"
											type="text"
											placeholder="What is your main use case?">
									</div>

									<div class="form-group compact">
										<label class="checkbox">
											<input
												type="checkbox"
												:checked="isFreeTextQuestion(question)"
												@change="setQuestionFreeText(qIndex, $event.target.checked)">
											Free text answer (user reply will be stored)
										</label>
									</div>

									<div class="answers-grid">
										<div
											v-for="(answer, aIndex) in question.answers"
											:key="answer.id"
											class="answer-row">
											<div class="answer-label">
												{{ isFreeTextAnswer(answer) ? 'TXT' : answer.id.toUpperCase() }}
											</div>
											<input
												v-if="!isFreeTextAnswer(answer)"
												v-model="answer.text"
												type="text"
												placeholder="Answer text"
												class="answer-text">
											<input
												v-else
												:value="'Free text (user types the answer)'"
												type="text"
												class="answer-text free-text"
												disabled>
											<select v-model="answer.next" class="answer-next">
												<option :value="null">
													End
												</option>
												<option
													v-for="targetQ in getAvailableNextQuestions(question.id)"
													:key="targetQ.id"
													:value="targetQ.id">
													→ {{ targetQ.id }}
												</option>
											</select>
											<button
												v-if="!isFreeTextQuestion(question) && question.answers.length > 2"
												type="button"
												class="button subtle small"
												@click="removeAnswer(qIndex, aIndex)">
												×
											</button>
										</div>
									</div>

									<button
										v-if="!isFreeTextQuestion(question) && question.answers.length < 4"
										type="button"
										class="button subtle small add-answer-btn"
										@click="addAnswer(qIndex)">
										+ Add Answer
									</button>
								</div>
							</div>

							<button
								v-if="formData.onboardingQuestions.questions.length < 15"
								type="button"
								class="button"
								@click="addOnboardingQuestion">
								Add Another Question
							</button>
						</div>

						<div v-if="onboardingValidationError" class="onboarding-error">
							{{ onboardingValidationError }}
						</div>
					</div>

					<!-- Actions -->
					<div class="form-actions">
						<button type="button" class="button" @click="$emit('cancel')">
							Cancel
						</button>
						<button type="submit" class="button primary">
							{{ submitButtonText }}
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { WIKI_ROOT_FOLDER } from '../branding.js'
import { generateUrl } from '@nextcloud/router'
import { getFilePickerBuilder, FilePickerType, showSuccess, showError } from '@nextcloud/dialogs'
import { getClient, defaultRootPath, getDefaultPropfind, resultToNode } from '@nextcloud/files/dav'
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js'

const WIKI_BUILT_IN_TOOLS = new Set([
	'wiki_search',
	'wiki_read_page',
	'wiki_write_page',
	'wiki_log_event',
])

const normalizeBuiltInToolName = (value) => String(value || '')
	.trim()
	.toLowerCase()
	.replace(/^builtin:/, '')
	.replace(/[^a-z0-9]+/g, '_')
	.replace(/^_+|_+$/g, '')

export default {
	name: 'BotForm',
	components: {
		NcProgressBar,
	},
	props: {
		bot: {
			type: Object,
			default: null,
		},
		userPermissions: {
			type: Object,
			default: () => ({
				isAdmin: false,
				isGroupAdmin: false,
				isTeamAdmin: false,
				hasApprovalRights: false,
				adminGroups: [],
				adminTeams: [],
			}),
		},
	},
	data() {
		return {
			WIKI_ROOT_FOLDER,
			formData: {
				botName: '',
				mentionName: '',
				description: '',
				systemPrompt: '',
				visibility: 'personal',
				allowedGroups: [],
				allowedTeams: [],
				model: '',
				useCustomTemperature: false,
				temperature: null,
				ragEnabled: false,
				personalWikiEnabled: false,
				personalWikiLocation: 'personal_files',
				personalWikiCollectiveId: '',
				personalWikiPath: '',
				selectedTools: [],
				onboardingQuestions: {
					start: 'q1',
					questions: [],
				},
			},
			settings: {
				defaultTemperature: 0.2,
				allowMultipleModels: false,
				allowedModels: [],
				secondaryEndpointEnabled: false,
				modelOptions: [],
			},
			availableGroups: [],
			availableTeams: [],
			availableCollectives: [],
			wikiLocationsLoading: false,
			availableTools: [],
			toolLoading: false,
			toolConfigs: {},
			rag: {
				sources: [],
				loading: false,
				adding: false,
				addingUrl: false,
				reindexing: {},
				removing: {},
				pollingInterval: null,
			},
			showUrlModal: false,
			urlInput: '',
			personalWikiInitiallyEnabled: false,
			personalWikiChanged: false,
		}
	},
	computed: {
		isEditing() {
			return !!this.bot
		},
		hasPendingChanges() {
			return this.bot && this.bot.has_pending_changes
		},
		isPendingApproval() {
			return this.bot && this.bot.approval_status === 'pending'
		},
		hasPendingSources() {
			return this.rag.sources.some(s => s.status === 'pending')
		},
		isValidUrl() {
			if (!this.urlInput || this.urlInput.trim() === '') {
				return false
			}
			try {
				const url = new URL(this.urlInput.trim())
				return url.protocol === 'http:' || url.protocol === 'https:'
			} catch {
				return false
			}
		},
		onboardingValidationError() {
			const questions = this.formData.onboardingQuestions.questions
			if (questions.length === 0) {
				return null
			}

			// Check for empty question texts
			for (const q of questions) {
				if (!q.text || q.text.trim() === '') {
					return `Question ${q.id} has no text`
				}
				for (const a of q.answers) {
					if (a && a.type === 'free_text') {
						continue
					}
					if (!a.text || a.text.trim() === '') {
						return `Question ${q.id}, answer ${a.id.toUpperCase()} has no text`
					}
				}
			}

			// Check for unreachable questions (not the start and not pointed to by any answer)
			const start = this.formData.onboardingQuestions.start
			const reachable = new Set([start])
			let changed = true
			while (changed) {
				changed = false
				for (const q of questions) {
					if (reachable.has(q.id)) {
						for (const a of q.answers) {
							if (a.next && !reachable.has(a.next)) {
								reachable.add(a.next)
								changed = true
							}
						}
					}
				}
			}

			for (const q of questions) {
				if (!reachable.has(q.id)) {
					return `Question ${q.id} is unreachable from the start`
				}
			}

			return null
		},
		temperatureValidationError() {
			if (!this.formData.useCustomTemperature) {
				return null
			}

			const value = this.normalizeTemperatureValue(this.formData.temperature)
			if (value === null) {
				return 'Enter a temperature between 0.0 and 1.0.'
			}
			if (value < 0 || value > 1) {
				return 'Temperature must be between 0.0 and 1.0.'
			}

			return null
		},
		inheritedTemperatureLabel() {
			return this.formatTemperature(this.settings.defaultTemperature)
		},
		hasOnboardingQuestions() {
			return this.formData.onboardingQuestions.questions.length > 0
		},
		requiresApprovalForGroups() {
			// Admins never need approval
			if (this.userPermissions.isAdmin) {
				return false
			}
			// Group admins don't need approval for their groups
			return !this.userPermissions.isGroupAdmin
		},
		requiresApprovalForTeams() {
			// Admins never need approval
			if (this.userPermissions.isAdmin) {
				return false
			}
			// Team admins don't need approval for their teams
			return !this.userPermissions.isTeamAdmin
		},
		willRequireApproval() {
			// Personal bots never need approval
			if (this.formData.visibility === 'personal') {
				return false
			}
			// Admins never need approval
			if (this.userPermissions.isAdmin) {
				return false
			}
			// Global is only available to admins (handled elsewhere)
			if (this.formData.visibility === 'global') {
				return true
			}
			// Check group visibility
			if (this.formData.visibility === 'groups') {
				// If user is not a group admin at all, needs approval
				if (!this.userPermissions.isGroupAdmin) {
					return true
				}
				// Check if all selected groups are in user's admin groups
				const adminGroups = this.userPermissions.adminGroups || []
				for (const groupId of this.formData.allowedGroups) {
					if (!adminGroups.includes(groupId)) {
						return true
					}
				}
				return false
			}
			// Check team visibility
			if (this.formData.visibility === 'teams') {
				// If user is not a team admin at all, needs approval
				if (!this.userPermissions.isTeamAdmin) {
					return true
				}
				// Check if all selected teams are in user's admin teams
				const adminTeams = this.userPermissions.adminTeams || []
				for (const teamId of this.formData.allowedTeams) {
					if (!adminTeams.includes(teamId)) {
						return true
					}
				}
				return false
			}
			return false
		},
		selectableModelOptions() {
			const options = this.settings.allowMultipleModels
				? this.settings.allowedModels.map((model) => this.findOrCreateModelOption(model))
				: (this.settings.secondaryEndpointEnabled ? this.settings.modelOptions : [])

			const filtered = options.filter(Boolean)
			if (this.formData.model) {
				const currentId = this.toEndpointModelId(this.formData.model)
				if (!filtered.some((option) => option.id === currentId)) {
					filtered.push(this.findOrCreateModelOption(currentId))
				}
			}

			return filtered
		},
		isPersonalBot() {
			return this.formData.visibility === 'personal'
		},
		isTeamBot() {
			return this.formData.visibility === 'teams'
		},
		isWikiCapableBot() {
			return this.isPersonalBot || this.isTeamBot
		},
		defaultPersonalWikiPath() {
			const source = this.formData.mentionName || this.formData.botName || 'wiki'
			return `${WIKI_ROOT_FOLDER}/Personal Wikis/${this.slugifyWikiPathSegment(source)}`
		},
		filteredWikiCollectives() {
			if (!this.isTeamBot) {
				return this.availableCollectives
			}
			const selectedTeams = new Set((this.formData.allowedTeams || []).map((teamId) => String(teamId)))
			return this.availableCollectives.filter((collective) => {
				const teamId = collective.team_id !== undefined && collective.team_id !== null ? String(collective.team_id) : ''
				return teamId !== '' && selectedTeams.has(teamId)
			})
		},
		visibleTools() {
			return this.availableTools.filter((tool) => !this.isWikiTool(tool))
		},
		submitButtonText() {
			if (this.isEditing) {
				return 'Update Bot'
			}
			if (this.willRequireApproval) {
				return 'Save as Draft'
			}
			return 'Create Bot'
		},
	},
	watch: {
		'formData.visibility'() {
			if (!this.isWikiCapableBot) {
				this.formData.personalWikiEnabled = false
			} else if (this.isTeamBot) {
				this.formData.personalWikiLocation = 'collective'
				this.formData.personalWikiPath = ''
			} else if (this.personalWikiInitiallyEnabled && !this.personalWikiChanged) {
				this.formData.personalWikiEnabled = true
			}
			this.ensureWikiSelectionValid()
			this.removeUnavailableSelectedTools()
		},
		'formData.allowedTeams'() {
			this.ensureWikiSelectionValid()
		},
	},
	mounted() {
		this.loadSettings()
		this.loadGroups()
		this.loadTeams()
		this.loadWikiLocations()
		this.loadAvailableTools()
		if (this.bot) {
			// Check if there are pending changes to load
			const pending = this.bot.pending_changes
			const hasPending = this.bot.has_pending_changes && pending

			// Load onboarding questions (from pending or approved)
			let onboardingQuestions = { start: 'q1', questions: [] }
			if (hasPending && pending.onboarding_questions) {
				onboardingQuestions = pending.onboarding_questions
			} else if (this.bot.onboarding_questions) {
				onboardingQuestions = this.bot.onboarding_questions
			}

			const hasPendingTemperature = hasPending && Object.prototype.hasOwnProperty.call(pending, 'temperature')
			const temperature = hasPendingTemperature
				? this.normalizeTemperatureValue(pending.temperature)
				: this.normalizeTemperatureValue(this.bot.temperature)

			this.formData = {
				// Use pending values if available, otherwise approved values
				botName: hasPending && pending.bot_name ? pending.bot_name : this.bot.bot_name,
				mentionName: this.bot.mention_name.replace('@', ''),
				description: hasPending && pending.description !== undefined ? pending.description : (this.bot.description || ''),
				systemPrompt: hasPending && pending.system_prompt ? pending.system_prompt : this.bot.system_prompt,
				visibility: hasPending && pending.visibility
					? pending.visibility
					: (this.bot.visibility ? this.bot.visibility : (this.bot.is_public ? 'global' : 'groups')),
				allowedGroups: hasPending && pending.allowed_groups
					? (typeof pending.allowed_groups === 'string' ? JSON.parse(pending.allowed_groups || '[]') : pending.allowed_groups)
					: (Array.isArray(this.bot.allowed_groups) ? this.bot.allowed_groups : []),
				allowedTeams: hasPending && pending.allowed_teams
					? (typeof pending.allowed_teams === 'string' ? JSON.parse(pending.allowed_teams || '[]') : pending.allowed_teams)
					: (Array.isArray(this.bot.allowed_teams) ? this.bot.allowed_teams : []),
				model: hasPending && pending.model !== undefined ? pending.model : (this.bot.model || ''),
				useCustomTemperature: temperature !== null,
				temperature,
				ragEnabled: hasPending && pending.rag_enabled !== undefined ? !!pending.rag_enabled : !!this.bot.rag_enabled,
				personalWikiEnabled: false,
				personalWikiLocation: 'personal_files',
				personalWikiCollectiveId: '',
				personalWikiPath: '',
				selectedTools: [],
				onboardingQuestions,
			}
			this.loadBotTools()
			this.loadRagSources()
		}
	},
	beforeDestroy() {
		this.stopPolling()
	},
	methods: {
		startPolling() {
			// Only start if not already polling
			if (this.rag.pollingInterval) {
				return
			}
			// Poll every 2 seconds for progress updates
			this.rag.pollingInterval = setInterval(() => {
				if (this.hasPendingSources) {
					this.loadRagSources()
				} else {
					// No more pending sources, stop polling
					this.stopPolling()
				}
			}, 2000)
		},
		stopPolling() {
			if (this.rag.pollingInterval) {
				clearInterval(this.rag.pollingInterval)
				this.rag.pollingInterval = null
			}
		},
		async loadGroups() {
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/groups'))
				const data = response.data
				this.availableGroups = Array.isArray(data.groups) ? data.groups : []
			} catch (e) {
				// ignore silently; UI will just show empty list
			}
		},
		async loadTeams() {
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/teams'))
				const data = response.data
				this.availableTeams = Array.isArray(data.teams) ? data.teams : []
			} catch (e) {
				// ignore when teams are unavailable or Circles app is disabled
			}
		},
		async loadWikiLocations() {
			this.wikiLocationsLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/wiki/locations'))
				const data = response.data
				this.availableCollectives = Array.isArray(data.collectives) ? data.collectives : []
				this.ensureWikiSelectionValid()
			} catch (e) {
				this.availableCollectives = []
				this.ensureWikiSelectionValid()
			} finally {
				this.wikiLocationsLoading = false
			}
		},
		async loadSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/settings'))
				const data = response.data
				this.settings.defaultTemperature = this.normalizeTemperatureValue(data.default_temperature) ?? 0.2
				this.settings.allowMultipleModels = !!data.allow_multiple_models
				this.settings.allowedModels = Array.isArray(data.allowed_models) ? data.allowed_models : []
				this.settings.secondaryEndpointEnabled = !!(data.secondary_api_endpoint || '').trim()
				if (this.settings.allowMultipleModels || this.settings.secondaryEndpointEnabled) {
					await this.loadModelOptions()
				}
				if (this.formData.model) {
					this.formData.model = this.toEndpointModelId(this.formData.model)
				} else if (this.selectableModelOptions.length > 0) {
					this.formData.model = this.selectableModelOptions[0].id
				}
			} catch (e) {
				// ignore
			}
		},
		async loadModelOptions() {
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/models'))
				this.settings.modelOptions = this.normalizeModelOptions(response.data?.model_options, response.data?.models)
			} catch (e) {
				this.settings.modelOptions = []
			}
		},
		toEndpointModelId(model) {
			const value = (model || '').trim()
			if (value === '') {
				return ''
			}
			if (/^(primary|secondary):/.test(value)) {
				return value
			}
			const matches = this.settings.modelOptions.filter((option) => option.model === value)
			return matches.length === 1 ? matches[0].id : `primary:${value}`
		},
		toStoredModelReference(model) {
			const id = this.toEndpointModelId(model)
			if (id === '') {
				return ''
			}
			const rawModel = id.replace(/^(primary|secondary):/, '')
			const matches = this.settings.modelOptions.filter((option) => option.model === rawModel)
			return matches.length > 1 ? id : rawModel
		},
		normalizeModelOptions(rawOptions, rawModels) {
			if (Array.isArray(rawOptions) && rawOptions.length > 0) {
				return rawOptions
					.filter((option) => option && option.id && option.model)
					.map((option) => ({
						id: String(option.id),
						label: option.label || this.modelLabel(option.id),
						model: String(option.model),
						endpoint: option.endpoint === 'secondary' ? 'secondary' : 'primary',
					}))
			}

			return Array.isArray(rawModels)
				? rawModels.map((model) => this.findOrCreateModelOption(model))
				: []
		},
		findOrCreateModelOption(model) {
			const id = this.toEndpointModelId(model)
			if (id === '') {
				return null
			}
			const found = this.settings.modelOptions.find((option) => option.id === id)
			if (found) {
				return found
			}
			const endpoint = id.startsWith('secondary:') ? 'secondary' : 'primary'
			return {
				id,
				label: this.modelLabel(id),
				model: id.replace(/^(primary|secondary):/, ''),
				endpoint,
			}
		},
		modelLabel(model) {
			const id = this.toEndpointModelId(model)
			if (id === '') {
				return ''
			}
			return id.replace(/^primary:/, 'Primary · ').replace(/^secondary:/, 'Secondary · ')
		},
		normalizeTemperatureValue(value) {
			if (value === null || value === undefined || value === '') {
				return null
			}

			const numeric = Number(value)
			if (!Number.isFinite(numeric)) {
				return null
			}

			return Math.round(numeric * 100) / 100
		},
		formatTemperature(value) {
			const temperature = this.normalizeTemperatureValue(value)
			return temperature === null ? '0.20' : temperature.toFixed(2)
		},
		onCustomTemperatureToggle() {
			if (this.formData.useCustomTemperature && this.normalizeTemperatureValue(this.formData.temperature) === null) {
				this.formData.temperature = this.settings.defaultTemperature
			}
		},
		async loadAvailableTools() {
			this.toolLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/tools'))
				const tools = response.data?.tools
				// Filter out RAG and Wiki tools - they are controlled by dedicated form sections.
				this.availableTools = Array.isArray(tools)
					? tools.filter(t => !(t.is_builtin && (t.builtin_name === 'rag_search_documents' || this.isWikiTool(t))))
					: []
			} catch (e) {
				this.availableTools = []
			} finally {
				this.toolLoading = false
			}
		},
		getToolKey(tool) {
			// For built-in tools, use 'builtin:name' format
			// For MCP tools, use the numeric id
			if (tool.is_builtin && tool.builtin_name) {
				return 'builtin:' + tool.builtin_name
			}
			// Fallback for built-in tools without builtin_name (shouldn't happen)
			if (tool.is_builtin) {
				// eslint-disable-next-line no-console
				console.warn('[DEBUG] Built-in tool missing builtin_name:', tool)
				return null
			}
			return tool.id
		},
		isWikiTool(toolOrKey) {
			if (!toolOrKey) {
				return false
			}
			if (typeof toolOrKey === 'string') {
				return WIKI_BUILT_IN_TOOLS.has(normalizeBuiltInToolName(toolOrKey))
			}
			if (toolOrKey.is_builtin) {
				return [toolOrKey.builtin_name, toolOrKey.builtinName, toolOrKey.name]
					.some((name) => WIKI_BUILT_IN_TOOLS.has(normalizeBuiltInToolName(name)))
			}
			return false
		},
		markPersonalWikiChanged() {
			this.personalWikiChanged = true
		},
		onPersonalWikiLocationChange() {
			this.markPersonalWikiChanged()
			if (this.isTeamBot) {
				this.formData.personalWikiLocation = 'collective'
				this.formData.personalWikiPath = ''
				return
			}
			if (this.formData.personalWikiLocation === 'personal_files') {
				this.formData.personalWikiCollectiveId = ''
			} else {
				this.formData.personalWikiPath = ''
			}
		},
		slugifyWikiPathSegment(value) {
			const slug = String(value || 'wiki')
				.trim()
				.toLowerCase()
				.replace(/^@+/, '')
				.replace(/[^a-z0-9_-]+/g, '-')
				.replace(/^[-_]+|[-_]+$/g, '')
			return slug || 'wiki'
		},
		normalizePersonalWikiPath(value) {
			return String(value || '')
				.replace(/\\/g, '/')
				.trim()
				.replace(/^\/+|\/+$/g, '')
		},
		getPersonalWikiConfig() {
			if (this.formData.personalWikiLocation === 'collective') {
				return {
					wiki_location: 'collective',
					wiki_collective_id: Number(this.formData.personalWikiCollectiveId),
				}
			}
			const path = this.normalizePersonalWikiPath(this.formData.personalWikiPath)
			return path !== '' ? { wiki_location: 'personal_files', wiki_root_path: path } : {}
		},
		getWikiToolEntries() {
			if (!this.isWikiCapableBot || !this.formData.personalWikiEnabled) {
				return []
			}
			const config = this.getPersonalWikiConfig()
			return Array.from(WIKI_BUILT_IN_TOOLS).map((builtinName) => {
				const entry = {
					is_builtin: true,
					builtin_name: builtinName,
				}
				if (Object.keys(config).length > 0) {
					entry.config = { ...config }
				}
				return entry
			})
		},
		isToolDisabled(tool) {
			return !this.isWikiCapableBot && this.isWikiTool(tool)
		},
		removeUnavailableSelectedTools() {
			const selectedTools = this.sanitizeSelectedToolsForVisibility(this.formData.selectedTools)
			if (selectedTools.length !== this.formData.selectedTools.length) {
				this.formData.selectedTools = selectedTools
			}

			const selectedKeys = selectedTools.map((toolKey) => String(toolKey))
			Object.keys(this.toolConfigs).forEach((key) => {
				if (!selectedKeys.includes(key)) {
					this.$delete(this.toolConfigs, key)
				}
			})
		},
		sanitizeSelectedToolsForVisibility(selectedTools) {
			if (!Array.isArray(selectedTools)) {
				return []
			}
			if (this.isWikiCapableBot) {
				return selectedTools
			}
			return selectedTools.filter((toolKey) => !this.isWikiTool(toolKey))
		},
		ensureWikiSelectionValid() {
			if (!this.isWikiCapableBot) {
				this.formData.personalWikiEnabled = false
				this.formData.personalWikiCollectiveId = ''
				return
			}
			if (this.isTeamBot) {
				this.formData.personalWikiLocation = 'collective'
				this.formData.personalWikiPath = ''
			}

			if (this.formData.personalWikiLocation !== 'collective' || !this.formData.personalWikiCollectiveId) {
				return
			}
			const selectedCollectiveId = String(this.formData.personalWikiCollectiveId)
			const stillAvailable = this.filteredWikiCollectives.some((collective) => String(collective.id) === selectedCollectiveId)
			if (!stillAvailable) {
				this.formData.personalWikiCollectiveId = ''
			}
		},
		async loadBotTools() {
			if (!this.isEditing) {
				return
			}
			const pendingTools = this.bot?.has_pending_changes && Array.isArray(this.bot?.pending_changes?.tools)
				? this.bot.pending_changes.tools
				: null
			if (pendingTools) {
				this.applyToolSelection(pendingTools)
				return
			}
			try {
				const response = await axios.get(generateUrl(`/apps/educai/api/v1/bots/${this.bot.id}/tools`))
				const entries = Array.isArray(response.data?.tools) ? response.data.tools : []
				this.applyToolSelection(entries)
			} catch (e) {
				// ignore errors, selection will remain empty
			}
		},
		applyToolSelection(entries) {
			const selected = []
			const configs = {}
			let wikiEnabled = false
			let wikiConfig = {}
			entries.forEach((entry) => {
				if (!entry) {
					return
				}
				if (entry.is_builtin && entry.builtin_name) {
					const key = 'builtin:' + entry.builtin_name
					if (this.isWikiTool(key)) {
						wikiEnabled = true
						if (entry.config && Object.keys(entry.config).length > 0) {
							wikiConfig = entry.config
						}
						return
					}
					selected.push(key)
					if (entry.config && Object.keys(entry.config).length > 0) {
						configs[key] = entry.config
					}
					return
				}
				const toolId = entry.tool_id ?? entry.tool?.id ?? entry.id
				if (toolId !== undefined && toolId !== null) {
					selected.push(toolId)
					if (entry.config && Object.keys(entry.config).length > 0) {
						configs[toolId] = entry.config
					}
				}
			})
			this.personalWikiInitiallyEnabled = wikiEnabled
			this.formData.personalWikiEnabled = this.isWikiCapableBot && wikiEnabled
			this.formData.personalWikiLocation = wikiConfig.wiki_location === 'collective' ? 'collective' : 'personal_files'
			this.formData.personalWikiCollectiveId = wikiConfig.wiki_collective_id !== undefined && wikiConfig.wiki_collective_id !== null ? String(wikiConfig.wiki_collective_id) : ''
			this.formData.personalWikiPath = this.formData.personalWikiLocation === 'personal_files' && typeof wikiConfig.wiki_root_path === 'string' ? wikiConfig.wiki_root_path : ''
			this.ensureWikiSelectionValid()
			this.personalWikiChanged = false
			this.formData.selectedTools = this.sanitizeSelectedToolsForVisibility(selected)
			this.toolConfigs = configs
			this.removeUnavailableSelectedTools()
		},
		async loadRagSources() {
			if (!this.isEditing) {
				return
			}
			this.rag.loading = true
			try {
				const response = await axios.get(generateUrl(`/apps/educai/api/v1/bots/${this.bot.id}/sources`))
				const sources = response.data?.sources
				this.rag.sources = Array.isArray(sources) ? sources : []

				// Start polling if there are pending sources
				if (this.hasPendingSources) {
					this.startPolling()
				}
			} catch (e) {
				showError('Failed to load attached sources')
			} finally {
				this.rag.loading = false
			}
		},
		async promptAddSources() {
			if (!this.isEditing || this.rag.adding) {
				return
			}
			const nodes = await this.pickNodes()
			if (!nodes || nodes.length === 0) {
				return
			}
			this.rag.adding = true
			try {
				for (const node of nodes) {
					// eslint-disable-next-line no-console
					console.log('[DEBUG] Processing node:', JSON.stringify(node, null, 2))
					const nodeId = this.extractNodeId(node)
					// eslint-disable-next-line no-console
					console.log('[DEBUG] Extracted nodeId:', nodeId)
					if (!nodeId) {
						// eslint-disable-next-line no-console
						console.warn('[DEBUG] Skipping node - no nodeId')
						continue
					}
					const nodeType = this.deriveNodeType(node)
					// eslint-disable-next-line no-console
					console.log('[DEBUG] Derived nodeType:', nodeType)
					const payload = { nodeId, nodeType }
					// eslint-disable-next-line no-console
					console.log('[DEBUG] Sending to backend:', JSON.stringify(payload, null, 2))
					await axios.post(generateUrl(`/apps/educai/api/v1/bots/${this.bot.id}/sources`), payload)
				}
				showSuccess('Sources queued for indexing')
				await this.loadRagSources()
			} catch (error) {
				console.error('Failed to attach sources', error)
				showError(error.response?.data?.error || 'Failed to attach sources')
			} finally {
				this.rag.adding = false
			}
		},
		async removeSource(source) {
			if (!this.isEditing || !source || !source.id) {
				return
			}
			this.$set(this.rag.removing, source.id, true)
			try {
				await axios.delete(generateUrl(`/apps/educai/api/v1/bots/${this.bot.id}/sources/${source.id}`))
				showSuccess('Source removed')
				await this.loadRagSources()
			} catch (error) {
				console.error('Failed to remove source', error)
				showError(error.response?.data?.error || 'Failed to remove source')
			} finally {
				this.$delete(this.rag.removing, source.id)
			}
		},
		async reindexSource(source) {
			if (!this.isEditing || !source || !source.id) {
				return
			}
			this.$set(this.rag.reindexing, source.id, true)
			try {
				await axios.post(generateUrl(`/apps/educai/api/v1/bots/${this.bot.id}/sources/${source.id}/reindex`))
				showSuccess('Reindex queued')
				await this.loadRagSources()
			} catch (error) {
				console.error('Failed to reindex source', error)
				showError(error.response?.data?.error || 'Failed to reindex source')
			} finally {
				this.$delete(this.rag.reindexing, source.id)
			}
		},
		closeUrlModal() {
			this.showUrlModal = false
			this.urlInput = ''
		},
		async submitUrl() {
			if (!this.isEditing || !this.isValidUrl || this.rag.addingUrl) {
				return
			}
			this.rag.addingUrl = true
			try {
				const sourceUrl = this.urlInput.trim()
				await axios.post(generateUrl(`/apps/educai/api/v1/bots/${this.bot.id}/sources`), { sourceUrl })
				showSuccess('URL source queued for indexing')
				this.closeUrlModal()
				await this.loadRagSources()
			} catch (error) {
				console.error('Failed to add URL source', error)
				showError(error.response?.data?.error || 'Failed to add URL source')
			} finally {
				this.rag.addingUrl = false
			}
		},
		async pickNodes() {
			try {
				const picker = getFilePickerBuilder('Select files or folders')
					.setMultiSelect(true)
					.allowDirectories(true)
					.setType(FilePickerType.Choose)
					.build()

				// pick() returns paths as strings
				const paths = await picker.pick()

				if (!paths || paths.length === 0) {
					return null
				}

				// Get WebDAV client
				const client = getClient()

				// Fetch file metadata for each path using WebDAV client
				const nodes = []
				for (const path of paths) {
					try {
						// The WebDAV client expects paths relative to the DAV root
						// defaultRootPath is like '/files/username', and path is like '/Talk/file.md'
						const fullPath = `${defaultRootPath}${path}`

						// Use the WebDAV client to get file stats
						// The client handles URL encoding automatically
						const result = await client.stat(fullPath, {
							details: true,
							data: getDefaultPropfind(),
						})

						// Convert WebDAV result to Node object
						const node = resultToNode(result.data)

						if (node && node.fileid) {
							// Determine the correct type - node.type can be 'file' or 'folder'
							// We need to convert 'folder' to 'dir' for our backend
							let nodeType = 'file'
							if (node.type === 'folder' || node.mime === 'httpd/unix-directory') {
								nodeType = 'folder'
							}

							nodes.push({
								fileid: node.fileid,
								path,
								mimetype: node.mime || 'application/octet-stream',
								type: nodeType,
							})
						} else {
							console.warn('No fileid found for path:', path)
							showError('Failed to get file info for ' + path)
						}
					} catch (error) {
						console.error('Failed to get file info for', path, error)
						showError('Failed to get file info for ' + path + ': ' + (error.response?.statusText || error.message))
					}
				}

				return nodes.length > 0 ? nodes : null
			} catch (error) {
				// User cancelled or closed the picker
				if (error.message && (error.message.includes('No nodes selected') || error.message.includes('FilePicker'))) {
					return null
				}
				console.error('File picker error:', error)
				showError('Failed to open file picker: ' + (error.message || error))
				return null
			}
		},
		extractNodeId(node) {
			if (!node || typeof node !== 'object') {
				return null
			}
			const candidates = [node.fileid, node.id, node?.attributes?.fileid]
			for (const value of candidates) {
				if (value === undefined || value === null) {
					continue
				}
				const parsed = parseInt(value, 10)
				if (!Number.isNaN(parsed) && parsed > 0) {
					return parsed
				}
			}
			return null
		},
		deriveNodeType(node) {
			if (!node || typeof node !== 'object') {
				return 'file'
			}
			const explicit = node.type || node.nodeType
			if (explicit === 'dir' || explicit === 'folder') {
				return 'folder'
			}
			if (node.isDirectory || node.isFolder) {
				return 'folder'
			}
			const mime = node.mimetype || node.mime || node?.attributes?.mimetype
			if (mime === 'httpd/unix-directory') {
				return 'folder'
			}
			return 'file'
		},
		describeSource(source) {
			if (!source) {
				return ''
			}
			if (source.path) {
				return source.path
			}
			if (source.display_name) {
				return source.display_name
			}
			if (source.source_url) {
				return source.source_url
			}
			return `Node #${source.node_id}`
		},
		getSourceTypeIconClass(source) {
			if (!source) {
				return 'file-icon'
			}
			if (source.node_type === 'url') {
				return 'url-icon'
			}
			if (source.node_type === 'folder') {
				return 'folder-icon'
			}
			return 'file-icon'
		},
		formatSourceStatus(status) {
			switch (status) {
			case 'ready':
				return 'Ready'
			case 'pending':
				return 'Pending'
			case 'error':
				return 'Error'
			default:
				return status || 'Unknown'
			}
		},
		formatProgressStage(stage) {
			const stages = {
				collecting: 'Collecting files…',
				extracting: 'Extracting text…',
				chunking: 'Processing content…',
				embedding: 'Generating embeddings…',
				storing: 'Saving results…',
				ready: 'Complete',
			}
			return stages[stage] || 'Processing…'
		},
		formatTimestamp(ts) {
			if (!ts) {
				return ''
			}
			const date = new Date(ts * 1000)
			return date.toLocaleString()
		},
		addOnboardingQuestion() {
			const questions = this.formData.onboardingQuestions.questions
			if (questions.length >= 15) {
				return
			}

			// Generate a unique question ID
			let nextNum = questions.length + 1
			let newId = `q${nextNum}`
			while (questions.some((q) => q.id === newId)) {
				nextNum++
				newId = `q${nextNum}`
			}

			questions.push({
				id: newId,
				text: '',
				answers: [
					{ id: 'a', text: '', next: null },
					{ id: 'b', text: '', next: null },
				],
			})

			// If this is the first question, set it as start
			if (questions.length === 1) {
				this.formData.onboardingQuestions.start = newId
			}
		},
		removeOnboardingQuestion(qIndex) {
			const questions = this.formData.onboardingQuestions.questions
			const removedId = questions[qIndex].id
			questions.splice(qIndex, 1)

			// Remove references to this question from other answers
			for (const q of questions) {
				for (const a of q.answers) {
					if (a.next === removedId) {
						a.next = null
					}
				}
			}

			// Update start if we removed the start question
			if (this.formData.onboardingQuestions.start === removedId) {
				this.formData.onboardingQuestions.start = questions.length > 0 ? questions[0].id : 'q1'
			}
		},
		addAnswer(qIndex) {
			const question = this.formData.onboardingQuestions.questions[qIndex]
			if (this.isFreeTextQuestion(question)) {
				return
			}
			if (question.answers.length >= 4) {
				return
			}

			// Find next available letter
			const usedIds = new Set(question.answers.map((a) => a.id))
			const letters = ['a', 'b', 'c', 'd']
			const nextId = letters.find((l) => !usedIds.has(l)) || 'x'

			question.answers.push({
				id: nextId,
				text: '',
				next: null,
			})
		},
		removeAnswer(qIndex, aIndex) {
			const question = this.formData.onboardingQuestions.questions[qIndex]
			if (this.isFreeTextQuestion(question)) {
				return
			}
			if (question.answers.length <= 2) {
				return
			}
			question.answers.splice(aIndex, 1)
		},
		isFreeTextAnswer(answer) {
			return !!answer && answer.type === 'free_text'
		},
		isFreeTextQuestion(question) {
			if (!question || !Array.isArray(question.answers)) {
				return false
			}
			return question.answers.length === 1 && this.isFreeTextAnswer(question.answers[0])
		},
		setQuestionFreeText(qIndex, enabled) {
			const question = this.formData.onboardingQuestions.questions[qIndex]
			if (!question) {
				return
			}
			if (enabled) {
				// Preserve existing "next" if present (best effort)
				const next = Array.isArray(question.answers) && question.answers[0] ? question.answers[0].next : null
				this.$set(question, 'answers', [
					{ id: 'text', text: '', type: 'free_text', next: next || null },
				])
				return
			}
			// Switch back to default fixed answers
			this.$set(question, 'answers', [
				{ id: 'a', text: '', next: null },
				{ id: 'b', text: '', next: null },
			])
		},
		getAvailableNextQuestions(currentId) {
			// Return all questions except the current one (to prevent self-loops)
			return this.formData.onboardingQuestions.questions.filter((q) => q.id !== currentId)
		},
		submitForm() {
			if (this.temperatureValidationError) {
				showError(this.temperatureValidationError)
				return
			}
			if (this.formData.personalWikiEnabled && this.isTeamBot && this.formData.allowedTeams.length === 0) {
				showError('Select at least one team before enabling the LLM Wiki.')
				return
			}
			if (this.formData.personalWikiEnabled && this.formData.personalWikiLocation === 'collective' && !this.formData.personalWikiCollectiveId) {
				showError('Select a collective for the LLM Wiki location.')
				return
			}
			if (this.formData.personalWikiEnabled && this.formData.personalWikiLocation === 'collective') {
				const selectedCollectiveId = String(this.formData.personalWikiCollectiveId)
				const selectedCollectiveMatchesScope = this.filteredWikiCollectives.some((collective) => String(collective.id) === selectedCollectiveId)
				if (!selectedCollectiveMatchesScope) {
					showError('Select a collective from one of the selected teams.')
					return
				}
			}
			if (this.formData.personalWikiEnabled && this.isTeamBot && this.formData.personalWikiLocation !== 'collective') {
				showError('Team bots can use LLM Wiki only with a Collective.')
				return
			}

			// eslint-disable-next-line no-console
			console.log('[DEBUG] selectedTools before submit:', JSON.stringify(this.formData.selectedTools))

			const selectedTools = this.sanitizeSelectedToolsForVisibility(this.formData.selectedTools)
			this.formData.selectedTools = selectedTools

			const toolPayload = selectedTools
				.filter((toolKey) => toolKey !== null && toolKey !== undefined)
				.map((toolKey) => {
					const config = this.toolConfigs[toolKey]

					// eslint-disable-next-line no-console
					console.log('[DEBUG] Processing toolKey:', toolKey, 'type:', typeof toolKey)

					// Check if this is a built-in tool
					if (typeof toolKey === 'string' && toolKey.startsWith('builtin:')) {
						const builtinName = toolKey.substring(8)
						const entry = {
							is_builtin: true,
							builtin_name: builtinName,
						}
						if (config && Object.keys(config).length > 0) {
							entry.config = config
						}
						return entry
					}

					// MCP tool - ensure it's a valid ID
					if (typeof toolKey === 'number' || (typeof toolKey === 'string' && !isNaN(parseInt(toolKey, 10)))) {
						const toolId = typeof toolKey === 'number' ? toolKey : parseInt(toolKey, 10)
						if (config && Object.keys(config).length > 0) {
							return {
								tool_id: toolId,
								config,
							}
						}
						return toolId
					}

					// Fallback - skip invalid entries
					// eslint-disable-next-line no-console
					console.warn('[DEBUG] Skipping invalid toolKey:', toolKey)
					return null
				})
				.filter((entry) => entry !== null)

			toolPayload.push(...this.getWikiToolEntries())

			// eslint-disable-next-line no-console
			console.log('[DEBUG] toolPayload:', JSON.stringify(toolPayload))

			// Build onboarding questions payload (only if there are questions)
			let onboardingQuestionsPayload = null
			if (this.formData.onboardingQuestions.questions.length > 0) {
				// Validate before submitting
				if (this.onboardingValidationError) {
					// eslint-disable-next-line no-console
					console.warn('[DEBUG] Onboarding validation error:', this.onboardingValidationError)
					// Allow submission anyway, just log the warning
				}
				onboardingQuestionsPayload = {
					start: this.formData.onboardingQuestions.start,
					questions: this.formData.onboardingQuestions.questions.map((q) => ({
						id: q.id,
						text: q.text,
						answers: q.answers.map((a) => ({
							id: a.id,
							text: a.text,
							next: a.next,
							type: a.type,
						})),
					})),
				}
			}

			this.$emit('save', {
				id: this.bot?.id,
				botName: this.formData.botName,
				mentionName: this.formData.mentionName,
				description: this.formData.description || null,
				systemPrompt: this.formData.systemPrompt,
				visibility: this.formData.visibility,
				allowedGroups: this.formData.visibility === 'groups' ? this.formData.allowedGroups : [],
				allowedTeams: this.formData.visibility === 'teams' ? this.formData.allowedTeams : [],
				model: this.selectableModelOptions.length > 0 ? this.toStoredModelReference(this.formData.model) : undefined,
				temperature: this.formData.useCustomTemperature ? this.normalizeTemperatureValue(this.formData.temperature) : null,
				ragEnabled: this.formData.ragEnabled,
				tools: toolPayload,
				onboardingQuestions: onboardingQuestionsPayload,
			})
		},
	},
}
</script>

<style scoped>
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
	max-width: 600px;
	width: 90%;
	max-height: 90vh;
	overflow-y: auto;
}

.modal-header {
	padding: 20px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.modal-header h2 {
	margin: 0;
	font-size: 20px;
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

.modal-body {
	padding: 20px;
}

.form-group {
	margin-bottom: 20px;
}

.form-group label {
	display: block;
	margin-bottom: 6px;
	font-weight: 600;
}

.form-group input,
.form-group textarea {
	width: 100%;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: 3px;
	font-family: inherit;
	font-size: 14px;
}

/* Make selects consistent with inputs */
.form-group select {
	width: 100%;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: 3px;
	font-family: inherit;
	font-size: 14px;
}

.form-group input:focus,
.form-group textarea:focus {
	outline: none;
	border-color: var(--color-primary);
}

.form-group textarea {
	resize: vertical;
	min-height: 120px;
}

.input-group {
	display: flex;
	align-items: center;
}

.input-group .prefix {
	padding: 10px 12px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-right: none;
	border-radius: 3px 0 0 3px;
	font-weight: 600;
}

.input-group input {
	border-radius: 0 3px 3px 0;
}

/* Enlarge Allowed groups multi-select */
#group-select,
#team-select {
	min-height: 140px;
}

.hint {
	margin: 6px 0 0 0;
	font-size: 13px;
	color: var(--color-text-lighter);
}

.form-actions {
	display: flex;
	gap: 10px;
	justify-content: flex-end;
	margin-top: 24px;
}

.button {
	padding: 10px 20px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	border-radius: 3px;
	cursor: pointer;
	font-size: 14px;
}

.button:hover {
	background: var(--color-background-hover);
}

.button.primary {
	background-color: var(--color-primary);
	color: white;
	border-color: var(--color-primary);
}

.button.primary:hover {
	background-color: var(--color-primary-element-light);
}

/* Align checkbox with label text and standardize size */
.form-group label.checkbox {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
.form-group label.checkbox input[type="checkbox"] {
	width: 16px;
	height: 16px;
	margin: 0;
	vertical-align: middle;
}

.section-divider {
	margin: 24px 0;
	border: none;
	border-top: 1px solid var(--color-border);
	height: 0;
	background: transparent;
}

.rag-source-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 12px;
}

.rag-actions {
	display: flex;
	justify-content: flex-start;
	gap: 10px;
}

.personal-wiki-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.wiki-path-panel {
	margin-top: 4px;
}

.wiki-path-panel code {
	word-break: break-all;
}

.advanced-settings {
	margin-top: 10px;
}

.advanced-settings summary {
	cursor: pointer;
	color: var(--color-primary);
	font-weight: 600;
}

.advanced-settings .form-group.compact {
	margin-top: 10px;
	margin-bottom: 0;
}

.source-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.source-item {
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 12px;
	background: var(--color-background-dark);
}

.source-main {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
}

.source-path {
	font-weight: 600;
	word-break: break-all;
}

.status-pill {
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}

.status-ready {
	background: var(--color-success);
	color: #000;
}

.status-pending {
	background: var(--color-warning);
	color: #000;
}

.status-error {
	background: var(--color-error);
	color: #fff;
}

.source-meta {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin-top: 6px;
}

.source-meta.source-error {
	color: var(--color-error);
}

.source-actions {
	display: flex;
	gap: 8px;
	margin-top: 10px;
}

.button.subtle {
	border-color: var(--color-border);
	background: var(--color-main-background);
}

.button.subtle:hover {
	background: var(--color-background-hover);
}

.tool-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 12px;
}

.tool-row {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-background-dark);
}

.tool-row--disabled {
	opacity: 0.65;
	background: var(--color-background-hover);
}

.tool-row input[type="checkbox"] {
	margin-top: 4px;
}

.tool-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.tool-name {
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
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
	font-size: 13px;
	color: var(--color-text-lighter);
}

.tool-unavailable {
	color: var(--color-error);
}

.tool-endpoint {
	font-size: 12px;
	color: var(--color-text-lighter);
	word-break: break-all;
}

.hint.muted {
	color: var(--color-text-lighter);
}

/* Progress bar styles for RAG indexing */
.source-progress {
	margin-top: 10px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.progress-container {
	width: 100%;
}

.progress-container :deep(.progress-bar) {
	width: 100%;
}

.progress-label {
	font-size: 12px;
	color: var(--color-text-lighter);
	display: flex;
	align-items: center;
	gap: 4px;
}

/* Disable actions while indexing */
.source-actions .button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

/* URL Modal Styles */
.url-modal-overlay {
	position: fixed;
	z-index: 10000;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
}

.url-modal {
	background: var(--color-main-background);
	border-radius: 8px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
	max-width: 500px;
	width: 90%;
}

.url-modal-header {
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.url-modal-header h3 {
	margin: 0;
	font-size: 18px;
}

.url-modal-body {
	padding: 20px;
}

.url-modal-body .form-group {
	margin-bottom: 0;
}

.url-modal-footer {
	padding: 16px 20px;
	border-top: 1px solid var(--color-border);
	display: flex;
	gap: 10px;
	justify-content: flex-end;
}

/* URL source icon indicator */
.source-type-icon {
	display: inline-flex;
	align-items: center;
	margin-right: 6px;
	color: var(--color-text-lighter);
}

.source-type-icon.url-icon::before {
	content: "🔗";
	font-size: 14px;
}

.source-type-icon.file-icon::before {
	content: "📄";
	font-size: 14px;
}

.source-type-icon.folder-icon::before {
	content: "📁";
	font-size: 14px;
}

/* Approval notice */
.approval-notice {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	margin-top: 10px;
	padding: 10px 14px;
	background: var(--color-warning-light, #fff3cd);
	border: 1px solid var(--color-warning);
	border-radius: 6px;
	font-size: 13px;
	color: var(--color-warning-text, #856404);
}

.approval-notice .icon-info {
	flex-shrink: 0;
	margin-top: 2px;
}

.pending-changes-notice {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	margin-bottom: 20px;
	padding: 14px 16px;
	background: var(--color-primary-element-light, #e8f4fd);
	border: 1px solid var(--color-primary-element, #0082c9);
	border-radius: 8px;
	font-size: 13px;
	color: var(--color-main-text);
}

.pending-changes-notice .icon-info {
	flex-shrink: 0;
	margin-top: 2px;
	color: var(--color-primary-element, #0082c9);
}

.pending-changes-notice .notice-content {
	flex: 1;
}

.pending-changes-notice .notice-content strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-primary-element, #0082c9);
}

.pending-changes-notice .notice-content p {
	margin: 0;
	color: var(--color-text-lighter);
}

/* Onboarding Questions Styles */
.onboarding-empty {
	padding: 20px;
	text-align: center;
	background: var(--color-background-dark);
	border-radius: 8px;
	margin-top: 12px;
}

.onboarding-empty p {
	margin: 0 0 12px 0;
	color: var(--color-text-lighter);
}

.onboarding-questions-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 12px;
}

.onboarding-question-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-dark);
	overflow: hidden;
}

.question-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 14px;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}

.question-badge {
	background: var(--color-primary-element);
	color: white;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: 600;
}

.question-id {
	color: var(--color-text-lighter);
	font-size: 12px;
	font-family: monospace;
}

.question-header .button {
	margin-left: auto;
}

.question-body {
	padding: 14px;
}

.question-body .form-group.compact {
	margin-bottom: 14px;
}

.question-body .form-group.compact label {
	font-size: 12px;
	margin-bottom: 4px;
}

.answers-grid {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.answer-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.answer-label {
	width: 24px;
	height: 24px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	border-radius: 50%;
	font-size: 12px;
	font-weight: 600;
	flex-shrink: 0;
}

input.answer-text {
	flex: 1;
	min-width: 0;
	width: auto;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 14px;
}

input.answer-text.free-text:disabled {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
	font-style: italic;
}

select.answer-next {
	width: 160px;
	flex: 0 0 160px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 13px;
	background: var(--color-main-background);
}

.add-answer-btn {
	margin-top: 8px;
	align-self: flex-start;
}

.button.small {
	padding: 4px 10px;
	font-size: 12px;
}

.temperature-field {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 10px;
}

.temperature-readonly {
	margin-top: 10px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-background-dark);
	font-size: 14px;
}

.inline-error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
	font-weight: 600;
}

.onboarding-error {
	margin-top: 12px;
	padding: 10px 14px;
	background: var(--color-error-light, #fdecea);
	border: 1px solid color-mix(in srgb, var(--color-error, #b91c1c) 65%, #000 35%);
	border-radius: 6px;
	font-size: 13px;
	color: var(--color-main-text, #111);
	font-weight: 600;
}
</style>
