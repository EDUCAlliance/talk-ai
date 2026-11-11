<template>
	<TalkAiDialog :name="t('educai', 'Submit for approval')" @close="$emit('close')">
		<template #head>
			<BotAvatar :name="bot.bot_name" :scope="scope.key" />
			<div>
				<b>{{ bot.bot_name }}</b>
				<div class="talkai-dialog__sub">
					{{ t('educai', 'Reviewed by: {reviewers}', { reviewers }) }}
				</div>
			</div>
		</template>

		<div class="talkai-field">
			<label class="talkai-label" for="talkai-submit-reason">{{ t('educai', 'Why should the bot be shared?') }}</label>
			<textarea id="talkai-submit-reason"
				v-model="reason"
				class="talkai-input"
				rows="3"
				:placeholder="t('educai', 'For example: what does the group need the bot for?')" />
		</div>
		<div class="talkai-sub">
			{{ t('educai', 'Sent along automatically') }}
		</div>
		<ul class="talkai-checks">
			<li class="talkai-checks__item">
				<TalkAiIcon name="check" />
				<span>
					<b>{{ t('educai', 'Description, instruction and capabilities') }}</b>
					<span class="talkai-checks__aside">{{ t('educai', 'from your entries') }}</span>
				</span>
			</li>
			<li class="talkai-checks__item">
				<TalkAiIcon name="check" />
				<span>
					<b>{{ t('educai', 'List of knowledge sources') }}</b>
					<span class="talkai-checks__aside">{{ sourcesText }}</span>
				</span>
			</li>
		</ul>
		<div class="talkai-note talkai-note--info talkai-dialog__note">
			<TalkAiIcon name="info" />
			<span>{{ t('educai', 'Until approval only you and the reviewers can use the bot. You can keep testing it in Talk with {mention}.', { mention }) }}</span>
		</div>

		<template #actions>
			<button type="button"
				class="talkai-btn"
				:disabled="submitting"
				@click="$emit('close')">
				{{ t('educai', 'Cancel') }}
			</button>
			<button type="button"
				class="talkai-btn talkai-btn--filled"
				:disabled="submitting"
				@click="submit">
				{{ submitting ? t('educai', 'Submitting…') : t('educai', 'Submit') }}
			</button>
		</template>
	</TalkAiDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import BotAvatar from './BotAvatar.vue'
import TalkAiDialog from './TalkAiDialog.vue'
import TalkAiIcon from './TalkAiIcon.vue'
import { n, t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { reviewersOf, scopeOf } from '../utils/botScope.js'
import { capabilitiesOf } from '../utils/capabilities.js'

// One question instead of four: what the bot can do and what it knows is taken from the bot itself.
export default {
	name: 'BotSubmitDialog',
	components: { BotAvatar, TalkAiDialog, TalkAiIcon },
	props: {
		bot: { type: Object, required: true },
		directory: { type: Object, default: () => ({}) },
	},
	emits: ['close', 'submitted'],
	data() {
		return {
			reason: '',
			sources: null,
			submitting: false,
		}
	},
	computed: {
		scope() {
			return scopeOf(this.bot, this.directory)
		},
		reviewers() {
			return reviewersOf(this.bot, this.directory)
		},
		mention() {
			return `@${String(this.bot.mention_name || '').replace(/^@+/, '')}`
		},
		sourcesText() {
			if (this.sources === null) {
				return t('educai', 'Loading sources…')
			}
			return this.sources.length === 0
				? t('educai', 'No sources')
				: n('educai', '%n source · reviewers can open it', '%n sources · reviewers can open them', this.sources.length)
		},
	},
	async mounted() {
		try {
			this.sources = await store.fetchSources(this.bot.id)
		} catch (error) {
			console.error('Failed to load sources', error)
			this.sources = []
		}
	},
	methods: {
		t,
		async submit() {
			this.submitting = true
			try {
				// The review form of earlier versions asked for these as free text. They are filled from the bot
				// so that every reviewer sees them, whichever view they use.
				const bot = await store.submitBot(this.bot.id, {
					approval_reason: this.reason.trim(),
					bot_capabilities: capabilitiesOf(this.bot).map(capability => capability.label).join(' · '),
					rag_source_description: (this.sources ?? [])
						.map(source => source.display_name || source.path || source.source_url)
						.filter(Boolean)
						.join(', '),
					testing_description: '',
				})
				showSuccess(t('educai', 'Submitted for approval'))
				this.$emit('submitted', bot)
			} catch (error) {
				console.error('Failed to submit bot for approval:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to submit for approval')))
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>
