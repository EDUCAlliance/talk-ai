<template>
	<section class="talkai-assist" aria-labelledby="talkai-assist-title">
		<div class="talkai-assist__art" aria-hidden="true">
			<TalkAiIcon name="sparkle" :size="30" />
		</div>
		<div class="talkai-assist__body">
			<h3 id="talkai-assist-title" class="talkai-assist__title">
				{{ t('educai', 'Your personal assistant') }}
			</h3>
			<p class="talkai-assist__text">
				{{ t('educai', 'A bot just for you. It knows the folders you choose and helps you search, summarise and write. Set up in a minute.') }}
			</p>
			<ul class="talkai-assist__asks" :aria-label="t('educai', 'Example questions')">
				<li v-for="example in examples" :key="example" class="talkai-assist__ask">
					{{ t('educai', '“{question}”', { question: example }) }}
				</li>
			</ul>
		</div>
		<button type="button" class="talkai-btn talkai-btn--filled talkai-assist__go" @click="$emit('setup')">
			{{ t('educai', 'Set up') }}
		</button>
	</section>
</template>

<script>
import TalkAiIcon from './TalkAiIcon.vue'
import { t } from '../l10n.js'
import { assistantExamples } from '../utils/assistant.js'

// Invitation to set up the personal assistant. It stays until the assistant exists, whatever other bots the user has.
export default {
	name: 'AssistantBanner',
	components: { TalkAiIcon },
	emits: ['setup'],
	computed: {
		examples() {
			return assistantExamples()
		},
	},
	methods: { t },
}
</script>
