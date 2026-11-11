<template>
	<TalkAiDialog :name="t('educai', 'Use {bot} in Talk', { bot: bot.bot_name })" @close="$emit('close')">
		<template #head>
			<BotAvatar :name="bot.bot_name" :scope="scope.key" />
			<div class="talkai-dialog__sub">
				{{ t('educai', 'Or mention {mention} in any chat', { mention }) }}
				<button type="button"
					class="talkai-btn talkai-btn--icon talkai-btn--small"
					:aria-label="t('educai', 'Copy {mention}', { mention })"
					:title="t('educai', 'Copy {mention}', { mention })"
					@click="copyMention">
					<TalkAiIcon name="copy" :size="15" />
				</button>
			</div>
		</template>

		<div class="talkai-seg talkai-dialog__seg" role="group" :aria-label="t('educai', 'Talk start mode')">
			<button type="button"
				class="talkai-seg__btn"
				:aria-pressed="String(mode === 'new')"
				@click="setMode('new')">
				{{ t('educai', 'New chat') }}
			</button>
			<button type="button"
				class="talkai-seg__btn"
				:aria-pressed="String(mode === 'existing')"
				@click="setMode('existing')">
				{{ t('educai', 'Existing chat') }}
			</button>
		</div>

		<div v-if="mode === 'new'" class="talkai-field">
			<label class="talkai-label" for="talkai-talk-room">{{ t('educai', 'Name of the chat') }}</label>
			<input id="talkai-talk-room"
				v-model="roomName"
				class="talkai-input"
				type="text">
		</div>

		<div v-else class="talkai-field">
			<div class="talkai-spread">
				<span class="talkai-label">{{ t('educai', 'Talk conversation') }}</span>
				<button type="button"
					class="talkai-btn talkai-btn--ghost talkai-btn--small"
					:disabled="loadingRooms"
					@click="loadRooms">
					<TalkAiIcon name="refresh" :size="13" />
					{{ t('educai', 'Refresh') }}
				</button>
			</div>
			<p v-if="loadingRooms" class="talkai-hint">
				{{ t('educai', 'Loading Talk conversations…') }}
			</p>
			<p v-else-if="roomsError" class="talkai-hint talkai-hint--error" role="alert">
				{{ roomsError }}
			</p>
			<p v-else-if="rooms.length === 0" class="talkai-hint">
				{{ t('educai', 'No Talk conversations available.') }}
			</p>
			<div v-else class="talkai-rooms">
				<label v-for="room in rooms"
					:key="room.token"
					class="talkai-rooms__item"
					:class="{ 'is-selected': selectedRoomToken === room.token }">
					<input v-model="selectedRoomToken"
						class="talkai-rooms__radio"
						type="radio"
						name="talkai-talk-room"
						:value="room.token">
					<span>
						<b class="talkai-rooms__name">{{ room.displayName }}</b>
						<span class="talkai-checks__aside">
							{{ room.isModerator
								? t('educai', 'You can activate {appName} here', { appName: APP_DISPLAY_NAME })
								: t('educai', 'Only moderators can activate {appName} here', { appName: APP_DISPLAY_NAME }) }}
						</span>
					</span>
				</label>
			</div>
			<div v-if="selectedRoom && !selectedRoom.isModerator" class="talkai-note talkai-note--warn talkai-dialog__note">
				<TalkAiIcon name="info" />
				<span>{{ t('educai', 'If {appName} is not active in this room yet, a moderator needs to activate it before you can use this bot there.', { appName: APP_DISPLAY_NAME }) }}</span>
			</div>
		</div>

		<div class="talkai-field">
			<label class="talkai-label" for="talkai-talk-message">{{ t('educai', 'First message') }}</label>
			<textarea id="talkai-talk-message"
				v-model="message"
				class="talkai-input"
				rows="3" />
		</div>

		<label class="talkai-dialog__option">
			<input v-model="sendMessage" class="talkai-check" type="checkbox">
			<span>
				{{ t('educai', 'Send the message in my name right away') }}
				<span v-if="mode === 'existing'" class="talkai-checks__aside">{{ t('educai', 'Recommended only when the selected room expects this message.') }}</span>
			</span>
		</label>

		<p v-if="error" class="talkai-hint talkai-hint--error" role="alert">
			{{ error }}
		</p>

		<template #actions>
			<button type="button"
				class="talkai-btn"
				:disabled="submitting"
				@click="$emit('close')">
				{{ t('educai', 'Cancel') }}
			</button>
			<button type="button"
				class="talkai-btn talkai-btn--filled"
				:disabled="submitDisabled"
				@click="submit">
				{{ submitLabel }}
			</button>
		</template>
	</TalkAiDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import BotAvatar from './BotAvatar.vue'
import TalkAiDialog from './TalkAiDialog.vue'
import TalkAiIcon from './TalkAiIcon.vue'
import { APP_DISPLAY_NAME } from '../branding.js'
import { t } from '../l10n.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { scopeOf } from '../utils/botScope.js'

// Start a bot in Talk: a new chat or an existing one, optionally with a first message.
export default {
	name: 'BotTalkDialog',
	components: { BotAvatar, TalkAiDialog, TalkAiIcon },
	props: {
		bot: { type: Object, required: true },
		// Optional first message, e.g. a suggested question
		firstMessage: { type: String, default: '' },
	},
	emits: ['close'],

	data() {
		return {
			APP_DISPLAY_NAME,
			mode: 'new',
			rooms: [],
			selectedRoomToken: null,
			roomName: t('educai', 'Questions for {bot}', { bot: this.bot.bot_name }),
			message: this.firstMessage
				|| t('educai', '{mention} What can you help me with?', { mention: this.formatMention(this.bot.mention_name) }),
			sendMessage: true,
			loadingRooms: false,
			roomsLoaded: false,
			roomsError: null,
			submitting: false,
			error: null,
		}
	},
	computed: {
		mention() {
			return this.formatMention(this.bot.mention_name)
		},
		scope() {
			return scopeOf(this.bot)
		},
		selectedRoom() {
			return this.rooms.find((room) => room.token === this.selectedRoomToken) || null
		},
		submitDisabled() {
			if (this.submitting) {
				return true
			}
			if (this.mode === 'existing') {
				return this.loadingRooms || !this.selectedRoomToken
			}
			return !this.roomName.trim()
		},
		submitLabel() {
			if (this.submitting) {
				return t('educai', 'Opening Talk…')
			}
			if (this.mode === 'new') {
				return t('educai', 'Create chat and open Talk')
			}
			return t('educai', 'Activate and open Talk')
		},
	},
	methods: {
		t,
		formatMention(mentionName) {
			return `@${String(mentionName || '').replace(/^@+/, '')}`
		},
		async copyMention() {
			try {
				await navigator.clipboard.writeText(this.mention)
				showSuccess(t('educai', 'Copied {mention}', { mention: this.mention }))
			} catch (error) {
				console.error('Failed to copy bot handle:', error)
				showError(t('educai', 'Failed to copy bot handle'))
			}
		},
		setMode(mode) {
			this.mode = mode
			this.error = null
			if (mode === 'existing' && !this.roomsLoaded) {
				this.loadRooms()
			}
		},
		async loadRooms() {
			this.loadingRooms = true
			this.roomsError = null
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/talk/rooms'))
				this.rooms = response.data?.rooms || []
				this.roomsLoaded = true
				if (!this.selectedRoomToken && this.rooms.length > 0) {
					const moderatorRoom = this.rooms.find((room) => room.isModerator)
					this.selectedRoomToken = (moderatorRoom || this.rooms[0]).token
				}
			} catch (error) {
				console.error('Failed to load Talk conversations:', error)
				this.roomsError = getApiErrorMessage(error, t('educai', 'Failed to load Talk conversations'))
				this.roomsLoaded = true
			} finally {
				this.loadingRooms = false
			}
		},
		async submit() {
			this.error = null

			if (this.mode === 'existing' && !this.selectedRoomToken) {
				this.error = t('educai', 'Please select a Talk conversation.')
				return
			}

			this.submitting = true
			try {
				const response = await axios.post(generateUrl('/apps/educai/api/v1/talk/start-bot-chat'), {
					botId: this.bot.id,
					mode: this.mode,
					roomToken: this.mode === 'existing' ? this.selectedRoomToken : null,
					roomName: this.mode === 'new' ? this.roomName.trim() : null,
					message: this.message.trim(),
					sendMessage: this.sendMessage,
				})

				const talkUrl = response.data?.talkUrl
				if (!talkUrl) {
					throw new Error('Talk did not return a room URL')
				}

				if (response.data?.messageSent) {
					showSuccess(t('educai', 'Message sent. Opening Talk…'))
				} else {
					showSuccess(t('educai', '{appName} is ready in Talk. Mention {mention} to start.', {
						appName: APP_DISPLAY_NAME,
						mention: this.mention,
					}))
				}

				window.location.href = talkUrl
			} catch (error) {
				console.error('Failed to start Talk chat:', error)
				this.error = getApiErrorMessage(error, t('educai', 'Failed to start Talk chat'))
				showError(this.error)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>
