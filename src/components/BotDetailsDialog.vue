<template>
	<TalkAiDialog :name="bot.bot_name" @close="$emit('close')">
		<template #head>
			<BotAvatar :name="bot.bot_name" :scope="scope.key" size="lg" />
			<div class="talkai-dialog__sub">
				{{ byline }}
			</div>
		</template>

		<p class="talkai-dialog__text" :class="{ 'talkai-muted': !details.description }">
			{{ details.description || t('educai', 'No description yet.') }}
		</p>
		<dl class="talkai-dl">
			<dt class="talkai-dl__term">
				{{ t('educai', 'Can') }}
			</dt>
			<dd class="talkai-dl__value">
				{{ loading ? t('educai', 'Loading…') : canText }}
			</dd>
			<dt class="talkai-dl__term">
				{{ t('educai', 'Knows') }}
			</dt>
			<dd class="talkai-dl__value">
				{{ loading ? t('educai', 'Loading…') : knowsText }}
			</dd>
			<dt class="talkai-dl__term">
				{{ t('educai', 'For') }}
			</dt>
			<dd class="talkai-dl__value">
				{{ audienceWithGuests(scope, details.allow_guests) }}
			</dd>
		</dl>
		<div class="talkai-note talkai-note--info talkai-dialog__note">
			<TalkAiIcon name="lock" />
			<span>{{ privacyText }}</span>
		</div>
		<details v-if="details.system_prompt" class="talkai-disclosure">
			<summary class="talkai-disclosure__summary">
				{{ t('educai', 'Instruction') }}
				<span class="talkai-disclosure__aside">· {{ t('educai', 'what the bot was told to do') }}</span>
			</summary>
			<p class="talkai-dialog__prompt">
				{{ details.system_prompt }}
			</p>
		</details>

		<template #actions>
			<router-link v-if="details.can_edit"
				class="talkai-btn"
				:to="`/bots/${bot.id}/basics`"
				@click="$emit('close')">
				<TalkAiIcon name="pencil" :size="16" />
				{{ t('educai', 'Edit') }}
			</router-link>
			<button type="button" class="talkai-btn talkai-btn--filled" @click="$emit('talk', bot)">
				{{ t('educai', 'Use in Talk') }}
			</button>
		</template>
	</TalkAiDialog>
</template>

<script>
import BotAvatar from './BotAvatar.vue'
import TalkAiDialog from './TalkAiDialog.vue'
import TalkAiIcon from './TalkAiIcon.vue'
import { getCanonicalLocale, n, t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { audienceWithGuests, scopeOf } from '../utils/botScope.js'
import { capabilitiesOf } from '../utils/capabilities.js'

// What a bot can do, what it knows and who it is for. Opens from a tile on Discover.
export default {
	name: 'BotDetailsDialog',
	components: { BotAvatar, TalkAiDialog, TalkAiIcon },
	props: {
		bot: { type: Object, required: true },
		directory: { type: Object, default: () => ({}) },
	},
	emits: ['close', 'talk'],
	data() {
		return {
			loading: true,
			loaded: null,
		}
	},
	computed: {
		// The list entry is shown at once; the detail request adds the tools and the number of sources.
		details() {
			return this.loaded ?? this.bot
		},
		scope() {
			return scopeOf(this.details, this.directory)
		},
		byline() {
			const mention = `@${String(this.bot.mention_name || '').replace(/^@+/, '')}`
			const created = this.details.created_at
				? new Date(this.details.created_at * 1000).toLocaleDateString(getCanonicalLocale(), { day: 'numeric', month: 'long', year: 'numeric' })
				: ''
			const owner = this.details.access_reason?.type === 'owner'
				? t('educai', 'by you')
				: (this.details.owner_display_name ? t('educai', 'by {owner}', { owner: this.details.owner_display_name }) : '')
			return [mention, owner, created].filter(Boolean).join(' · ')
		},
		capabilities() {
			return capabilitiesOf(this.details)
		},
		canText() {
			const labels = this.capabilities.map(capability => capability.label)
			return labels.length > 0 ? labels.join(' · ') : t('educai', 'Answer in the chat')
		},
		knowsText() {
			const count = Number(this.details.rag_source_count) || 0
			if (this.details.rag_enabled && count > 0) {
				return n('educai', '%n source', '%n sources', count)
			}
			return this.capabilities.some(capability => capability.key === 'documents')
				? t('educai', 'Only files you put into the chat')
				: t('educai', 'No stored sources')
		},
		privacyText() {
			const own = t('educai', 'The bot only sees your own files if you put them into the chat.')
			return this.details.rag_enabled
				? t('educai', 'Answers can reproduce content from its sources.') + ' ' + own
				: own
		},
	},
	async mounted() {
		try {
			this.loaded = await store.fetchPublicBot(this.bot.id)
		} catch (error) {
			// The dialog stays useful with the data of the list.
			console.error('Failed to load bot details', error)
		} finally {
			this.loading = false
		}
	},
	methods: { t, audienceWithGuests },
}
</script>
