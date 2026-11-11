<template>
	<section class="talkai-screen">
		<header class="talkai-page-head">
			<div>
				<h2 class="talkai-page-head__title">
					{{ t('educai', 'My bots') }}
				</h2>
				<p class="talkai-page-head__lead">
					{{ t('educai', 'Bots you created. See at a glance what is live, what is waiting for approval and where something needs your attention.') }}
				</p>
			</div>
		</header>

		<div v-if="rows.length > 0" class="talkai-list">
			<div class="talkai-list__head" aria-hidden="true">
				<span>{{ t('educai', 'Bot') }}</span>
				<span>{{ t('educai', 'Status') }}</span>
				<span>{{ t('educai', 'Knowledge') }}</span>
				<span />
			</div>
			<div v-for="row in rows" :key="row.bot.id" class="talkai-list__row">
				<div class="talkai-list__name">
					<BotAvatar :name="row.bot.bot_name" :scope="row.scope.key" />
					<div>
						<router-link class="talkai-list__title talkai-tile__open" :to="`/bots/${row.bot.id}`">
							{{ row.bot.bot_name }}
						</router-link>
						<span class="talkai-list__sub">{{ row.mention }} · {{ row.scope.detail }}</span>
					</div>
				</div>
				<div class="talkai-list__meta">
					<span class="talkai-row">
						<span v-for="chip in row.status.chips"
							:key="chip.key"
							class="talkai-chip"
							:class="[`talkai-chip--${chip.tone}`, { 'talkai-chip--dot': chip.dot }]">{{ chip.label }}</span>
					</span>
					<span v-if="row.status.note" class="talkai-list__note">{{ row.status.note }}</span>
					<div v-if="row.status.feedback" class="talkai-feedback">
						{{ t('educai', 'Reviewer: “{feedback}”', { feedback: row.status.feedback }) }}
					</div>
				</div>
				<div class="talkai-list__meta">
					<span>
						{{ row.sources }}
						<template v-if="row.problems">
							· <b class="talkai-warn-text">{{ row.problems }}</b>
						</template>
					</span>
					<span v-if="row.abilities" class="talkai-list__note">{{ row.abilities }}</span>
				</div>
				<div class="talkai-list__actions">
					<button v-if="row.status.stage === 'draft'"
						type="button"
						class="talkai-btn talkai-btn--filled"
						@click="submitBot = row.bot">
						{{ t('educai', 'Submit') }}
					</button>
					<router-link class="talkai-btn" :to="row.status.stage === 'feedback' ? `/bots/${row.bot.id}/basics` : `/bots/${row.bot.id}`">
						{{ row.status.stage === 'feedback' ? t('educai', 'Revise') : t('educai', 'Open') }}
					</router-link>
					<NcActions :aria-label="t('educai', 'More actions for {bot}', { bot: row.bot.bot_name })">
						<NcActionButton close-after-click @click="talkBot = row.bot">
							<template #icon>
								<TalkAiIcon name="chat" :size="20" />
							</template>
							{{ t('educai', 'Use in Talk') }}
						</NcActionButton>
						<NcActionButton close-after-click @click="deleteCandidate = row.bot">
							<template #icon>
								<TalkAiIcon name="trash" :size="20" />
							</template>
							{{ t('educai', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>
		</div>
		<div v-else-if="failed" class="talkai-state" role="alert">
			<span class="talkai-state__icon"><TalkAiIcon name="alert" :size="26" /></span>
			<b class="talkai-state__title">{{ t('educai', 'Your bots could not be loaded') }}</b>
			<button type="button" class="talkai-btn" @click="load">
				{{ t('educai', 'Try again') }}
			</button>
		</div>
		<div v-else-if="!loaded" class="talkai-state" role="status">
			<NcLoadingIcon :size="32" />
			<span>{{ t('educai', 'Loading bots…') }}</span>
		</div>
		<div v-else class="talkai-state">
			<span class="talkai-state__icon"><TalkAiIcon name="robot" :size="26" /></span>
			<b class="talkai-state__title">{{ t('educai', 'You have not created a bot yet') }}</b>
			<p class="talkai-state__text">
				{{ t('educai', 'A bot answers in Talk with the knowledge and the instruction you give it. A template gets you started in a few minutes.') }}
			</p>
			<router-link class="talkai-btn talkai-btn--filled" to="/new">
				<TalkAiIcon name="plus" :size="16" />
				{{ t('educai', 'New bot') }}
			</router-link>
		</div>

		<BotSubmitDialog v-if="submitBot"
			:bot="submitBot"
			:directory="directory"
			@close="submitBot = null"
			@submitted="submitBot = null" />
		<BotTalkDialog v-if="talkBot" :bot="talkBot" @close="talkBot = null" />
		<TalkAiDialog v-if="deleteCandidate"
			:name="t('educai', 'Delete {bot}?', { bot: deleteCandidate.bot_name })"
			size="small"
			@close="deleteCandidate = null">
			<p>{{ t('educai', 'The bot, its knowledge index and its conversation history are deleted. Files in your Nextcloud stay unchanged.') }}</p>
			<template #actions>
				<button type="button"
					class="talkai-btn"
					:disabled="deleting"
					@click="deleteCandidate = null">
					{{ t('educai', 'Cancel') }}
				</button>
				<button type="button"
					class="talkai-btn talkai-btn--danger"
					:disabled="deleting"
					@click="deleteBot">
					{{ t('educai', 'Delete') }}
				</button>
			</template>
		</TalkAiDialog>
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import BotAvatar from '../components/BotAvatar.vue'
import BotSubmitDialog from '../components/BotSubmitDialog.vue'
import BotTalkDialog from '../components/BotTalkDialog.vue'
import TalkAiDialog from '../components/TalkAiDialog.vue'
import TalkAiIcon from '../components/TalkAiIcon.vue'
import { n, t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { scopeOf } from '../utils/botScope.js'
import { statusOf } from '../utils/botStatus.js'
import { capabilitiesOf } from '../utils/capabilities.js'

// The bots the user created, with their state between draft and approval.
export default {
	name: 'MyBots',
	components: { BotAvatar, BotSubmitDialog, BotTalkDialog, NcActionButton, NcActions, NcLoadingIcon, TalkAiDialog, TalkAiIcon },
	data() {
		return {
			submitBot: null,
			talkBot: null,
			deleteCandidate: null,
			deleting: false,
		}
	},
	computed: {
		directory() {
			return store.state.directory
		},
		loaded() {
			return store.state.loaded.ownBots
		},
		failed() {
			return store.state.failed.ownBots
		},
		// Bots that need the owner first: feedback, then drafts, then the rest by name.
		rows() {
			const order = { feedback: 0, draft: 1 }
			return store.state.ownBots
				.map(bot => this.describe(bot))
				.sort((a, b) => (order[a.status.stage] ?? 2) - (order[b.status.stage] ?? 2)
					|| String(a.bot.bot_name).localeCompare(String(b.bot.bot_name)))
		},
	},
	created() {
		this.load()
	},
	methods: {
		t,
		load() {
			store.loadOwnBots().catch(error => console.error('Failed to load own bots', error))
		},
		describe(bot) {
			const count = Number(bot.rag_source_count)
			const problems = Number(bot.rag_source_problem_count) || 0
			const abilities = capabilitiesOf(bot).filter(capability => capability.key !== 'knowledge')
			let sources = bot.rag_enabled ? t('educai', 'Knowledge is on') : t('educai', 'No sources')
			if (Number.isFinite(count) && bot.rag_source_count !== null && bot.rag_source_count !== undefined) {
				sources = count === 0 ? t('educai', 'No sources') : n('educai', '%n source', '%n sources', count)
			}
			return {
				bot,
				scope: scopeOf(bot, this.directory),
				status: statusOf(bot),
				mention: `@${String(bot.mention_name || '').replace(/^@+/, '')}`,
				sources,
				problems: problems > 0 ? n('educai', '%n problem', '%n problems', problems) : '',
				abilities: abilities.map(capability => capability.label).join(' · '),
			}
		},
		async deleteBot() {
			const bot = this.deleteCandidate
			this.deleting = true
			try {
				await store.deleteBot(bot.id)
				showSuccess(t('educai', 'Bot deleted successfully'))
				this.deleteCandidate = null
			} catch (error) {
				console.error('Failed to delete bot:', error)
				showError(getApiErrorMessage(error, t('educai', 'Failed to delete bot')))
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>
