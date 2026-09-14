<template>
	<div class="embedding-maintenance" aria-live="polite">
		<h4>{{ t('educai', 'Index maintenance') }}</h4>
		<p>{{ t('educai', 'Rebuild all bot knowledge sources (files, folders and URLs). The course catalogue is included only when its integration is enabled. Existing wiki folders are not renamed or moved.') }}</p>
		<p>{{ t('educai', 'Reindexing is queued and processed by Nextcloud background jobs. Queued does not mean completed.') }}</p>
		<div v-if="pendingChanges" class="maintenance-notice">
			<p>{{ t('educai', 'Save the changed embedding or integration settings before reindexing.') }}</p>
			<button type="button" :disabled="saving" @click="$emit('save-settings')">
				{{ t('educai', 'Save settings first') }}
			</button>
		</div>
		<p v-else-if="needsReindex" class="maintenance-notice">
			{{ t('educai', 'One or more enabled indexes need reindexing. Queue them below to use the current configuration.') }}
		</p>
		<div class="maintenance-actions">
			<button type="button"
				class="primary"
				:disabled="queueing || saving || pendingChanges || !canQueue"
				@click="queueAll">
				{{ queueing ? t('educai', 'Queuing…') : t('educai', 'Reindex All Embeddings') }}
			</button>
			<button type="button" :disabled="loading" @click="loadStatus">
				{{ loading ? t('educai', 'Loading…') : t('educai', 'Refresh index status') }}
			</button>
		</div>
		<p v-if="error" role="alert" class="maintenance-error">
			{{ error }}
		</p>
		<p v-if="queueMessage" role="status">
			{{ queueMessage }}
		</p>
		<div v-if="status" class="maintenance-scopes">
			<div class="maintenance-scope">
				<h5>{{ t('educai', 'Bot knowledge sources') }}</h5>
				<p v-if="!status.rag.enabled">
					{{ t('educai', 'RAG is disabled. Existing index data is retained; bot sources are skipped when reindexing.') }}
				</p>
				<dl class="maintenance-counts">
					<div><dt>{{ t('educai', 'Total') }}</dt><dd>{{ status.rag.total }}</dd></div>
					<div><dt>{{ t('educai', 'Queued') }}</dt><dd>{{ status.rag.queued }}</dd></div>
					<div><dt>{{ t('educai', 'Processing') }}</dt><dd>{{ status.rag.processing }}</dd></div>
					<div><dt>{{ t('educai', 'Ready') }}</dt><dd>{{ status.rag.ready }}</dd></div>
					<div><dt>{{ t('educai', 'Errors') }}</dt><dd>{{ status.rag.error }}</dd></div>
				</dl>
				<p>{{ t('educai', 'Last indexed: {date}', { date: formatDate(status.rag.last_indexed) }) }}</p>
				<ul v-if="status.rag.errors.length" class="maintenance-errors">
					<li v-for="source in status.rag.errors" :key="source.source_id">
						{{ t('educai', 'Source {source} (bot {bot}) could not be indexed. Check source access and embedding settings, then retry.', { source: source.source_id, bot: source.bot_id }) }}
					</li>
				</ul>
				<p v-if="status.rag.error > status.rag.errors.length">
					{{ t('educai', 'Only the first 50 failed sources are shown.') }}
				</p>
			</div>
			<div class="maintenance-scope">
				<h5>{{ t('educai', 'Course catalogue') }}</h5>
				<button v-if="status.catalogue.enabled"
					type="button"
					:disabled="queueing || saving || pendingChanges || !!error"
					@click="queueCatalogue">
					{{ t('educai', 'Reindex catalogue only') }}
				</button>
				<p>{{ catalogueState }}</p>
				<p v-if="!status.catalogue.enabled">
					{{ t('educai', 'Catalogue is not included in reindexing while disabled. Stored index data is retained.') }}
				</p>
				<p>{{ t('educai', 'Indexed courses: {count}', { count: status.catalogue.count }) }}</p>
				<p>{{ t('educai', 'Last indexed: {date}', { date: formatDate(status.catalogue.last_indexed) }) }}</p>
				<p v-if="status.catalogue.enabled && status.catalogue.last_error" role="alert" class="maintenance-error">
					{{ t('educai', 'The last catalogue indexing attempt failed. Check the catalogue connection and embedding configuration, then retry. Details are available in the Nextcloud log.') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t, getCanonicalLocale } from '../l10n.js'
import { getApiErrorMessage } from '../utils/apiError.js'

export default {
	name: 'EmbeddingMaintenance',
	props: {
		pendingChanges: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
		active: { type: Boolean, default: true },
		refreshKey: { type: Number, default: 0 },
	},
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
	beforeDestroy() {
		this.destroyed = true
		clearInterval(this.timer)
	},
	methods: {
		t,
		formatDate(timestamp) {
			return timestamp ? new Date(timestamp * 1000).toLocaleString(getCanonicalLocale()) : t('educai', 'Never')
		},
		async loadStatus() {
			if (this.loading || this.destroyed) return
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/admin/embeddings/status'))
				if (!this.destroyed) {
					this.status = response.data
					this.error = ''
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
	margin-block-end: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.maintenance-actions,
.maintenance-counts,
.maintenance-scopes {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-block: 12px;
}

.maintenance-scope {
	flex: 1 1 280px;
}

.maintenance-counts dd {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
}

.maintenance-notice {
	padding: 12px;
	background: var(--color-warning-hover);
	border-radius: var(--border-radius);
}

.maintenance-error,
.maintenance-errors {
	color: var(--color-error);
}
</style>
