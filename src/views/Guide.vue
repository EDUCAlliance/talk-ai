<template>
	<section class="talkai-screen talkai-guide">
		<header class="talkai-page-head">
			<div>
				<h2 class="talkai-page-head__title">
					{{ t('educai', 'How {appName} works', { appName: APP_DISPLAY_NAME }) }}
				</h2>
				<p class="talkai-page-head__lead">
					{{ t('educai', 'Bots answer in Talk. You use the ones that are shared with you, and you can create your own.') }}
				</p>
			</div>
		</header>

		<div class="talkai-grid2">
			<div class="talkai-section">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Use a bot') }}
				</h3>
				<p class="talkai-section__lead">
					{{ t('educai', 'In any Talk chat, mention the bot with @ and ask your question. Under “Discover” you find all bots you can use, and “Use in Talk” opens a chat with the first message already written.') }}
				</p>
				<router-link class="talkai-go" to="/discover">
					{{ t('educai', 'Discover') }}
					<TalkAiIcon name="right" :size="15" />
				</router-link>
			</div>
			<div class="talkai-section">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Your data') }}
				</h3>
				<p class="talkai-section__lead">
					{{ t('educai', 'Files stay in your Nextcloud. A bot only reads the sources its creator selected and the files you put into the chat. “Activity” shows your own conversations with bots, and only you see it.') }}
				</p>
				<router-link class="talkai-go" to="/activity">
					{{ t('educai', 'Activity') }}
					<TalkAiIcon name="right" :size="15" />
				</router-link>
			</div>
		</div>

		<div class="talkai-section">
			<h3 class="talkai-section__title">
				{{ t('educai', 'One colour per audience') }}
			</h3>
			<p class="talkai-section__lead">
				{{ t('educai', 'The colour of a bot tells who can use it. The same information always stands next to it as text.') }}
			</p>
			<ul class="talkai-guide__scopes">
				<li v-for="scope in scopes" :key="scope.key" class="talkai-guide__scope">
					<BotAvatar :name="scope.initials" :scope="scope.key" />
					<span>
						<b>{{ scope.label }}</b>
						<span class="talkai-checks__aside">{{ scope.text }}</span>
					</span>
				</li>
			</ul>
		</div>

		<div class="talkai-section">
			<h3 class="talkai-section__title">
				{{ t('educai', 'From an idea to a shared bot') }}
			</h3>
			<p class="talkai-section__lead">
				{{ t('educai', 'A bot only for you is active right away. A bot for a group, a team or everyone is reviewed first.') }}
			</p>
			<div class="talkai-flow">
				<span class="talkai-flow__step">{{ t('educai', 'Create') }}</span>
				<span class="talkai-flow__arrow" aria-hidden="true">→</span>
				<span class="talkai-flow__step">{{ t('educai', 'Test') }}</span>
				<span class="talkai-flow__arrow" aria-hidden="true">→</span>
				<span class="talkai-flow__step">{{ t('educai', 'Submit') }}</span>
				<span class="talkai-flow__arrow" aria-hidden="true">→</span>
				<span class="talkai-flow__step">{{ t('educai', 'Approval') }}</span>
			</div>
			<p class="talkai-hint">
				{{ t('educai', 'Until approval only you and the reviewers can use the bot. Later changes to an approved bot are reviewed again, and the approved version stays live in the meantime.') }}
			</p>
		</div>
	</section>
</template>

<script>
import BotAvatar from '../components/BotAvatar.vue'
import TalkAiIcon from '../components/TalkAiIcon.vue'
import { APP_DISPLAY_NAME } from '../branding.js'
import { t } from '../l10n.js'

export default {
	name: 'Guide',
	components: { BotAvatar, TalkAiIcon },
	data() {
		return { APP_DISPLAY_NAME }
	},
	computed: {
		scopes() {
			return [
				{ key: 'personal', initials: 'M', label: t('educai', 'Only you'), text: t('educai', 'Nobody else sees the bot. No approval needed.') },
				{ key: 'group', initials: 'G', label: t('educai', 'Group'), text: t('educai', 'For example a degree programme or a department.') },
				{ key: 'team', initials: 'T', label: t('educai', 'Team'), text: t('educai', 'For example a project or a working group.') },
				{ key: 'everyone', initials: 'E', label: t('educai', 'Everyone'), text: t('educai', 'Everyone in this Nextcloud.') },
			]
		},
	},
	methods: { t },
}
</script>
