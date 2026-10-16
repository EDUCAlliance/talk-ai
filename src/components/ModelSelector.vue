<template>
	<div class="model-selector">
		<select :id="id"
			v-model="selectedValue"
			:disabled="disabled"
			:aria-describedby="`${id}-status`">
			<option v-if="emptyLabel" value="">
				{{ emptyLabel }}
			</option>
			<option v-for="option in choices" :key="option.id" :value="option.id">
				{{ option.label }}
			</option>
		</select>
		<p :id="`${id}-status`" class="model-selector__hint" role="status">
			<template v-if="value && !hasProvider">
				{{ t('educai', 'This saved model has no provider binding. Choose a provider-specific model to make routing explicit. Keeping it unchanged preserves the saved value.') }}
			</template>
			<template v-else-if="selectedAvailability === 'missing'">
				{{ t('educai', 'The provider does not list this model. Your selection is preserved; it may still be usable.') }}
			</template>
			<template v-else-if="selectedAvailability === 'stale'">
				{{ t('educai', 'Shown from the last model list that loaded successfully. Model availability has not been verified again.') }}
			</template>
		</p>
		<details v-if="allowManual" class="model-selector__manual">
			<summary>{{ t('educai', 'Enter a model manually') }}</summary>
			<div class="model-selector__entry">
				<div>
					<label :for="`${id}-provider`">{{ t('educai', 'Provider') }}</label>
					<select :id="`${id}-provider`" v-model="manualEndpoint" :disabled="disabled">
						<option value="primary">
							{{ t('educai', 'Primary') }}
						</option>
						<option value="secondary">
							{{ t('educai', 'Secondary') }}
						</option>
					</select>
				</div>
				<div>
					<label :for="`${id}-manual`">{{ t('educai', 'Model ID') }}</label>
					<input :id="`${id}-manual`"
						v-model="manualModel"
						type="text"
						maxlength="502"
						:disabled="disabled"
						placeholder="model-name">
				</div>
				<button type="button"
					class="button"
					:disabled="disabled"
					@click="useManualModel">
					{{ t('educai', 'Use model') }}
				</button>
			</div>
			<p class="model-selector__hint">
				{{ t('educai', 'Useful when a provider does not publish a model list. Use the model ID expected by that provider.') }}
			</p>
			<p v-if="manualError" class="model-selector__error" role="alert">
				{{ manualError }}
			</p>
		</details>
	</div>
</template>

<script>
import { t } from '../l10n.js'
import * as modelSelection from '../utils/modelSelection.js'

export default {
	name: 'ModelSelector',
	props: {
		id: { type: String, required: true },
		value: { type: String, default: '' },
		options: { type: Array, default: () => [] },
		unlistedEndpoints: { type: Array, default: () => [] },
		emptyLabel: { type: String, default: '' },
		allowManual: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
	},
	data() {
		return { manualEndpoint: 'primary', manualModel: '', manualError: '' }
	},
	computed: {
		selectedValue: {
			get() { return this.value || '' },
			set(value) { this.$emit('input', value) },
		},
		choices() {
			return modelSelection.modelOptionsWithCurrent(this.options, this.value, this.unlistedEndpoints)
		},
		hasProvider() {
			return !!modelSelection.splitModelReference(this.value)
		},
		selectedAvailability() {
			return this.choices.find(option => option.id === this.value)?.availability
		},
	},
	methods: {
		t,
		useManualModel() {
			const reference = `${this.manualEndpoint}:${this.manualModel.trim()}`
			if (!modelSelection.splitModelReference(reference)) {
				this.manualError = t('educai', 'Enter a model ID without spaces or control characters.')
				return
			}
			this.manualError = ''
			this.$emit('input', reference)
		},
	},
}
</script>

<style scoped>
.model-selector select,
.model-selector input {
	width: 100%;
	max-width: 100%;
}

.model-selector__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 6px 0;
}

.model-selector__hint:empty {
	display: none;
}

.model-selector__manual {
	margin-top: 8px;
}

.model-selector__manual summary {
	cursor: pointer;
}

.model-selector__entry {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: 8px;
	margin-top: 8px;
}

.model-selector__entry > div {
	flex: 1 1 150px;
	min-width: 0;
}

.model-selector__entry label {
	display: block;
}

.model-selector__error {
	color: var(--color-error-text);
}
</style>
