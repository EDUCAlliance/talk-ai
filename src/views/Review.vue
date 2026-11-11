<template>
	<section class="talkai-screen">
		<header class="talkai-page-head">
			<div>
				<h2 class="talkai-page-head__title">
					{{ t('educai', 'To review') }}
				</h2>
				<p class="talkai-page-head__lead">
					{{ t('educai', 'Bots waiting for approval for your groups and teams. You only see what you are allowed to approve.') }}
				</p>
			</div>
		</header>

		<div v-if="failed" class="talkai-state" role="alert">
			<span class="talkai-state__icon"><TalkAiIcon name="alert" :size="26" /></span>
			<b class="talkai-state__title">{{ t('educai', 'The reviews could not be loaded') }}</b>
			<button type="button" class="talkai-btn" @click="load">
				{{ t('educai', 'Try again') }}
			</button>
		</div>
		<div v-else-if="!loaded" class="talkai-state" role="status">
			<NcLoadingIcon :size="32" />
			<span>{{ t('educai', 'Loading reviews…') }}</span>
		</div>
		<div v-else-if="entries.length === 0" class="talkai-state">
			<span class="talkai-state__icon talkai-state__icon--ok"><TalkAiIcon name="check" :size="26" /></span>
			<b class="talkai-state__title">{{ t('educai', 'Everything reviewed') }}</b>
			<p class="talkai-state__text">
				{{ t('educai', 'New requests appear here.') }}
			</p>
		</div>

		<div v-else class="talkai-review">
			<nav class="talkai-queue" :aria-label="t('educai', 'Bots to review')">
				<button v-for="entry in entries"
					:key="entry.bot.id"
					type="button"
					class="talkai-queue__item"
					:aria-current="current && entry.bot.id === current.bot.id ? 'true' : undefined"
					@click="select(entry.bot.id)">
					<BotAvatar :name="entry.target.bot_name" :scope="entry.scope.key" size="sm" />
					<span>
						<b class="talkai-queue__name">{{ entry.target.bot_name }}</b>
						<span class="talkai-queue__sub">{{ entry.isUpdate ? t('educai', 'Changed bot') : t('educai', 'New bot') }} · {{ entry.bot.owner_name }}</span>
						<span class="talkai-queue__sub">{{ entry.scope.detail }}<template v-if="entry.submitted"> · {{ entry.submitted }}</template></span>
					</span>
				</button>
			</nav>

			<div v-if="current">
				<div class="talkai-bot-head talkai-review__head">
					<BotAvatar :name="current.target.bot_name" :scope="current.scope.key" size="lg" />
					<div class="talkai-bot-head__text">
						<h3 class="talkai-review__title">
							{{ current.target.bot_name }}
						</h3>
						<div class="talkai-row talkai-bot-head__meta">
							<span class="talkai-chip talkai-chip--info">{{ current.isUpdate ? t('educai', 'Change to an approved bot') : t('educai', 'New bot') }}</span>
							<span class="talkai-muted talkai-editor__small">{{ current.byline }}</span>
						</div>
					</div>
					<button type="button"
						class="talkai-btn"
						:disabled="busy"
						@click="testBot(current.bot)">
						<TalkAiIcon name="chat" :size="16" />
						{{ t('educai', 'Test it yourself') }}
					</button>
				</div>

				<div v-if="current.isUpdate" class="talkai-section">
					<h4 class="talkai-section__title">
						{{ t('educai', 'What has changed?') }}
					</h4>
					<p class="talkai-section__lead">
						{{ t('educai', 'Compared with the approved version, which stays live until you decide.') }}
					</p>
					<div v-if="current.changes.length > 0" class="talkai-diff">
						<div v-for="(change, index) in current.changes"
							:key="index"
							class="talkai-diff__line"
							:class="`talkai-diff__line--${change.kind}`">
							<span class="hidden-visually">{{ change.kind === 'add' ? t('educai', 'Added:') : t('educai', 'Removed:') }}</span>
							{{ change.text }}
						</div>
					</div>
					<p v-else class="talkai-muted">
						{{ t('educai', 'No difference in the fields this view compares. Check the instruction and the sources below.') }}
					</p>
				</div>

				<div class="talkai-grid2">
					<div class="talkai-section">
						<h4 class="talkai-section__title">
							{{ t('educai', 'Reason') }}
						</h4>
						<p v-if="current.bot.approval_reason" class="talkai-review__quote">
							“{{ current.bot.approval_reason }}”
						</p>
						<p v-else class="talkai-muted">
							{{ t('educai', 'No reason given.') }}
						</p>
						<p v-if="current.bot.testing_description" class="talkai-hint">
							{{ t('educai', 'How it was tested: {text}', { text: current.bot.testing_description }) }}
						</p>
					</div>
					<div class="talkai-section">
						<h4 class="talkai-section__title">
							{{ t('educai', 'At a glance') }}
						</h4>
						<dl class="talkai-dl talkai-dl--narrow">
							<dt class="talkai-dl__term">
								{{ t('educai', 'For') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ current.scope.detail }}
							</dd>
							<dt class="talkai-dl__term">
								{{ t('educai', 'Can') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ current.abilities }}
							</dd>
							<dt class="talkai-dl__term">
								{{ t('educai', 'Knows') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ sourcesSummary }}
							</dd>
						</dl>
					</div>
				</div>

				<div class="talkai-section">
					<h4 class="talkai-section__title">
						{{ t('educai', 'Description and instruction') }}
					</h4>
					<p class="talkai-section__lead">
						{{ current.target.description || t('educai', 'No description yet.') }}
					</p>
					<p class="talkai-dialog__prompt">
						{{ current.target.system_prompt }}
					</p>
				</div>

				<div v-if="sources.length > 0 || sourcesError" class="talkai-section">
					<h4 class="talkai-section__title">
						{{ t('educai', 'Knowledge sources') }}
					</h4>
					<p v-if="sourcesError" class="talkai-hint talkai-hint--error" role="alert">
						{{ sourcesError }}
					</p>
					<ul v-else class="talkai-sources talkai-review__sources">
						<li v-for="source in sources" :key="source.id" class="talkai-src">
							<span class="talkai-src__icon"><TalkAiIcon :name="source.node_type === 'url' ? 'link' : (source.node_type === 'folder' ? 'folder' : 'file')" /></span>
							<span class="talkai-src__main">
								<b class="talkai-src__name">{{ source.display_name || source.path || source.source_url }}</b>
							</span>
							<span class="talkai-chip talkai-chip--dot" :class="`talkai-chip--${source.status === 'ready' ? 'ok' : (source.status === 'pending' ? 'info' : 'warn')}`">
								{{ sourceStatus(source.status) }}
							</span>
							<a v-if="sourceUrl(source)"
								class="talkai-go"
								:href="sourceUrl(source)"
								target="_blank"
								rel="noopener noreferrer">{{ t('educai', 'Open') }}</a>
						</li>
					</ul>
				</div>

				<div class="talkai-decision">
					<label class="talkai-label" for="talkai-review-feedback">
						{{ t('educai', 'Feedback for {owner}', { owner: current.bot.owner_name }) }}
						<span class="talkai-label__aside">· {{ t('educai', 'optional, sent along when you reject') }}</span>
					</label>
					<textarea id="talkai-review-feedback"
						v-model="feedback"
						class="talkai-input"
						rows="2" />
					<div class="talkai-row talkai-decision__actions">
						<button type="button"
							class="talkai-btn talkai-btn--danger"
							:disabled="busy"
							@click="askReject(current.bot)">
							{{ t('educai', 'Reject') }}
						</button>
						<button type="button"
							class="talkai-btn talkai-btn--filled"
							:disabled="busy"
							@click="approve(current.bot)">
							<TalkAiIcon name="check" :size="16" />
							{{ t('educai', 'Approve') }}
						</button>
					</div>
				</div>
			</div>
		</div>

		<TalkAiDialog v-if="rejectCandidate"
			:name="t('educai', 'Reject {bot}?', { bot: rejectCandidate.bot_name })"
			size="small"
			@close="cancelReject">
			<p>
				{{ rejectCandidate.has_pending_changes
					? t('educai', 'The change is discarded and the approved version stays live.')
					: t('educai', 'The bot goes back to {owner} as a draft.', { owner: rejectCandidate.owner_name }) }}
			</p>
			<p v-if="feedback.trim()" class="talkai-review__quote">
				“{{ feedback.trim() }}”
			</p>
			<p v-else class="talkai-muted">
				{{ t('educai', 'You have not written feedback. You can reject without it.') }}
			</p>
			<template #actions>
				<button type="button"
					class="talkai-btn"
					:disabled="busy"
					@click="cancelReject">
					{{ t('educai', 'Cancel') }}
				</button>
				<button type="button"
					class="talkai-btn talkai-btn--danger"
					:disabled="busy"
					@click="confirmReject">
					{{ t('educai', 'Reject') }}
				</button>
			</template>
		</TalkAiDialog>
		<BotTalkDialog v-if="talkBot" :bot="talkBot" @close="talkBot = null" />
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import BotAvatar from '../components/BotAvatar.vue'
import BotTalkDialog from '../components/BotTalkDialog.vue'
import TalkAiDialog from '../components/TalkAiDialog.vue'
import TalkAiIcon from '../components/TalkAiIcon.vue'
import { getCanonicalLocale, n, t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { diffVersions } from '../utils/botDiff.js'
import { scopeOf } from '../utils/botScope.js'
import { capabilitiesOf } from '../utils/capabilities.js'

// Bots waiting for the approval of this user: what was submitted, what it changes, and the decision.
export default {
	name: 'Review',
	components: { BotAvatar, BotTalkDialog, NcLoadingIcon, TalkAiDialog, TalkAiIcon },

	data() {
		return {
			selectedId: null,
			feedback: '',
			busy: false,
			rejectCandidate: null,
			talkBot: null,
			sources: [],
			sourcesError: '',
			sourcesFor: null,
		}
	},

	computed: {
		loaded() {
			return store.state.loaded.approvals
		},
		failed() {
			return store.state.failed.approvals
		},
		entries() {
			return store.state.approvals.map(bot => this.describe(bot))
		},
		current() {
			return this.entries.find(entry => entry.bot.id === this.selectedId) ?? this.entries[0] ?? null
		},
		sourcesSummary() {
			if (!this.current.target.rag_enabled) {
				return t('educai', 'No stored sources')
			}
			return this.sourcesFor === this.current.bot.id
				? n('educai', '%n source', '%n sources', this.sources.length)
				: t('educai', 'Loading sources…')
		},
	},

	watch: {
		'$route.params.id'(id) {
			this.selectedId = id === undefined ? null : Number(id)
		},
		current: {
			handler(current, previous) {
				if (current?.bot.id !== previous?.bot.id) {
					this.feedback = ''
					this.loadSources()
				}
			},
		},
	},

	mounted() {
		const id = this.$route?.params?.id
		this.selectedId = id === undefined ? null : Number(id)
		this.load()
		this.loadSources()
	},

	methods: {
		t,
		load() {
			store.loadApprovals().catch(error => console.error('Failed to load approvals', error))
		},
		select(id) {
			this.selectedId = id
			this.$router?.replace(`/review/${id}`)
		},
		// The version to review, next to the version people use now.
		describe(bot) {
			const target = bot.review_target ?? {
				bot_name: bot.bot_name,
				description: bot.description,
				system_prompt: bot.system_prompt,
				temperature: bot.temperature,
				visibility: bot.visibility || (bot.is_public ? 'global' : 'groups'),
				allowed_groups: bot.allowed_groups ?? [],
				allowed_teams: bot.allowed_teams ?? [],
				rag_enabled: bot.rag_enabled,
				onboarding_questions: bot.onboarding_questions,
				tools: [],
				is_update: !!bot.has_pending_changes,
			}
			// Team ids say nothing; the review delivers the names.
			const teams = (target.allowed_teams ?? []).map((id, index) => ({ id, displayName: target.allowed_team_names?.[index] ?? id }))
			const directory = { groups: store.state.directory.groups, teams: [...teams, ...store.state.directory.teams] }
			const scope = scopeOf(target, directory)
			const abilities = capabilitiesOf(target).map(capability => capability.label)
			const isUpdate = !!target.is_update
			const names = tools => Array.isArray(tools) ? capabilitiesOf({ tools }).map(capability => capability.label) : null
			return {
				bot,
				target,
				scope,
				isUpdate,
				submitted: this.formatDate(bot.submitted_at),
				byline: bot.submitted_at
					? t('educai', 'submitted by {owner} on {date}', { owner: bot.owner_name, date: this.formatDate(bot.submitted_at) })
					: t('educai', 'submitted by {owner}', { owner: bot.owner_name }),
				abilities: abilities.length > 0 ? abilities.join(' · ') : t('educai', 'Answer in the chat'),
				changes: isUpdate
					? diffVersions({
						name: bot.bot_name,
						description: bot.description,
						instruction: bot.system_prompt,
						audience: scopeOf(bot, directory).detail,
						tools: names(bot.live_tools),
						knowledge: bot.rag_enabled,
						temperature: bot.temperature,
						questions: bot.onboarding_questions,
					}, {
						name: target.bot_name,
						description: target.description,
						instruction: target.system_prompt,
						audience: scope.detail,
						tools: Array.isArray(bot.live_tools) ? names(target.tools) : null,
						knowledge: target.rag_enabled,
						temperature: target.temperature,
						questions: target.onboarding_questions,
					})
					: [],
			}
		},
		formatDate(timestamp) {
			return timestamp
				? new Date(timestamp * 1000).toLocaleDateString(getCanonicalLocale(), { day: 'numeric', month: 'short', year: 'numeric' })
				: ''
		},
		async loadSources() {
			const current = this.current
			this.sources = []
			this.sourcesError = ''
			this.sourcesFor = null
			if (!current || !current.target.rag_enabled) {
				return
			}
			try {
				const sources = await store.fetchSources(current.bot.id)
				if (this.current?.bot.id === current.bot.id) {
					this.sources = sources
					this.sourcesFor = current.bot.id
				}
			} catch (error) {
				console.error('Failed to load sources for review', error)
				if (this.current?.bot.id === current.bot.id) {
					this.sourcesError = getApiErrorMessage(error, t('educai', 'The knowledge sources could not be loaded.'))
					this.sourcesFor = current.bot.id
				}
			}
		},
		sourceUrl(source) {
			if (source.node_type === 'url' && source.source_url) {
				return source.source_url
			}
			return Number(source.node_id) > 0 ? generateUrl(`/f/${source.node_id}`) : null
		},
		sourceStatus(status) {
			return {
				ready: t('educai', 'Ready'),
				pending: t('educai', 'Reading'),
				unavailable: t('educai', 'Not reachable'),
			}[status] ?? t('educai', 'Not readable')
		},
		async testBot(bot) {
			this.busy = true
			try {
				await store.enableTest(bot.id)
				this.talkBot = { ...bot, bot_name: bot.review_target?.bot_name ?? bot.bot_name }
			} catch (error) {
				console.error('Failed to enable testing:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to enable testing')))
			} finally {
				this.busy = false
			}
		},
		async approve(bot) {
			this.busy = true
			try {
				await store.approveBot(bot.id)
				showSuccess(bot.has_pending_changes
					? t('educai', 'Approved. The new version is live now.')
					: t('educai', 'Approved. The bot is live now.'))
				this.afterDecision()
			} catch (error) {
				console.error('Failed to approve bot:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to approve bot')))
			} finally {
				this.busy = false
			}
		},
		// Rejecting asks once more: it sends the bot back to its owner.
		askReject(bot) {
			this.rejectCandidate = bot
		},
		cancelReject() {
			this.rejectCandidate = null
		},
		async confirmReject() {
			const bot = this.rejectCandidate
			if (!bot) {
				return
			}
			this.busy = true
			try {
				const feedback = this.feedback.trim()
				await store.rejectBot(bot.id, feedback)
				showSuccess(feedback === ''
					? t('educai', 'Rejected')
					: t('educai', 'Rejected. {owner} sees your feedback.', { owner: bot.owner_name }))
				this.rejectCandidate = null
				this.afterDecision()
			} catch (error) {
				console.error('Failed to reject bot:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to reject bot')))
			} finally {
				this.busy = false
			}
		},
		afterDecision() {
			this.feedback = ''
			this.selectedId = null
			this.$router?.replace('/review')
		},
	},
}
</script>
