<template>
	<li class="ta-api-op" :class="{ 'ta-api-op--off': !offered }">
		<div class="ta-api-op__head">
			<div class="ta-list__main">
				<p class="ta-list__title">
					<code class="ta-api-op__method">{{ operation.method }}</code>
					<code>{{ operation.path }}</code>
				</p>
				<p class="ta-list__meta">
					{{ operation.summary || operation.operation_id || operation.match_key }}
				</p>
			</div>
			<div class="ta-api-op__chips">
				<span v-if="!operation.supported" class="ta-chip ta-chip--error">{{ t('educai', 'Not supported') }}</span>
				<span v-else-if="revoked" class="ta-chip ta-chip--warn">{{ t('educai', 'Revoked') }}</span>
				<span v-else-if="offered" class="ta-chip ta-chip--ok">{{ t('educai', 'Offered to bots') }}</span>
				<span v-else class="ta-chip ta-chip--plain">{{ t('educai', 'Not offered') }}</span>
				<span v-if="operation.deprecated" class="ta-chip ta-chip--warn">{{ t('educai', 'Deprecated') }}</span>
				<span v-if="dirty" class="ta-chip ta-chip--info">{{ t('educai', 'Unsaved') }}</span>
			</div>
			<ToggleSwitch v-if="!readonly && operation.supported"
				:id="`api-op-${operation.id}-enabled`"
				:model-value="modelValue.enabled"
				:label="t('educai', 'Offer to bots')"
				@update:model-value="setEnabled" />
			<button v-if="readonly && offered"
				type="button"
				class="ta-btn ta-btn--danger ta-btn--small"
				:disabled="busy"
				@click="$emit('revoke')">
				{{ t('educai', 'Revoke') }}
			</button>
		</div>

		<ul v-if="!operation.supported" class="ta-api-op__reasons">
			<li v-for="blocker in operation.blockers" :key="blocker.code + blocker.detail">
				{{ reasonText(blocker) }}
			</li>
		</ul>
		<ul v-if="operation.notes.length" class="ta-api-op__reasons ta-api-op__reasons--warn">
			<li v-for="note in operation.notes" :key="note.code + note.detail">
				{{ reasonText(note) }}
			</li>
		</ul>

		<div v-if="operation.supported && (offered || modelValue.enabled)" class="ta-api-op__body">
			<label class="ta-check ta-api-op__confirm">
				<input type="checkbox"
					:checked="modelValue.readConfirmed"
					:disabled="readonly"
					@change="set('readConfirmed', $event.target.checked)">
				<span>{{ t('educai', 'I checked that this operation only reads data and changes nothing. GET or readOnly in the document is no proof.') }}</span>
			</label>

			<div class="ta-grid">
				<div class="ta-field">
					<label :for="`api-op-${operation.id}-label`" class="ta-label">{{ t('educai', 'Tool label') }}</label>
					<input :id="`api-op-${operation.id}-label`"
						class="ta-input"
						type="text"
						maxlength="80"
						:value="modelValue.label"
						:disabled="readonly"
						@input="set('label', $event.target.value)">
				</div>
				<div class="ta-field">
					<label :for="`api-op-${operation.id}-auth`" class="ta-label">{{ t('educai', 'Authentication for this operation') }}</label>
					<select :id="`api-op-${operation.id}-auth`"
						class="ta-input"
						:value="modelValue.auth"
						:disabled="readonly"
						@change="set('auth', $event.target.value)">
						<option value="" disabled>
							{{ t('educai', 'Choose…') }}
						</option>
						<option v-for="choice in authOptions"
							:key="choice.key"
							:value="choice.key"
							:disabled="!choice.supported">
							{{ choice.supported ? choice.label : `${choice.label} – ${choice.reason}` }}
						</option>
					</select>
				</div>
			</div>
			<div class="ta-field">
				<label :for="`api-op-${operation.id}-description`" class="ta-label">{{ t('educai', 'Description for the model') }}</label>
				<textarea :id="`api-op-${operation.id}-description`"
					class="ta-input"
					rows="2"
					maxlength="600"
					:value="modelValue.description"
					:disabled="readonly"
					@input="set('description', $event.target.value)" />
				<p class="ta-hint">
					{{ t('educai', 'The model reads this to decide when to call the tool. Leave empty to use the summary from the document.') }}
				</p>
			</div>

			<details class="ta-details">
				<summary>
					{{ t('educai', 'Parameters and results') }}
					<span>· {{ mappingSummary }}</span>
				</summary>
				<div class="ta-details__body">
					<table v-if="operation.parameters.length" class="ta-api-params">
						<thead>
							<tr>
								<th>{{ t('educai', 'Parameter') }}</th>
								<th>{{ t('educai', 'In') }}</th>
								<th>{{ t('educai', 'Type') }}</th>
								<th>{{ t('educai', 'Required') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="parameter in operation.parameters" :key="`${parameter.in}:${parameter.name}`">
								<td><code>{{ parameter.name }}</code></td>
								<td>{{ parameter.in }}</td>
								<td>{{ parameterType(parameter) }}</td>
								<td>{{ parameter.required ? t('educai', 'yes') : t('educai', 'no') }}</td>
							</tr>
						</tbody>
					</table>
					<div class="ta-field">
						<label :for="`api-op-${operation.id}-fixed`" class="ta-label">{{ t('educai', 'Fixed query values') }}</label>
						<textarea :id="`api-op-${operation.id}-fixed`"
							class="ta-input ta-input--mono"
							rows="2"
							placeholder="lang = de"
							:value="modelValue.fixed"
							:disabled="readonly"
							@input="set('fixed', $event.target.value)" />
						<p class="ta-hint">
							{{ t('educai', 'One name = value per line. The server always sends these values; the model cannot change them.') }}
						</p>
					</div>
					<div class="ta-grid ta-grid--three">
						<div v-for="field in mappingFields" :key="field.key" class="ta-field">
							<label :for="`api-op-${operation.id}-${field.key}`" class="ta-label">{{ field.label }}</label>
							<input :id="`api-op-${operation.id}-${field.key}`"
								class="ta-input ta-input--mono"
								type="text"
								:placeholder="field.placeholder"
								:value="modelValue[field.key]"
								:disabled="readonly"
								@input="set(field.key, $event.target.value)">
						</div>
					</div>
					<div class="ta-field">
						<label :for="`api-op-${operation.id}-fields`" class="ta-label">{{ t('educai', 'Extra result fields') }}</label>
						<textarea :id="`api-op-${operation.id}-fields`"
							class="ta-input ta-input--mono"
							rows="3"
							placeholder="ects = /credits"
							:value="modelValue.fields"
							:disabled="readonly"
							@input="set('fields', $event.target.value)" />
						<p class="ta-hint">
							{{ t('educai', 'One name = /JSON pointer per line, relative to a record. Only these fields reach the model.') }}
						</p>
					</div>
					<div class="ta-grid">
						<div class="ta-field">
							<label :for="`api-op-${operation.id}-linkTemplate`" class="ta-label">{{ t('educai', 'Source link template') }}</label>
							<input :id="`api-op-${operation.id}-linkTemplate`"
								class="ta-input ta-input--mono"
								type="text"
								placeholder="https://example.org/courses/{id}"
								:value="modelValue.linkTemplate"
								:disabled="readonly"
								@input="set('linkTemplate', $event.target.value)">
						</div>
						<div class="ta-field">
							<label :for="`api-op-${operation.id}-linkField`" class="ta-label">{{ t('educai', 'Or link from field') }}</label>
							<input :id="`api-op-${operation.id}-linkField`"
								class="ta-input ta-input--mono"
								type="text"
								placeholder="/url"
								:value="modelValue.linkField"
								:disabled="readonly"
								@input="set('linkField', $event.target.value)">
						</div>
					</div>
					<div class="ta-field">
						<label :for="`api-op-${operation.id}-origins`" class="ta-label">{{ t('educai', 'Allowed link origins') }}</label>
						<textarea :id="`api-op-${operation.id}-origins`"
							class="ta-input ta-input--mono"
							rows="2"
							placeholder="https://example.org"
							:value="modelValue.linkOrigins"
							:disabled="readonly"
							@input="set('linkOrigins', $event.target.value)" />
						<p class="ta-hint">
							{{ t('educai', 'One HTTPS origin per line. Links to other hosts are dropped.') }}
						</p>
					</div>
					<fieldset class="ta-choice">
						<legend>{{ t('educai', 'Paging (optional)') }}</legend>
						<div class="ta-grid ta-grid--four">
							<div v-for="key in pagingKeys" :key="key" class="ta-field">
								<label :for="`api-op-${operation.id}-paging-${key}`" class="ta-label">{{ pagingLabel(key) }}</label>
								<input :id="`api-op-${operation.id}-paging-${key}`"
									class="ta-input ta-input--mono"
									type="text"
									:placeholder="`/${key}`"
									:value="modelValue.paging[key]"
									:disabled="readonly"
									@input="setPaging(key, $event.target.value)">
							</div>
						</div>
					</fieldset>
				</div>
			</details>

			<p v-if="lineErrors.length" class="ta-note ta-note--error" role="alert">
				<span v-for="error in lineErrors" :key="error">{{ error }}</span>
			</p>
			<div v-else-if="problems.length && !dirty" class="ta-note ta-note--warn" role="status">
				<strong>{{ readonly ? t('educai', 'Bots cannot call this tool right now:') : t('educai', 'Before approval:') }}</strong>
				<ul class="ta-api-op__reasons">
					<li v-for="problem in problems" :key="problem">
						{{ codeText(problem) || problem }}
					</li>
				</ul>
			</div>

			<div class="ta-api-op__test">
				<p class="ta-subheading">
					{{ t('educai', 'Test call') }}
				</p>
				<p class="ta-hint">
					{{ dirty
						? t('educai', 'Save the review first. The test uses the saved settings.')
						: t('educai', 'Sends one real request with these values. Nothing is called automatically.') }}
				</p>
				<div v-if="fields.length" class="ta-grid ta-grid--three">
					<div v-for="field in fields" :key="`${field.in}:${field.name}`" class="ta-field">
						<label :for="`api-op-${operation.id}-test-${field.in}-${field.name}`" class="ta-label">
							{{ field.name }}<span v-if="field.required" aria-hidden="true"> *</span>
						</label>
						<input :id="`api-op-${operation.id}-test-${field.in}-${field.name}`"
							v-model="testValues[`${field.in}:${field.name}`]"
							class="ta-input"
							type="text"
							:placeholder="parameterType(field)"
							:required="field.required">
					</div>
				</div>
				<TestAction :label="t('educai', 'Run test call')"
					:busy-label="t('educai', 'Calling…')"
					:busy="testing"
					:disabled="dirty || problems.length > 0"
					:result="testResult"
					:idle-text="problems.length > 0 && !dirty ? t('educai', 'Resolve the points above to test.') : ''"
					@run="test" />
				<details v-if="testOutput" class="ta-details">
					<summary>{{ t('educai', 'Show what the model would receive') }}</summary>
					<pre class="ta-pre">{{ testOutput }}</pre>
				</details>
			</div>
		</div>
	</li>
</template>

<script>
import { n, t } from '../../l10n.js'
import { PAGING_KEYS, authChoices, codeText, errorText, reviewEdit, testArguments, testFields } from '../../utils/apiConnections.js'
import TestAction from './TestAction.vue'
import ToggleSwitch from './ToggleSwitch.vue'

export default {
	name: 'ApiOperationReview',
	components: { TestAction, ToggleSwitch },
	props: {
		operation: { type: Object, required: true },
		modelValue: { type: Object, required: true },
		problems: { type: Array, default: () => [] },
		readonly: { type: Boolean, default: false },
		dirty: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		runTest: { type: Function, required: true },
	},
	emits: ['update:modelValue', 'revoke'],

	data() {
		return {
			testValues: {},
			testing: false,
			testResult: null,
			testOutput: '',
			pagingKeys: PAGING_KEYS,
		}
	},
	computed: {
		revoked() {
			return Boolean(this.operation.config?.revoked_at)
		},
		offered() {
			return this.readonly
				? this.operation.config?.enabled === true && !this.revoked
				: this.modelValue.enabled
		},
		authOptions() {
			return authChoices(this.operation)
		},
		fields() {
			return testFields(this.operation, this.modelValue)
		},
		lineErrors() {
			return reviewEdit(this.modelValue).errors
		},
		mappingFields() {
			return [
				{ key: 'records', label: t('educai', 'Records at'), placeholder: '/items' },
				{ key: 'id', label: t('educai', 'ID field'), placeholder: '/id' },
				{ key: 'title', label: t('educai', 'Title field'), placeholder: '/title' },
			]
		},
		mappingSummary() {
			const extra = reviewEdit(this.modelValue).edit.mapping.fields.length
			return this.modelValue.linkTemplate.trim() || this.modelValue.linkField.trim()
				? n('educai', '{count} extra field, with source link', '{count} extra fields, with source link', extra, { count: extra })
				: n('educai', '{count} extra field, no source link', '{count} extra fields, no source link', extra, { count: extra })
		},
	},
	watch: {
		'operation.id'() {
			this.testValues = {}
			this.testResult = null
			this.testOutput = ''
		},
	},
	methods: {
		t,
		codeText,
		set(key, value) {
			this.$emit('update:modelValue', { ...this.modelValue, [key]: value })
		},
		setEnabled(enabled) {
			const supported = this.authOptions.filter(choice => choice.supported)
			const auth = enabled && this.modelValue.auth === '' && supported.length === 1 ? supported[0].key : this.modelValue.auth
			this.$emit('update:modelValue', { ...this.modelValue, enabled, auth })
		},
		setPaging(key, value) {
			this.set('paging', { ...this.modelValue.paging, [key]: value })
		},
		reasonText(entry) {
			const text = codeText(entry.code) ?? entry.code
			return entry.detail ? `${text} (${entry.detail})` : text
		},
		parameterType(parameter) {
			const schema = parameter.schema ?? {}
			if (schema.type === 'array') {
				return t('educai', 'list of {type}, comma separated', { type: schema.items?.type ?? 'string' })
			}
			return Array.isArray(schema.enum) ? schema.enum.join(' | ') : (schema.type ?? 'string')
		},
		pagingLabel(key) {
			return {
				has_more: t('educai', '"More results" flag at'),
				next_cursor: t('educai', 'Next cursor at'),
				next_offset: t('educai', 'Next offset at'),
				total: t('educai', 'Total count at'),
			}[key] ?? key
		},
		async test() {
			const { arguments: args, errors } = testArguments(this.fields, this.testValues)
			if (errors.length) {
				this.testResult = { success: false, message: errors.join(' ') }
				this.testOutput = ''
				return
			}
			this.testing = true
			this.testResult = null
			this.testOutput = ''
			try {
				const result = await this.runTest(args)
				this.testResult = result.success
					? { success: true, message: t('educai', 'The call worked.') }
					: { success: false, message: codeText(result.error?.code) ?? result.error?.message ?? t('educai', 'The call failed.') }
				this.testOutput = result.success ? pretty(result.output) : ''
			} catch (error) {
				this.testResult = { success: false, message: errorText(error, t('educai', 'The test call could not be started.')) }
			} finally {
				this.testing = false
			}
		},
	},
}

function pretty(output) {
	try {
		return JSON.stringify(JSON.parse(output), null, 2)
	} catch {
		return String(output ?? '')
	}
}
</script>
