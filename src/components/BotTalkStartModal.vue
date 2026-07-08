<template>
	<div class="talk-start-overlay" @click.self="$emit('close')">
		<div class="talk-start-modal">
			<div class="modal-header">
				<div class="header-content">
					<h2>Start using {{ bot.bot_name }} in Talk</h2>
					<span class="mention-badge">{{ mention }}</span>
				</div>
				<button class="close-button" @click="$emit('close')">
					<span class="icon-close" />
				</button>
			</div>

			<div class="modal-tabs">
				<div class="mode-tabs" role="tablist" aria-label="Talk start mode">
					<button
						type="button"
						class="mode-tab"
						:class="{ active: mode === 'new' }"
						:aria-selected="String(mode === 'new')"
						@click="setMode('new')">
						New chat
					</button>
					<button
						type="button"
						class="mode-tab"
						:class="{ active: mode === 'existing' }"
						:aria-selected="String(mode === 'existing')"
						@click="setMode('existing')">
						Existing chat
					</button>
				</div>
			</div>

			<div class="modal-body">
				<div v-if="mode === 'new'" class="form-section">
					<label for="talk-room-name">Chat name</label>
					<input
						id="talk-room-name"
						v-model="roomName"
						type="text"
						placeholder="Chat with bot">
				</div>

				<div v-if="mode === 'existing'" class="form-section">
					<div class="section-header">
						<label>Talk conversation</label>
						<button
							type="button"
							class="refresh-button"
							:disabled="loadingRooms"
							@click="loadRooms">
							Refresh
						</button>
					</div>

					<div v-if="loadingRooms" class="room-state">
						<span class="icon-loading" />
						<span>Loading Talk conversations...</span>
					</div>

					<div v-else-if="roomsError" class="error-state">
						{{ roomsError }}
					</div>

					<div v-else-if="rooms.length === 0" class="room-state">
						No Talk conversations available.
					</div>

					<div v-else class="room-list">
						<label
							v-for="room in rooms"
							:key="room.token"
							class="room-option"
							:class="{ selected: selectedRoomToken === room.token }">
							<input
								v-model="selectedRoomToken"
								type="radio"
								name="talk-room"
								:value="room.token">
							<span class="room-copy">
								<span class="room-title">{{ room.displayName }}</span>
								<span class="room-meta">
									{{ room.isModerator ? `You can activate ${APP_DISPLAY_NAME} here` : `Only moderators can activate ${APP_DISPLAY_NAME} here` }}
								</span>
							</span>
						</label>
					</div>

					<div v-if="selectedRoom && !selectedRoom.isModerator" class="warning-state">
						If {{ APP_DISPLAY_NAME }} is not active in this room yet, a moderator needs to activate it before you can use this bot there.
					</div>
				</div>

				<div class="form-section">
					<label for="talk-first-message">First message</label>
					<textarea
						id="talk-first-message"
						v-model="message"
						rows="4"
						placeholder="@bot What can you help me with?" />
				</div>

				<label class="send-option">
					<input v-model="sendMessage" type="checkbox">
					<span>
						Send this message immediately as me
						<small v-if="mode === 'existing'">Recommended only when the selected room expects this message.</small>
					</span>
				</label>

				<div v-if="error" class="error-state">
					{{ error }}
				</div>
			</div>

			<div class="modal-footer">
				<button class="button" :disabled="submitting" @click="$emit('close')">
					Cancel
				</button>
				<button class="button primary" :disabled="submitDisabled" @click="submit">
					<span v-if="submitting" class="icon-loading" />
					{{ submitLabel }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { APP_DISPLAY_NAME } from '../branding.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'BotTalkStartModal',
	props: {
		bot: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			APP_DISPLAY_NAME,
			mode: 'new',
			rooms: [],
			selectedRoomToken: null,
			roomName: `Chat with ${this.bot.bot_name}`,
			message: `${this.formatMention(this.bot.mention_name)} What can you help me with?`,
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
				return 'Opening Talk...'
			}
			if (this.mode === 'new') {
				return this.sendMessage ? 'Create chat, send message and open Talk' : 'Create chat and open Talk'
			}
			return this.sendMessage ? 'Activate, send message and open Talk' : 'Activate and open Talk'
		},
	},
	methods: {
		formatMention(mentionName) {
			const name = (mentionName || '').startsWith('@') ? mentionName.substring(1) : mentionName
			return `@${name}`
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
				this.roomsError = this.errorFromResponse(error, 'Failed to load Talk conversations')
				this.roomsLoaded = true
			} finally {
				this.loadingRooms = false
			}
		},
		async submit() {
			this.error = null

			if (this.mode === 'existing' && !this.selectedRoomToken) {
				this.error = 'Please select a Talk conversation.'
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
					showSuccess('Message sent. Opening Talk...')
				} else {
					showSuccess(`${APP_DISPLAY_NAME} is ready in Talk. Mention ${this.mention} to start.`)
				}

				window.location.href = talkUrl
			} catch (error) {
				console.error('Failed to start Talk chat:', error)
				this.error = this.errorFromResponse(error, 'Failed to start Talk chat')
				showError(this.error)
			} finally {
				this.submitting = false
			}
		},
		errorFromResponse(error, fallback) {
			return error?.response?.data?.error || error?.message || fallback
		},
	},
}
</script>

<style scoped>
.talk-start-overlay {
	position: fixed;
	z-index: 10000;
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

.talk-start-modal {
	background: var(--color-main-background);
	border-radius: 12px;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.24);
	max-width: 720px;
	width: 92%;
	max-height: 88vh;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.modal-header {
	padding: 20px 24px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
}

.header-content {
	min-width: 0;
}

.header-content h2 {
	margin: 0 0 8px 0;
	font-size: 22px;
	font-weight: 600;
	line-height: 1.25;
}

.mention-badge {
	display: inline-block;
	background: var(--color-primary-element);
	color: #fff;
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

.modal-body {
	padding: 24px;
	overflow-y: auto;
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.modal-tabs {
	padding: 24px 24px 0;
	flex: 0 0 auto;
}

.mode-tabs {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
}

.mode-tab {
	border: 0;
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 11px 14px;
	font-weight: 600;
	cursor: pointer;
}

.mode-tab + .mode-tab {
	border-left: 1px solid var(--color-border);
}

.mode-tab.active {
	background: var(--color-primary-element);
	color: #fff;
}

.form-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
}

label,
.section-header label {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-lighter);
	text-transform: uppercase;
}

input[type='text'],
textarea {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	padding: 10px 12px;
}

textarea {
	resize: vertical;
	min-height: 96px;
	line-height: 1.5;
}

.refresh-button {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	border-radius: 16px;
	padding: 5px 12px;
	cursor: pointer;
	color: var(--color-main-text);
}

.refresh-button:disabled {
	cursor: default;
	opacity: 0.6;
}

.room-state,
.error-state,
.warning-state {
	padding: 12px 14px;
	border-radius: 8px;
	font-size: 14px;
	line-height: 1.4;
}

.room-state {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
	display: flex;
	align-items: center;
	gap: 8px;
}

.error-state {
	background: var(--color-error-hover);
	color: var(--color-error-text);
	border: 1px solid var(--color-error);
}

.warning-state {
	background: var(--color-warning);
	color: #000;
}

.room-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 240px;
	overflow-y: auto;
}

.room-option {
	display: flex;
	gap: 12px;
	align-items: flex-start;
	padding: 12px 14px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	cursor: pointer;
	text-transform: none;
	color: var(--color-main-text);
}

.room-option.selected {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 1px var(--color-primary-element);
}

.room-option input {
	margin-top: 3px;
	flex: 0 0 auto;
}

.room-copy {
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.room-title {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.room-meta {
	font-size: 12px;
	font-weight: 400;
	color: var(--color-text-lighter);
}

.send-option {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 12px 14px;
	background: var(--color-background-dark);
	border-radius: 8px;
	text-transform: none;
	color: var(--color-main-text);
}

.send-option input {
	margin-top: 2px;
}

.send-option span {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.send-option small {
	color: var(--color-text-lighter);
	font-weight: 400;
	line-height: 1.35;
}

.modal-footer {
	padding: 16px 24px;
	border-top: 1px solid var(--color-border);
	display: flex;
	justify-content: flex-end;
	gap: 10px;
}

.button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 10px 18px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	border-radius: 20px;
	cursor: pointer;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
	transition: background 0.2s, opacity 0.2s;
}

.button:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.button.primary {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: #fff;
}

.button.primary:hover:not(:disabled) {
	background: var(--color-primary-element-light);
}

.button:disabled {
	cursor: default;
	opacity: 0.6;
}

@media (max-width: 600px) {
	.talk-start-modal {
		width: 100%;
		height: 100%;
		max-height: none;
		border-radius: 0;
	}

	.modal-footer {
		flex-direction: column-reverse;
	}

	.button {
		width: 100%;
	}
}
</style>
