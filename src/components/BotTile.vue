<template>
	<article class="talkai-tile" :class="{ 'talkai-tile--link': !preview }" @click="onTileClick">
		<div class="talkai-tile__top">
			<BotAvatar :name="name" :scope="scope.key" />
			<div class="talkai-tile__id">
				<h3 class="talkai-tile__name">
					<button v-if="!preview"
						type="button"
						class="talkai-tile__open"
						:title="t('educai', 'Show details')"
						@click.stop="$emit('details', bot)">
						{{ name }}
					</button>
					<template v-else>
						{{ name }}
					</template>
				</h3>
				<div class="talkai-tile__mention">
					{{ mention }}
				</div>
			</div>
		</div>
		<p class="talkai-tile__text" :class="{ 'talkai-tile__text--empty': !bot.description }">
			{{ bot.description || t('educai', 'No description yet.') }}
		</p>
		<div class="talkai-caps">
			<span v-for="capability in capabilities" :key="capability.key" class="talkai-cap">
				<TalkAiIcon :name="capability.icon" :size="13" />
				<span class="talkai-cap__text">{{ capability.label }}</span>
			</span>
		</div>
		<div class="talkai-tile__foot">
			<span class="talkai-who">
				<TalkAiIcon :name="scopeIcon" :size="15" />
				<span class="talkai-who__text">{{ scope.detail }}</span>
			</span>
			<template v-if="!preview">
				<router-link v-if="bot.can_edit"
					class="talkai-tile__edit"
					:to="`/bots/${bot.id}/basics`"
					@click.stop>
					<TalkAiIcon name="pencil" :size="15" />
					{{ t('educai', 'Edit') }}
				</router-link>
				<button type="button" class="talkai-go" @click.stop="$emit('talk', bot)">
					{{ t('educai', 'Use in Talk') }}
					<TalkAiIcon name="right" :size="15" />
				</button>
			</template>
		</div>
	</article>
</template>

<script>
import BotAvatar from './BotAvatar.vue'
import TalkAiIcon from './TalkAiIcon.vue'
import { t } from '../l10n.js'
import { SCOPE_ICONS, scopeOf } from '../utils/botScope.js'
import { capabilitiesOf } from '../utils/capabilities.js'

// One tile for every place a bot is shown as a card: Discover and the preview while creating a bot.
export default {
	name: 'BotTile',
	components: { BotAvatar, TalkAiIcon },
	props: {
		bot: { type: Object, required: true },
		// Known groups and teams, to name the audience of own bots
		directory: { type: Object, default: () => ({}) },
		// A preview has no actions and does not open anything
		preview: { type: Boolean, default: false },
	},
	emits: ['details', 'talk'],
	computed: {
		name() {
			return this.bot.bot_name || t('educai', 'New bot')
		},
		mention() {
			const mention = String(this.bot.mention_name || '').replace(/^@+/, '')
			return `@${mention || t('educai', 'newbot')}`
		},
		scope() {
			return scopeOf(this.bot, this.directory)
		},
		scopeIcon() {
			return SCOPE_ICONS[this.scope.key]
		},
		capabilities() {
			return capabilitiesOf(this.bot).slice(0, 4)
		},
	},
	methods: {
		t,
		onTileClick(event) {
			if (!this.preview && !event.target.closest('a, button')) {
				this.$emit('details', this.bot)
			}
		},
	},
}
</script>
