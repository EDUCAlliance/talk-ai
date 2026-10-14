<template>
	<div class="ta-panel">
		<AdminSection anchor="educai-admin-bots"
			:title="t('educai', 'All Bots')"
			:description="t('educai', 'Every bot on this server. Changes here apply immediately.')">
			<template #actions>
				<button type="button"
					class="ta-btn"
					:disabled="admin.loadingAllBots"
					@click="admin.loadAllBots()">
					{{ admin.loadingAllBots ? t('educai', 'Loading…') : t('educai', 'Refresh') }}
				</button>
			</template>
			<div class="ta-toolbar">
				<div class="ta-segmented" role="group" :aria-label="t('educai', 'Filter bots')">
					<button v-for="filter in filters"
						:key="filter.id"
						type="button"
						:aria-pressed="String(activeFilter === filter.id)"
						@click="activeFilter = filter.id">
						{{ filter.label }} <small>{{ filter.count }}</small>
					</button>
				</div>
				<input v-model="search"
					class="ta-input"
					type="search"
					:placeholder="t('educai', 'Search name, mention or owner …')"
					:aria-label="t('educai', 'Search bots')">
			</div>
			<p v-if="pendingCount > 0" class="ta-note">
				{{ n('educai', '{count} bot is waiting for approval. Approvals are handled in the personal {app} settings under "My bots".', '{count} bots are waiting for approval. Approvals are handled in the personal {app} settings under "My bots".', pendingCount, { count: pendingCount, app: appName }) }}
				<a :href="approvalsUrl" class="ta-link">{{ t('educai', 'Open approvals') }} →</a>
			</p>
			<p v-if="admin.loadingAllBots && admin.allBots.length === 0" class="ta-hint">
				{{ t('educai', 'Loading bots…') }}
			</p>
			<p v-else-if="visibleBots.length === 0" class="ta-empty">
				{{ admin.allBots.length === 0 ? t('educai', 'No bots found.') : t('educai', 'No bot matches the filter.') }}
			</p>
			<div v-else class="ta-list">
				<div v-for="bot in visibleBots" :key="bot.id" class="ta-list__row bot-row">
					<div class="ta-list__main">
						<p class="ta-list__title">
							{{ bot.bot_name }}
							<span class="ta-chip" :class="statusClass(bot.approval_status)">{{ admin.formatApprovalStatus(bot.approval_status) }}</span>
						</p>
						<p class="ta-list__meta">
							{{ meta(bot) }}
						</p>
					</div>
					<div class="ta-list__actions">
						<button type="button" class="ta-btn" @click="admin.editBotAdmin(bot)">
							{{ t('educai', 'Edit') }}
						</button>
						<button type="button" class="ta-btn ta-btn--danger" @click="admin.deleteBotAdmin(bot)">
							{{ t('educai', 'Delete') }}
						</button>
					</div>
				</div>
			</div>
		</AdminSection>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { t, n } from '../../l10n.js'
import { APP_DISPLAY_NAME } from '../../branding.js'
import AdminSection from './AdminSection.vue'

export default {
	name: 'BotsTab',
	components: { AdminSection },
	inject: ['admin'],
	data() {
		return { activeFilter: 'all', search: '', appName: APP_DISPLAY_NAME }
	},
	computed: {
		pendingCount() {
			return this.admin.allBots.filter(bot => bot.approval_status === 'pending').length
		},
		filters() {
			const bots = this.admin.allBots
			const count = status => bots.filter(bot => (bot.approval_status || 'approved') === status).length
			return [
				{ id: 'all', label: t('educai', 'All'), count: bots.length },
				{ id: 'pending', label: t('educai', 'Pending approval'), count: count('pending') },
				{ id: 'approved', label: t('educai', 'Approved'), count: count('approved') },
				{ id: 'draft', label: t('educai', 'Draft'), count: count('draft') },
				{ id: 'personal', label: t('educai', 'Personal'), count: count('personal') },
			]
		},
		visibleBots() {
			const query = this.search.trim().toLowerCase()
			return this.admin.allBots.filter(bot => {
				if (this.activeFilter !== 'all' && (bot.approval_status || 'approved') !== this.activeFilter) return false
				if (!query) return true
				return [bot.bot_name, bot.mention_name, bot.user_id].some(value => String(value || '').toLowerCase().includes(query))
			})
		},
		approvalsUrl() {
			return generateUrl('/settings/user/educai')
		},
	},
	methods: {
		t,
		n,
		meta(bot) {
			const name = String(bot.mention_name || '')
			const parts = [name.startsWith('@') ? name : `@${name}`, t('educai', 'Owner: {owner}', { owner: bot.user_id })]
			// The status chip already says "Personal" for personal bots.
			if (bot.visibility !== 'personal') parts.push(this.admin.formatVisibility(bot.visibility))
			return parts.join(' · ')
		},
		statusClass(status) {
			return { pending: 'ta-chip--warn', approved: 'ta-chip--ok', personal: 'ta-chip--info' }[status || 'approved'] || ''
		},
	},
}
</script>
