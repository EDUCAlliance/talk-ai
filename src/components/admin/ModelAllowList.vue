<template>
	<div id="allowed-models" class="ta-allow">
		<div class="ta-allow__head">
			<strong>
				{{ t('educai', 'Released models') }}
				<span class="ta-allow__count">· {{ n('educai', '{count} selected', '{count} selected', modelValue.length, { count: modelValue.length }) }}</span>
			</strong>
			<input v-model="filter"
				class="ta-input"
				type="search"
				:placeholder="t('educai', 'Filter models …')"
				:aria-label="t('educai', 'Filter models')">
		</div>
		<template v-for="group in groups" :key="group.endpoint">
			<div class="ta-allow__group">
				<span>{{ groupLabel(group) }}</span>
				<span class="ta-allow__group-actions">
					<button v-if="group.total > 0"
						type="button"
						class="ta-btn ta-btn--ghost ta-btn--small"
						:aria-label="t('educai', 'Release all listed models of {provider}', { provider: providerName(group.endpoint) })"
						@click="setGroup(group, true)">
						{{ t('educai', 'All') }}
					</button>
					<button type="button"
						class="ta-btn ta-btn--ghost ta-btn--small"
						:aria-label="t('educai', 'Release no listed model of {provider}', { provider: providerName(group.endpoint) })"
						@click="setGroup(group, false)">
						{{ t('educai', 'None') }}
					</button>
				</span>
			</div>
			<div v-for="item in group.items" :key="item.id" class="ta-allow__item">
				<label>
					<input type="checkbox"
						:checked="item.selected"
						:data-model="item.id"
						@change="toggle(item.id, $event.target.checked)">
					<span class="ta-allow__name">{{ item.model }}</span>
				</label>
				<span v-if="item.tags.length" class="ta-allow__tags">
					<span v-for="tag in item.tags"
						:key="tag"
						class="ta-chip"
						:class="tagClass(tag)"
						:title="tagTitle(tag)">{{ tagLabel(tag) }}</span>
				</span>
			</div>
			<div v-if="group.hiddenCount > 0" class="ta-allow__more">
				<span>{{ n('educai', '… {count} more model', '… {count} more models', group.hiddenCount, { count: group.hiddenCount }) }}</span>
				<button v-if="!filter"
					type="button"
					class="ta-btn ta-btn--ghost ta-btn--small"
					@click="expanded[group.endpoint] = true">
					{{ t('educai', 'Show all') }}
				</button>
			</div>
		</template>
		<p v-if="groups.length === 0" class="ta-allow__empty">
			{{ filter ? t('educai', 'No model matches the filter.') : t('educai', 'No models known yet. Refresh the model lists or add a model manually.') }}
		</p>
		<div class="ta-allow__add">
			<details class="ta-allow__manual-toggle">
				<summary>{{ t('educai', '+ Add an unlisted model') }}</summary>
				<div class="ta-allow__manual">
					<div class="ta-field">
						<label for="manual-allowed-provider" class="ta-label">{{ t('educai', 'Provider') }}</label>
						<select id="manual-allowed-provider" v-model="manualEndpoint" class="ta-input">
							<option value="primary">
								{{ t('educai', 'Primary') }}
							</option>
							<option value="secondary">
								{{ t('educai', 'Secondary') }}
							</option>
						</select>
					</div>
					<div class="ta-field ta-allow__manual-id">
						<label for="manual-allowed-model" class="ta-label">{{ t('educai', 'Model ID') }}</label>
						<input id="manual-allowed-model"
							v-model="manualModel"
							class="ta-input ta-input--mono"
							type="text"
							maxlength="502"
							placeholder="model-name"
							@keydown.enter.prevent="addManual">
					</div>
					<button type="button" class="ta-btn" @click="addManual">
						{{ t('educai', 'Add') }}
					</button>
				</div>
				<p v-if="manualError" class="ta-hint" role="alert">
					{{ manualError }}
				</p>
			</details>
		</div>
	</div>
</template>

<script>
import { t, n } from '../../l10n.js'
import { buildAllowListGroups, setReleased } from '../../utils/modelAllowList.js'
import { splitModelReference } from '../../utils/modelSelection.js'

export default {
	name: 'ModelAllowList',
	props: {
		modelValue: { type: Array, required: true },
		options: { type: Array, default: () => [] },
		defaultModel: { type: String, default: '' },
		fallbackModel: { type: String, default: '' },
		unlistedEndpoints: { type: Array, default: () => [] },
		providerHosts: { type: Object, default: () => ({}) },
	},
	emits: ['update:modelValue'],

	data() {
		return { filter: '', expanded: {}, manualEndpoint: 'primary', manualModel: '', manualError: '' }
	},
	computed: {
		groups() {
			return buildAllowListGroups(this.options, this.modelValue, {
				filter: this.filter,
				defaultModel: this.defaultModel,
				fallbackModel: this.fallbackModel,
				expanded: this.expanded,
				unlistedEndpoints: this.unlistedEndpoints,
			})
		},
	},
	methods: {
		t,
		n,
		providerName(endpoint) {
			if (endpoint === 'secondary') return t('educai', 'Secondary')
			if (endpoint === 'primary') return t('educai', 'Primary')
			return t('educai', 'Without provider')
		},
		groupLabel(group) {
			const host = this.providerHosts[group.endpoint]
			const name = host ? `${this.providerName(group.endpoint)} · ${host}` : this.providerName(group.endpoint)
			if (group.endpoint === 'unbound') return name
			const parts = []
			if (group.total > 0) parts.push(t('educai', '{selected} of {total} released', { selected: group.selectedCount, total: group.total }))
			if (group.missingCount > 0) parts.push(n('educai', '{count} not listed', '{count} not listed', group.missingCount, { count: group.missingCount }))
			if (group.manualCount > 0) parts.push(n('educai', '{count} entered manually', '{count} entered manually', group.manualCount, { count: group.manualCount }))
			return parts.length > 0 ? `${name} — ${parts.join(' · ')}` : name
		},
		tagLabel(tag) {
			switch (tag) {
			case 'default': return t('educai', 'Default')
			case 'fallback': return t('educai', 'Fallback')
			case 'new': return t('educai', 'Newly listed')
			case 'stale': return t('educai', 'Last known list')
			default: return t('educai', 'Not listed')
			}
		},
		tagTitle(tag) {
			return tag === 'missing'
				? t('educai', 'The provider does not list this model. Requests may fail; Talk AI then uses the fallback model if one is set.')
				: null
		},
		tagClass(tag) {
			if (tag === 'missing') return 'ta-chip--warn'
			if (tag === 'new' || tag === 'stale') return ''
			return 'ta-chip--info'
		},
		toggle(id, released) {
			this.$emit('update:modelValue', setReleased(this.modelValue, [id], released))
		},
		setGroup(group, released) {
			const ids = released
				? group.matchIds.filter(id => !!splitModelReference(id) && this.options.some(option => option.id === id))
				: group.matchIds
			this.$emit('update:modelValue', setReleased(this.modelValue, ids, released))
		},
		addManual() {
			const reference = `${this.manualEndpoint}:${this.manualModel.trim()}`
			if (!splitModelReference(reference)) {
				this.manualError = t('educai', 'Enter a model ID without spaces or control characters.')
				return
			}
			this.manualError = ''
			this.manualModel = ''
			this.$emit('update:modelValue', setReleased(this.modelValue, [reference], true))
		},
	},
}
</script>
