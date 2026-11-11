<template>
	<section class="talkai-screen">
		<div v-if="loadError" class="talkai-state" role="alert">
			<span class="talkai-state__icon"><TalkAiIcon name="alert" :size="26" /></span>
			<b class="talkai-state__title">{{ loadError }}</b>
			<router-link class="talkai-btn" to="/my-bots">
				{{ t('educai', 'Back to My bots') }}
			</router-link>
		</div>
		<div v-else-if="!bot" class="talkai-state" role="status">
			<NcLoadingIcon :size="32" />
			<span>{{ t('educai', 'Loading bot…') }}</span>
		</div>

		<template v-else>
			<nav class="talkai-crumbs" :aria-label="t('educai', 'Breadcrumb')">
				<router-link class="talkai-crumbs__link" to="/my-bots">
					{{ t('educai', 'My bots') }}
				</router-link>
				<TalkAiIcon name="chevron" :size="14" />
				<span>{{ bot.bot_name }}</span>
			</nav>
			<div class="talkai-bot-head">
				<BotAvatar :name="bot.bot_name" :scope="scope.key" size="lg" />
				<div class="talkai-bot-head__text">
					<h2 class="talkai-bot-head__title">
						{{ bot.bot_name }}
					</h2>
					<div class="talkai-row talkai-bot-head__meta">
						<span class="talkai-bot-head__mention">{{ mention }}</span>
						<span v-for="chip in status.chips"
							:key="chip.key"
							class="talkai-chip"
							:class="[`talkai-chip--${chip.tone}`, { 'talkai-chip--dot': chip.dot }]">{{ chip.label }}</span>
						<span class="talkai-who">
							<TalkAiIcon :name="scopeIcon" :size="15" />
							<span class="talkai-who__text">{{ scope.detail }}</span>
						</span>
					</div>
				</div>
				<div class="talkai-row">
					<button type="button" class="talkai-btn talkai-btn--filled" @click="talkOpen = true">
						<TalkAiIcon name="chat" :size="16" />
						{{ t('educai', 'Use in Talk') }}
					</button>
					<NcActions force-menu :aria-label="t('educai', 'More actions')">
						<NcActionButton close-after-click @click="deleteOpen = true">
							<template #icon>
								<TalkAiIcon name="trash" :size="20" />
							</template>
							{{ t('educai', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<nav class="talkai-tabs" :aria-label="t('educai', 'Bot settings')">
				<router-link v-for="entry in tabs"
					:key="entry.key"
					class="talkai-tabs__tab"
					:class="{ 'is-current': entry.key === tab }"
					:aria-current="entry.key === tab ? 'page' : undefined"
					:to="entry.key === 'overview' ? `/bots/${bot.id}` : `/bots/${bot.id}/${entry.key}`">
					{{ entry.label }}
					<span v-if="entry.badge"
						class="talkai-chip talkai-chip--warn talkai-tabs__badge"
						:aria-label="entry.badgeLabel">{{ entry.badge }}</span>
				</router-link>
			</nav>

			<!-- Overview -->
			<div v-if="tab === 'overview'">
				<div v-if="statusNote" class="talkai-note talkai-bot__note" :class="`talkai-note--${statusNote.tone}`">
					<TalkAiIcon :name="statusNote.icon" />
					<span>
						{{ statusNote.text }}
						<router-link v-if="statusNote.link" class="talkai-linkish" :to="statusNote.link.to">{{ statusNote.link.label }}</router-link>
					</span>
					<button v-if="status.stage === 'draft' || status.stage === 'feedback'"
						type="button"
						class="talkai-btn talkai-btn--filled talkai-bot__note-action"
						@click="submitOpen = true">
						{{ t('educai', 'Submit for approval') }}
					</button>
				</div>
				<div v-if="preview && preview.sourceProblemCount > 0" class="talkai-note talkai-note--warn talkai-bot__note">
					<TalkAiIcon name="alert" />
					<span>
						{{ n('educai', '%n source is not readable.', '%n sources are not readable.', preview.sourceProblemCount) }}
						<router-link class="talkai-linkish" :to="`/bots/${bot.id}/knowledge`">{{ t('educai', 'Fix') }}</router-link>
					</span>
				</div>
				<div class="talkai-grid2">
					<div class="talkai-section">
						<h3 class="talkai-section__title">
							{{ t('educai', 'In short') }}
						</h3>
						<p class="talkai-section__lead">
							{{ bot.description || t('educai', 'No description yet.') }}
						</p>
						<dl class="talkai-dl">
							<dt class="talkai-dl__term">
								{{ t('educai', 'Can') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ preview ? preview.abilities : t('educai', 'Loading…') }}
							</dd>
							<dt class="talkai-dl__term">
								{{ t('educai', 'Knows') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ preview ? preview.knowledge : t('educai', 'Loading…') }}
							</dd>
							<dt class="talkai-dl__term">
								{{ t('educai', 'For') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ scope.detail }}
							</dd>
							<dt class="talkai-dl__term">
								{{ t('educai', 'Model') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ bot.model || t('educai', 'Recommended by the administration') }}
							</dd>
						</dl>
					</div>
					<div class="talkai-section">
						<h3 class="talkai-section__title">
							{{ t('educai', 'History') }}
						</h3>
						<p class="talkai-section__lead">
							{{ t('educai', 'Creation and approval of this bot.') }}
						</p>
						<ul class="talkai-timeline">
							<li v-for="entry in history" :key="entry.key" class="talkai-timeline__item">
								<span class="talkai-timeline__dot" :class="`talkai-timeline__dot--${entry.tone}`">
									<TalkAiIcon v-if="entry.tone === 'ok'" name="check" :size="12" />
								</span>
								<span>
									<b>{{ entry.title }}</b>
									<span class="talkai-checks__aside">{{ entry.text }}</span>
								</span>
							</li>
						</ul>
					</div>
				</div>
			</div>

			<div v-if="bot.has_pending_changes && editorSection" class="talkai-note talkai-note--info talkai-bot__note">
				<TalkAiIcon name="clock" />
				<span>{{ t('educai', 'You see the version that is in review. The approved version stays live until approval.') }}</span>
			</div>
			<BotEditor ref="editor"
				mode="page"
				:section="editorSection"
				:bot="bot"
				:user-permissions="permissions"
				@save="saveBot"
				@update:dirty="dirty = $event"
				@update:preview="preview = $event" />

			<!-- Access & approval: where the bot stands -->
			<div v-if="tab === 'access'" class="talkai-section">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Approval') }}
				</h3>
				<p class="talkai-section__lead">
					{{ approvalText }}
				</p>
				<div v-if="pendingDiff.length > 0" class="talkai-diff">
					<div v-for="(change, index) in pendingDiff"
						:key="index"
						class="talkai-diff__line"
						:class="`talkai-diff__line--${change.kind}`">
						<span class="hidden-visually">{{ change.kind === 'add' ? t('educai', 'Added:') : t('educai', 'Removed:') }}</span>
						{{ change.text }}
					</div>
				</div>
				<div v-if="status.feedback" class="talkai-feedback talkai-bot__feedback">
					{{ t('educai', 'Reviewer: “{feedback}”', { feedback: status.feedback }) }}
				</div>
				<div v-if="status.stage === 'draft' || status.stage === 'feedback'" class="talkai-row talkai-editor__gap">
					<button type="button"
						class="talkai-btn talkai-btn--filled"
						:disabled="dirty"
						@click="submitOpen = true">
						{{ t('educai', 'Submit for approval') }}
					</button>
					<span v-if="dirty" class="talkai-muted">{{ t('educai', 'Save your changes first.') }}</span>
				</div>
			</div>

			<div v-if="tab === 'advanced'" class="talkai-section">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Technical details') }}
				</h3>
				<dl class="talkai-dl">
					<dt class="talkai-dl__term">
						{{ t('educai', 'Bot ID') }}
					</dt>
					<dd class="talkai-dl__value talkai-mono">
						{{ bot.id }}
					</dd>
					<dt class="talkai-dl__term">
						{{ t('educai', 'Mention') }}
					</dt>
					<dd class="talkai-dl__value talkai-mono">
						{{ mention }}
					</dd>
				</dl>
			</div>

			<div v-if="dirty"
				class="talkai-savebar"
				role="region"
				:aria-label="t('educai', 'Unsaved changes')">
				<span class="talkai-savebar__dot" aria-hidden="true" />
				<span class="talkai-savebar__text">
					{{ t('educai', 'Unsaved changes') }}
					<span v-if="goesToReview" class="talkai-savebar__aside">· {{ t('educai', 'They only go live after a new approval.') }}</span>
				</span>
				<button type="button"
					class="talkai-btn talkai-btn--ghost talkai-savebar__discard"
					:disabled="saving"
					@click="discard">
					{{ t('educai', 'Discard') }}
				</button>
				<button type="button"
					class="talkai-btn talkai-savebar__save"
					:disabled="saving"
					@click="save">
					{{ saveLabel }}
				</button>
			</div>

			<BotTalkDialog v-if="talkOpen" :bot="bot" @close="talkOpen = false" />
			<BotSubmitDialog v-if="submitOpen"
				:bot="submitTarget"
				:directory="directory"
				@close="submitOpen = false"
				@submitted="onSubmitted" />
			<TalkAiDialog v-if="deleteOpen"
				:name="t('educai', 'Delete {bot}?', { bot: bot.bot_name })"
				size="small"
				@close="deleteOpen = false">
				<p>{{ t('educai', 'The bot, its knowledge index and its conversation history are deleted. Files in your Nextcloud stay unchanged.') }}</p>
				<template #actions>
					<button type="button"
						class="talkai-btn"
						:disabled="deleting"
						@click="deleteOpen = false">
						{{ t('educai', 'Cancel') }}
					</button>
					<button type="button"
						class="talkai-btn talkai-btn--danger"
						:disabled="deleting"
						@click="deleteBot">
						{{ t('educai', 'Delete') }}
					</button>
				</template>
			</TalkAiDialog>
		</template>
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import BotAvatar from '../components/BotAvatar.vue'
import BotEditor from '../components/BotEditor.vue'
import BotSubmitDialog from '../components/BotSubmitDialog.vue'
import BotTalkDialog from '../components/BotTalkDialog.vue'
import TalkAiDialog from '../components/TalkAiDialog.vue'
import TalkAiIcon from '../components/TalkAiIcon.vue'
import { getCanonicalLocale, n, t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { diffVersions } from '../utils/botDiff.js'
import { SCOPE_ICONS, reviewersOf, scopeOf } from '../utils/botScope.js'
import { statusOf } from '../utils/botStatus.js'

const TABS = ['overview', 'basics', 'knowledge', 'capabilities', 'access', 'advanced']
const SECTIONS = { basics: 'basics', knowledge: 'knowledge', capabilities: 'capabilities', access: 'audience', advanced: 'advanced' }

// One bot: where it stands, and its settings in tabs. Changes are collected and saved with the bar at the bottom.
export default {
	name: 'BotPage',
	components: { BotAvatar, BotEditor, BotSubmitDialog, BotTalkDialog, NcActionButton, NcActions, NcLoadingIcon, TalkAiDialog, TalkAiIcon },

	beforeRouteLeave() {
		return !this.dirty || window.confirm(t('educai', 'Leave without saving? Your changes will be lost.'))
	},

	data() {
		return {
			bot: null,
			loadError: '',
			dirty: false,
			saving: false,
			preview: null,
			talkOpen: false,
			submitOpen: false,
			deleteOpen: false,
			deleting: false,
		}
	},

	computed: {
		permissions() {
			return store.state.permissions
		},
		directory() {
			return store.state.directory
		},
		tab() {
			return TABS.includes(this.$route.params.tab) ? this.$route.params.tab : 'overview'
		},
		editorSection() {
			return SECTIONS[this.tab] ?? ''
		},
		tabs() {
			const problems = this.preview?.sourceProblemCount ?? 0
			return [
				{ key: 'overview', label: t('educai', 'Overview') },
				{ key: 'basics', label: t('educai', 'Basics') },
				{
					key: 'knowledge',
					label: t('educai', 'Knowledge'),
					badge: problems > 0 ? String(problems) : '',
					badgeLabel: n('educai', '%n problem', '%n problems', problems),
				},
				{ key: 'capabilities', label: t('educai', 'Capabilities') },
				{ key: 'access', label: t('educai', 'Access & approval') },
				{ key: 'advanced', label: t('educai', 'Advanced') },
			]
		},
		scope() {
			return scopeOf(this.bot, this.directory)
		},
		scopeIcon() {
			return SCOPE_ICONS[this.scope.key]
		},
		status() {
			return statusOf(this.bot)
		},
		mention() {
			return `@${String(this.bot.mention_name || '').replace(/^@+/, '')}`
		},
		// The own list entry carries the tools of the live version; the single bot does not.
		listEntry() {
			return store.state.ownBots.find(own => own.id === this.bot.id) ?? null
		},
		submitTarget() {
			return { ...this.bot, tools: this.preview?.bot.tools ?? [] }
		},
		goesToReview() {
			return !!this.preview?.saveGoesToReview
		},
		saveLabel() {
			if (this.saving) {
				return t('educai', 'Saving…')
			}
			return this.goesToReview ? t('educai', 'Save and submit') : t('educai', 'Save')
		},
		statusNote() {
			const submitted = this.formatDate(this.bot.submitted_at)
			switch (this.status.stage) {
			case 'change-in-review':
				return {
					tone: 'info',
					icon: 'clock',
					text: submitted
						? t('educai', 'Your changes from {date} are being reviewed. Until approval the previous version keeps running.', { date: submitted })
						: t('educai', 'Your changes are being reviewed. Until approval the previous version keeps running.'),
					link: { to: `/bots/${this.bot.id}/access`, label: t('educai', 'Details') },
				}
			case 'in-review':
				return {
					tone: 'info',
					icon: 'clock',
					text: t('educai', 'Waiting for approval by {reviewers}. Until then only you and the reviewers can use the bot.', { reviewers: reviewersOf(this.bot, this.directory) }),
				}
			case 'draft':
				return {
					tone: 'warn',
					icon: 'info',
					text: t('educai', 'This bot is a draft: only you can use it. Test it in Talk, then submit it for approval.'),
				}
			case 'feedback':
				return {
					tone: 'err',
					icon: 'info',
					text: t('educai', 'The reviewer sent it back: “{feedback}” Revise the bot and submit it again.', { feedback: this.status.feedback }),
				}
			default:
				return this.status.feedback
					? { tone: 'err', icon: 'info', text: t('educai', 'Your last change was not approved: “{feedback}” The approved version stays live.', { feedback: this.status.feedback }) }
					: null
			}
		},
		approvalText() {
			const reviewers = reviewersOf(this.bot, this.directory)
			switch (this.status.stage) {
			case 'personal':
				return t('educai', 'A bot only for you needs no approval.')
			case 'draft':
				return t('educai', 'Not submitted yet. Reviewed by: {reviewers}.', { reviewers })
			case 'feedback':
				return t('educai', 'Sent back by the reviewer. Revise the bot and submit it again.')
			case 'in-review':
				return t('educai', 'Submitted on {date}. Reviewed by: {reviewers}.', { date: this.formatDate(this.bot.submitted_at), reviewers })
			case 'change-in-review':
				return t('educai', 'A change was submitted on {date} and is waiting for approval. The approved version stays live.', { date: this.formatDate(this.bot.submitted_at) })
			default:
				return this.bot.approved_at
					? t('educai', 'Approved on {date}. Later changes are reviewed again.', { date: this.formatDate(this.bot.approved_at) })
					: t('educai', 'Approved. Later changes are reviewed again.')
			}
		},
		// What the version in review changes, compared with the live one.
		pendingDiff() {
			const pending = this.bot.pending_changes
			if (!this.bot.has_pending_changes || !pending || this.dirty) {
				return []
			}
			const list = value => typeof value === 'string' ? JSON.parse(value || '[]') : (value ?? [])
			const liveTools = Array.isArray(this.listEntry?.tools) ? this.listEntry.tools.map(tool => tool.name) : null
			const nextBot = {
				visibility: pending.visibility ?? this.bot.visibility,
				allowed_groups: list(pending.allowed_groups),
				allowed_teams: list(pending.allowed_teams),
			}
			return diffVersions({
				name: this.bot.bot_name,
				description: this.bot.description,
				instruction: this.bot.system_prompt,
				audience: this.scope.detail,
				tools: liveTools,
				knowledge: this.bot.rag_enabled,
				model: this.bot.model,
				temperature: this.bot.temperature,
				questions: this.bot.onboarding_questions,
			}, {
				name: pending.bot_name ?? this.bot.bot_name,
				description: pending.description,
				instruction: pending.system_prompt ?? this.bot.system_prompt,
				audience: scopeOf(nextBot, this.directory).detail,
				tools: liveTools && this.preview ? this.preview.bot.tools.map(tool => tool.name) : null,
				knowledge: pending.rag_enabled ?? this.bot.rag_enabled,
				model: pending.model,
				temperature: pending.temperature,
				questions: pending.onboarding_questions,
			})
		},
		history() {
			const entries = []
			const push = (key, time, title, tone, text = '') => {
				if (time) {
					entries.push({ key, time, title, tone, text: [this.formatDate(time), text].filter(Boolean).join(' · ') })
				}
			}
			push('created', this.bot.created_at, t('educai', 'Created'), 'ok')
			push('approved', this.bot.approved_at, t('educai', 'Approved'), 'ok')
			if (this.status.stage === 'in-review') {
				push('submitted', this.bot.submitted_at, t('educai', 'Submitted for approval'), 'current')
			} else if (this.status.stage === 'change-in-review') {
				push('submitted', this.bot.submitted_at, t('educai', 'Change submitted'), 'current')
			}
			entries.sort((a, b) => b.time - a.time)
			if (this.status.feedback) {
				entries.unshift({ key: 'feedback', title: t('educai', 'Sent back by the reviewer'), tone: 'current', text: `“${this.status.feedback}”` })
			}
			return entries
		},
	},

	watch: {
		'$route.params.id': {
			immediate: true,
			handler(id, previous) {
				if (id !== undefined && id !== previous) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,
		n,
		formatDate(timestamp) {
			return timestamp
				? new Date(timestamp * 1000).toLocaleDateString(getCanonicalLocale(), { day: 'numeric', month: 'short', year: 'numeric' })
				: ''
		},
		async load() {
			this.bot = null
			this.loadError = ''
			this.dirty = false
			this.preview = null
			try {
				this.bot = await store.fetchBot(this.$route.params.id)
			} catch (error) {
				console.error('Failed to load bot', error)
				this.loadError = error?.response?.status === 403
					? t('educai', 'You cannot edit this bot.')
					: t('educai', 'This bot could not be loaded.')
			}
		},
		save() {
			this.$refs.editor.save()
		},
		discard() {
			this.$refs.editor.reset()
		},
		async saveBot(payload) {
			this.saving = true
			try {
				const updated = await store.updateBot(this.bot.id, payload)
				const status = updated.approval_status || 'approved'
				if (status === 'pending') {
					showSuccess(t('educai', 'Saved and submitted. The change goes live after approval.'))
				} else if (status === 'draft') {
					showSuccess(t('educai', 'Saved as draft. Submit it for approval when it is ready.'))
				} else {
					showSuccess(t('educai', 'Saved'))
				}
				// A fresh object makes the editor show the saved state.
				this.bot = updated
				this.dirty = false
			} catch (error) {
				console.error('Failed to save bot:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to save bot')))
			} finally {
				this.saving = false
			}
		},
		async onSubmitted(bot) {
			this.submitOpen = false
			this.bot = bot
		},
		async deleteBot() {
			this.deleting = true
			this.$refs.editor?.stopLoading()
			try {
				await store.deleteBot(this.bot.id)
				showSuccess(t('educai', 'Bot deleted successfully'))
				this.dirty = false
				this.$router.push('/my-bots')
			} catch (error) {
				console.error('Failed to delete bot:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to delete bot')))
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>
