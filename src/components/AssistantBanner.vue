<template>
	<section class="talkai-assist" aria-labelledby="talkai-assist-title">
		<div class="talkai-assist__art" aria-hidden="true">
			<i class="talkai-assist__blob talkai-assist__blob--violet" />
			<i class="talkai-assist__blob talkai-assist__blob--orange" />
			<i class="talkai-assist__blob talkai-assist__blob--green" />
			<TalkAiIcon class="talkai-assist__spark" name="sparkle" :size="34" />
		</div>
		<div class="talkai-assist__body">
			<h3 id="talkai-assist-title" class="talkai-assist__title">
				{{ t('educai', 'Your personal assistant') }}
			</h3>
			<p class="talkai-assist__text">
				{{ t('educai', 'A bot just for you. It knows the folders you choose and helps you search, summarise and write. Set up in a minute.') }}
			</p>
		</div>
		<div class="talkai-assist__actions">
			<button type="button" class="talkai-btn talkai-btn--filled" @click="$emit('setup')">
				{{ t('educai', 'Set up') }}
			</button>
		</div>
	</section>
</template>

<script>
import TalkAiIcon from './TalkAiIcon.vue'
import { t } from '../l10n.js'

// Invitation to set up the personal assistant. It stays until the assistant exists, whatever other bots the user has.
export default {
	name: 'AssistantBanner',
	components: { TalkAiIcon },
	emits: ['setup'],
	methods: { t },
}
</script>
