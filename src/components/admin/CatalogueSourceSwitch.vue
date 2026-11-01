<template>
	<div class="ta-catalogue-source">
		<h4 id="educai-admin-catalogue-source" class="ta-subheading">
			{{ t('educai', 'Record source') }}
		</h4>
		<p v-if="loading" class="ta-hint">
			{{ t('educai', 'Loading the catalogue source…') }}
		</p>
		<p v-else-if="loadError" class="ta-note ta-note--error" role="alert">
			{{ loadError }}
			<button type="button" class="ta-btn ta-btn--small" @click="load">
				{{ t('educai', 'Retry') }}
			</button>
		</p>
		<template v-else>
			<fieldset class="ta-choice">
				<legend>{{ t('educai', 'Where the catalogue refresh reads courses from') }}</legend>
				<label>
					<input type="radio"
						name="catalogue-source"
						:checked="choice === null"
						@change="choice = null">
					<span>
						<strong>{{ t('educai', 'Catalogue API endpoint') }}</strong>
						<small>{{ t('educai', 'Reads directly from the endpoint above. This is the default.') }}</small>
					</span>
				</label>
				<label v-for="candidate in candidates" :key="candidate.id">
					<input type="radio"
						name="catalogue-source"
						:checked="choice === candidate.id"
						:disabled="!usable(candidate) && current !== candidate.id"
						@change="choice = candidate.id">
					<span>
						<strong>{{ t('educai', 'API connection "{name}"', { name: candidate.name }) }}</strong>
						<small v-for="line in statusLines(candidate)" :key="line">{{ line }}</small>
					</span>
				</label>
			</fieldset>
			<p v-if="candidates.length === 0" class="ta-hint">
				{{ t('educai', 'To read through a reviewed API connection instead, add the EDUC course catalogue preset under Capabilities → API connections, approve it and enable it.') }}
			</p>
			<p v-if="!selectedFound" class="ta-note ta-note--warn" role="status">
				{{ t('educai', 'The selected API connection no longer exists. The catalogue refresh fails and keeps the existing index until you choose another source.') }}
			</p>
			<p v-else-if="currentCandidate && !usable(currentCandidate)" class="ta-note ta-note--warn" role="status">
				{{ t('educai', 'The selected API connection cannot serve the course list right now. Catalogue searches and refreshes are paused and the existing index is kept until the connection works again or you switch back.') }}
			</p>
			<p v-if="choice !== null" class="ta-note ta-note--warn" role="note">
				{{ t('educai', 'Course data reaches every bot that uses the catalogue tools. Only an API connection released for personal, group, team and global bots can serve the catalogue.') }}
			</p>
			<p v-if="error" class="ta-note ta-note--error" role="alert">
				{{ error }}
			</p>
			<div class="ta-row">
				<button type="button"
					class="ta-btn ta-btn--primary"
					:disabled="saving || choice === current"
					@click="save">
					{{ saving ? t('educai', 'Switching…') : t('educai', 'Use this source') }}
				</button>
				<span class="ta-hint">{{ t('educai', 'Applies immediately and separately from the settings above. The existing index is kept; the next refresh reads from the new source.') }}</span>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { t } from '../../l10n.js'
import { CATALOGUE_SOURCE_URL, codeText, errorText } from '../../utils/apiConnections.js'

const LIST_OPERATION = 'GET /course/list'
const VERSION_OPERATION = 'GET /version'

export default {
	name: 'CatalogueSourceSwitch',
	data() {
		return {
			loading: true,
			loadError: '',
			current: null,
			choice: null,
			candidates: [],
			selectedFound: true,
			saving: false,
			error: '',
		}
	},
	computed: {
		currentCandidate() {
			return this.candidates.find(candidate => candidate.id === this.current) ?? null
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const response = await axios.get(generateUrl(CATALOGUE_SOURCE_URL))
				this.apply(response.data)
			} catch (error) {
				this.loadError = errorText(error, t('educai', 'Could not load the catalogue source.'))
			} finally {
				this.loading = false
			}
		},
		apply(data) {
			this.current = data.connection_id ?? null
			this.choice = this.current
			this.candidates = data.candidates ?? []
			this.selectedFound = data.selected_found !== false
		},
		usable(candidate) {
			return candidate.operations?.[LIST_OPERATION] === null
		},
		statusLines(candidate) {
			const operations = candidate.operations ?? {}
			const describe = (key, label) => {
				if (!(key in operations)) {
					return null
				}
				const reason = operations[key]
				return reason === null
					? t('educai', '{operation}: ready', { operation: label })
					: t('educai', '{operation}: {reason}', { operation: label, reason: codeText(reason) ?? reason })
			}
			return [
				describe(LIST_OPERATION, t('educai', 'Course list')),
				describe(VERSION_OPERATION, t('educai', 'Version check (optional)')),
			].filter(Boolean)
		},
		async save() {
			this.saving = true
			this.error = ''
			try {
				const response = await axios.put(generateUrl(CATALOGUE_SOURCE_URL), { connectionId: this.choice })
				this.apply(response.data)
				showSuccess(this.current === null
					? t('educai', 'The catalogue reads from its API endpoint again.')
					: t('educai', 'The catalogue reads through the API connection now.'))
			} catch (error) {
				this.error = errorText(error, t('educai', 'Could not switch the catalogue source.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
