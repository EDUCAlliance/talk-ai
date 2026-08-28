<template>
	<section class="activity-view">
		<div class="activity-title-row">
			<div>
				<h3>{{ t('educai', 'Activity') }}</h3>
				<p>{{ t('educai', 'This page shows {appName} interactions you started. Detailed tool inputs and results are visible only to you.', { appName: APP_DISPLAY_NAME }) }}</p>
			</div>
			<div class="activity-actions">
				<button
					type="button"
					class="button"
					:disabled="loading"
					@click="loadTraces">
					<span class="icon-history" />
					{{ t('educai', 'Refresh') }}
				</button>
				<button
					type="button"
					class="button error"
					:disabled="traces.length === 0 || clearing"
					@click="clearActivity">
					<span class="icon-delete" />
					{{ t('educai', 'Clear my activity') }}
				</button>
			</div>
		</div>

		<p class="privacy-note">
			{{ t('educai', 'Only interactions started by your account are shown here. We take privacy seriously: your trace history is private to your account and cannot be viewed by anyone else.') }}
		</p>

		<div class="filters">
			<label>
				<span>{{ t('educai', 'Search') }}</span>
				<input
					v-model.trim="filters.q"
					type="search"
					:placeholder="t('educai', 'Message, bot, or error')"
					@keyup.enter="applyFilters">
			</label>
			<label>
				<span>{{ t('educai', 'Status') }}</span>
				<select v-model="filters.status" @change="applyFilters">
					<option value="">{{ t('educai', 'Any status') }}</option>
					<option value="running">{{ t('educai', 'Running') }}</option>
					<option value="success">{{ t('educai', 'Success') }}</option>
					<option value="error">{{ t('educai', 'Error') }}</option>
					<option value="partial">{{ t('educai', 'Partial') }}</option>
				</select>
			</label>
			<label>
				<span>{{ t('educai', 'Bot mention name') }}</span>
				<input
					v-model.trim="filters.botMentionName"
					type="search"
					@keyup.enter="applyFilters">
			</label>
			<label>
				<span>{{ t('educai', 'From') }}</span>
				<input
					v-model="filters.fromDate"
					type="date"
					@change="applyFilters">
			</label>
			<label>
				<span>{{ t('educai', 'To') }}</span>
				<input
					v-model="filters.toDate"
					type="date"
					@change="applyFilters">
			</label>
			<label class="checkbox-label">
				<input v-model="filters.onlyErrors" type="checkbox" @change="applyFilters">
				<span>{{ t('educai', 'Only errors') }}</span>
			</label>
			<label class="checkbox-label">
				<input v-model="filters.onlyWithTools" type="checkbox" @change="applyFilters">
				<span>{{ t('educai', 'Only with tools') }}</span>
			</label>
			<button type="button" class="button primary" @click="applyFilters">
				<span class="icon-search" />
				{{ t('educai', 'Apply') }}
			</button>
		</div>

		<div v-if="error" class="error-banner">
			{{ error }}
		</div>

		<div v-if="loading" class="loading">
			<span class="icon-loading" />
			<p>{{ t('educai', 'Loading activity…') }}</p>
		</div>

		<div v-else-if="traces.length === 0" class="empty-state">
			<span class="icon-comment" />
			<h3>{{ t('educai', 'No activity yet') }}</h3>
			<p>{{ t('educai', 'Use a bot in Nextcloud Talk and your own trace history will appear here.') }}</p>
		</div>

		<div v-else class="activity-layout">
			<div class="trace-list" role="list">
				<button
					v-for="trace in traces"
					:key="trace.id"
					type="button"
					class="trace-row"
					:class="{ selected: selectedTraceId === trace.id }"
					@click="selectTrace(trace.id)">
					<span class="trace-main">
						<span class="trace-meta">
							<span>{{ formatDate(trace.started_at) }}</span>
							<span>{{ trace.bot_mention_name || APP_DISPLAY_NAME }}</span>
							<span class="status-badge" :class="trace.status">{{ formatStatus(trace.status) }}</span>
						</span>
						<span class="trace-preview">{{ trace.user_message_preview || t('educai', 'No message preview') }}</span>
					</span>
					<span class="trace-counts">
						<span>{{ formatToolCount(trace.tool_call_count) }}</span>
						<span>{{ formatDuration(trace.duration_ms) }}</span>
					</span>
				</button>

				<div class="pagination">
					<button
						type="button"
						class="button"
						:disabled="offset === 0"
						@click="previousPage">
						{{ t('educai', 'Previous') }}
					</button>
					<span>{{ paginationLabel }}</span>
					<button
						type="button"
						class="button"
						:disabled="offset + limit >= total"
						@click="nextPage">
						{{ t('educai', 'Next') }}
					</button>
				</div>
			</div>

			<aside class="trace-detail">
				<div v-if="detailLoading" class="loading-small">
					<span class="icon-loading" />
					<span>{{ t('educai', 'Loading trace details…') }}</span>
				</div>
				<div v-else-if="detailError" class="error-banner">
					{{ detailError }}
				</div>
				<div v-else-if="selectedTrace">
					<div class="detail-header">
						<div>
							<h4>{{ selectedTrace.bot_mention_name || t('educai', '{appName} run', { appName: APP_DISPLAY_NAME }) }}</h4>
							<p>
								{{ t('educai', '{date} - {status} - {events}', {
									date: formatDate(selectedTrace.started_at),
									status: formatStatus(selectedTrace.status),
									events: formatEventCount(selectedTrace.event_count),
								}) }}
							</p>
						</div>
						<div class="detail-actions">
							<button
								type="button"
								class="button"
								@click="exportSelectedTrace">
								<span class="icon-download" />
								{{ t('educai', 'Export JSON') }}
							</button>
							<button
								type="button"
								class="button error"
								@click="deleteSelectedTrace">
								<span class="icon-delete" />
								{{ t('educai', 'Delete') }}
							</button>
						</div>
					</div>

					<div class="run-summary">
						<div><strong>{{ t('educai', 'Status') }}</strong><span>{{ formatStatus(selectedTrace.status) }}</span></div>
						<div><strong>{{ t('educai', 'Duration') }}</strong><span>{{ formatDuration(selectedTrace.duration_ms) }}</span></div>
						<div><strong>{{ t('educai', 'Tools') }}</strong><span>{{ formatNumber(selectedTrace.tool_call_count) }}</span></div>
						<div><strong>{{ t('educai', 'Tokens') }}</strong><span>{{ formatTokens(selectedTrace.total_token_count) }}</span></div>
					</div>

					<ol class="event-list">
						<li v-for="event in events" :key="event.id" class="event-item">
							<div class="event-header">
								<span class="event-type">{{ formatEventType(event.event_type) }}</span>
								<span v-if="event.tool_name" class="event-tool">{{ event.tool_name }}</span>
								<span v-if="event.status" class="status-badge" :class="event.status">{{ formatStatus(event.status) }}</span>
								<span class="event-time">{{ formatDate(event.created_at) }}</span>
							</div>
							<p v-if="event.error_message" class="event-error">
								{{ event.error_message }}
							</p>
							<details v-if="getRawLlmPayload(event)" class="raw-llm-payload">
								<summary>{{ t('educai', 'Raw LLM payload') }}</summary>
								<pre>{{ formatJson(getRawLlmPayload(event)) }}</pre>
							</details>
							<div v-if="event.payload_preview" class="event-block">
								<strong>{{ t('educai', 'Payload') }}</strong>
								<pre>{{ event.payload_preview }}</pre>
								<details v-if="event.payload_json">
									<summary>{{ t('educai', 'Show JSON') }}</summary>
									<pre>{{ formatJson(event.payload_json) }}</pre>
								</details>
							</div>
							<div v-if="event.result_preview" class="event-block">
								<strong>{{ t('educai', 'Result') }}</strong>
								<pre>{{ event.result_preview }}</pre>
								<details v-if="event.result_json">
									<summary>{{ t('educai', 'Show JSON') }}</summary>
									<pre>{{ formatJson(event.result_json) }}</pre>
								</details>
							</div>
							<div v-if="event.duration_ms !== null && event.duration_ms !== undefined" class="event-duration">
								{{ formatDuration(event.duration_ms) }}
							</div>
						</li>
					</ol>
				</div>
				<div v-else class="empty-detail">
					{{ t('educai', 'Select an activity run to inspect its timeline.') }}
				</div>
			</aside>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { APP_DISPLAY_NAME } from '../branding.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { getCanonicalLocale, n, t } from '../l10n.js'
import { getApiErrorMessage } from '../utils/apiError.js'

export default {
	name: 'PersonalActivity',

	data() {
		return {
			APP_DISPLAY_NAME,
			traces: [],
			total: 0,
			limit: 25,
			offset: 0,
			loading: false,
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
	},

	mounted() {
		this.loadTraces()
	},

	methods: {
		async loadTraces() {
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
				this.traces = response.data.traces || []
				this.total = response.data.total || 0
				this.limit = response.data.limit || this.limit
				this.offset = response.data.offset || this.offset
				if (this.traces.length > 0 && !this.selectedTraceId) {
					await this.selectTrace(this.traces[0].id)
				} else if (this.selectedTraceId && !this.traces.some((trace) => trace.id === this.selectedTraceId)) {
					this.clearSelection()
				}
			} catch (error) {
				this.error = getApiErrorMessage(error, t('educai', 'Failed to load activity'))
				showError(this.error)
			} finally {
				this.loading = false
			}
		},

		applyFilters() {
			this.offset = 0
			this.clearSelection()
			this.loadTraces()
		},

		async selectTrace(id) {
			this.selectedTraceId = id
			this.detailLoading = true
			this.detailError = ''
			try {
				const response = await axios.get(generateUrl(`/apps/educai/api/v1/traces/${id}`))
				this.selectedTrace = response.data.trace
				this.events = response.data.events || []
			} catch (error) {
				this.detailError = getApiErrorMessage(error, t('educai', 'Activity details unavailable'))
				showError(this.detailError)
			} finally {
				this.detailLoading = false
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

<style scoped>
.activity-view {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.activity-title-row,
.detail-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.activity-title-row h3,
.detail-header h4 {
	margin: 0 0 4px;
}

.activity-title-row p,
.detail-header p,
.privacy-note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.activity-actions,
.detail-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.privacy-note {
	padding: 10px 12px;
	border-inline-start: 3px solid var(--color-primary-element);
	background: var(--color-background-hover);
}

.filters {
	display: grid;
	grid-template-columns: minmax(180px, 1fr) minmax(140px, 180px) minmax(150px, 190px) minmax(130px, 150px) minmax(130px, 150px) auto auto auto;
	gap: 12px;
	align-items: end;
}

.filters label {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-weight: 600;
}

.filters input,
.filters select {
	min-height: 36px;
}

.checkbox-label {
	flex-direction: row !important;
	align-items: center;
	min-height: 36px;
}

.error-banner {
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background: var(--color-error);
	color: var(--color-error-text);
}

.loading,
.empty-state,
.empty-detail,
.loading-small {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	min-height: 120px;
	color: var(--color-text-maxcontrast);
}

.empty-state {
	flex-direction: column;
}

.activity-layout {
	display: grid;
	grid-template-columns: minmax(320px, 0.9fr) minmax(420px, 1.3fr);
	gap: 16px;
	align-items: start;
}

.trace-list,
.trace-detail {
	min-width: 0;
}

.trace-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	min-height: 72px;
	margin-bottom: 8px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.trace-row.selected,
.trace-row:hover {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.trace-main {
	min-width: 0;
}

.trace-meta,
.trace-counts,
.event-header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.trace-preview {
	display: block;
	overflow: hidden;
	margin-top: 6px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.trace-counts {
	justify-content: flex-end;
	min-width: 88px;
}

.status-badge {
	display: inline-flex;
	align-items: center;
	min-height: 22px;
	padding: 0 8px;
	border-radius: 999px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 12px;
	font-weight: 700;
}

.status-badge.success,
.status-badge.ok {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.status-badge.error {
	background: var(--color-error);
	color: var(--color-error-text);
}

.status-badge.partial,
.status-badge.running,
.status-badge.started {
	background: var(--color-warning);
	color: var(--color-main-text);
}

.pagination {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-top: 12px;
}

.trace-detail {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.run-summary {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 8px;
	margin: 16px 0;
}

.run-summary div {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.event-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.event-item {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.event-type {
	color: var(--color-main-text);
	font-weight: 700;
	text-transform: capitalize;
}

.event-tool {
	font-family: var(--font-face-monospace);
}

.event-time {
	margin-inline-start: auto;
}

.event-error {
	color: var(--color-error);
	font-weight: 600;
}

.raw-llm-payload {
	margin-top: 10px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.raw-llm-payload summary {
	cursor: pointer;
	font-weight: 600;
}

.raw-llm-payload pre {
	overflow: auto;
	max-height: 420px;
	margin: 6px 0;
	padding: 10px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 12px;
	line-height: 1.35;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.event-block {
	margin-top: 10px;
}

.event-block pre {
	overflow: auto;
	max-height: 260px;
	margin: 6px 0;
	padding: 10px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.event-duration {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

@media (max-width: 1000px) {
	.activity-layout,
	.filters {
		grid-template-columns: 1fr;
	}

	.activity-title-row,
	.detail-header {
		flex-direction: column;
	}
}

@media (max-width: 700px) {
	.run-summary {
		grid-template-columns: 1fr 1fr;
	}

	.trace-row {
		align-items: flex-start;
		flex-direction: column;
	}
}
</style>
