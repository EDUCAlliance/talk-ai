<template>
	<AdminSection anchor="educai-admin-api-connections"
		:title="t('educai', 'API connections')"
		:description="t('educai', 'Read-only HTTP APIs described by an OpenAPI 3.0 document. You review every operation before bots may call it. Changes here apply immediately.')">
		<template #actions>
			<button v-if="!detail"
				type="button"
				class="ta-btn"
				:aria-expanded="String(presetsOpen)"
				@click="togglePresets">
				{{ t('educai', 'Add from preset') }}
			</button>
			<button v-if="!detail"
				type="button"
				class="ta-btn ta-btn--primary"
				@click="openDialog(null)">
				{{ t('educai', 'Add API connection') }}
			</button>
		</template>

		<template v-if="selectedId === null">
			<p v-if="actionError" class="ta-note ta-note--error" role="alert">
				{{ actionError }}
			</p>
			<div v-if="presetsOpen" class="ta-api-presets">
				<p v-if="presets === null" class="ta-hint">
					{{ t('educai', 'Loading presets…') }}
				</p>
				<template v-else>
					<p class="ta-hint">
						{{ t('educai', 'A preset creates a disabled connection with a curated document and suggested review values. Nothing is sent to the API; you confirm every operation, the data visibility and the approval yourself.') }}
					</p>
					<div v-for="preset in presets" :key="preset.id" class="ta-list__row">
						<div class="ta-list__main">
							<p class="ta-list__title">
								{{ preset.name }}
							</p>
							<p class="ta-list__meta">
								{{ preset.description }}
							</p>
							<p class="ta-list__meta">
								<code class="ta-code">{{ preset.base_url }}</code>
								· {{ authModeLabel(preset.auth_mode) }}
								· {{ n('educai', '{count} read operation', '{count} read operations', preset.operations.length, { count: preset.operations.length }) }}
								· {{ t('educai', 'Based on upstream version {version} of {date}', { version: preset.upstream.version, date: preset.upstream.retrieved_at }) }}
							</p>
						</div>
						<div class="ta-list__actions">
							<button type="button"
								class="ta-btn ta-btn--primary"
								:disabled="busy !== ''"
								@click="createFromPreset(preset)">
								{{ busy === 'preset' ? t('educai', 'Creating…') : t('educai', 'Create connection') }}
							</button>
						</div>
					</div>
				</template>
			</div>
			<p v-if="loading" class="ta-hint">
				{{ t('educai', 'Loading API connections…') }}
			</p>
			<p v-else-if="loadError" class="ta-note ta-note--error" role="alert">
				{{ loadError }}
				<button type="button" class="ta-btn ta-btn--small" @click="load">
					{{ t('educai', 'Retry') }}
				</button>
			</p>
			<p v-else-if="connections.length === 0" class="ta-empty">
				{{ t('educai', 'No API connections yet.') }}
			</p>
			<div v-else class="ta-list">
				<div v-for="connection in connections"
					:key="connection.id"
					class="ta-list__row api-connection-row"
					:class="{ 'ta-list__row--muted': !connection.enabled }">
					<div class="ta-list__main">
						<p class="ta-list__title">
							{{ connection.name }}
							<span v-for="chip in connectionChips(connection)"
								:key="chip.label"
								class="ta-chip"
								:class="chip.class">{{ chip.label }}</span>
						</p>
						<p v-if="connection.description" class="ta-list__meta">
							{{ connection.description }}
						</p>
						<p class="ta-list__meta">
							<code class="ta-code">{{ connection.base_url || t('educai', 'No base address') }}</code>
							· {{ authModeLabel(connection.auth_mode) }}
							<template v-if="connection.last_check">
								· {{ lastCheckText(connection.last_check) }}
							</template>
						</p>
					</div>
					<div class="ta-list__actions">
						<button type="button" class="ta-btn ta-btn--primary" @click="open(connection.id)">
							{{ connection.draft_revision_id || !connection.active_revision_id ? t('educai', 'Review') : t('educai', 'Open') }}
						</button>
						<button v-if="connection.enabled || connection.active_revision_id"
							type="button"
							class="ta-btn"
							:disabled="busy !== ''"
							@click="setEnabled(connection, !connection.enabled)">
							{{ connection.enabled ? t('educai', 'Disable') : t('educai', 'Enable') }}
						</button>
						<button type="button"
							class="ta-btn ta-btn--danger"
							:disabled="busy !== ''"
							@click="remove(connection)">
							{{ t('educai', 'Delete') }}
						</button>
					</div>
				</div>
			</div>
		</template>

		<div v-else class="ta-api-detail">
			<div class="ta-row ta-api-detail__head">
				<button type="button" class="ta-btn ta-btn--ghost" @click="close">
					← {{ t('educai', 'All API connections') }}
				</button>
			</div>
			<p v-if="detailLoading && !detail" class="ta-hint">
				{{ t('educai', 'Loading…') }}
			</p>
			<p v-else-if="detailError && !detail" class="ta-note ta-note--error" role="alert">
				{{ detailError }}
				<button type="button" class="ta-btn ta-btn--small" @click="fetchDetail">
					{{ t('educai', 'Retry') }}
				</button>
			</p>

			<template v-if="detail">
				<div class="ta-api-detail__title">
					<h3 class="ta-section__title">
						{{ detail.connection.name }}
					</h3>
					<span v-for="chip in connectionChips(detail.connection)"
						:key="chip.label"
						class="ta-chip"
						:class="chip.class">{{ chip.label }}</span>
				</div>
				<p v-if="actionError" class="ta-note ta-note--error" role="alert">
					{{ actionError }}
				</p>

				<h4 class="ta-subheading">
					{{ t('educai', '1 · Document') }}
				</h4>
				<p v-if="detail.active" class="ta-list__meta">
					{{ t('educai', 'Approved: {title}', { title: revisionTitle(detail.active) }) }}
					· {{ t('educai', 'by {user}, {time}', { user: detail.active.approved_by, time: formatWhen(detail.active.approved_at) }) }}
					· <code class="ta-code">{{ shortSha(detail.active) }}</code>
				</p>
				<p v-if="draft" class="ta-list__meta">
					{{ t('educai', 'Draft: {title}', { title: revisionTitle(draft) }) }}
					· {{ t('educai', 'imported by {user}, {time}', { user: draft.created_by, time: formatWhen(draft.created_at) }) }}
					· <code class="ta-code">{{ shortSha(draft) }}</code>
				</p>
				<div class="ta-api-import">
					<button type="button"
						class="ta-btn"
						:disabled="busy !== ''"
						@click="$refs.documentFile.click()">
						{{ busy === 'import-file' ? t('educai', 'Importing…') : t('educai', 'Upload JSON document') }}
					</button>
					<input ref="documentFile"
						class="ta-hidden-file"
						type="file"
						accept=".json,application/json"
						@change="importFile">
					<span class="ta-hint">{{ t('educai', 'or') }}</span>
					<input v-model="importUrl"
						class="ta-input ta-input--mono"
						type="url"
						:aria-label="t('educai', 'Document address')"
						placeholder="https://api.example.org/openapi.json"
						@keydown.enter.prevent="importFromUrl">
					<button type="button"
						class="ta-btn"
						:disabled="busy !== '' || importUrl.trim() === ''"
						@click="importFromUrl">
						{{ busy === 'import-url' ? t('educai', 'Importing…') : t('educai', 'Import') }}
					</button>
				</div>
				<p class="ta-hint">
					{{ t('educai', 'OpenAPI 3.0 as JSON, up to 2 MiB. Importing only creates a draft; bots keep using the approved revision until you approve the new one.') }}
				</p>
				<div v-if="detail.active && !detail.connection.approval_current && !draft" class="ta-note ta-note--warn" role="status">
					{{ t('educai', 'The base address or authentication changed after approval, so the tools of this connection are paused.') }}
					<button type="button"
						class="ta-btn ta-btn--small"
						:disabled="busy !== ''"
						@click="reopen">
						{{ t('educai', 'Review the approved document again') }}
					</button>
				</div>
				<ul v-if="draft && draft.diagnostics.length" class="ta-api-op__reasons ta-api-op__reasons--warn">
					<li v-for="(diagnostic, index) in draft.diagnostics" :key="index">
						{{ diagnosticText(diagnostic) }}
					</li>
				</ul>

				<h4 class="ta-subheading">
					{{ draft ? t('educai', '2 · Address and authentication') : t('educai', 'Address and authentication') }}
				</h4>
				<dl class="ta-api-facts">
					<template v-if="draft">
						<dt>{{ t('educai', 'Servers in the document') }}</dt>
						<dd>
							<ul v-if="draft.servers.length" class="ta-api-servers">
								<li v-for="server in draft.servers" :key="server.url">
									<code class="ta-code">{{ server.resolved || server.url }}</code>
									<span v-if="!server.resolved" class="ta-value--muted"> · {{ t('educai', 'not usable as base address') }}</span>
									<button v-else-if="server.resolved !== detail.connection.base_url"
										type="button"
										class="ta-btn ta-btn--small"
										:disabled="busy !== ''"
										@click="useServer(server.resolved)">
										{{ t('educai', 'Use as base address') }}
									</button>
								</li>
							</ul>
							<span v-else>{{ t('educai', 'none declared') }}</span>
						</dd>
					</template>
					<dt>{{ t('educai', 'Address actually used') }}</dt>
					<dd>
						<code v-if="detail.connection.base_url" class="ta-code">{{ detail.connection.base_url }}</code>
						<span v-else class="ta-value--warn">{{ t('educai', 'not set') }}</span>
					</dd>
					<dt>{{ t('educai', 'Authentication') }}</dt>
					<dd>
						{{ authModeLabel(detail.connection.auth_mode) }}
						<template v-if="detail.connection.auth_mode !== 'none'">
							· {{ detail.connection.credential_set ? t('educai', 'credential stored') : t('educai', 'no credential stored') }}
						</template>
					</dd>
					<dt>{{ t('educai', 'Data visibility') }}</dt>
					<dd :class="{ 'ta-value--warn': !visibilityConfirmed }">
						{{ visibilityConfirmed ? t('educai', 'Confirmed for everyone who reads bot answers') : t('educai', 'not confirmed') }}
					</dd>
				</dl>
				<p v-if="draft && serverMismatch" class="ta-note ta-note--warn" role="status">
					{{ t('educai', 'The address used is not one of the servers in the document. Requests still only go to the address used.') }}
				</p>
				<button type="button" class="ta-btn" @click="openDialog(detail.connection)">
					{{ t('educai', 'Edit connection settings') }}
				</button>

				<template v-if="draft">
					<h4 class="ta-subheading">
						{{ t('educai', '3 · Operations') }}
					</h4>
					<p class="ta-hint">
						{{ n('educai', '{count} operation can be offered.', '{count} operations can be offered.', supportedCount, { count: supportedCount }) }}
						{{ blockedCount ? n('educai', '{count} is not supported.', '{count} are not supported.', blockedCount, { count: blockedCount }) : '' }}
					</p>
					<ul class="ta-api-ops">
						<ApiOperationReview v-for="operation in draftOperations"
							:key="`${draft.id}-${operation.id}`"
							v-model="states[operation.id]"
							:operation="operation"
							:problems="draftProblems(operation)"
							:dirty="dirtyIds.includes(operation.id)"
							:run-test="args => runTest(draft.id, operation.id, args)" />
					</ul>

					<template v-if="diff.length">
						<h4 class="ta-subheading">
							{{ t('educai', '4 · Changes compared to the approved revision') }}
						</h4>
						<ul class="ta-api-diff">
							<li v-for="entry in diff" :key="`${entry.kind}-${entry.id}`">
								<span class="ta-chip" :class="diffChip(entry.kind)">{{ diffKindLabel(entry.kind) }}</span>
								<strong>{{ entry.label || operationKey(entry.id) }}</strong>
								<span v-if="entry.aspects.length"> · {{ entry.aspects.join(', ') }}</span>
								<span v-if="entry.kind === 'removed' && entry.wasOffered" class="ta-value--warn">
									· {{ t('educai', 'offered today; bots that use it will see it as unavailable after approval') }}
								</span>
								<dl v-if="entry.changes.length" class="ta-api-diff__values">
									<template v-for="value in entry.changes" :key="value.aspect">
										<dt>{{ value.label }}</dt>
										<dd>
											<strong>{{ t('educai', 'Approved value') }}</strong>
											<pre class="ta-pre">{{ value.before }}</pre>
											<strong>{{ t('educai', 'Draft value') }}</strong>
											<pre class="ta-pre">{{ value.after }}</pre>
										</dd>
									</template>
								</dl>
								<span v-if="entry.kind === 'added'" class="ta-list__meta">
									· {{ t('educai', 'new operations are not offered until you review them') }}
								</span>
							</li>
						</ul>
					</template>

					<h4 class="ta-subheading">
						{{ diff.length ? t('educai', '5 · Approve') : t('educai', '4 · Approve') }}
					</h4>
					<p class="ta-hint">
						{{ n('educai', 'After approval {count} tool is offered to bots of this connection.', 'After approval {count} tools are offered to bots of this connection.', offeredCount, { count: offeredCount }) }}
						{{ detail.active ? t('educai', 'The approved revision is replaced.') : '' }}
					</p>
					<ul v-if="approvalBlockers.length" class="ta-api-op__reasons ta-api-op__reasons--warn">
						<li v-for="blocker in approvalBlockers" :key="blocker">
							{{ blocker }}
						</li>
					</ul>
					<div class="ta-row">
						<button type="button"
							class="ta-btn"
							:disabled="busy !== '' || dirtyIds.length === 0 || lineErrorCount > 0"
							@click="saveReview">
							{{ busy === 'save' ? t('educai', 'Saving…') : t('educai', 'Save review') }}
						</button>
						<button type="button"
							class="ta-btn ta-btn--primary"
							:disabled="busy !== '' || approvalBlockers.length > 0"
							@click="approve">
							{{ busy === 'approve' ? t('educai', 'Approving…') : (dirtyIds.length ? t('educai', 'Save and approve') : t('educai', 'Approve revision')) }}
						</button>
						<span class="ta-dialog-actions__spacer" />
						<button type="button"
							class="ta-btn ta-btn--danger"
							:disabled="busy !== ''"
							@click="discard">
							{{ t('educai', 'Discard draft') }}
						</button>
					</div>
				</template>

				<template v-if="detail.active">
					<h4 class="ta-subheading">
						{{ t('educai', 'Approved tools') }}
					</h4>
					<p v-if="!detail.connection.enabled" class="ta-note ta-note--warn" role="status">
						{{ t('educai', 'The connection is disabled. Bots cannot call these tools until you enable it.') }}
						<button type="button"
							class="ta-btn ta-btn--small ta-btn--primary"
							:disabled="busy !== ''"
							@click="setEnabled(detail.connection, true)">
							{{ t('educai', 'Enable connection') }}
						</button>
					</p>
					<p v-if="activeOperations.length === 0" class="ta-empty">
						{{ t('educai', 'No operation is approved.') }}
					</p>
					<ul v-else class="ta-api-ops">
						<ApiOperationReview v-for="operation in activeOperations"
							:key="`${detail.active.id}-${operation.id}`"
							:model-value="activeStates[operation.id]"
							:operation="operation"
							:problems="activeProblems(operation)"
							readonly
							:busy="busy !== ''"
							:run-test="args => runTest(detail.active.id, operation.id, args)"
							@revoke="revoke(operation)" />
					</ul>
				</template>
				<p v-else-if="!draft" class="ta-empty">
					{{ t('educai', 'Upload or import the OpenAPI document of this API to start the review.') }}
				</p>
			</template>
		</div>

		<ApiConnectionDialog v-if="dialog"
			:connection="dialog.connection"
			:groups="groups"
			:saving="dialog.saving"
			:error="dialog.error"
			@close="dialog = null"
			@save="saveConnection" />
	</AdminSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '../../l10n.js'
import { formatWhen } from '../../utils/adminOverview.js'
import { CONNECTIONS_URL, DATA_VISIBILITY, PRESETS_URL, REVISIONS_URL, authModeLabel, codeText, diffEntries, errorText, reviewChanged, reviewEdit, reviewState } from '../../utils/apiConnections.js'
import AdminSection from './AdminSection.vue'
import ApiConnectionDialog from './ApiConnectionDialog.vue'
import ApiOperationReview from './ApiOperationReview.vue'

const MAX_DOCUMENT_BYTES = 2_097_152

export default {
	name: 'ApiConnectionsSection',
	components: { AdminSection, ApiConnectionDialog, ApiOperationReview },

	data() {
		return {
			connections: [],
			loading: true,
			loadError: '',
			groups: [],
			groupsLoaded: false,
			dialog: null,
			selectedId: null,
			detail: null,
			detailLoading: false,
			detailError: '',
			states: {},
			busy: '',
			actionError: '',
			importUrl: '',
			presetsOpen: false,
			presets: null,
		}
	},
	computed: {
		draft() {
			return this.detail?.draft ?? null
		},
		draftOperations() {
			const operations = [...(this.draft?.operations ?? [])]
			return operations.sort((a, b) => Number(b.supported) - Number(a.supported))
		},
		supportedCount() {
			return this.draftOperations.filter(operation => operation.supported).length
		},
		blockedCount() {
			return this.draftOperations.length - this.supportedCount
		},
		dirtyIds() {
			return this.draftOperations
				.filter(operation => this.states[operation.id] && reviewChanged(this.states[operation.id], operation))
				.map(operation => operation.id)
		},
		lineErrorCount() {
			return this.draftOperations.reduce((count, operation) => count + (this.states[operation.id] ? reviewEdit(this.states[operation.id]).errors.length : 0), 0)
		},
		offeredCount() {
			return this.draftOperations.filter(operation => operation.supported && this.states[operation.id]?.enabled).length
		},
		visibilityConfirmed() {
			return this.detail?.connection.data_visibility === DATA_VISIBILITY
		},
		serverMismatch() {
			const base = this.detail?.connection.base_url ?? ''
			const servers = (this.draft?.servers ?? []).map(server => server.resolved).filter(Boolean)
			return base !== '' && servers.length > 0 && !servers.some(server => base === server || base.startsWith(`${server.replace(/\/+$/, '')}/`))
		},
		approvalBlockers() {
			if (!this.draft) {
				return []
			}
			const blockers = []
			if (!this.detail.connection.base_url) {
				blockers.push(codeText('base_url_missing'))
			}
			if (!this.visibilityConfirmed) {
				blockers.push(codeText('data_visibility_missing'))
			}
			if (this.offeredCount === 0) {
				blockers.push(codeText('no_operation_enabled'))
			}
			if (this.lineErrorCount > 0) {
				blockers.push(t('educai', 'Fix the marked input lines first.'))
			}
			const pending = Object.keys(this.draft.approval_problems ?? {}).length
			if (pending > 0 && this.dirtyIds.length === 0) {
				blockers.push(n('educai', '{count} offered operation still needs attention.', '{count} offered operations still need attention.', pending, { count: pending }))
			}
			return blockers
		},
		diff() {
			if (!this.draft) {
				return []
			}
			return diffEntries(this.draft.diff, this.detail.active, this.draftOperations.map(operation => ({
				...operation,
				config: this.states[operation.id] ? reviewEdit(this.states[operation.id]).edit : operation.config,
			})))
		},
		activeOperations() {
			return (this.detail?.active?.operations ?? []).filter(operation => operation.config?.enabled === true)
		},
		activeStates() {
			return Object.fromEntries(this.activeOperations.map(operation => [operation.id, reviewState(operation)]))
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		n,
		codeText,
		authModeLabel,
		formatWhen,
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const response = await axios.get(generateUrl(CONNECTIONS_URL))
				this.connections = response.data.connections ?? []
			} catch (error) {
				this.loadError = errorText(error, t('educai', 'Could not load the API connections.'))
			} finally {
				this.loading = false
			}
		},
		connectionChips(connection) {
			const chips = [connection.enabled
				? { label: t('educai', 'Enabled'), class: 'ta-chip--ok' }
				: { label: t('educai', 'Disabled'), class: '' }]
			if (connection.active_revision_id) {
				chips.push(connection.approval_current
					? { label: n('educai', '{count} tool approved', '{count} tools approved', connection.approved_operations, { count: connection.approved_operations }), class: 'ta-chip--plain' }
					: { label: t('educai', 'Paused: settings changed'), class: 'ta-chip--warn' })
			} else {
				chips.push({ label: t('educai', 'Not approved'), class: 'ta-chip--plain' })
			}
			if (connection.draft_revision_id) {
				chips.push({ label: t('educai', 'Draft to review'), class: 'ta-chip--info' })
			}
			if (connection.data_visibility !== DATA_VISIBILITY) {
				chips.push({ label: t('educai', 'Data visibility not confirmed'), class: 'ta-chip--warn' })
			}
			if (connection.catalogue_source) {
				chips.push({ label: t('educai', 'Course catalogue source'), class: 'ta-chip--info' })
			}
			return chips
		},
		lastCheckText(check) {
			const result = check.status === 'ok'
				? t('educai', 'worked')
				: (codeText(check.status) ?? check.status)
			return t('educai', 'Last test call {time}: {result}', { time: formatWhen(check.at), result })
		},
		revisionTitle(revision) {
			const info = revision.info ?? {}
			const title = info.title || t('educai', 'Untitled API')
			return info.version ? `${title} ${info.version}` : title
		},
		shortSha(revision) {
			return String(revision.sha256 ?? '').slice(0, 12)
		},
		diagnosticText(diagnostic) {
			const text = codeText(diagnostic.code) ?? diagnostic.code
			return diagnostic.where ? `${text} (${diagnostic.where})` : text
		},
		operationKey(id) {
			return this.draft?.operations.find(operation => operation.id === id)?.match_key ?? `#${id}`
		},
		diffKindLabel(kind) {
			return {
				added: t('educai', 'New'),
				removed: t('educai', 'Removed'),
				changed: t('educai', 'Changed'),
			}[kind]
		},
		diffChip(kind) {
			return { added: 'ta-chip--info', removed: 'ta-chip--error', changed: 'ta-chip--warn' }[kind]
		},
		draftProblems(operation) {
			if (!this.states[operation.id]?.enabled) {
				return []
			}
			return this.draft.approval_problems?.[operation.id] ?? []
		},
		activeProblems(operation) {
			return operation.config?.revoked_at ? ['operation_revoked'] : operation.problems.filter(problem => problem !== 'read_not_confirmed')
		},

		applyDetail(data) {
			this.detail = data
			this.states = Object.fromEntries((data.draft?.operations ?? []).map(operation => [operation.id, reviewState(operation)]))
			this.updateListEntry(data.connection)
		},
		updateListEntry(connection) {
			const index = this.connections.findIndex(entry => entry.id === connection.id)
			if (index === -1) {
				this.connections.push(connection)
			} else {
				this.connections.splice(index, 1, connection)
			}
		},
		async open(id) {
			this.selectedId = id
			this.detail = null
			this.actionError = ''
			this.importUrl = ''
			await this.fetchDetail()
		},
		async fetchDetail() {
			this.detailLoading = true
			this.detailError = ''
			try {
				const response = await axios.get(generateUrl(`${CONNECTIONS_URL}/${this.selectedId}`))
				this.applyDetail(response.data)
			} catch (error) {
				this.detailError = errorText(error, t('educai', 'Could not load the API connection.'))
			} finally {
				this.detailLoading = false
			}
		},
		close() {
			if (this.dirtyIds.length && !confirm(t('educai', 'Discard the unsaved review changes?'))) {
				return
			}
			this.selectedId = null
			this.detail = null
			this.states = {}
			this.actionError = ''
		},

		async openDialog(connection) {
			this.dialog = { connection, saving: false, error: '' }
			if (!this.groupsLoaded) {
				try {
					const response = await axios.get(generateUrl('/apps/educai/api/v1/groups'))
					this.groups = response.data.groups ?? []
					this.groupsLoaded = true
				} catch {
					this.groups = []
				}
			}
		},
		async saveConnection(payload) {
			const dialog = this.dialog
			dialog.saving = true
			dialog.error = ''
			try {
				const response = dialog.connection
					? await axios.put(generateUrl(`${CONNECTIONS_URL}/${dialog.connection.id}`), payload)
					: await axios.post(generateUrl(CONNECTIONS_URL), payload)
				this.dialog = null
				if (this.selectedId === response.data.connection.id) {
					this.mergeConnection(response.data)
				} else {
					this.updateListEntry(response.data.connection)
					this.selectedId = response.data.connection.id
					this.applyDetail(response.data)
				}
				showSuccess(t('educai', 'API connection saved'))
			} catch (error) {
				dialog.error = errorText(error, t('educai', 'Could not save the API connection.'))
			} finally {
				dialog.saving = false
			}
		},
		mergeConnection(data) {
			if (!this.dirtyIds.length) {
				this.applyDetail(data)
				return
			}
			const states = this.states
			this.applyDetail(data)
			this.states = { ...this.states, ...Object.fromEntries(Object.entries(states).filter(([id]) => id in this.states)) }
		},
		async setEnabled(connection, enabled) {
			await this.run('enable', async () => {
				const response = await axios.put(generateUrl(`${CONNECTIONS_URL}/${connection.id}/enabled`), { enabled })
				if (this.selectedId === connection.id) {
					this.mergeConnection(response.data)
				} else {
					this.updateListEntry(response.data.connection)
				}
			}, enabled ? t('educai', 'Could not enable the API connection.') : t('educai', 'Could not disable the API connection.'))
		},
		async useServer(url) {
			if (this.detail.active && !confirm(t('educai', 'Changing the base address pauses the approved tools until you approve a revision again. Continue?'))) {
				return
			}
			await this.run('server', async () => {
				const response = await axios.put(generateUrl(`${CONNECTIONS_URL}/${this.selectedId}`), { base_url: url })
				this.mergeConnection(response.data)
			}, t('educai', 'Could not save the API connection.'))
		},
		async togglePresets() {
			this.presetsOpen = !this.presetsOpen
			if (!this.presetsOpen || this.presets !== null) {
				return
			}
			try {
				const response = await axios.get(generateUrl(PRESETS_URL))
				this.presets = response.data.presets ?? []
			} catch (error) {
				this.presetsOpen = false
				this.actionError = errorText(error, t('educai', 'Could not load the presets.'))
			}
		},
		async createFromPreset(preset) {
			await this.run('preset', async () => {
				const response = await axios.post(generateUrl(`${PRESETS_URL}/${encodeURIComponent(preset.id)}/connections`))
				this.presetsOpen = false
				this.importUrl = ''
				showSuccess(t('educai', 'Connection "{name}" created. Review its operations before enabling it.', { name: response.data.connection.name }))
				this.selectedId = response.data.connection.id
				this.applyDetail(response.data)
			}, t('educai', 'Could not create the connection from the preset.'))
		},
		async remove(connection) {
			if (!confirm(t('educai', 'Delete API connection "{name}"? Bots that use its tools lose access immediately.', { name: connection.name }))) {
				return
			}
			await this.run('delete', async () => {
				await axios.delete(generateUrl(`${CONNECTIONS_URL}/${connection.id}`))
				this.connections = this.connections.filter(entry => entry.id !== connection.id)
			}, t('educai', 'Could not delete the API connection.'))
		},

		async importFile(event) {
			const file = event.target.files?.[0]
			event.target.value = ''
			if (!file) {
				return
			}
			if (file.size > MAX_DOCUMENT_BYTES) {
				this.actionError = codeText('document_too_large')
				return
			}
			const document = await file.text()
			await this.importDocument('import-file', { document })
		},
		async importFromUrl() {
			const url = this.importUrl.trim()
			if (url === '' || this.busy !== '') {
				return
			}
			if (await this.importDocument('import-url', { url })) {
				this.importUrl = ''
			}
		},
		async importDocument(kind, body) {
			if (this.dirtyIds.length && !confirm(t('educai', 'Importing replaces the current draft and its unsaved review changes. Continue?'))) {
				return false
			}
			return this.run(kind, async () => {
				const response = await axios.post(generateUrl(`${CONNECTIONS_URL}/${this.selectedId}/import`), body)
				this.applyDetail(response.data)
			}, t('educai', 'Could not import the document.'))
		},
		async reopen() {
			await this.run('reopen', async () => {
				const response = await axios.post(generateUrl(`${CONNECTIONS_URL}/${this.selectedId}/reopen`))
				this.applyDetail(response.data)
			}, t('educai', 'Could not open the approved document again.'))
		},

		async saveReview() {
			const ids = this.dirtyIds
			if (ids.length === 0) {
				return true
			}
			const operations = Object.fromEntries(ids.map(id => [id, reviewEdit(this.states[id]).edit]))
			return this.run('save', async () => {
				const response = await axios.put(generateUrl(`${REVISIONS_URL}/${this.draft.id}/operations`), { operations })
				this.applyDetail(response.data)
			}, t('educai', 'Could not save the review.'))
		},
		async approve() {
			const revisionId = this.draft.id
			if (!(await this.saveReview())) {
				return
			}
			if (this.approvalBlockers.length) {
				return
			}
			await this.run('approve', async () => {
				const response = await axios.post(generateUrl(`${REVISIONS_URL}/${revisionId}/approve`))
				this.applyDetail(response.data)
				showSuccess(response.data.connection.enabled
					? t('educai', 'Revision approved. Bots can use the new tools now.')
					: t('educai', 'Revision approved. Enable the connection to offer its tools.'))
			}, t('educai', 'Could not approve the revision.'))
		},
		async discard() {
			if (!confirm(t('educai', 'Discard this draft and its review? The approved revision stays in effect.'))) {
				return
			}
			const revisionId = this.draft.id
			await this.run('discard', async () => {
				await axios.delete(generateUrl(`${REVISIONS_URL}/${revisionId}`))
				this.states = {}
				await this.fetchDetail()
			}, t('educai', 'Could not discard the draft.'))
		},
		async revoke(operation) {
			const label = operation.config?.label || operation.match_key
			if (!confirm(t('educai', 'Revoke "{label}"? Bots lose this tool immediately. To offer it again, approve a new revision.', { label }))) {
				return
			}
			await this.run('revoke', async () => {
				const response = await axios.post(generateUrl(`${REVISIONS_URL}/${this.detail.active.id}/operations/${operation.id}/revoke`))
				this.mergeConnection(response.data)
				if (this.states[operation.id]) {
					// Keep unsaved mappings/text, but never the pre-revocation confirmation.
					this.states[operation.id] = { ...this.states[operation.id], readConfirmed: false }
				}
			}, t('educai', 'Could not revoke the operation.'))
		},
		async runTest(revisionId, operationId, args) {
			const response = await axios.post(generateUrl(`${REVISIONS_URL}/${revisionId}/operations/${operationId}/test`), { arguments: args })
			this.refreshConnection()
			return response.data
		},
		async refreshConnection() {
			try {
				const response = await axios.get(generateUrl(`${CONNECTIONS_URL}/${this.selectedId}`))
				this.detail = { ...this.detail, connection: response.data.connection }
				this.updateListEntry(response.data.connection)
			} catch {
				// The test result is already shown; the last-check line refreshes on the next load.
			}
		},

		async run(kind, action, fallback) {
			this.busy = kind
			this.actionError = ''
			try {
				await action()
				return true
			} catch (error) {
				this.actionError = errorText(error, fallback)
				return false
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>
