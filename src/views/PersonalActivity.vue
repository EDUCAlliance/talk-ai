<template>
	<section class="talkai-screen">
		<header class="talkai-page-head">
			<div>
				<h2 class="talkai-page-head__title">
					{{ t('educai', 'Activity') }}
				</h2>
				<p class="talkai-page-head__lead">
					{{ t('educai', 'Your conversations with bots and what happened in the background. Only you see this page.') }}
				</p>
			</div>
			<button type="button"
				class="talkai-btn talkai-btn--ghost"
				:disabled="loading"
				@click="loadTraces">
				<TalkAiIcon name="refresh" :size="16" />
				{{ t('educai', 'Refresh') }}
			</button>
		</header>

		<div class="talkai-toolbar">
			<label class="talkai-search">
				<TalkAiIcon name="search" />
				<input v-model.trim="filters.q"
					class="talkai-search__input"
					type="text"
					:placeholder="t('educai', 'Message, bot, or error')"
					:aria-label="t('educai', 'Search activity')"
					@keyup.enter="applyFilters">
			</label>
			<div class="talkai-filters" role="group" :aria-label="t('educai', 'Filter activity')">
				<button type="button"
					class="talkai-filter"
					:aria-pressed="String(!filters.onlyErrors && !filters.onlyWithTools)"
					@click="setQuickFilter('')">
					{{ t('educai', 'All') }}
				</button>
				<button type="button"
					class="talkai-filter"
					:aria-pressed="String(filters.onlyErrors)"
					@click="setQuickFilter('onlyErrors')">
					{{ t('educai', 'Errors only') }}
				</button>
				<button type="button"
					class="talkai-filter"
					:aria-pressed="String(filters.onlyWithTools)"
					@click="setQuickFilter('onlyWithTools')">
					{{ t('educai', 'With tools') }}
				</button>
			</div>
		</div>
		<details class="talkai-disclosure talkai-act__more" :open="hasMoreFilters">
			<summary class="talkai-disclosure__summary">
				{{ t('educai', 'More filters') }}
				<span class="talkai-disclosure__aside">· {{ t('educai', 'status, bot and period') }}</span>
			</summary>
			<div class="talkai-act__filters">
				<label>
					<span class="talkai-label">{{ t('educai', 'Status') }}</span>
					<select v-model="filters.status" class="talkai-input" @change="applyFilters">
						<option value="">{{ t('educai', 'Any status') }}</option>
						<option value="running">{{ t('educai', 'Running') }}</option>
						<option value="success">{{ t('educai', 'Success') }}</option>
						<option value="error">{{ t('educai', 'Error') }}</option>
						<option value="partial">{{ t('educai', 'Partial') }}</option>
						<option value="incomplete">{{ t('educai', 'Incomplete') }}</option>
					</select>
				</label>
				<label>
					<span class="talkai-label">{{ t('educai', 'Bot mention name') }}</span>
					<input v-model.trim="filters.botMentionName"
						class="talkai-input"
						type="text"
						@keyup.enter="applyFilters"
						@change="applyFilters">
				</label>
				<label>
					<span class="talkai-label">{{ t('educai', 'From') }}</span>
					<input v-model="filters.fromDate"
						class="talkai-input"
						type="date"
						@change="applyFilters">
				</label>
				<label>
					<span class="talkai-label">{{ t('educai', 'To') }}</span>
					<input v-model="filters.toDate"
						class="talkai-input"
						type="date"
						@change="applyFilters">
				</label>
			</div>
		</details>

		<div v-if="error" class="talkai-note talkai-note--err talkai-act__gap" role="alert">
			<TalkAiIcon name="alert" />
			<span>{{ error }}</span>
		</div>

		<div v-if="loading && traces.length === 0" class="talkai-state" role="status">
			<span>{{ t('educai', 'Loading activity…') }}</span>
		</div>
		<div v-else-if="traces.length === 0" class="talkai-state">
			<span class="talkai-state__icon"><TalkAiIcon name="chat" :size="26" /></span>
			<b class="talkai-state__title">{{ t('educai', 'No activity yet') }}</b>
			<p class="talkai-state__text">
				{{ t('educai', 'Use a bot in Nextcloud Talk and your own trace history will appear here.') }}
			</p>
		</div>

		<div v-else class="talkai-act">
			<div>
				<template v-for="day in days" :key="day.label">
					<div class="talkai-act__day">
						{{ day.label }}
					</div>
					<button v-for="trace in day.traces"
						:key="trace.id"
						type="button"
						class="talkai-run"
						:aria-pressed="String(selectedTraceId === trace.id)"
						@click="selectTrace(trace.id)">
						<span class="talkai-run__time">{{ formatTime(trace.started_at) }}</span>
						<BotAvatar :name="botNameOf(trace)" :scope="scopeKeyOf(trace)" size="sm" />
						<span class="talkai-run__text">
							<b class="talkai-run__bot">{{ trace.bot_mention_name || APP_DISPLAY_NAME }}<template v-if="Number(trace.tool_call_count) > 0"> · {{ formatToolCount(trace.tool_call_count) }}</template></b>
							<span class="talkai-run__message">{{ trace.user_message_preview || t('educai', 'No message preview') }}</span>
						</span>
						<span class="talkai-chip talkai-chip--dot" :class="`talkai-chip--${statusTone(trace.status)}`">
							{{ trace.status === 'success' ? formatDuration(trace.duration_ms) : formatStatus(trace.status) }}
						</span>
					</button>
				</template>

				<div class="talkai-act__pages">
					<button type="button"
						class="talkai-btn talkai-btn--small"
						:disabled="offset === 0"
						@click="previousPage">
						{{ t('educai', 'Previous') }}
					</button>
					<span class="talkai-muted">{{ paginationLabel }}</span>
					<button type="button"
						class="talkai-btn talkai-btn--small"
						:disabled="offset + limit >= total"
						@click="nextPage">
						{{ t('educai', 'Next') }}
					</button>
				</div>
				<button type="button"
					class="talkai-btn talkai-btn--ghost talkai-btn--small talkai-act__clear"
					:disabled="clearing"
					@click="clearActivity">
					<TalkAiIcon name="trash" :size="13" />
					{{ t('educai', 'Clear my activity') }}
				</button>
			</div>

			<aside class="talkai-section talkai-act__detail">
				<p v-if="detailLoading" class="talkai-muted" role="status">
					{{ t('educai', 'Loading trace details…') }}
				</p>
				<div v-else-if="detailError" class="talkai-note talkai-note--err" role="alert">
					<TalkAiIcon name="alert" />
					<span>{{ detailError }}</span>
				</div>
				<template v-else-if="selectedTrace">
					<div class="talkai-spread">
						<h3 class="talkai-section__title">
							{{ selectedTrace.bot_mention_name
								? t('educai', 'Answer from {bot}', { bot: selectedTrace.bot_mention_name })
								: t('educai', '{appName} run', { appName: APP_DISPLAY_NAME }) }}
						</h3>
						<span class="talkai-chip talkai-chip--dot" :class="`talkai-chip--${statusTone(selectedTrace.status)}`">{{ formatStatus(selectedTrace.status) }}</span>
					</div>
					<p class="talkai-section__lead">
						{{ formatDate(selectedTrace.started_at) }} · {{ formatDuration(selectedTrace.duration_ms) }} · {{ formatToolCount(selectedTrace.tool_call_count) }} · {{ formatTokens(selectedTrace.total_token_count) }}
					</p>

					<ol class="talkai-timeline">
						<li v-for="event in events" :key="event.id" class="talkai-timeline__item">
							<span class="talkai-timeline__dot" :class="`talkai-timeline__dot--${eventTone(event)}`">
								<TalkAiIcon v-if="eventTone(event) === 'ok'" name="check" :size="12" />
							</span>
							<div class="talkai-act__event">
								<b>{{ formatEventType(event.event_type) }}<template v-if="event.tool_name"> · {{ event.tool_name }}</template></b>
								<span class="talkai-checks__aside">
									{{ formatDate(event.created_at) }}<template v-if="event.status"> · {{ formatStatus(event.status) }}</template><template v-if="event.duration_ms !== null && event.duration_ms !== undefined"> · {{ formatDuration(event.duration_ms) }}</template>
								</span>
								<p v-if="event.error_message" class="talkai-act__error">
									{{ event.error_message }}
								</p>
								<details v-if="event.payload_preview || event.result_preview || getRawLlmPayload(event)" class="talkai-act__tech">
									<summary>{{ t('educai', 'Technical details') }}</summary>
									<template v-if="getRawLlmPayload(event)">
										<b class="talkai-act__label">{{ t('educai', 'Raw LLM payload') }}</b>
										<pre class="talkai-act__pre">{{ formatJson(getRawLlmPayload(event)) }}</pre>
									</template>
									<template v-if="event.payload_preview">
										<b class="talkai-act__label">{{ t('educai', 'Payload') }}</b>
										<pre class="talkai-act__pre">{{ event.payload_json ? formatJson(event.payload_json) : event.payload_preview }}</pre>
									</template>
									<template v-if="event.result_preview">
										<b class="talkai-act__label">{{ t('educai', 'Result') }}</b>
										<pre class="talkai-act__pre">{{ event.result_json ? formatJson(event.result_json) : event.result_preview }}</pre>
									</template>
								</details>
							</div>
						</li>
					</ol>
					<div class="talkai-row talkai-act__gap">
						<button type="button" class="talkai-btn talkai-btn--small" @click="exportSelectedTrace">
							{{ t('educai', 'Export JSON') }}
						</button>
						<button type="button" class="talkai-btn talkai-btn--ghost talkai-btn--small" @click="deleteSelectedTrace">
							{{ t('educai', 'Delete') }}
						</button>
					</div>
				</template>
				<p v-else class="talkai-muted">
					{{ t('educai', 'Select an activity run to inspect its timeline.') }}
				</p>
			</aside>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { APP_DISPLAY_NAME } from '../branding.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import BotAvatar from '../components/BotAvatar.vue'
import TalkAiIcon from '../components/TalkAiIcon.vue'
import { getCanonicalLocale, n, t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { scopeOf } from '../utils/botScope.js'

export default {
	name: 'PersonalActivity',

	components: { BotAvatar, TalkAiIcon },

	data() {
		return {
			APP_DISPLAY_NAME,
			traces: [],
			total: 0,
			limit: 25,
			offset: 0,
			loading: false,
			tracesRequestId: 0,
			detailRequestId: 0,
			clearing: false,
			error: '',
			selectedTraceId: null,
			selectedTrace: null,
			events: [],
			detailLoading: false,
			detailError: '',
			filters: {
				q: '',
				status: '',
				botMentionName: '',
				fromDate: '',
				toDate: '',
				onlyErrors: false,
				onlyWithTools: false,
			},
		}
	},

	computed: {
		paginationLabel() {
			return t('educai', '{start}–{end} of {total}', {
				start: this.formatNumber(this.offset + 1),
				end: this.formatNumber(Math.min(this.offset + this.limit, this.total)),
				total: this.formatNumber(this.total),
			})
		},

		hasMoreFilters() {
			return !!(this.filters.status || this.filters.botMentionName || this.filters.fromDate || this.filters.toDate)
		},

		// Runs of the current page under the day they started
		days() {
			const days = []
			for (const trace of this.traces) {
				const label = this.dayLabel(trace.started_at)
				if (days.length === 0 || days[days.length - 1].label !== label) {
					days.push({ label, traces: [] })
				}
				days[days.length - 1].traces.push(trace)
			}
			return days
		},

		// Bots the user can see, by mention: an activity entry only knows the mention
		botsByMention() {
			const bots = new Map()
			for (const bot of [...store.state.publicBots, ...store.state.ownBots]) {
				bots.set(String(bot.mention_name || '').replace(/^@+/, '').toLowerCase(), bot)
			}
			return bots
		},
	},

	mounted() {
		this.loadTraces()
	},

	beforeUnmount() {
		this.tracesRequestId++
		this.detailRequestId++
	},

	methods: {
		async loadTraces() {
			const requestId = ++this.tracesRequestId
			this.loading = true
			this.error = ''
			try {
				const params = {
					limit: this.limit,
					offset: this.offset,
				}
				if (this.filters.q) {
					params.q = this.filters.q
				}
				if (this.filters.status) {
					params.status = this.filters.status
				}
				if (this.filters.botMentionName) {
					params.botMentionName = this.filters.botMentionName
				}
				if (this.filters.fromDate) {
					params.from = this.startOfDay(this.filters.fromDate)
				}
				if (this.filters.toDate) {
					params.to = this.endOfDay(this.filters.toDate)
				}
				if (this.filters.onlyErrors) {
					params.onlyErrors = '1'
				}
				if (this.filters.onlyWithTools) {
					params.onlyWithTools = '1'
				}

				const response = await axios.get(generateUrl('/apps/educai/api/v1/traces'), { params })
				if (requestId !== this.tracesRequestId) return
				this.traces = response.data.traces || []
				this.total = response.data.total || 0
				this.limit = response.data.limit || this.limit
				this.offset = response.data.offset ?? this.offset
				if (this.traces.length > 0 && !this.selectedTraceId) {
					await this.selectTrace(this.traces[0].id)
				} else if (this.selectedTraceId && !this.traces.some((trace) => trace.id === this.selectedTraceId)) {
					this.clearSelection()
				} else if (this.selectedTraceId) {
					await this.selectTrace(this.selectedTraceId)
				}
			} catch (error) {
				if (requestId !== this.tracesRequestId) return
				this.error = getApiErrorMessage(error, t('educai', 'Failed to load activity'))
				showError(this.error)
			} finally {
				if (requestId === this.tracesRequestId) this.loading = false
			}
		},

		applyFilters() {
			this.offset = 0
			this.clearSelection()
			this.loadTraces()
		},

		setQuickFilter(key) {
			this.filters.onlyErrors = key === 'onlyErrors' && !this.filters.onlyErrors
			this.filters.onlyWithTools = key === 'onlyWithTools' && !this.filters.onlyWithTools
			this.applyFilters()
		},

		botOf(trace) {
			return this.botsByMention.get(String(trace.bot_mention_name || '').replace(/^@+/, '').toLowerCase()) ?? null
		},

		botNameOf(trace) {
			return this.botOf(trace)?.bot_name ?? String(trace.bot_mention_name || '').replace(/^@+/, '')
		},

		// A bot that is gone or no longer shared gets the neutral colour
		scopeKeyOf(trace) {
			const bot = this.botOf(trace)
			return bot ? scopeOf(bot).key : 'everyone'
		},

		statusTone(status) {
			return { success: 'ok', error: 'err', partial: 'warn', incomplete: 'warn', running: 'info' }[status] ?? 'off'
		},

		eventTone(event) {
			if (event.status === 'error' || event.event_type === 'error' || event.error_message) {
				return 'err'
			}
			return ['ok', 'success', 'completed', 'used', 'registered'].includes(event.status) ? 'ok' : 'plain'
		},

		dayLabel(timestamp) {
			if (!timestamp) {
				return '-'
			}
			const date = new Date(timestamp * 1000)
			const midnight = value => new Date(value.getFullYear(), value.getMonth(), value.getDate()).getTime()
			const days = Math.round((midnight(new Date()) - midnight(date)) / 86400000)
			if (days === 0) {
				return t('educai', 'Today')
			}
			if (days === 1) {
				return t('educai', 'Yesterday')
			}
			return date.toLocaleDateString(getCanonicalLocale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
		},

		formatTime(timestamp) {
			return timestamp
				? new Date(timestamp * 1000).toLocaleTimeString(getCanonicalLocale(), { hour: '2-digit', minute: '2-digit' })
				: '-'
		},

		async selectTrace(id) {
			const requestId = ++this.detailRequestId
			this.selectedTraceId = id
			this.selectedTrace = null
			this.events = []
			this.detailLoading = true
			this.detailError = ''
			try {
				const response = await axios.get(generateUrl(`/apps/educai/api/v1/traces/${id}`))
				if (requestId !== this.detailRequestId || this.selectedTraceId !== id) return
				this.selectedTrace = response.data.trace
				this.events = response.data.events || []
			} catch (error) {
				if (requestId !== this.detailRequestId || this.selectedTraceId !== id) return
				this.detailError = getApiErrorMessage(error, t('educai', 'Activity details unavailable'))
				showError(this.detailError)
			} finally {
				if (requestId === this.detailRequestId) this.detailLoading = false
			}
		},

		async deleteSelectedTrace() {
			if (!this.selectedTrace || !confirm(t('educai', 'Delete this activity trace?'))) {
				return
			}
			try {
				await axios.delete(generateUrl(`/apps/educai/api/v1/traces/${this.selectedTrace.id}`))
				showSuccess(t('educai', 'Activity trace deleted'))
				this.clearSelection()
				await this.loadTraces()
			} catch (error) {
				showError(getApiErrorMessage(error, t('educai', 'Deletion failed')))
			}
		},

		async clearActivity() {
			if (!confirm(t('educai', 'Clear all of your {appName} activity traces?', { appName: APP_DISPLAY_NAME }))) {
				return
			}
			this.clearing = true
			try {
				await axios.delete(generateUrl('/apps/educai/api/v1/traces'))
				showSuccess(t('educai', 'Activity cleared'))
				this.clearSelection()
				await this.loadTraces()
			} catch (error) {
				showError(getApiErrorMessage(error, t('educai', 'Deletion failed')))
			} finally {
				this.clearing = false
			}
		},

		exportSelectedTrace() {
			if (!this.selectedTrace) {
				return
			}
			const payload = {
				trace: this.selectedTrace,
				events: this.events,
			}
			const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = `educai-trace-${this.selectedTrace.id}.json`
			document.body.appendChild(link)
			link.click()
			document.body.removeChild(link)
			URL.revokeObjectURL(url)
		},

		nextPage() {
			this.offset += this.limit
			this.loadTraces()
		},

		previousPage() {
			this.offset = Math.max(0, this.offset - this.limit)
			this.loadTraces()
		},

		clearSelection() {
			this.detailRequestId++
			this.detailLoading = false
			this.selectedTraceId = null
			this.selectedTrace = null
			this.events = []
			this.detailError = ''
		},

		formatDate(timestamp) {
			if (!timestamp) {
				return '-'
			}
			return new Date(timestamp * 1000).toLocaleString(getCanonicalLocale())
		},

		formatDuration(durationMs) {
			if (durationMs === null || durationMs === undefined) {
				return '-'
			}
			if (durationMs < 1000) {
				return t('educai', '{duration} ms', { duration: this.formatNumber(durationMs) })
			}
			return t('educai', '{duration} s', {
				duration: (durationMs / 1000).toLocaleString(getCanonicalLocale(), {
					minimumFractionDigits: 1,
					maximumFractionDigits: 1,
				}),
			})
		},

		formatTokens(value) {
			if (value === null || value === undefined || value === '') {
				return '-'
			}
			const tokens = Number(value)
			if (!Number.isFinite(tokens)) {
				return '-'
			}
			return n('educai', '{count} token', '{count} tokens', tokens, {
				count: tokens.toLocaleString(getCanonicalLocale()),
			})
		},

		formatToolCount(value) {
			const count = Number(value) || 0
			return n('educai', '{count} tool', '{count} tools', count, {
				count: count.toLocaleString(getCanonicalLocale()),
			})
		},

		formatEventCount(value) {
			const count = Number(value) || 0
			return n('educai', '{count} event', '{count} events', count, {
				count: count.toLocaleString(getCanonicalLocale()),
			})
		},

		formatNumber(value) {
			const number = Number(value)
			return Number.isFinite(number) ? number.toLocaleString(getCanonicalLocale()) : '-'
		},

		formatStatus(status) {
			if (!status) {
				return t('educai', 'Unknown')
			}
			const labels = {
				running: t('educai', 'Running'),
				success: t('educai', 'Success'),
				error: t('educai', 'Error'),
				partial: t('educai', 'Partial'),
				incomplete: t('educai', 'Incomplete'),
				ok: t('educai', 'OK'),
				started: t('educai', 'Started'),
				used: t('educai', 'Used'),
				reconciliation_deferred: t('educai', 'Reconciliation deferred'),
				completed: t('educai', 'Completed'),
				skipped: t('educai', 'Skipped'),
				registered: t('educai', 'Registered'),
				unregistered: t('educai', 'Unregistered'),
			}
			return labels[status] || t('educai', 'Unknown status')
		},

		formatEventType(type) {
			const labels = {
				assistant_response: t('educai', 'Assistant response'),
				conversation_history: t('educai', 'Conversation history'),
				error: t('educai', 'Error'),
				llm_request: t('educai', 'LLM request'),
				llm_response: t('educai', 'LLM response'),
				provider_attempt: t('educai', 'Provider attempt'),
				provider_attempt_budget_exhausted: t('educai', 'Provider attempt budget exhausted'),
				provider_compatibility: t('educai', 'Provider compatibility'),
				queue_delivery: t('educai', 'Queue delivery'),
				queue_persistence: t('educai', 'Queue persistence'),
				tool_call: t('educai', 'Tool call'),
				tool_rejected: t('educai', 'Tool rejected'),
				tool_result: t('educai', 'Tool result'),
				user_message: t('educai', 'User message'),
			}
			return labels[type] || t('educai', 'Unknown event')
		},

		getRawLlmPayload(event) {
			if (!event || event.event_type !== 'llm_request' || !event.payload_json) {
				return null
			}
			return event.payload_json.request_payload || null
		},

		formatJson(value) {
			return JSON.stringify(value, null, 2)
		},

		startOfDay(dateValue) {
			const date = new Date(`${dateValue}T00:00:00`)
			return Math.floor(date.getTime() / 1000)
		},

		endOfDay(dateValue) {
			const date = new Date(`${dateValue}T23:59:59`)
			return Math.floor(date.getTime() / 1000)
		},
	},
}
</script>
