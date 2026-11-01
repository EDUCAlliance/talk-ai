<template>
	<div class="embedding-maintenance" tabindex="-1" aria-live="polite">
		<div class="embedding-maintenance__head">
			<div>
				<h4>{{ t('educai', 'Index maintenance') }}</h4>
				<p class="ta-hint">
					{{ t('educai', 'Reindexing is queued and processed by Nextcloud background jobs. Queued does not mean completed.') }}
				</p>
			</div>
			<div class="embedding-maintenance__actions">
				<button type="button"
					class="ta-btn"
					:disabled="loading"
					@click="loadStatus">
					{{ loading ? t('educai', 'Loading…') : t('educai', 'Refresh index status') }}
				</button>
				<button type="button"
					class="ta-btn ta-btn--primary"
					:disabled="queueing || saving || pendingChanges || !canQueue"
					@click="queueAll">
					{{ queueing ? t('educai', 'Queuing…') : t('educai', 'Reindex All Embeddings') }}
				</button>
			</div>
		</div>
		<div v-if="pendingChanges" class="ta-note ta-note--warn">
			{{ t('educai', 'Save the changed embedding or integration settings before reindexing.') }}
			<button type="button"
				class="ta-btn ta-btn--small"
				:disabled="saving"
				@click="$emit('save-settings')">
				{{ t('educai', 'Save settings first') }}
			</button>
		</div>
		<p v-else-if="needsReindex" class="ta-note ta-note--warn">
			{{ t('educai', 'One or more enabled indexes need reindexing. Queue them below to use the current configuration.') }}
		</p>
		<p v-if="error" role="alert" class="ta-note ta-note--error">
			{{ error }}
		</p>
		<p v-if="queueMessage" role="status" class="ta-hint">
			{{ queueMessage }}
		</p>
		<template v-if="status">
			<dl class="ta-stats embedding-maintenance__stats">
				<div>
					<dt>{{ t('educai', 'Ready') }}</dt>
					<dd>{{ status.rag.ready }}</dd>
				</div>
				<div>
					<dt>{{ t('educai', 'Queued') }}</dt>
					<dd>{{ status.rag.queued }}</dd>
				</div>
				<div>
					<dt>{{ t('educai', 'Processing') }}</dt>
					<dd>{{ status.rag.processing }}</dd>
				</div>
				<div :class="{ 'ta-stat--error': status.rag.error > 0 }">
					<dt>{{ t('educai', 'Errors') }}</dt>
					<dd>{{ status.rag.error }}</dd>
				</div>
				<div>
					<dt>{{ t('educai', 'Total') }}</dt>
					<dd>{{ status.rag.total }}</dd>
				</div>
			</dl>
			<p class="ta-hint">
				{{ t('educai', 'Last indexed: {date}', { date: formatDate(status.rag.last_indexed) }) }}
			</p>
			<p v-if="!status.rag.enabled" class="ta-hint">
				{{ t('educai', 'RAG is disabled. Existing index data is retained; bot sources are skipped when reindexing.') }}
			</p>
			<details v-if="status.rag.errors.length" class="ta-details embedding-maintenance__errors">
				<summary>{{ n('educai', 'Show {count} failed source', 'Show {count} failed sources', status.rag.errors.length, { count: status.rag.errors.length }) }}</summary>
				<ul class="embedding-maintenance__error-list">
					<li v-for="source in status.rag.errors"
						:key="source.source_id"
						class="embedding-maintenance__error">
						<p class="embedding-maintenance__error-title">
							<strong>{{ sourceName(source) }}</strong>
							<span class="embedding-maintenance__error-type">{{ sourceType(source) }}</span>
						</p>
						<p class="embedding-maintenance__error-meta">
							{{ botLabel(source) }}
							<template v-if="source.owner_uid">
								· {{ t('educai', 'added by {user}', { user: source.owner_uid }) }}
							</template>
							<template v-if="source.path && source.path !== source.display_name">
								· <span class="embedding-maintenance__error-path">{{ source.path }}</span>
							</template>
						</p>
						<p class="embedding-maintenance__error-message">
							{{ source.error_message || t('educai', 'The knowledge source could not be indexed. Check source access and embedding settings, then retry.') }}
						</p>
						<details v-if="source.detail"
							class="embedding-maintenance__error-detail"
							:open="!source.error_message || source.error_code === 'knowledge_source_processing_failed'">
							<summary>{{ t('educai', 'Technical details') }}</summary>
							<code>{{ source.detail }}</code>
						</details>
					</li>
				</ul>
				<p v-if="status.rag.error > status.rag.errors.length" class="ta-hint">
					{{ t('educai', 'Only the first 50 failed sources are shown.') }}
				</p>
			</details>
			<div v-if="status.catalogue.enabled || status.catalogue.count" class="embedding-maintenance__catalogue">
				<p>
					<strong>{{ t('educai', 'Course catalogue') }}</strong>
					· {{ catalogueState }}
					· {{ t('educai', 'Indexed courses: {count}', { count: status.catalogue.count }) }}
					· {{ t('educai', 'Last indexed: {date}', { date: formatDate(status.catalogue.last_indexed) }) }}
				</p>
				<button v-if="status.catalogue.enabled"
					type="button"
					class="ta-btn ta-btn--small"
					:disabled="queueing || saving || pendingChanges || !!error"
					@click="queueCatalogue">
					{{ t('educai', 'Reindex catalogue only') }}
				</button>
			</div>
			<p v-if="status.catalogue.enabled && status.catalogue.last_error" role="alert" class="ta-note ta-note--error">
				{{ t('educai', 'The last catalogue indexing attempt failed. Check the catalogue connection and embedding configuration, then retry. Details are available in the Nextcloud log.') }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t, n, getCanonicalLocale } from '../l10n.js'
import { getApiErrorMessage } from '../utils/apiError.js'

export default {
	name: 'EmbeddingMaintenance',
	props: {
		pendingChanges: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
		active: { type: Boolean, default: true },
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['save-settings', 'status'],

	data() {
		return { status: null, loading: false, queueing: false, error: '', queueMessage: '', timer: null, destroyed: false }
	},
	computed: {
		canQueue() {
			return !!this.status && !this.error && (this.status.rag.enabled || this.status.catalogue.enabled)
		},
		needsReindex() {
			return this.status && ((this.status.rag.enabled && this.status.rag.needs_reindex)
				|| (this.status.catalogue.enabled && this.status.catalogue.needs_reindex))
		},
		catalogueState() {
			const labels = {
				disabled: t('educai', 'Disabled'),
				source_unavailable: t('educai', 'Source unavailable'),
				idle: t('educai', 'Not yet indexed'),
				queued: t('educai', 'Queued'),
				running: t('educai', 'Processing'),
				completed: t('educai', 'Ready'),
				failed: t('educai', 'Failed'),
			}
			return labels[this.status?.catalogue?.state] || t('educai', 'Unknown')
		},
	},
	watch: {
		refreshKey() { this.loadStatus() },
		active(value) { if (value) this.loadStatus() },
	},
	mounted() {
		this.loadStatus()
		this.timer = setInterval(() => { if (this.active) this.loadStatus() }, 5000)
	},
	beforeUnmount() {
		this.destroyed = true
		clearInterval(this.timer)
	},
	methods: {
		t,
		n,
		formatDate(timestamp) {
			return timestamp ? new Date(timestamp * 1000).toLocaleString(getCanonicalLocale()) : t('educai', 'Never')
		},
		sourceName(source) {
			return source.display_name || source.path || t('educai', 'Source {id}', { id: source.source_id })
		},
		sourceType(source) {
			const labels = {
				url: t('educai', 'Web page'),
				folder: t('educai', 'Folder'),
				file: t('educai', 'File'),
			}
			return labels[source.source_type] || ''
		},
		botLabel(source) {
			if (!source.bot_name) {
				return t('educai', 'Deleted bot (ID {id})', { id: source.bot_id })
			}
			return source.bot_mention
				? t('educai', 'Bot "{name}" ({mention})', { name: source.bot_name, mention: source.bot_mention })
				: t('educai', 'Bot "{name}"', { name: source.bot_name })
		},
		async loadStatus() {
			if (this.loading || this.destroyed) return
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/admin/embeddings/status'))
				if (!this.destroyed) {
					this.status = response.data
					this.error = ''
					this.$emit('status', response.data)
				}
			} catch (error) {
				if (!this.destroyed) this.error = getApiErrorMessage(error, t('educai', 'Failed to load embedding status'))
			} finally {
				this.loading = false
			}
		},
		async queueAll() {
			if (this.queueing || this.pendingChanges || this.saving || !this.canQueue) return
			this.queueing = true
			this.queueMessage = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/educai/api/v1/admin/embeddings/reindex-all'))
				this.queueMessage = t('educai', 'Queued: {sources} bot sources and {catalogue} catalogue jobs. Already queued: {existing}. Skipped (RAG disabled): {skipped}. Queue failures: {failed}. Processing happens in background jobs.', {
					sources: data.queued_rag_sources,
					catalogue: data.queued_catalogue_jobs,
					existing: data.already_queued_rag_sources + data.already_queued_catalogue_jobs,
					skipped: data.skipped_rag_sources,
					failed: data.failed_rag_sources + data.failed_catalogue_jobs,
				})
				await this.loadStatus()
			} catch (error) {
				this.error = getApiErrorMessage(error, t('educai', 'Failed to queue reindex jobs'))
			} finally {
				this.queueing = false
			}
		},
		async queueCatalogue() {
			if (this.queueing || this.pendingChanges || this.saving || !this.status?.catalogue.enabled) return
			this.queueing = true
			this.queueMessage = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/educai/api/v1/admin/catalogue/reindex'))
				this.queueMessage = data.queued
					? t('educai', 'Catalogue reindex queued. Bot sources were not queued; processing happens in background jobs.')
					: t('educai', 'Catalogue reindex is already queued or processing.')
				await this.loadStatus()
			} catch (error) {
				this.error = getApiErrorMessage(error, t('educai', 'Failed to queue catalogue reindex'))
			} finally {
				this.queueing = false
			}
		},
	},
}
</script>

<style scoped>
.embedding-maintenance {
	margin-block: 16px 0;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container, 12px);
	background: color-mix(in srgb, var(--color-primary-element) 2%, var(--color-main-background));
	scroll-margin-top: 70px;
}

.embedding-maintenance__head {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
}

.embedding-maintenance__head h4 {
	margin: 0;
	font-size: 15px;
}

.embedding-maintenance__head .ta-hint {
	margin-top: 2px;
}

.embedding-maintenance__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.embedding-maintenance__stats {
	margin-block-start: 14px;
}

.embedding-maintenance__error-list {
	display: grid;
	gap: 8px;
	margin: 10px 0 0;
	padding: 0;
	list-style: none;
}

.embedding-maintenance__error {
	padding: 8px 12px;
	border-inline-start: 3px solid var(--color-error, #c62828);
	border-radius: var(--border-radius, 6px);
	background: color-mix(in srgb, var(--color-error, #c62828) 6%, var(--color-main-background));
	font-size: 14px;
}

.embedding-maintenance__error p {
	margin: 0;
}

.embedding-maintenance__error-title {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 8px;
	overflow-wrap: anywhere;
}

.embedding-maintenance__error-type,
.embedding-maintenance__error-meta {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.embedding-maintenance__error-path {
	overflow-wrap: anywhere;
}

.embedding-maintenance__error .embedding-maintenance__error-message {
	margin-block-start: 4px;
	color: var(--color-error-text, var(--color-error));
}

.embedding-maintenance__error-detail {
	margin-block-start: 4px;
	font-size: 13px;
}

.embedding-maintenance__error-detail summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.embedding-maintenance__error-detail code {
	display: block;
	margin-block-start: 4px;
	padding: 6px 8px;
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-dark);
	font-size: 12px;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.embedding-maintenance__catalogue {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px 12px;
	margin-block-start: 12px;
	padding-block-start: 12px;
	border-top: 1px solid var(--color-border);
	font-size: 14px;
}

.embedding-maintenance__catalogue p {
	margin: 0;
}
</style>
