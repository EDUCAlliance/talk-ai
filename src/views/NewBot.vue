<template>
	<!-- Starting point -->
	<section v-if="view === 'templates'" class="talkai-screen">
		<header class="talkai-page-head">
			<div>
				<h2 class="talkai-page-head__title">
					{{ t('educai', 'New bot') }}
				</h2>
				<p class="talkai-page-head__lead">
					{{ t('educai', 'Choose a starting point. A template prefills the instruction and the capabilities, you can change everything afterwards.') }}
				</p>
			</div>
		</header>
		<div class="talkai-grid">
			<button v-for="template in templates"
				:key="template.key"
				type="button"
				class="talkai-tpl"
				:class="{ 'talkai-tpl--blank': template.key === 'blank' }"
				@click="start(template)">
				<span class="talkai-tpl__icon"><TalkAiIcon :name="template.icon" :size="22" /></span>
				<span class="talkai-tpl__title">{{ template.title }}</span>
				<span class="talkai-tpl__text">{{ template.text }}</span>
				<span class="talkai-tpl__includes">{{ template.includes }}</span>
			</button>
		</div>
	</section>

	<!-- Created -->
	<section v-else-if="view === 'done'" class="talkai-screen">
		<div class="talkai-done">
			<span class="talkai-state__icon talkai-state__icon--ok talkai-done__icon"><TalkAiIcon name="check" :size="26" /></span>
			<h2 ref="doneTitle" class="talkai-done__title" tabindex="-1">
				{{ doneTexts.title }}
			</h2>
			<p class="talkai-done__text">
				{{ doneTexts.text }}
			</p>
			<div v-if="done.state !== 'ready'" class="talkai-flow talkai-done__flow">
				<span class="talkai-flow__step is-done">{{ t('educai', 'Create') }}</span>
				<span class="talkai-flow__arrow" aria-hidden="true">→</span>
				<span class="talkai-flow__step" :class="done.state === 'submitted' ? 'is-done' : 'is-current'">{{ t('educai', 'Test') }}</span>
				<span class="talkai-flow__arrow" aria-hidden="true">→</span>
				<span class="talkai-flow__step" :class="{ 'is-done': done.state === 'submitted' }">{{ t('educai', 'Submit') }}</span>
				<span class="talkai-flow__arrow" aria-hidden="true">→</span>
				<span class="talkai-flow__step" :class="{ 'is-current': done.state === 'submitted' }">{{ t('educai', 'Approval') }}</span>
			</div>
			<div class="talkai-row talkai-done__actions">
				<router-link class="talkai-btn" :to="`/bots/${done.bot.id}`">
					{{ t('educai', 'Go to bot page') }}
				</router-link>
				<button type="button" class="talkai-btn talkai-btn--filled" @click="talkBot = done.bot">
					{{ t('educai', 'Test in Talk') }}
				</button>
			</div>
		</div>
		<BotTalkDialog v-if="talkBot" :bot="talkBot" @close="talkBot = null" />
	</section>

	<!-- Wizard -->
	<section v-else class="talkai-screen talkai-wiz">
		<div class="talkai-wiz__top">
			<router-link class="talkai-wiz__back" to="/my-bots">
				<TalkAiIcon name="close" :size="15" />
				{{ t('educai', 'Close') }}
			</router-link>
			<span class="talkai-wiz__note">{{ t('educai', 'Nothing is saved until you finish.') }}</span>
			<button type="button"
				class="talkai-btn talkai-btn--ghost talkai-wiz__toggle"
				:aria-expanded="String(sidebarOpen)"
				aria-controls="app-sidebar-vue"
				@click="toggleSidebar">
				<TalkAiIcon name="sidebar" />
				{{ t('educai', 'Preview') }}
			</button>
		</div>
		<header class="talkai-page-head talkai-wiz__head">
			<h2 class="talkai-page-head__title">
				{{ title }}
			</h2>
		</header>
		<ol class="talkai-stepper">
			<li v-for="entry in steps"
				:key="entry.number"
				class="talkai-stepper__item"
				:class="{ 'is-done': entry.number < step, 'is-current': entry.number === step }">
				<button type="button"
					class="talkai-stepper__link"
					:aria-current="entry.number === step ? 'step' : undefined"
					@click="goTo(entry.number)">
					<span class="talkai-stepper__num">{{ entry.number }}</span>
					<span class="talkai-stepper__label">{{ entry.label }}</span>
				</button>
			</li>
		</ol>
		<div class="talkai-stepper__caption">
			{{ t('educai', 'Step {step} of {total} · {name}', { step, total: steps.length, name: current.label }) }}
		</div>

		<h3 ref="stepTitle" class="talkai-step__title" tabindex="-1">
			{{ current.title }}
		</h3>
		<p class="talkai-step__lead">
			{{ current.lead }}
		</p>

		<BotEditor ref="editor"
			:key="editorKey"
			mode="wizard"
			:section="current.section"
			:preset="preset"
			:user-permissions="permissions"
			@save="payload = $event"
			@update:preview="preview = $event"
			@go-to-step="goTo" />

		<div v-if="step === steps.length && preview" class="talkai-note talkai-wiz__final" :class="preview.requiresApproval ? 'talkai-note--warn' : 'talkai-note--ok'">
			<TalkAiIcon name="info" />
			<span>{{ finalNote }}</span>
		</div>

		<div class="talkai-wiz__nav">
			<button type="button"
				class="talkai-btn talkai-btn--ghost"
				:disabled="creating"
				@click="back">
				<TalkAiIcon name="left" :size="16" />
				{{ t('educai', 'Back') }}
			</button>
			<span class="talkai-row">
				<button v-if="step === steps.length && preview && preview.requiresApproval"
					type="button"
					class="talkai-btn talkai-btn--big"
					:disabled="creating"
					@click="finish('draft')">
					{{ t('educai', 'Keep as draft') }}
				</button>
				<button type="button"
					class="talkai-btn talkai-btn--filled talkai-btn--big"
					:disabled="creating"
					@click="next">
					{{ nextLabel }}
					<TalkAiIcon v-if="step < steps.length || (preview && preview.requiresApproval)" name="right" :size="16" />
				</button>
			</span>
		</div>

		<Teleport defer to="#talkai-sidebar-host">
			<NcAppSidebar v-if="sidebarOpen && preview"
				:name="preview.bot.bot_name || t('educai', 'New bot')"
				:subname="t('educai', 'Draft · not live yet')"
				no-toggle
				@close="toggleSidebar">
				<NcAppSidebarTab id="talkai-preview" :name="t('educai', 'Preview')" :order="1">
					<template #icon>
						<TalkAiIcon name="eye" :size="20" />
					</template>
					<div class="talkai talkai-side">
						<h4 class="talkai-side__title">
							{{ t('educai', 'How the bot appears') }}
						</h4>
						<BotTile :bot="preview.bot" :directory="preview.directory" preview />
						<h4 class="talkai-side__title">
							{{ t('educai', 'At a glance') }}
						</h4>
						<dl class="talkai-dl talkai-dl--narrow">
							<dt class="talkai-dl__term">
								{{ t('educai', 'Knowledge') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ preview.knowledge }}
							</dd>
							<dt class="talkai-dl__term">
								{{ t('educai', 'Can') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ preview.abilities }}
							</dd>
							<dt class="talkai-dl__term">
								{{ t('educai', 'Goes live') }}
							</dt>
							<dd class="talkai-dl__value">
								{{ preview.goesLive }}
							</dd>
						</dl>
						<h4 class="talkai-side__title">
							{{ t('educai', 'Try it out') }}
						</h4>
						<BotPreviewChat :key="editorKey"
							:bot="preview.bot"
							:draft="preview.draft"
							:directory="preview.directory" />
					</div>
				</NcAppSidebarTab>
			</NcAppSidebar>
		</Teleport>

		<BotSubmitDialog v-if="submitBot"
			:bot="submitBot"
			:directory="directory"
			@close="finishSubmit(false)"
			@submitted="finishSubmit(true)" />
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import BotEditor from '../components/BotEditor.vue'
import BotPreviewChat from '../components/BotPreviewChat.vue'
import BotSubmitDialog from '../components/BotSubmitDialog.vue'
import BotTalkDialog from '../components/BotTalkDialog.vue'
import BotTile from '../components/BotTile.vue'
import TalkAiIcon from '../components/TalkAiIcon.vue'
import { t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { reviewersOf } from '../utils/botScope.js'

const SIDEBAR_KEY = 'talkai-preview-sidebar'

// A new bot in five steps. The bot is created at the end; until then everything stays in the browser.
export default {
	name: 'NewBot',
	components: { BotEditor, BotPreviewChat, BotSubmitDialog, BotTalkDialog, BotTile, NcAppSidebar, NcAppSidebarTab, TalkAiIcon },

	beforeRouteLeave() {
		if (this.started && !this.done && this.$refs.editor?.dirty) {
			return window.confirm(t('educai', 'Leave without creating the bot? Your entries will be lost.'))
		}
		return true
	},

	data() {
		return {
			started: false,
			preset: null,
			editorKey: 0,
			preview: null,
			payload: null,
			creating: false,
			submitBot: null,
			done: null,
			talkBot: null,
			sidebarOpen: this.initialSidebar(),
		}
	},

	computed: {
		permissions() {
			return store.state.permissions
		},
		directory() {
			return store.state.directory
		},
		step() {
			const step = Number(this.$route.params.step)
			return Number.isInteger(step) && step >= 1 && step <= 5 ? step : 0
		},
		view() {
			if (this.done) {
				return 'done'
			}
			return this.step > 0 && this.started ? 'wizard' : 'templates'
		},
		steps() {
			return [
				{
					number: 1,
					section: 'basics',
					label: t('educai', 'Basics'),
					title: t('educai', 'Basics'),
					lead: t('educai', 'What is the bot called and what should it do? That is all you need to get started.'),
				},
				{
					number: 2,
					section: 'audience',
					label: t('educai', 'Who uses it?'),
					title: t('educai', 'Who uses the bot?'),
					lead: t('educai', 'This decides whether the bot is live right away or reviewed first. It also sets the colour of the bot.'),
				},
				{
					number: 3,
					section: 'knowledge',
					label: t('educai', 'Knowledge'),
					title: t('educai', 'What should the bot know?'),
					lead: t('educai', 'Add files, folders or web pages. As soon as there are sources, the bot searches them. No extra switch needed.'),
				},
				{
					number: 4,
					section: 'capabilities',
					label: t('educai', 'Capabilities'),
					title: t('educai', 'What should the bot be able to do?'),
					lead: t('educai', 'The template has set recommendations. Turn on only what the bot needs.'),
				},
				{
					number: 5,
					section: 'finish',
					label: t('educai', 'Check & finish'),
					title: t('educai', 'Check and finish'),
					lead: t('educai', 'If everything fits, finish the creation. You can test the bot in Talk right afterwards.'),
				},
			]
		},
		current() {
			return this.steps[Math.max(this.step, 1) - 1]
		},
		title() {
			const name = this.preview?.bot.bot_name
			return name ? t('educai', 'New bot: {name}', { name }) : t('educai', 'New bot')
		},
		templates() {
			return [
				{
					key: 'documents',
					icon: 'book',
					title: t('educai', 'Questions from documents'),
					text: t('educai', 'Answers from files, folders and web pages and names where it found the answer.'),
					includes: t('educai', 'Knowledge · Documents in chat'),
					preset: {
						systemPrompt: t('educai', 'You answer questions from the stored documents. Answer in a friendly and concise way and always name where you found the answer. If you are not sure about something, say so.'),
						capabilities: ['documents'],
					},
				},
				{
					key: 'learning',
					icon: 'cap',
					title: t('educai', 'Learning companion'),
					text: t('educai', 'Explains the content of a course, asks practice questions and gives feedback.'),
					includes: t('educai', 'Knowledge · Documents in chat · Images'),
					preset: {
						systemPrompt: t('educai', 'You are a patient learning companion for a course. Explain the content step by step, ask one practice question at a time and give feedback on the answer. Rely on the stored documents.'),
						capabilities: ['documents', 'images'],
					},
				},
				{
					key: 'writing',
					icon: 'pencil',
					title: t('educai', 'Writing help'),
					text: t('educai', 'Gives feedback on texts that are shared in the chat.'),
					includes: t('educai', 'Documents in chat · Images'),
					preset: {
						systemPrompt: t('educai', 'You give constructive feedback on texts that people share in the chat: structure, style and citations. Say what works and what to improve. Do not rewrite whole passages.'),
						capabilities: ['documents', 'images'],
					},
				},
				{
					key: 'team',
					icon: 'team',
					title: t('educai', 'Team knowledge'),
					text: t('educai', 'Answers from the shared folders, decisions and runbooks of your team.'),
					includes: t('educai', 'Knowledge · Documents in chat'),
					preset: {
						systemPrompt: t('educai', 'You answer questions of the team from its shared documents: decisions, runbooks and notes. Name the document you used. If the documents do not contain the answer, say so.'),
						visibility: 'teams',
						capabilities: ['documents'],
					},
				},
				{
					key: 'blank',
					icon: 'plus',
					title: t('educai', 'Start from scratch'),
					text: t('educai', 'No defaults. You describe yourself what the bot should do.'),
					includes: t('educai', 'Documents in chat'),
					preset: { systemPrompt: '', capabilities: ['documents'] },
				},
			]
		},
		nextLabel() {
			if (this.creating) {
				return t('educai', 'Creating…')
			}
			if (this.step < this.steps.length) {
				return t('educai', 'Next')
			}
			return this.preview?.requiresApproval ? t('educai', 'Create and submit for approval') : t('educai', 'Create bot')
		},
		finalNote() {
			const mention = `@${this.preview.bot.mention_name}`
			if (this.preview.bot.visibility === 'personal') {
				return t('educai', 'After creation the bot is available right away in your chats with {mention}.', { mention })
			}
			return this.preview.requiresApproval
				? t('educai', 'The bot needs approval. Reviewed by: {reviewers}. Until then you can already test it yourself in Talk with {mention}.', { reviewers: this.preview.reviewers, mention })
				: t('educai', 'You administer this audience, so the bot is live for it right after creation.')
		},
		doneTexts() {
			const mention = `@${String(this.done.bot.mention_name || '').replace(/^@+/, '')}`
			if (this.done.state === 'submitted') {
				return {
					title: t('educai', 'Submitted'),
					text: t('educai', 'Reviewed by: {reviewers}. Until approval only you and the reviewers can use the bot.', { reviewers: reviewersOf(this.done.bot, this.directory) }),
				}
			}
			if (this.done.state === 'draft') {
				return {
					title: t('educai', 'Saved as draft'),
					text: t('educai', 'Test it in Talk with {mention} and submit it for approval when it is ready. You find it under “My bots”.', { mention }),
				}
			}
			return {
				title: t('educai', 'Your bot is ready'),
				text: t('educai', 'Mention {mention} in a Talk chat or start a conversation directly.', { mention }),
			}
		},
	},

	watch: {
		// A deep link into the wizard without a chosen starting point shows the templates.
		step: {
			immediate: true,
			handler(step) {
				if (step === 0 && this.done) {
					// "New bot" again after finishing: start over.
					this.done = null
					this.started = false
				}
				if (step > 0 && !this.started && !this.done) {
					this.$router.replace('/new')
				}
				this.$nextTick(() => this.$refs.stepTitle?.focus({ preventScroll: true }))
			},
		},
	},

	methods: {
		t,
		initialSidebar() {
			const saved = window.localStorage?.getItem(SIDEBAR_KEY)
			return saved === null || saved === undefined ? window.matchMedia('(min-width: 1280px)').matches : saved === '1'
		},
		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
			window.localStorage?.setItem(SIDEBAR_KEY, this.sidebarOpen ? '1' : '0')
		},
		start(template) {
			this.preset = template.preset
			this.editorKey++
			this.preview = null
			this.started = true
			this.$router.push('/new/1')
		},
		goTo(step) {
			// Going forward passes the checks of the steps in between.
			for (let passed = this.step; passed < step; passed++) {
				const problem = this.$refs.editor.validateStep(passed)
				if (problem) {
					showError(problem)
					if (passed !== this.step) {
						this.$router.push(`/new/${passed}`)
					}
					return
				}
			}
			this.$router.push(`/new/${step}`)
		},
		back() {
			this.$router.push(this.step > 1 ? `/new/${this.step - 1}` : '/new')
		},
		next() {
			if (this.step < this.steps.length) {
				this.goTo(this.step + 1)
			} else {
				this.finish(this.preview?.requiresApproval ? 'submit' : 'create')
			}
		},
		async finish(mode) {
			this.payload = null
			if (!this.$refs.editor.save() || !this.payload) {
				return
			}
			this.creating = true
			try {
				const bot = await store.createBot(this.payload)
				await this.$refs.editor.attachPendingSources(bot.id)
				if (mode === 'submit' && bot.approval_status === 'draft') {
					// The bot exists now. Submitting can still be cancelled; then it stays a draft.
					// The answer of the server carries no tool list; the form knows what was just saved.
					this.submitBot = { ...bot, tools: this.preview.bot.tools }
					return
				}
				this.finishWith(bot, bot.approval_status === 'draft' ? 'draft' : 'ready')
			} catch (error) {
				console.error('Failed to create bot:', error)
				showError(getApiErrorMessage(error, t('educai', 'The bot could not be created. Check the name and the mention, another bot may already use them.')))
			} finally {
				this.creating = false
			}
		},
		finishSubmit(submitted) {
			const bot = this.submitBot
			this.submitBot = null
			this.finishWith(bot, submitted ? 'submitted' : 'draft')
		},
		finishWith(bot, state) {
			if (state === 'draft') {
				showSuccess(t('educai', 'Saved as draft'))
			}
			this.done = { bot, state }
			this.$nextTick(() => this.$refs.doneTitle?.focus({ preventScroll: true }))
		},
	},
}
</script>
