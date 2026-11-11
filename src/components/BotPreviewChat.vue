<template>
	<div class="talkai-trychat">
		<!-- Above the chat, so it is read before the first message and never scrolls out of sight below it -->
		<div class="talkai-note talkai-note--info talkai-trychat__note">
			<TalkAiIcon name="info" :size="16" />
			<span>{{ t('educai', 'Only a preview. The real chat happens in Talk once the bot is created.') }}</span>
		</div>
		<p v-if="!ready" class="talkai-side__text">
			{{ t('educai', 'Enter a name and an instruction first. Then you can try here how the bot answers.') }}
		</p>
		<template v-else>
			<div ref="log"
				class="talkai-trychat__log"
				role="log"
				aria-live="polite"
				:aria-label="t('educai', 'Preview conversation')">
				<p v-if="messages.length === 0 && !sending" class="talkai-trychat__empty">
					{{ t('educai', 'Write a message to see how the bot answers.') }}
				</p>
				<div v-for="(message, index) in messages"
					:key="index"
					class="talkai-trychat__msg"
					:class="`talkai-trychat__msg--${message.role}`">
					<BotAvatar v-if="message.role === 'assistant'"
						:name="bot.bot_name"
						:scope="scope"
						size="sm" />
					<div class="talkai-trychat__bubble">
						{{ message.content }}
						<span v-if="message.incomplete" class="talkai-trychat__cut">{{ t('educai', 'Cut off at the output limit.') }}</span>
					</div>
				</div>
				<div v-if="sending" class="talkai-trychat__msg talkai-trychat__msg--assistant">
					<BotAvatar :name="bot.bot_name" :scope="scope" size="sm" />
					<div class="talkai-trychat__bubble talkai-trychat__bubble--wait">
						{{ t('educai', 'The bot is writing…') }}
					</div>
				</div>
			</div>
			<p v-if="error" class="talkai-trychat__error" role="alert">
				{{ error }}
			</p>
			<!-- One line that grows with the text and the button next to it, like the message field in Talk -->
			<form class="talkai-trychat__form" @submit.prevent="send">
				<textarea ref="field"
					v-model="input"
					class="talkai-input talkai-trychat__input"
					rows="1"
					:placeholder="t('educai', 'Write a message')"
					:aria-label="t('educai', 'Message to the bot')"
					:disabled="sending"
					@keydown.enter.exact.prevent="send" />
				<button type="submit" class="talkai-btn talkai-btn--filled" :disabled="sending || input.trim() === ''">
					{{ t('educai', 'Send') }}
				</button>
			</form>
			<button v-if="messages.length > 0"
				type="button"
				class="talkai-trychat__reset"
				:disabled="sending"
				@click="reset">
				{{ t('educai', 'Start over') }}
			</button>
			<p class="talkai-side__text talkai-trychat__detail">
				{{ t('educai', 'The preview uses the instruction and the model from the form, without knowledge and capabilities.') }}
			</p>
		</template>
	</div>
</template>

<script>
import BotAvatar from './BotAvatar.vue'
import TalkAiIcon from './TalkAiIcon.vue'
import { t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { scopeOf } from '../utils/botScope.js'

// The server answers at most this many messages of one preview conversation.
const MAX_MESSAGES = 20

// Try a bot before it exists: a short conversation answered with the instruction and model of the form.
// Nothing is stored. The real conversation happens in Talk, once the bot is created.
export default {
	name: 'BotPreviewChat',
	components: { BotAvatar, TalkAiIcon },
	props: {
		// The bot as the form describes it right now
		bot: { type: Object, required: true },
		// Instruction, model and temperature of the form
		draft: { type: Object, required: true },
		directory: { type: Object, default: () => ({}) },
	},
	data() {
		return {
			messages: [],
			input: '',
			sending: false,
			error: '',
		}
	},
	computed: {
		ready() {
			return String(this.bot.bot_name || '').trim() !== '' && String(this.draft.systemPrompt || '').trim() !== ''
		},
		scope() {
			return scopeOf(this.bot, this.directory).key
		},
	},
	watch: {
		input() {
			this.$nextTick(this.fitField)
		},
	},
	methods: {
		t,
		// The message field grows with its text; the stylesheet stops it after a few lines.
		fitField() {
			const field = this.$refs.field
			if (field) {
				field.style.height = 'auto'
				field.style.height = `${field.scrollHeight + field.offsetHeight - field.clientHeight}px`
			}
		},
		async send() {
			const text = this.input.trim()
			if (text === '' || this.sending) {
				return
			}
			this.messages.push({ role: 'user', content: text })
			this.input = ''
			this.error = ''
			this.sending = true
			this.scrollDown()
			try {
				const answer = await store.previewReply({
					systemPrompt: this.draft.systemPrompt,
					model: this.draft.model ?? null,
					temperature: this.draft.temperature ?? null,
					messages: this.messages.slice(-MAX_MESSAGES).map(({ role, content }) => ({ role, content })),
				})
				this.messages.push({ role: 'assistant', content: String(answer?.reply ?? ''), incomplete: answer?.incomplete === true })
			} catch (error) {
				console.error('The bot preview failed:', error)
				this.error = getApiErrorMessage(error, t('educai', 'The preview could not answer. Try again.'))
				// The unanswered message goes back into the field, so nothing has to be typed twice.
				this.messages.pop()
				this.input = text
			} finally {
				this.sending = false
				this.scrollDown()
				// The field was disabled while the bot wrote; typing goes on without another click.
				this.$nextTick(() => this.$refs.field?.focus({ preventScroll: true }))
			}
		},
		reset() {
			this.messages = []
			this.error = ''
		},
		scrollDown() {
			this.$nextTick(() => {
				const log = this.$refs.log
				if (log) {
					log.scrollTop = log.scrollHeight
				}
			})
		},
	},
}
</script>
