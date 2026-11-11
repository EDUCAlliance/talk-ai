<template>
	<TalkAiDialog :name="t('educai', 'Set up your personal assistant')" @close="close">
		<template #head>
			<BotAvatar scope="personal">
				<TalkAiIcon name="sparkle" :size="22" />
			</BotAvatar>
			<div>
				<b>{{ t('educai', 'Personal assistant') }}</b>
				<div class="talkai-dialog__sub">
					{{ t('educai', 'A bot just for you') }}
				</div>
			</div>
		</template>

		<div class="talkai-sub talkai-assist-setup__sub">
			{{ t('educai', 'What it can do') }}
		</div>
		<ul class="talkai-checks">
			<li v-for="ability in abilities" :key="ability.key" class="talkai-checks__item">
				<TalkAiIcon :name="ability.icon" />
				<span>
					<b>{{ ability.title }}</b>
					<span class="talkai-checks__aside">{{ ability.text }}</span>
				</span>
			</li>
		</ul>
		<div class="talkai-note talkai-note--info talkai-dialog__note">
			<TalkAiIcon name="lock" />
			<span>{{ t('educai', 'Only you can use it, and it needs no approval. It knows only the folders you add.') }}</span>
		</div>
		<p class="talkai-assist-setup__next">
			{{ t('educai', 'Next you choose the folders it may know. Afterwards you find it pinned in first place under “Discover” and “My bots”.') }}
		</p>

		<template #actions>
			<button type="button"
				class="talkai-btn"
				:disabled="creating"
				@click="close">
				{{ t('educai', 'Cancel') }}
			</button>
			<button type="button"
				class="talkai-btn talkai-btn--filled"
				:disabled="creating"
				@click="create">
				{{ creating ? t('educai', 'Setting up…') : t('educai', 'Set up') }}
			</button>
		</template>
	</TalkAiDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import BotAvatar from './BotAvatar.vue'
import TalkAiDialog from './TalkAiDialog.vue'
import TalkAiIcon from './TalkAiIcon.vue'
import { t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { assistantPayload } from '../utils/assistant.js'
import { capabilityTexts } from '../utils/capabilities.js'

// Confirms what the personal assistant is and creates it. The folders are chosen right afterwards on its page.
export default {
	name: 'AssistantSetupDialog',
	components: { BotAvatar, TalkAiDialog, TalkAiIcon },
	emits: ['close', 'created'],
	data() {
		return {
			// null until the tool list has answered; an empty list when it failed
			offeredTools: null,
			creating: false,
		}
	},
	computed: {
		payload() {
			return assistantPayload(this.offeredTools ?? [])
		},
		// The knowledge search follows the folders; the rest is what the administration offers.
		abilities() {
			const names = this.payload.tools.map(tool => tool.builtin_name)
			const offered = [
				{ key: 'documents', icon: 'file', tool: 'room_search_documents' },
				{ key: 'images', icon: 'image', tool: 'attachment_analyze_image' },
			].filter(ability => names.includes(ability.tool))
			return [{ key: 'knowledge', icon: 'book' }, ...offered]
				.map(ability => ({ ...ability, ...capabilityTexts(ability.key) }))
		},
	},
	async mounted() {
		try {
			this.offeredTools = await store.fetchOfferedTools()
		} catch (error) {
			console.error('Failed to load the offered tools', error)
			this.offeredTools = []
		}
	},
	methods: {
		t,
		close() {
			if (!this.creating) {
				this.$emit('close')
			}
		},
		async create() {
			this.creating = true
			try {
				if (this.offeredTools === null) {
					this.offeredTools = await store.fetchOfferedTools().catch(() => [])
				}
				const bot = await store.createAssistant(this.payload)
				if (bot === null) {
					// The server says it exists, the list does not show it yet. Nothing to continue with.
					this.$emit('close')
					return
				}
				showSuccess(t('educai', 'Your assistant is set up. Now choose the folders it may know.'))
				this.$emit('created', bot)
			} catch (error) {
				console.error('Failed to set up the personal assistant:', error)
				showError(getApiErrorMessage(error, t('educai', 'The assistant could not be set up. Please try again.')))
				if (error?.response?.data?.errorCode === 'assistant_exists') {
					this.$emit('close')
				}
			} finally {
				this.creating = false
			}
		},
	},
}
</script>
