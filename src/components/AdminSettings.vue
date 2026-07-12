<template>
	<!-- eslint-disable vue/html-indent, vue/singleline-html-element-content-newline -->
	<div class="admin-settings">
		<h3 class="settings-title">
			<span class="icon-settings" />
			Administrator Settings
		</h3>

		<form class="settings-body" autocomplete="off" @submit.prevent="saveSettings">
			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('appearance') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('appearance'))"
					:aria-controls="sectionId('appearance')"
					@click="toggleSection('appearance')">
					<span class="accordion-heading">
						<span class="accordion-title">Appearance</span>
						<span class="accordion-description">App icon variants for settings and navigation surfaces.</span>
					</span>
					<span class="accordion-meta">{{ appIconSummary }}</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('appearance')"
					:id="sectionId('appearance')"
					class="accordion-panel">
					<div class="app-icon-config">
						<div class="icon-mode-tabs" aria-label="App icon mode">
							<button
								type="button"
								class="icon-mode-tab"
								:class="{ 'icon-mode-tab--active': settings.appIconMode === 'default' }"
								@click="setAppIconMode('default')">
								Default
							</button>
							<button
								type="button"
								class="icon-mode-tab"
								:class="{ 'icon-mode-tab--active': settings.appIconMode === 'custom' }"
								@click="setAppIconMode('custom')">
								Custom
							</button>
						</div>

						<div class="app-icon-preview-grid">
							<div class="app-icon-preview-card app-icon-preview-card--light">
								<span class="app-icon-preview-card__label">Black on light</span>
								<span class="app-icon-preview-card__frame">
									<img
										v-if="appIconBlackPreviewUrl"
										:src="appIconBlackPreviewUrl"
										:alt="APP_DISPLAY_NAME + ' black app icon preview'">
									<span v-else class="app-icon-preview-card__missing">Missing</span>
								</span>
							</div>
							<div class="app-icon-preview-card app-icon-preview-card--dark">
								<span class="app-icon-preview-card__label">White on dark</span>
								<span class="app-icon-preview-card__frame">
									<img
										v-if="appIconWhitePreviewUrl"
										:src="appIconWhitePreviewUrl"
										:alt="APP_DISPLAY_NAME + ' white app icon preview'">
									<span v-else class="app-icon-preview-card__missing">Missing</span>
								</span>
							</div>
						</div>

						<div v-if="settings.appIconMode === 'default'" class="app-icon-mode-panel">
							<p class="hint">
								Uses the bundled Talk AI icon pair: dark icon for light settings surfaces, white icon for app navigation.
							</p>
						</div>

						<div v-else class="app-icon-mode-panel">
							<div class="form-grid">
								<div class="form-group">
									<label for="app-icon-black-url">Black icon</label>
									<div class="app-icon-input-row">
										<input
											id="app-icon-black-url"
											v-model="settings.appIconBlackUrl"
											type="text"
											name="educai-app-icon-black-url"
											autocomplete="off"
											placeholder="https://example.org/educai-black.svg or /apps/theming/img/educai-black.svg">
										<button
											type="button"
											class="button"
											:disabled="uploadingAppIconVariant === 'black'"
											@click="triggerAppIconUpload('black')">
											{{ uploadingAppIconVariant === 'black' ? 'Uploading...' : 'Upload SVG' }}
										</button>
										<input
											ref="appIconBlackFileInput"
											class="app-icon-file-input"
											type="file"
											accept="image/svg+xml,.svg"
											@change="uploadAppIconSvg('black', $event)">
									</div>
									<p class="hint">
										Used on light settings surfaces. Enter a URL/path or upload an SVG from your computer.
									</p>
								</div>
								<div class="form-group">
									<label for="app-icon-white-url">White icon</label>
									<div class="app-icon-input-row">
										<input
											id="app-icon-white-url"
											v-model="settings.appIconWhiteUrl"
											type="text"
											name="educai-app-icon-white-url"
											autocomplete="off"
											placeholder="https://example.org/educai-white.svg or /apps/theming/img/educai-white.svg">
										<button
											type="button"
											class="button"
											:disabled="uploadingAppIconVariant === 'white'"
											@click="triggerAppIconUpload('white')">
											{{ uploadingAppIconVariant === 'white' ? 'Uploading...' : 'Upload SVG' }}
										</button>
										<input
											ref="appIconWhiteFileInput"
											class="app-icon-file-input"
											type="file"
											accept="image/svg+xml,.svg"
											@change="uploadAppIconSvg('white', $event)">
									</div>
									<p class="hint">
										Used in the dark app navigation/header context. Enter a URL/path or upload an SVG from your computer.
									</p>
								</div>
							</div>
						</div>

						<div class="button-row app-icon-actions">
							<button type="button" class="button" @click="resetAppIconConfig">
								Reset to default icon
							</button>
						</div>
					</div>
				</div>
			</section>

			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('essentials') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('essentials'))"
					:aria-controls="sectionId('essentials')"
					@click="toggleSection('essentials')">
					<span class="accordion-heading">
						<span class="accordion-title">Essentials</span>
						<span class="accordion-description">Default creativity and Talk webhook secret.</span>
					</span>
					<span class="accordion-meta">{{ essentialsSummary }}</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('essentials')"
					:id="sectionId('essentials')"
					class="accordion-panel">
					<div class="form-group">
						<label for="default-temperature">Default Temperature</label>
						<input
							id="default-temperature"
							v-model.number="settings.defaultTemperature"
							type="number"
							min="0"
							max="1"
							step="0.05"
							placeholder="0.20">
						<p class="hint">
							Controls how deterministic or creative bots are by default.
							Lower values are better for agentic, tool-using, RAG, and workflow bots.
							Higher values can be useful for creative writing or brainstorming bots.
						</p>
						<div class="temperature-presets">
							<button type="button" class="button" @click="applyDefaultTemperaturePreset(0.2)">
								Precise 0.20
							</button>
							<button type="button" class="button" @click="applyDefaultTemperaturePreset(0.4)">
								Balanced 0.40
							</button>
							<button type="button" class="button" @click="applyDefaultTemperaturePreset(0.6)">
								Creative 0.60
							</button>
						</div>
					</div>

					<div class="form-group">
						<label for="webhook-secret">Webhook Secret</label>
						<input
							id="webhook-secret"
							v-model="settings.webhookSecret"
							type="password"
							name="educai-webhook-secret"
							autocomplete="new-password"
							placeholder="your-webhook-secret">
						<p class="hint">
							Secret used to verify webhook requests from Nextcloud Talk.
						</p>
					</div>
				</div>
			</section>

			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('modelEndpoints') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('modelEndpoints'))"
					:aria-controls="sectionId('modelEndpoints')"
					@click="toggleSection('modelEndpoints')">
					<span class="accordion-heading">
						<span class="accordion-title">Model Endpoints</span>
						<span class="accordion-description">Primary and optional secondary OpenAI-compatible providers.</span>
					</span>
					<span class="accordion-meta">{{ modelEndpointsSummary }}</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('modelEndpoints')"
					:id="sectionId('modelEndpoints')"
					class="accordion-panel">
					<div class="section-heading">
						<h4>Primary Endpoint</h4>
						<p class="hint">
							Used for existing raw model names and as the default provider.
						</p>
					</div>

					<div class="form-group">
						<label for="api-endpoint">Primary API Endpoint</label>
						<input
							id="api-endpoint"
							v-model="settings.apiEndpoint"
							type="url"
							name="educai-api-endpoint"
							autocomplete="off"
							placeholder="https://chat-ai.academiccloud.de/v1/chat/completions"
							required>
						<p class="hint">
							AcademicCloud (GWDG) or OpenAI-compatible API endpoint URL.
						</p>
					</div>

					<div class="form-group">
						<label for="api-key">Primary API Key</label>
						<input
							id="api-key"
							v-model="settings.apiKey"
							type="password"
							name="educai-api-key"
							autocomplete="new-password"
							placeholder="sk-...">
						<p class="hint">
							Used for primary endpoint requests. Stored securely and never displayed back.
						</p>
					</div>

					<div class="section-heading">
						<h4>Secondary Endpoint</h4>
						<p class="hint">
							Optional. When configured, bots can choose models from both endpoints.
						</p>
					</div>

					<div class="form-group">
						<label for="secondary-api-endpoint">Secondary API Endpoint</label>
						<input
							id="secondary-api-endpoint"
							v-model="settings.secondaryApiEndpoint"
							type="url"
							name="educai-secondary-api-endpoint"
							autocomplete="off"
							placeholder="https://example.org/v1/chat/completions">
					</div>

					<div class="form-group">
						<label for="secondary-api-key">Secondary API Key</label>
						<input
							id="secondary-api-key"
							v-model="settings.secondaryApiKey"
							type="password"
							name="educai-secondary-api-key"
							autocomplete="new-password"
							placeholder="sk-...">
						<p class="hint">
							Leave blank to keep an already stored secondary key.
						</p>
					</div>

					<div class="section-heading">
						<h4>Model Selection</h4>
					</div>

					<div class="form-group">
						<label for="default-model">Default Model</label>
						<select
							v-if="availableModelOptions.length > 0"
							id="default-model"
							v-model="settings.defaultModel"
							:disabled="settings.allowMultipleModels">
							<option
								v-for="option in availableModelOptionsWithCurrent(settings.defaultModel)"
								:key="option.id"
								:value="option.id">
								{{ option.label }}
							</option>
						</select>
						<input
							v-else
							id="default-model"
							v-model="settings.defaultModel"
							type="text"
							:disabled="settings.allowMultipleModels"
							placeholder="primary:llama-3.3-70b-instruct">
						<p v-if="settings.allowMultipleModels" class="hint">
							Disabled because Multiple Models mode is enabled.
						</p>
						<p v-else class="hint">
							Legacy unprefixed model names continue to use the primary endpoint.
						</p>
					</div>

					<div class="form-group">
						<label class="checkbox">
							<input
								v-model="settings.allowMultipleModels"
								type="checkbox"
								@change="onToggleMultiple">
							Allow Multiple Models (per-bot selection)
						</label>
						<p class="hint">
							If enabled, choose which models are available for bots. Users will select one when creating a bot.
						</p>
					</div>

					<div v-if="settings.allowMultipleModels" class="form-group">
						<label>Allowed Models</label>
						<div v-if="loadingModels" class="hint">
							Loading models…
						</div>
						<div v-else>
							<div v-if="modelLoadError" class="hint" style="color:var(--color-error)">
								{{ modelLoadError }}
							</div>
							<div v-if="availableModelOptions.length === 0" class="hint">
								No models loaded.
								<button
									type="button"
									class="button"
									@click="loadModels">
									Load models
								</button>
							</div>
							<select
								v-model="settings.allowedModels"
								multiple
								:size="modelSelectSize">
								<option
									v-for="option in availableModelOptionsWithCurrent(settings.allowedModels)"
									:key="option.id"
									:value="option.id">
									{{ option.label }}
								</option>
							</select>
							<p class="hint">
								Hold Cmd/Ctrl to select multiple. Save after selection.
							</p>
						</div>
					</div>
				</div>
			</section>

			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('fallbackTimeouts') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('fallbackTimeouts'))"
					:aria-controls="sectionId('fallbackTimeouts')"
					@click="toggleSection('fallbackTimeouts')">
					<span class="accordion-heading">
						<span class="accordion-title">Fallback &amp; Timeouts</span>
						<span class="accordion-description">One retry model and LLM request time limits.</span>
					</span>
					<span class="accordion-meta">{{ fallbackTimeoutSummary }}</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('fallbackTimeouts')"
					:id="sectionId('fallbackTimeouts')"
					class="accordion-panel">
					<div class="form-group">
						<label for="fallback-model">Fallback Model</label>
						<select
							v-if="availableModelOptions.length > 0"
							id="fallback-model"
							v-model="settings.fallbackModel">
							<option value="">No fallback</option>
							<option
								v-for="option in availableModelOptionsWithCurrent(settings.fallbackModel)"
								:key="option.id"
								:value="option.id">
								{{ option.label }}
							</option>
						</select>
						<input
							v-else
							id="fallback-model"
							v-model="settings.fallbackModel"
							type="text"
							placeholder="secondary:qwen3-coder-next">
						<p class="hint">
							Used once after timeout or connection failures before the request fails finally.
						</p>
					</div>

					<div class="form-grid form-grid--three">
						<div class="form-group">
							<label for="llm-chat-timeout">Chat Timeout (seconds)</label>
							<input
								id="llm-chat-timeout"
								v-model.number="settings.llmChatTimeout"
								type="number"
								min="1"
								step="1">
							<p class="hint">
								Maximum time to wait for a regular, non-streaming LLM response.
							</p>
						</div>
						<div class="form-group">
							<label for="llm-stream-timeout">Streaming Timeout (seconds)</label>
							<input
								id="llm-stream-timeout"
								v-model.number="settings.llmStreamTimeout"
								type="number"
								min="1"
								step="1">
							<p class="hint">
								Maximum time to keep a streaming response open while the model is generating.
							</p>
						</div>
						<div class="form-group">
							<label for="llm-models-timeout">Model-List Timeout (seconds)</label>
							<input
								id="llm-models-timeout"
								v-model.number="settings.llmModelsTimeout"
								type="number"
								min="1"
								step="1">
						</div>
					</div>
				</div>
			</section>

			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('rag') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('rag'))"
					:aria-controls="sectionId('rag')"
					@click="toggleSection('rag')">
					<span class="accordion-heading">
						<span class="accordion-title">RAG &amp; Embeddings</span>
						<span class="accordion-description">Knowledge retrieval, embedding provider, limits, and chunking.</span>
					</span>
					<span class="accordion-meta" :class="{ 'accordion-meta--warning': hasPendingEmbeddingConfigChange }">
						{{ ragSummary }}
					</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('rag')"
					:id="sectionId('rag')"
					class="accordion-panel">
					<div class="section-heading">
						<h4>Retrieval-Augmented Generation</h4>
						<p class="hint">
							Configure embeddings and chunking parameters used when bots index Nextcloud files.
						</p>
					</div>

					<div class="form-group">
				<label class="checkbox">
					<input
						v-model="settings.ragEnabled"
						type="checkbox">
					Enable Retrieval-Augmented Generation
				</label>
				<p class="hint">
					When enabled, bots can index approved files and surface relevant snippets in conversations.
				</p>
			</div>

			<div class="form-group">
				<label for="embedding-endpoint">Embedding API Endpoint</label>
				<input
					id="embedding-endpoint"
					v-model="settings.embeddingApiEndpoint"
					type="url"
					name="educai-embedding-endpoint"
					autocomplete="off"
					placeholder="https://chat-ai.academiccloud.de/v1/embeddings">
				<p class="hint">
					Optional. Leave blank to reuse the chat completion endpoint.
				</p>
			</div>

			<div class="form-group">
				<label for="embedding-key">Embedding API Key</label>
				<input
					id="embedding-key"
					v-model="settings.embeddingApiKey"
					type="password"
					name="educai-embedding-key"
					autocomplete="new-password"
					placeholder="sk-...">
				<p class="hint">
					Optional. Provide only if embeddings require a different credential. Stored securely and never displayed back.
				</p>
			</div>

			<div class="form-group">
				<label for="embedding-model">Embedding Model</label>
				<input
					id="embedding-model"
					v-model="settings.embeddingModel"
					type="text"
					placeholder="multilingual-e5-large-instruct">
				<p class="hint">
					Set the provider-specific embedding model identifier (e.g., multilingual-e5-large-instruct, e5-mistral-7b-instruct).
				</p>
			</div>

			<div class="form-group">
				<label for="embedding-rate-limit-mode">Embedding Rate Limit Mode</label>
				<select
					id="embedding-rate-limit-mode"
					v-model="settings.embeddingRateLimitMode">
					<option value="inherit">
						Inherit Chat Limits
					</option>
					<option value="disabled">
						Disabled
					</option>
					<option value="custom">
						Custom
					</option>
				</select>
				<p class="hint">
					Use <code>inherit</code> to follow chat queue limits, <code>disabled</code> for self-hosted embedding endpoints without throttling, or <code>custom</code> for dedicated embedding quotas.
				</p>
			</div>

			<div v-if="settings.embeddingRateLimitMode === 'custom'" class="form-grid">
				<div class="form-group">
					<label for="embedding-rate-limit-second">Embedding Requests per Second</label>
					<input
						id="embedding-rate-limit-second"
						v-model.number="settings.embeddingRateLimitSecond"
						type="number"
						min="1"
						placeholder="Optional">
					<p class="hint">
						Optional. Leave blank if the embedding provider does not expose second-level headers.
					</p>
				</div>
				<div class="form-group">
					<label for="embedding-rate-limit-minute">Embedding Requests per Minute</label>
					<input
						id="embedding-rate-limit-minute"
						v-model.number="settings.embeddingRateLimitMinute"
						type="number"
						min="1"
						placeholder="100">
					<p class="hint">
						Default for GWDG embeddings: 100/minute.
					</p>
				</div>
				<div class="form-group">
					<label for="embedding-rate-limit-hour">Embedding Requests per Hour</label>
					<input
						id="embedding-rate-limit-hour"
						v-model.number="settings.embeddingRateLimitHour"
						type="number"
						min="1"
						placeholder="2000">
					<p class="hint">
						Default for GWDG embeddings: 2000/hour.
					</p>
				</div>
				<div class="form-group">
					<label for="embedding-rate-limit-day">Embedding Requests per Day</label>
					<input
						id="embedding-rate-limit-day"
						v-model.number="settings.embeddingRateLimitDay"
						type="number"
						min="1"
						placeholder="4000">
					<p class="hint">
						Default for GWDG embeddings: 4000/day.
					</p>
				</div>
			</div>
			<div v-if="hasPendingEmbeddingConfigChange" class="embedding-warning">
				<strong>Embedding settings changed.</strong>
				<p>
					Save first, then run <code>Reindex All Embeddings</code> to rebuild bot vectors for the active embedding model.
				</p>
			</div>

			<div class="form-grid">
				<div class="form-group">
					<label for="rag-chunk-size">Chunk Size (tokens)</label>
					<input
						id="rag-chunk-size"
						v-model.number="settings.ragChunkSize"
						type="number"
						min="100"
						step="10"
						placeholder="750">
					<p class="hint">
						Controls how much text each embedding covers. Larger chunks reduce recall but speed ingestion.
					</p>
				</div>
				<div class="form-group">
					<label for="rag-chunk-overlap">Chunk Overlap (tokens)</label>
					<input
						id="rag-chunk-overlap"
						v-model.number="settings.ragChunkOverlap"
						type="number"
						min="0"
						step="5"
						placeholder="50">
				<p class="hint">
					Overlap provides continuity between adjacent chunks. Increase for long-form documents.
				</p>
			</div>
		</div>
				</div>
			</section>

			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('docling') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('docling'))"
					:aria-controls="sectionId('docling')"
					@click="toggleSection('docling')">
					<span class="accordion-heading">
						<span class="accordion-title">Document Conversion</span>
						<span class="accordion-description">Docling conversion for PDF and Office ingestion.</span>
					</span>
					<span class="accordion-meta">{{ doclingSummary }}</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('docling')"
					:id="sectionId('docling')"
					class="accordion-panel">
					<div class="section-heading">
						<h4>Document Conversion (Docling)</h4>
						<p class="hint">
							Enable conversion of PDF, DOCX, PPTX and other binary documents to text for RAG ingestion.
							This uses the Docling API to extract content from documents that cannot be read as plain text.
						</p>
					</div>

					<div class="form-group">
				<label class="checkbox">
					<input
						v-model="settings.doclingEnabled"
						type="checkbox">
					Enable Document Conversion
				</label>
				<p class="hint">
					When enabled, PDF and Office documents attached to bots will be automatically converted to text for indexing.
					Uses the dedicated Docling API key when configured, otherwise falls back to the main API key.
				</p>
			</div>

			<div class="form-group">
				<label for="docling-endpoint">Docling API Endpoint</label>
				<input
					id="docling-endpoint"
					v-model="settings.doclingApiEndpoint"
					type="url"
					name="educai-docling-endpoint"
					autocomplete="off"
					placeholder="https://chat-ai.academiccloud.de/v1/documents/convert">
				<p class="hint">
					Optional. Leave blank to use the default Academic Cloud endpoint.
				</p>
			</div>

			<div class="form-group">
				<label for="docling-key">Docling API Key</label>
				<input
					id="docling-key"
					v-model="settings.doclingApiKey"
					type="password"
					name="educai-docling-key"
					autocomplete="new-password"
					placeholder="sk-...">
				<p class="hint">
					Optional. Provide only if Docling requires a different credential.
					{{ doclingKeyHint }}
				</p>
			</div>

			<div class="button-row">
				<button
					type="button"
					class="button"
					:disabled="doclingTesting"
					@click="testDoclingConnection">
					{{ doclingTesting ? 'Testing…' : 'Test Connection' }}
				</button>
				<span v-if="doclingTestResult" :class="doclingTestResult.success ? 'success-text' : 'error-text'">
					{{ doclingTestResult.success ? '✓ Connection successful' : ('✗ ' + doclingTestResult.error) }}
				</span>
			</div>

			<div class="supported-formats">
				<p class="hint">
					<strong>Supported formats:</strong> PDF, DOCX, DOC, PPTX, PPT, XLSX, XLS, PNG, JPG, TIFF, BMP
				</p>
			</div>
				</div>
			</section>

			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('media') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('media'))"
					:aria-controls="sectionId('media')"
					@click="toggleSection('media')">
					<span class="accordion-heading">
						<span class="accordion-title">Media Tools</span>
						<span class="accordion-description">Image understanding and speech-to-text models.</span>
					</span>
					<span class="accordion-meta">{{ mediaSummary }}</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('media')"
					:id="sectionId('media')"
					class="accordion-panel">
					<div class="section-heading">
						<h4>Image Understanding</h4>
						<p class="hint">
							Configure a multimodal model for image attachments uploaded in Nextcloud Talk. Bots only get image understanding when the built-in image tool is enabled for that bot.
						</p>
					</div>

					<div class="form-group">
				<label for="vision-endpoint">Vision API Endpoint</label>
				<input
					id="vision-endpoint"
					v-model="settings.visionApiEndpoint"
					type="url"
					name="educai-vision-endpoint"
					autocomplete="off"
					placeholder="https://chat-ai.academiccloud.de/v1/chat/completions">
				<p class="hint">
					Optional. Leave blank to reuse the main chat completion endpoint.
				</p>
			</div>

			<div class="form-group">
				<label for="vision-key">Vision API Key</label>
				<input
					id="vision-key"
					v-model="settings.visionApiKey"
					type="password"
					name="educai-vision-key"
					autocomplete="new-password"
					placeholder="sk-...">
				<p class="hint">
					Optional. Leave blank to reuse the main API key.
				</p>
			</div>

			<div class="form-group">
				<label for="vision-model">Vision Model</label>
				<input
					id="vision-model"
					v-model="settings.visionModel"
					type="text"
					placeholder="llama-4-scout-17b-16e-instruct">
				<p class="hint">
					Set a model identifier that supports image inputs.
				</p>
			</div>

			<div class="button-row">
				<button
					type="button"
					class="button"
					:disabled="visionTesting"
					@click="testVisionConnection">
					{{ visionTesting ? 'Testing…' : 'Test Vision' }}
				</button>
				<span v-if="visionTestResult" :class="visionTestResult.success ? 'success-text' : 'error-text'">
					{{ visionTestResult.success ? '✓ Vision connection successful' : ('✗ ' + visionTestResult.error) }}
				</span>
			</div>

			<div class="section-divider" role="presentation" />

			<div class="section-heading">
				<h4>Speech To Text</h4>
				<p class="hint">
					Configure a transcription model for audio and voice-message attachments uploaded in Nextcloud Talk. Bots only get this ability when the built-in audio tool is enabled for that bot.
				</p>
			</div>

			<div class="form-group">
				<label for="speech-endpoint">Speech API Endpoint</label>
				<input
					id="speech-endpoint"
					v-model="settings.speechApiEndpoint"
					type="url"
					name="educai-speech-endpoint"
					autocomplete="off"
					placeholder="https://chat-ai.academiccloud.de/v1/audio/transcriptions">
				<p class="hint">
					Optional. Leave blank to derive an OpenAI-compatible transcription endpoint from the main chat endpoint.
				</p>
			</div>

			<div class="form-group">
				<label for="speech-key">Speech API Key</label>
				<input
					id="speech-key"
					v-model="settings.speechApiKey"
					type="password"
					name="educai-speech-key"
					autocomplete="new-password"
					placeholder="sk-...">
				<p class="hint">
					Optional. Leave blank to reuse the main API key.
				</p>
			</div>

			<div class="form-group">
				<label for="speech-model">Speech Model</label>
				<input
					id="speech-model"
					v-model="settings.speechModel"
					type="text"
					placeholder="whisper-large-v3">
				<p class="hint">
					Set the transcription model identifier, for example a Whisper-compatible model.
				</p>
			</div>

			<div class="button-row">
				<button
					type="button"
					class="button"
					:disabled="speechTesting"
					@click="testSpeechConnection">
					{{ speechTesting ? 'Testing…' : 'Test Speech' }}
				</button>
				<span v-if="speechTestResult" :class="speechTestResult.success ? 'success-text' : 'error-text'">
					{{ speechTestResult.success ? '✓ Speech connection successful' : ('✗ ' + speechTestResult.error) }}
				</span>
			</div>
				</div>
			</section>

			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('limits') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('limits'))"
					:aria-controls="sectionId('limits')"
					@click="toggleSection('limits')">
					<span class="accordion-heading">
						<span class="accordion-title">Rate Limits</span>
						<span class="accordion-description">LLM queue limits, live counters, and queue processing.</span>
					</span>
					<span class="accordion-meta">{{ rateLimitSummary }}</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('limits')"
					:id="sectionId('limits')"
					class="accordion-panel">
					<div class="section-heading">
						<h4>LLM Rate Limiting Queue</h4>
						<p class="hint">
							When enabled, requests that exceed the LLM provider's rate limit will be queued and processed automatically via background jobs.
							Configure limits based on your provider's quota. For GWDG, use 30/minute, 200/hour, and 1000/day. Leave per-second empty if your provider does not expose a second-level window.
						</p>
					</div>

					<div class="form-group">
				<label class="checkbox">
					<input
						v-model="settings.rateLimitEnabled"
						type="checkbox">
					Enable Rate Limit Queue
				</label>
				<p class="hint">
					When enabled, requests exceeding rate limits will be queued instead of failing. A background job will process queued requests as capacity becomes available.
				</p>
			</div>

			<div v-if="settings.rateLimitEnabled" class="form-grid">
				<div class="form-group">
					<label for="rate-limit-second">Requests per Second</label>
					<input
						id="rate-limit-second"
						v-model.number="settings.rateLimitSecond"
						type="number"
						min="1"
						placeholder="Optional">
					<p class="hint">
						Optional. Leave blank for providers like GWDG that only expose minute/hour/day headers.
					</p>
				</div>
				<div class="form-group">
					<label for="rate-limit-minute">Requests per Minute</label>
					<input
						id="rate-limit-minute"
						v-model.number="settings.rateLimitMinute"
						type="number"
						min="1"
						placeholder="30">
					<p class="hint">
						Maximum requests per minute (GWDG default: 30)
					</p>
				</div>
				<div class="form-group">
					<label for="rate-limit-hour">Requests per Hour</label>
					<input
						id="rate-limit-hour"
						v-model.number="settings.rateLimitHour"
						type="number"
						min="1"
						placeholder="200">
					<p class="hint">
						Maximum requests per hour (GWDG default: 200)
					</p>
				</div>
				<div class="form-group">
					<label for="rate-limit-day">Requests per Day</label>
					<input
						id="rate-limit-day"
						v-model.number="settings.rateLimitDay"
						type="number"
						min="1"
						placeholder="1000">
					<p class="hint">
						Maximum requests per day (GWDG default: 1000)
					</p>
				</div>
			</div>

			<div v-if="settings.rateLimitEnabled" class="form-group">
				<label for="rate-limit-queue-message">Queue Message</label>
				<textarea
					id="rate-limit-queue-message"
					v-model="settings.rateLimitQueueMessage"
					rows="4"
					placeholder="⏳ Your request has been queued. Position: {position}, estimated wait: ~{wait} seconds." />
				<p class="hint">
					Message shown to users when their request is queued. Use <code>{position}</code> for queue position and <code>{wait}</code> for estimated wait time in seconds. Leave empty for default message.
				</p>
			</div>

			<div v-if="settings.rateLimitEnabled" class="rate-limit-status">
				<div class="button-row">
					<button
						type="button"
						class="button"
						:disabled="rateLimitLoading"
						@click="loadRateLimitStatus">
						{{ rateLimitLoading ? 'Loading…' : 'Refresh Status' }}
					</button>
					<button
						type="button"
						class="button primary"
						:disabled="queueProcessing || !chatQueueStats.pending"
						@click="processQueueNow">
						{{ queueProcessing ? 'Processing…' : 'Process Queue Now' }}
					</button>
				</div>
				<div v-if="rateLimitStatus" class="status-grid">
					<div class="status-card">
						<div class="status-value">{{ chatQueueStats.pending }}</div>
						<div class="status-label">Queued</div>
					</div>
					<div class="status-card">
						<div class="status-value">{{ chatQueueStats.processing }}</div>
						<div class="status-label">Processing</div>
					</div>
				</div>
				<p v-if="rateLimitStatus" class="hint rate-limit-hint">
					Chat requests and embeddings are tracked separately. The cards below show the last observed provider headers per endpoint.
				</p>
				<div v-if="rateLimitStatus" class="rate-limit-subsection">
					<div class="rate-limit-subsection__header">
						<h5>Chat / Queue</h5>
						<p class="hint">Used for bot replies and queued requests.</p>
					</div>
					<div class="status-grid status-grid--compact">
						<div class="status-card" :class="chatCanProcess ? 'status-ok' : 'status-limited'">
							<div class="status-value">{{ chatCanProcess ? '✓' : '⏳' }}</div>
							<div class="status-label">{{ chatCanProcess ? 'Available' : 'Rate Limited' }}</div>
						</div>
						<div v-if="chatRateLimitState?.limit_minute" class="status-card">
							<div class="status-value">{{ chatRateLimitState.remaining_minute }}/{{ chatRateLimitState.limit_minute }}</div>
							<div class="status-label">Per Minute</div>
						</div>
						<div v-if="chatRateLimitState?.limit_second" class="status-card">
							<div class="status-value">{{ chatRateLimitState.remaining_second }}/{{ chatRateLimitState.limit_second }}</div>
							<div class="status-label">Per Second</div>
						</div>
						<div v-if="chatRateLimitState?.limit_hour" class="status-card">
							<div class="status-value">{{ chatRateLimitState.remaining_hour }}/{{ chatRateLimitState.limit_hour }}</div>
							<div class="status-label">Per Hour</div>
						</div>
						<div v-if="chatRateLimitState?.limit_day" class="status-card">
							<div class="status-value">{{ chatRateLimitState.remaining_day }}/{{ chatRateLimitState.limit_day }}</div>
							<div class="status-label">Per Day</div>
						</div>
					</div>
				</div>
				<div v-if="rateLimitStatus" class="rate-limit-subsection">
					<div class="rate-limit-subsection__header">
						<h5>Embeddings</h5>
						<p class="hint">Embeddings are tracked independently from the chat queue.</p>
					</div>
					<p v-if="embeddingRateLimitModeStatus === 'disabled'" class="hint">
						Embedding rate limiting is disabled. Requests go straight to the configured embedding endpoint.
					</p>
					<p v-else-if="embeddingStatusSource === 'configured'" class="hint">
						{{ embeddingConfiguredHint }}
					</p>
					<p v-else-if="embeddingStatusSource === 'observed'" class="hint">
						Showing the last observed embedding endpoint headers.
					</p>
					<div v-if="embeddingRateLimitModeStatus !== 'disabled' && embeddingRateLimitState" class="status-grid status-grid--compact">
						<div class="status-card" :class="embeddingCanProcess ? 'status-ok' : 'status-limited'">
							<div class="status-value">{{ embeddingCanProcess ? '✓' : '⏳' }}</div>
							<div class="status-label">{{ embeddingCanProcess ? 'Available' : 'Rate Limited' }}</div>
						</div>
						<div v-if="embeddingRateLimitState.limit_minute" class="status-card">
							<div class="status-value">{{ embeddingRateLimitState.remaining_minute }}/{{ embeddingRateLimitState.limit_minute }}</div>
							<div class="status-label">Per Minute</div>
						</div>
						<div v-if="embeddingRateLimitState.limit_second" class="status-card">
							<div class="status-value">{{ embeddingRateLimitState.remaining_second }}/{{ embeddingRateLimitState.limit_second }}</div>
							<div class="status-label">Per Second</div>
						</div>
						<div v-if="embeddingRateLimitState.limit_hour" class="status-card">
							<div class="status-value">{{ embeddingRateLimitState.remaining_hour }}/{{ embeddingRateLimitState.limit_hour }}</div>
							<div class="status-label">Per Hour</div>
						</div>
						<div v-if="embeddingRateLimitState.limit_day" class="status-card">
							<div class="status-value">{{ embeddingRateLimitState.remaining_day }}/{{ embeddingRateLimitState.limit_day }}</div>
							<div class="status-label">Per Day</div>
						</div>
					</div>
					<p v-else-if="embeddingRateLimitModeStatus !== 'disabled'" class="hint">
						No embedding rate-limit headers observed yet.
					</p>
				</div>
			</div>
				</div>
			</section>

			<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('memory') }">
				<button
					type="button"
					class="accordion-header"
					:aria-expanded="String(isSectionOpen('memory'))"
					:aria-controls="sectionId('memory')"
					@click="toggleSection('memory')">
					<span class="accordion-heading">
						<span class="accordion-title">Conversation Memory</span>
						<span class="accordion-description">Context history budget for bot replies.</span>
					</span>
					<span class="accordion-meta">{{ memorySummary }}</span>
					<span class="accordion-chevron" aria-hidden="true">›</span>
				</button>
				<div
					v-show="isSectionOpen('memory')"
					:id="sectionId('memory')"
					class="accordion-panel">
					<div class="section-heading">
						<h4>Conversation Memory</h4>
						<p class="hint">
							Configure how much conversation history is sent to the LLM for context.
							Using token-based limits prevents context window overflow with large messages.
						</p>
					</div>

					<div class="form-group">
				<label for="conversation-context-tokens">Context Token Limit</label>
				<input
					id="conversation-context-tokens"
					v-model.number="settings.conversationContextTokens"
					type="number"
					min="1000"
					step="500"
					placeholder="8000">
				<p class="hint">
					Maximum tokens of conversation history to include. Default: 8000 (safe for most models).
					Higher values provide more context but may exceed model limits. Common limits:
					Llama 3.1/3.3: 128K, Qwen 3: 32K-128K, Mistral: 32K-128K.
				</p>
			</div>
				</div>
			</section>

			<button type="submit" class="button primary" :disabled="saving">
				{{ saving ? 'Saving...' : 'Save Settings' }}
			</button>
		</form>

		<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('tools') }">
			<button
				type="button"
				class="accordion-header"
				:aria-expanded="String(isSectionOpen('tools'))"
				:aria-controls="sectionId('tools')"
				@click="toggleSection('tools')">
				<span class="accordion-heading">
					<span class="accordion-title">Agent Tools</span>
					<span class="accordion-description">Model Context Protocol tool registry.</span>
				</span>
				<span class="accordion-meta">{{ toolsSummary }}</span>
				<span class="accordion-chevron" aria-hidden="true">›</span>
			</button>
			<div
				v-show="isSectionOpen('tools')"
				:id="sectionId('tools')"
				class="accordion-panel">
				<div class="section-heading">
					<h4>Agent Tool Registry</h4>
				<button
					type="button"
					class="button primary"
					@click="openCreateTool">
					Register Tool
				</button>
			</div>
			<p class="hint section-body">
				Register Model Context Protocol endpoints. Enabled tools become available for bot creators to attach to their assistants.
			</p>
			<div v-if="loadingTools" class="hint section-body">
				Loading tools…
			</div>
			<template v-else>
				<div v-if="tools.length === 0" class="hint section-body">
					No tools registered yet.
				</div>
				<div v-else class="tool-list section-body">
					<div
						v-for="tool in tools"
						:key="tool.id"
						class="tool-row"
						:class="{ 'tool-row--disabled': !tool.enabled }">
						<div class="tool-info">
							<div class="tool-name">
								<span class="tool-name__label">{{ tool.name }}</span>
								<span class="badge" :class="tool.enabled ? 'badge-success' : 'badge-muted'">
									{{ tool.enabled ? 'Enabled' : 'Disabled' }}
								</span>
							</div>
							<p v-if="tool.description" class="hint">
								{{ tool.description }}
							</p>
							<p class="hint">
								Endpoint: <code>{{ tool.mcp_endpoint_url }}</code>
							</p>
						</div>
						<div class="tool-actions">
							<button
								type="button"
								class="button"
								:disabled="toolSaving"
								@click="toggleToolEnabled(tool)">
								{{ tool.enabled ? 'Disable' : 'Enable' }}
							</button>
							<button
								type="button"
								class="button"
								:disabled="toolSaving"
								@click="editTool(tool)">
								Edit
							</button>
							<button
								type="button"
								class="button danger"
								:disabled="toolSaving"
								@click="deleteTool(tool)">
								Delete
							</button>
						</div>
					</div>
				</div>
			</template>

			<div v-if="toolFormVisible" class="tool-form">
				<h4>{{ toolForm.id ? 'Edit Tool' : 'Register Tool' }}</h4>
				<form @submit.prevent="saveTool">
					<div class="form-group">
						<label for="tool-name">Tool Name</label>
						<input
							id="tool-name"
							v-model="toolForm.name"
							type="text"
							required>
					</div>
					<div class="form-group">
						<label for="tool-endpoint">MCP Endpoint URL</label>
						<input
							id="tool-endpoint"
							v-model="toolForm.mcpEndpointUrl"
							type="url"
							name="educai-mcp-endpoint"
							autocomplete="off"
							required>
						<p class="hint">
							HTTP or WebSocket URL exposing the MCP server.
						</p>
					</div>
					<div class="form-group">
						<label for="tool-description">Description</label>
						<textarea
							id="tool-description"
							v-model="toolForm.description"
							rows="3"
							placeholder="Short human-readable summary" />
					</div>
					<div class="form-group">
						<label for="tool-auth">Authentication JSON</label>
						<textarea
							id="tool-auth"
							v-model="toolForm.authentication"
							rows="4"
							placeholder="{&quot;headers&quot;:{&quot;Authorization&quot;:&quot;Bearer &lt;token&gt;&quot;}}" />
						<p class="hint">
							Provide JSON describing auth headers or tokens. {{ toolHasStoredAuth ? 'Stored credentials are kept unless you enter new values (re-enter to test or rotate).' : 'Leave blank when no authentication is required.' }}
						</p>
					</div>
					<div class="form-group">
						<label class="checkbox">
							<input
								v-model="toolForm.enabled"
								type="checkbox">
							Enabled by default
						</label>
					</div>
					<div class="button-row">
						<button
							type="submit"
							class="button primary"
							:disabled="toolSaving">
							{{ toolForm.id ? 'Save Changes' : 'Create Tool' }}
						</button>
						<button
							type="button"
							class="button"
							:disabled="toolSaving"
							@click="closeToolForm">
							Cancel
						</button>
						<button
							type="button"
							class="button"
							:disabled="toolTesting"
							@click="testToolConnection">
							{{ toolTesting ? 'Testing…' : 'Test Connection' }}
						</button>
					</div>
				</form>
				<pre v-if="toolTestResult" class="tool-test-result">
					{{ toolTestResult }}
				</pre>
			</div>
			</div>
		</section>

		<section class="accordion-section" :class="{ 'accordion-section--open': isSectionOpen('bots') }">
			<button
				type="button"
				class="accordion-header"
				:aria-expanded="String(isSectionOpen('bots'))"
				:aria-controls="sectionId('bots')"
				@click="toggleSection('bots')">
				<span class="accordion-heading">
					<span class="accordion-title">All Bots</span>
					<span class="accordion-description">Admin overview and direct bot maintenance.</span>
				</span>
				<span class="accordion-meta">{{ botsSummary }}</span>
				<span class="accordion-chevron" aria-hidden="true">›</span>
			</button>
			<div
				v-show="isSectionOpen('bots')"
				:id="sectionId('bots')"
				class="accordion-panel">
				<div class="section-heading">
					<h4>All Bots</h4>
				<div class="actions">
					<button
						type="button"
						class="button"
						:disabled="loadingAllBots"
						@click="loadAllBots">
						{{ loadingAllBots ? 'Loading…' : 'Refresh' }}
					</button>
				</div>
			</div>
			<div v-if="loadingAllBots" class="hint">Loading bots…</div>
			<div v-else-if="allBots.length === 0" class="hint">No bots found.</div>
			<div v-else class="bot-table">
				<div class="bot-row bot-row--header">
					<span>Name</span>
					<span>Mention</span>
					<span>Owner</span>
					<span>Visibility</span>
					<span>Status</span>
					<span class="actions-col">Actions</span>
				</div>
				<div v-for="bot in allBots" :key="bot.id" class="bot-row">
					<span>{{ bot.bot_name }}</span>
					<span>{{ bot.mention_name }}</span>
					<span>{{ bot.user_id }}</span>
					<span>{{ formatVisibility(bot.visibility) }}</span>
					<span class="status-pill" :class="`status-${bot.approval_status || 'approved'}`">
						{{ bot.approval_status || 'approved' }}
					</span>
					<span class="actions-col">
						<button type="button" class="button" @click="editBotAdmin(bot)">Edit</button>
						<button type="button" class="button danger" @click="deleteBotAdmin(bot)">Delete</button>
					</span>
				</div>
			</div>
			</div>
		</section>

		<BotForm
			v-if="adminEditingBot"
			:bot="adminEditingBot"
			:user-permissions="{ isAdmin: true, isGroupAdmin: true, isTeamAdmin: true, hasApprovalRights: true, adminGroups: [], adminTeams: [] }"
			@save="saveBotAdmin"
			@cancel="closeAdminEditor" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { APP_DISPLAY_NAME } from '../branding.js'
import { generateUrl, imagePath } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import BotForm from './BotForm.vue'
import { applyEducAiRuntimeIconPayload } from '../utils/appIconRuntime.js'

export default {
	name: 'AdminSettings',
	components: {
		BotForm,
	},
	data() {
		return {
			APP_DISPLAY_NAME,
			saving: false,
			settings: {
				apiKey: '',
				apiProvider: 'custom',
				apiEndpoint: '',
				secondaryApiEndpoint: '',
				secondaryApiKey: '',
				defaultModel: 'llama-3.3-70b-instruct',
				defaultTemperature: 0.2,
				fallbackModel: '',
				appIconUrl: '',
				appIconMode: 'default',
				appIconBlackUrl: '',
				appIconWhiteUrl: '',
				llmChatTimeout: 90,
				llmStreamTimeout: 240,
				llmModelsTimeout: 20,
				webhookSecret: '',
				allowMultipleModels: false,
				allowedModels: [],
				embeddingApiEndpoint: '',
				embeddingApiKey: '',
				embeddingModel: '',
				embeddingRateLimitMode: 'inherit',
				embeddingRateLimitSecond: null,
				embeddingRateLimitMinute: 100,
				embeddingRateLimitHour: 2000,
				embeddingRateLimitDay: 4000,
				ragChunkSize: 750,
				ragChunkOverlap: 50,
				ragEnabled: false,
				catalogueEnabled: false,
				catalogueApiEndpoint: '',
				catalogueReindexHours: 24,
				doclingEnabled: false,
				doclingApiEndpoint: '',
				doclingApiKey: '',
				visionApiEndpoint: '',
				visionApiKey: '',
				visionModel: '',
				speechApiEndpoint: '',
				speechApiKey: '',
				speechModel: '',
				rateLimitEnabled: false,
				rateLimitSecond: null,
				rateLimitMinute: 30,
				rateLimitHour: 200,
				rateLimitDay: 1000,
				rateLimitQueueMessage: '',
				conversationContextTokens: 8000,
			},
			openSections: {
				appearance: true,
				essentials: true,
				modelEndpoints: false,
				fallbackTimeouts: false,
				rag: false,
				docling: false,
				media: false,
				limits: false,
				memory: false,
				tools: false,
				bots: false,
			},
			availableModels: [],
			availableModelOptions: [],
			loadingModels: false,
			modelLoadError: '',
			modelSelectSize: 12,
			loadingTools: false,
			toolSaving: false,
			toolTesting: false,
			toolFormVisible: false,
			tools: [],
			toolForm: {
				id: null,
				name: '',
				mcpEndpointUrl: '',
				description: '',
				authentication: '',
				enabled: false,
			},
			toolHasStoredAuth: false,
			toolTestResult: '',
			doclingTesting: false,
			doclingTestResult: null,
			doclingHasStoredApiKey: false,
			visionTesting: false,
			visionTestResult: null,
			speechTesting: false,
			speechTestResult: null,
			rateLimitLoading: false,
			rateLimitStatus: null,
			queueProcessing: false,
			globalEmbeddingReindexing: false,
			embeddingConfigBaseline: {
				apiProvider: 'custom',
				apiEndpoint: '',
				embeddingApiEndpoint: '',
				embeddingModel: '',
				hasApiKey: false,
				hasEmbeddingApiKey: false,
			},
			loadingAllBots: false,
			allBots: [],
			adminEditingBot: null,
			uploadingAppIconVariant: '',
			appIconUploadUrls: {
				black: '',
				white: '',
			},
			appIconPreviewUrls: {
				black: '',
				white: '',
			},
			appIconPreviewVersion: {
				black: 0,
				white: 0,
			},
		}
	},
	computed: {
		appIconSummary() {
			if (this.settings.appIconMode === 'custom') {
				return 'Custom'
			}
			return 'Default'
		},
		appIconBlackPreviewUrl() {
			return this.resolveAppIconPreviewUrl('black')
		},
		appIconWhitePreviewUrl() {
			return this.resolveAppIconPreviewUrl('white')
		},
		hasPendingEmbeddingConfigChange() {
			const payload = {
				apiKey: this.settings.apiKey || '',
				apiProvider: this.settings.apiProvider || 'custom',
				apiEndpoint: this.settings.apiEndpoint || '',
				embeddingApiEndpoint: (this.settings.embeddingApiEndpoint || '').trim(),
				embeddingApiKey: this.settings.embeddingApiKey ? this.settings.embeddingApiKey : null,
				embeddingModel: (this.settings.embeddingModel || '').trim(),
			}
			return this.hasEmbeddingConfigChange(payload)
		},
		chatQueueStats() {
			return this.rateLimitStatus?.queue_stats || {
				pending: 0,
				processing: 0,
				completed: 0,
				failed: 0,
				total: 0,
			}
		},
		chatRateLimitState() {
			return this.rateLimitStatus?.chat_status?.state || this.rateLimitStatus?.state || null
		},
		chatCanProcess() {
			if (typeof this.rateLimitStatus?.chat_status?.can_process === 'boolean') {
				return this.rateLimitStatus.chat_status.can_process
			}
			return !!this.rateLimitStatus?.can_process
		},
		embeddingRateLimitState() {
			return this.rateLimitStatus?.embedding_status?.state || null
		},
		embeddingRateLimitModeStatus() {
			return this.rateLimitStatus?.embedding_status?.mode || this.settings.embeddingRateLimitMode || 'inherit'
		},
		embeddingStatusSource() {
			return this.rateLimitStatus?.embedding_status?.source || 'configured'
		},
		embeddingConfiguredHint() {
			if (this.embeddingRateLimitModeStatus === 'custom') {
				return 'Using the configured custom embedding limits until the provider returns dedicated headers.'
			}
			return 'Using inherited chat limits until the embedding endpoint returns its own rate-limit headers.'
		},
		embeddingCanProcess() {
			if (typeof this.rateLimitStatus?.embedding_status?.can_process === 'boolean') {
				return this.rateLimitStatus.embedding_status.can_process
			}
			return false
		},
		doclingKeyHint() {
			if (this.doclingHasStoredApiKey) {
				return 'A stored Docling key is configured and kept unless you enter a new value.'
			}
			return 'Leave blank to reuse the main API key.'
		},
		essentialsSummary() {
			return `Temp ${this.formatTemperature(this.settings.defaultTemperature)}`
		},
		modelEndpointsSummary() {
			if (this.settings.allowMultipleModels) {
				return `${this.settings.allowedModels.length} allowed model(s)`
			}
			if ((this.settings.secondaryApiEndpoint || '').trim() !== '') {
				return 'Primary + Secondary'
			}
			return this.formatModelLabel(this.settings.defaultModel) || 'Primary only'
		},
		fallbackTimeoutSummary() {
			const fallback = this.formatModelLabel(this.settings.fallbackModel)
			return fallback || `${this.settings.llmChatTimeout || 90}/${this.settings.llmStreamTimeout || 240}/${this.settings.llmModelsTimeout || 20}s`
		},
		modelOptionMap() {
			return this.availableModelOptions.reduce((map, option) => {
				map[option.id] = option
				return map
			}, {})
		},
		ragSummary() {
			if (this.hasPendingEmbeddingConfigChange) {
				return 'Changed - reindex needed'
			}
			return this.settings.ragEnabled ? (this.settings.embeddingModel || 'Enabled') : 'Disabled'
		},
		doclingSummary() {
			return this.settings.doclingEnabled ? 'Enabled' : 'Disabled'
		},
		mediaSummary() {
			const enabled = []
			if (this.settings.visionModel) {
				enabled.push('Vision')
			}
			if (this.settings.speechModel) {
				enabled.push('Speech')
			}
			return enabled.length ? enabled.join(' + ') : 'No models'
		},
		rateLimitSummary() {
			if (!this.settings.rateLimitEnabled) {
				return 'Disabled'
			}
			return `${this.chatQueueStats.pending} queued`
		},
		memorySummary() {
			return `${this.settings.conversationContextTokens || 8000} tokens`
		},
		toolsSummary() {
			if (this.loadingTools) {
				return 'Loading'
			}
			return `${this.tools.length} registered`
		},
		botsSummary() {
			if (this.loadingAllBots) {
				return 'Loading'
			}
			return `${this.allBots.length} bot(s)`
		},
	},
	mounted() {
		this.loadSettings()
		this.loadTools()
		this.loadAllBots()
	},
	methods: {
		isSectionOpen(key) {
			return !!this.openSections[key]
		},
		sectionId(key) {
			return `educai-admin-section-${key}`
		},
		toggleSection(key) {
			if (Object.prototype.hasOwnProperty.call(this.openSections, key)) {
				this.openSections[key] = !this.openSections[key]
			}
		},
		normalizeTemperatureValue(value) {
			const numeric = Number(value)
			if (!Number.isFinite(numeric) || numeric < 0 || numeric > 1) {
				return null
			}

			return Math.round(numeric * 100) / 100
		},
		normalizeAppIconUrl(value) {
			const url = (value || '').trim()
			if (url === '') {
				return ''
			}
			if (url.length > 1024) {
				return null
			}
			if (this.isUploadedAppIconReference(url)) {
				return this.isValidUploadedAppIconReference(url) ? url : null
			}
			if (/^https?:\/\//i.test(url)) {
				try {
					const parsed = new URL(url)
					return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? url : null
				} catch {
					return null
				}
			}
			if (url.startsWith('/') && !url.startsWith('//')) {
				return url
			}
			return null
		},
		isUploadedAppIconReference(value) {
			return /^educai-upload:\/\/(black|white)$/i.test(String(value || '').trim())
		},
		isServerBackedAppIconReference(value) {
			return this.isUploadedAppIconReference(value)
		},
		isValidUploadedAppIconReference(value) {
			try {
				const parsed = new URL(String(value || '').trim())
				return parsed.protocol === 'educai-upload:' && ['black', 'white'].includes(parsed.hostname)
			} catch {
				return false
			}
		},
		normalizeLoadedAppIconValue(value) {
			const raw = String(value || '').trim()
			return /^nextcloud-file(id)?:\/\//i.test(raw) ? '' : raw
		},
		triggerAppIconUpload(variant) {
			const refName = variant === 'white' ? 'appIconWhiteFileInput' : 'appIconBlackFileInput'
			const input = this.$refs[refName]
			if (input) {
				input.value = ''
				input.click()
			}
		},
		async uploadAppIconSvg(variant, event) {
			const input = event?.target
			const file = input?.files?.[0] || null
			if (!file) {
				return
			}

			if (!this.isLocalSvgUpload(file)) {
				showError('Please upload an SVG file up to 1 MB')
				input.value = ''
				return
			}

			this.uploadingAppIconVariant = variant
			try {
				const formData = new FormData()
				formData.append('icon', file)
				const response = await axios.post(
					this.resolveAppIconUploadUrl(variant),
					formData,
				)
				const value = response.data?.value || `educai-upload://${variant}`
				if (variant === 'white') {
					this.settings.appIconWhiteUrl = value
				} else {
					this.settings.appIconBlackUrl = value
				}
				this.appIconPreviewVersion[variant] = Date.now()
				showSuccess('App icon SVG uploaded')
			} catch (error) {
				console.error('Failed to upload app icon SVG:', error)
				showError(error?.response?.data?.error || 'Failed to upload app icon SVG')
			} finally {
				this.uploadingAppIconVariant = ''
				input.value = ''
			}
		},
		isLocalSvgUpload(file) {
			return file
				&& file.size > 0
				&& file.size <= 1024 * 1024
				&& (file.type === 'image/svg+xml' || file.name.toLowerCase().endsWith('.svg'))
		},
		resolveAppIconUploadUrl(variant) {
			return this.appIconUploadUrls[variant] || generateUrl(`/apps/educai/api/v1/admin/app-icon-upload/${variant}`)
		},
		setAppIconMode(mode) {
			if (!['default', 'custom'].includes(mode)) {
				return
			}
			this.settings.appIconMode = mode
			if (mode === 'default') {
				this.settings.appIconBlackUrl = ''
				this.settings.appIconWhiteUrl = ''
			}
		},
		resetAppIconConfig() {
			this.settings.appIconMode = 'default'
			this.settings.appIconBlackUrl = ''
			this.settings.appIconWhiteUrl = ''
			this.settings.appIconUrl = ''
		},
		resolveAppIconPreviewUrl(variant) {
			if (this.settings.appIconMode === 'default') {
				return imagePath('educai', variant === 'white' ? 'app.svg' : 'app-dark.svg')
			}

			if (this.settings.appIconMode === 'custom') {
				const url = this.normalizeAppIconUrl(
					variant === 'white' ? this.settings.appIconWhiteUrl : this.settings.appIconBlackUrl,
				)
				if (this.isServerBackedAppIconReference(url)) {
					const version = this.appIconPreviewVersion[variant] || 0
					const previewUrl = this.appIconPreviewUrls[variant] || generateUrl(`/apps/educai/api/v1/admin/app-icon-preview/${variant}`)
					return `${previewUrl}?source=${encodeURIComponent(url)}&v=${version}`
				}

				return url || ''
			}

			return ''
		},
		buildAppIconPayload() {
			const mode = ['default', 'custom'].includes(this.settings.appIconMode)
				? this.settings.appIconMode
				: 'default'

			if (mode === 'default') {
				return {
					appIconMode: 'default',
					appIconBlackUrl: null,
					appIconWhiteUrl: null,
					appIconUrl: '',
				}
			}

			const blackUrl = this.normalizeAppIconUrl(this.settings.appIconBlackUrl)
			if (blackUrl === null) {
				showError('Black app icon must be an http(s) URL, an absolute Nextcloud path, or an uploaded SVG')
				return null
			}
			const whiteUrl = this.normalizeAppIconUrl(this.settings.appIconWhiteUrl)
			if (whiteUrl === null) {
				showError('White app icon must be an http(s) URL, an absolute Nextcloud path, or an uploaded SVG')
				return null
			}

			if (!blackUrl || !whiteUrl) {
				showError('Custom app icon mode requires both black and white icons')
				return null
			}

			return {
				appIconMode: 'custom',
				appIconBlackUrl: blackUrl,
				appIconWhiteUrl: whiteUrl,
				appIconUrl: blackUrl || '',
			}
		},
		formatTemperature(value) {
			const temperature = this.normalizeTemperatureValue(value)
			return temperature === null ? '0.20' : temperature.toFixed(2)
		},
		toEndpointModelId(model) {
			const value = (model || '').trim()
			if (value === '') {
				return ''
			}
			return /^(primary|secondary):/.test(value) ? value : `primary:${value}`
		},
		formatModelLabel(model) {
			const id = this.toEndpointModelId(model)
			if (id === '') {
				return ''
			}
			return this.modelOptionMap[id]?.label || id.replace(/^primary:/, 'Primary · ').replace(/^secondary:/, 'Secondary · ')
		},
		normalizeModelOptions(rawOptions, rawModels) {
			if (Array.isArray(rawOptions) && rawOptions.length > 0) {
				return rawOptions
					.filter((option) => option && option.id && option.model)
					.map((option) => ({
						id: String(option.id),
						label: option.label || this.formatModelLabel(option.id),
						model: String(option.model),
						endpoint: option.endpoint === 'secondary' ? 'secondary' : 'primary',
					}))
			}

			return Array.isArray(rawModels)
				? rawModels.map((model) => ({
					id: this.toEndpointModelId(String(model)),
					label: this.formatModelLabel(String(model)),
					model: String(model).replace(/^(primary|secondary):/, ''),
					endpoint: String(model).startsWith('secondary:') ? 'secondary' : 'primary',
				}))
				: []
		},
		availableModelOptionsWithCurrent(current) {
			const values = Array.isArray(current) ? current : [current]
			const options = [...this.availableModelOptions]
			const existing = new Set(options.map((option) => option.id))
			values
				.map((value) => this.toEndpointModelId(value))
				.filter((id) => id !== '' && !existing.has(id))
				.forEach((id) => {
					const endpoint = id.startsWith('secondary:') ? 'secondary' : 'primary'
					const model = id.replace(/^(primary|secondary):/, '')
					options.push({
						id,
						label: this.formatModelLabel(id),
						model,
						endpoint,
					})
					existing.add(id)
				})
			return options
		},
		applyDefaultTemperaturePreset(value) {
			this.settings.defaultTemperature = value
		},
		toOptionalPositiveInteger(value) {
			const numeric = Number(value)
			return Number.isFinite(numeric) && numeric > 0 ? numeric : null
		},
		toPositiveInteger(value, fallback) {
			const numeric = Number(value)
			return Number.isFinite(numeric) && numeric > 0 ? numeric : fallback
		},
		async loadSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/educai/api/v1/settings'))
				// Don't overwrite password fields with masked values
				const data = response.data
				this.settings.apiProvider = data.api_provider || 'custom'
				this.settings.apiEndpoint = data.api_endpoint || ''
				this.settings.secondaryApiEndpoint = data.secondary_api_endpoint || ''
				this.settings.defaultModel = this.toEndpointModelId(data.default_model || 'llama-3.3-70b-instruct')
				this.settings.defaultTemperature = this.normalizeTemperatureValue(data.default_temperature) ?? 0.2
				this.settings.fallbackModel = this.toEndpointModelId(data.fallback_model || '')
				this.appIconUploadUrls = {
					black: data.app_icon_upload_urls?.black || '',
					white: data.app_icon_upload_urls?.white || '',
				}
				this.appIconPreviewUrls = {
					black: data.app_icon_preview_urls?.black || '',
					white: data.app_icon_preview_urls?.white || '',
				}
				this.settings.appIconUrl = this.normalizeLoadedAppIconValue(data.app_icon_url || '')
				this.settings.appIconMode = data.app_icon_mode === 'custom' || data.app_icon_url ? 'custom' : 'default'
				this.settings.appIconBlackUrl = this.normalizeLoadedAppIconValue(data.app_icon_black_url || (this.settings.appIconMode === 'custom' ? data.app_icon_url || '' : ''))
				this.settings.appIconWhiteUrl = this.normalizeLoadedAppIconValue(data.app_icon_white_url || (this.settings.appIconMode === 'custom' ? data.app_icon_url || '' : ''))
				applyEducAiRuntimeIconPayload(data)
				this.settings.llmChatTimeout = typeof data.llm_chat_timeout === 'number' && data.llm_chat_timeout > 0 ? data.llm_chat_timeout : 90
				this.settings.llmStreamTimeout = typeof data.llm_stream_timeout === 'number' && data.llm_stream_timeout > 0 ? data.llm_stream_timeout : 240
				this.settings.llmModelsTimeout = typeof data.llm_models_timeout === 'number' && data.llm_models_timeout > 0 ? data.llm_models_timeout : 20
				this.settings.allowMultipleModels = !!data.allow_multiple_models
				this.settings.allowedModels = Array.isArray(data.allowed_models) ? data.allowed_models.map((model) => this.toEndpointModelId(model)) : []
				this.settings.embeddingApiEndpoint = data.embedding_api_endpoint || ''
				this.settings.embeddingModel = data.embedding_model || ''
				this.settings.embeddingRateLimitMode = data.embedding_rate_limit_mode || 'inherit'
				this.settings.embeddingRateLimitSecond = typeof data.embedding_rate_limit_second === 'number' && data.embedding_rate_limit_second > 0 ? data.embedding_rate_limit_second : null
				this.settings.embeddingRateLimitMinute = typeof data.embedding_rate_limit_minute === 'number' && data.embedding_rate_limit_minute > 0 ? data.embedding_rate_limit_minute : 100
				this.settings.embeddingRateLimitHour = typeof data.embedding_rate_limit_hour === 'number' && data.embedding_rate_limit_hour > 0 ? data.embedding_rate_limit_hour : 2000
				this.settings.embeddingRateLimitDay = typeof data.embedding_rate_limit_day === 'number' && data.embedding_rate_limit_day > 0 ? data.embedding_rate_limit_day : 4000
				this.embeddingConfigBaseline = {
					apiProvider: this.settings.apiProvider,
					apiEndpoint: this.settings.apiEndpoint,
					embeddingApiEndpoint: this.settings.embeddingApiEndpoint,
					embeddingModel: this.settings.embeddingModel,
					hasApiKey: data.api_key === '***',
					hasEmbeddingApiKey: data.embedding_api_key === '***',
				}
				this.settings.ragChunkSize = typeof data.rag_chunk_size === 'number' ? data.rag_chunk_size : 750
				this.settings.ragChunkOverlap = typeof data.rag_chunk_overlap === 'number' ? data.rag_chunk_overlap : 50
				this.settings.ragEnabled = !!data.rag_enabled
				this.settings.catalogueEnabled = !!data.catalogue_enabled
				this.settings.catalogueApiEndpoint = data.catalogue_api_endpoint || ''
				this.settings.catalogueReindexHours = typeof data.catalogue_reindex_hours === 'number' ? data.catalogue_reindex_hours : 24
				this.settings.doclingEnabled = !!data.docling_enabled
				this.settings.doclingApiEndpoint = data.docling_api_endpoint || ''
				this.doclingHasStoredApiKey = data.docling_api_key === '***'
				this.settings.visionApiEndpoint = data.vision_api_endpoint || ''
				this.settings.visionModel = data.vision_model || ''
				this.settings.speechApiEndpoint = data.speech_api_endpoint || ''
				this.settings.speechModel = data.speech_model || ''
				this.settings.rateLimitEnabled = !!data.rate_limit_enabled
				this.settings.rateLimitSecond = typeof data.rate_limit_second === 'number' && data.rate_limit_second > 0 ? data.rate_limit_second : null
				this.settings.rateLimitMinute = typeof data.rate_limit_minute === 'number' && data.rate_limit_minute > 0 ? data.rate_limit_minute : 30
				this.settings.rateLimitHour = typeof data.rate_limit_hour === 'number' && data.rate_limit_hour > 0 ? data.rate_limit_hour : 200
				this.settings.rateLimitDay = typeof data.rate_limit_day === 'number' && data.rate_limit_day > 0 ? data.rate_limit_day : 1000
				this.settings.rateLimitQueueMessage = data.rate_limit_queue_message || ''
				this.settings.conversationContextTokens = typeof data.conversation_context_tokens === 'number' ? data.conversation_context_tokens : 8000
				if (this.settings.allowMultipleModels || this.settings.secondaryApiEndpoint) {
					this.loadModels()
				}
				if (this.settings.rateLimitEnabled) {
					this.loadRateLimitStatus()
				}
				// Leave apiKey and webhookSecret empty to avoid showing masked values
			} catch (error) {
				console.error('Failed to load settings:', error)
			}
		},
		onToggleMultiple() {
			if (this.settings.allowMultipleModels && this.availableModelOptions.length === 0) {
				this.loadModels()
			}
		},
		async loadModels() {
			this.loadingModels = true
			this.modelLoadError = ''
			try {
				const resp = await axios.get(generateUrl('/apps/educai/api/v1/models'))
				this.availableModelOptions = this.normalizeModelOptions(resp.data?.model_options, resp.data?.models)
				this.availableModels = this.availableModelOptions.map((option) => option.id)
				// Adjust visible rows between 12 and 18 depending on list length
				const n = this.availableModelOptions.length
				this.modelSelectSize = Math.max(12, Math.min(18, n || 12))
				// If no selection yet, preselect the default model if present
				if (this.settings.allowedModels.length === 0 && this.settings.defaultModel) {
					if (this.availableModels.includes(this.settings.defaultModel)) {
						this.settings.allowedModels = [this.settings.defaultModel]
					}
				}
			} catch (e) {
				console.error('Failed to load models', e)
				this.modelLoadError = 'Failed to load models from provider. Check API key and endpoint.'
			} finally {
				this.loadingModels = false
			}
		},
		async saveSettings() {
			this.saving = true
			try {
				const defaultTemperature = this.normalizeTemperatureValue(this.settings.defaultTemperature)
				if (defaultTemperature === null) {
					showError('Default temperature must be between 0.0 and 1.0')
					return
				}
				const appIconPayload = this.buildAppIconPayload()
				if (appIconPayload === null) {
					return
				}

				// Build payload explicitly to avoid sending empty secret values
				const payload = {
					apiKey: this.settings.apiKey || '',
					apiEndpoint: (this.settings.apiEndpoint || '').trim(),
					secondaryApiEndpoint: (this.settings.secondaryApiEndpoint || '').trim(),
					secondaryApiKey: this.settings.secondaryApiKey ? this.settings.secondaryApiKey : null,
					defaultModel: this.toEndpointModelId(this.settings.defaultModel),
					defaultTemperature,
					fallbackModel: this.toEndpointModelId(this.settings.fallbackModel),
					...appIconPayload,
					llmChatTimeout: this.toPositiveInteger(this.settings.llmChatTimeout, 90),
					llmStreamTimeout: this.toPositiveInteger(this.settings.llmStreamTimeout, 240),
					llmModelsTimeout: this.toPositiveInteger(this.settings.llmModelsTimeout, 20),
					apiProvider: this.settings.apiProvider || 'custom',
					allowMultipleModels: this.settings.allowMultipleModels,
					allowedModels: this.settings.allowMultipleModels ? this.settings.allowedModels.map((model) => this.toEndpointModelId(model)) : null,
					embeddingApiEndpoint: (this.settings.embeddingApiEndpoint || '').trim(),
					embeddingApiKey: this.settings.embeddingApiKey ? this.settings.embeddingApiKey : null,
					embeddingModel: (this.settings.embeddingModel || '').trim(),
					embeddingRateLimitMode: this.settings.embeddingRateLimitMode || 'inherit',
					embeddingRateLimitSecond: this.toOptionalPositiveInteger(this.settings.embeddingRateLimitSecond),
					embeddingRateLimitMinute: this.toPositiveInteger(this.settings.embeddingRateLimitMinute, 100),
					embeddingRateLimitHour: this.toPositiveInteger(this.settings.embeddingRateLimitHour, 2000),
					embeddingRateLimitDay: this.toPositiveInteger(this.settings.embeddingRateLimitDay, 4000),
					ragChunkSize: Number.isFinite(this.settings.ragChunkSize) ? Number(this.settings.ragChunkSize) : null,
					ragChunkOverlap: Number.isFinite(this.settings.ragChunkOverlap) ? Number(this.settings.ragChunkOverlap) : null,
					ragEnabled: this.settings.ragEnabled,
					catalogueEnabled: this.settings.catalogueEnabled,
					catalogueApiEndpoint: (this.settings.catalogueApiEndpoint || '').trim(),
					catalogueReindexHours: Number.isFinite(this.settings.catalogueReindexHours) ? Number(this.settings.catalogueReindexHours) : 24,
					doclingEnabled: this.settings.doclingEnabled,
					doclingApiEndpoint: (this.settings.doclingApiEndpoint || '').trim(),
					doclingApiKey: this.settings.doclingApiKey ? this.settings.doclingApiKey : null,
					visionApiEndpoint: (this.settings.visionApiEndpoint || '').trim(),
					visionApiKey: this.settings.visionApiKey ? this.settings.visionApiKey : null,
					visionModel: (this.settings.visionModel || '').trim(),
					speechApiEndpoint: (this.settings.speechApiEndpoint || '').trim(),
					speechApiKey: this.settings.speechApiKey ? this.settings.speechApiKey : null,
					speechModel: (this.settings.speechModel || '').trim(),
					rateLimitEnabled: this.settings.rateLimitEnabled,
					rateLimitSecond: this.toOptionalPositiveInteger(this.settings.rateLimitSecond),
					rateLimitMinute: this.toPositiveInteger(this.settings.rateLimitMinute, 30),
					rateLimitHour: this.toPositiveInteger(this.settings.rateLimitHour, 200),
					rateLimitDay: this.toPositiveInteger(this.settings.rateLimitDay, 1000),
					rateLimitQueueMessage: (this.settings.rateLimitQueueMessage || '').trim(),
					conversationContextTokens: Number.isFinite(this.settings.conversationContextTokens) ? Number(this.settings.conversationContextTokens) : 8000,
				}
				// Only include webhookSecret when user provided a non-empty value
				if (this.settings.webhookSecret && this.settings.webhookSecret.trim() !== '') {
					payload.webhookSecret = this.settings.webhookSecret
				}

				const embeddingConfigChanged = this.hasEmbeddingConfigChange(payload)
				if (embeddingConfigChanged) {
					const proceed = confirm(
						'Embedding configuration changes detected (model/endpoint/key).\n\n'
						+ 'You must run "Reindex All Embeddings" after saving, otherwise retrieval quality will be degraded.\n\n'
						+ 'Continue saving settings?',
					)
					if (!proceed) {
						this.saving = false
						return
					}
				}

				const response = await axios.put(
					generateUrl('/apps/educai/api/v1/settings'),
					payload,
				)
				applyEducAiRuntimeIconPayload(response.data, { refreshNavigation: true })
				if (embeddingConfigChanged) {
					showSuccess('Settings saved. Run "Reindex All Embeddings" now to rebuild vectors for the new embedding configuration.')
				} else {
					showSuccess('Settings saved successfully')
				}
				// Keep baseline in sync after successful save
				const apiKeyUpdated = !!(payload.apiKey && payload.apiKey.trim() !== '')
				const secondaryApiKeyUpdated = !!(payload.secondaryApiKey && payload.secondaryApiKey.trim() !== '')
				const embeddingKeyUpdated = !!(payload.embeddingApiKey && payload.embeddingApiKey.trim() !== '')
				const doclingKeyUpdated = !!(payload.doclingApiKey && payload.doclingApiKey.trim() !== '')
				this.doclingHasStoredApiKey = this.doclingHasStoredApiKey || doclingKeyUpdated
				this.clearSecretInputs()
				this.embeddingConfigBaseline = {
					apiProvider: this.settings.apiProvider || 'custom',
					apiEndpoint: this.settings.apiEndpoint,
					embeddingApiEndpoint: this.settings.embeddingApiEndpoint,
					embeddingModel: this.settings.embeddingModel,
					hasApiKey: this.embeddingConfigBaseline.hasApiKey || apiKeyUpdated,
					hasEmbeddingApiKey: this.embeddingConfigBaseline.hasEmbeddingApiKey || embeddingKeyUpdated,
				}
				if (secondaryApiKeyUpdated) {
					this.settings.secondaryApiKey = ''
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
				showError('Failed to save settings')
			} finally {
				this.saving = false
			}
		},
		clearSecretInputs() {
			this.settings.apiKey = ''
			this.settings.secondaryApiKey = ''
			this.settings.webhookSecret = ''
			this.settings.embeddingApiKey = ''
			this.settings.doclingApiKey = ''
			this.settings.visionApiKey = ''
			this.settings.speechApiKey = ''
		},
		openCreateTool() {
			this.resetToolForm()
			this.toolFormVisible = true
		},
		resetToolForm() {
			this.toolForm = {
				id: null,
				name: '',
				mcpEndpointUrl: '',
				description: '',
				authentication: '',
				enabled: false,
			}
			this.toolHasStoredAuth = false
			this.toolTestResult = ''
		},
		closeToolForm() {
			this.resetToolForm()
			this.toolFormVisible = false
		},
		async loadTools() {
			this.loadingTools = true
			try {
				const resp = await axios.get(generateUrl('/apps/educai/api/v1/admin/tools'))
				this.tools = Array.isArray(resp.data?.tools) ? resp.data.tools : []
			} catch (error) {
				console.error('Failed to load tools', error)
				showError('Failed to load tools')
			} finally {
				this.loadingTools = false
			}
		},
		editTool(tool) {
			this.toolForm = {
				id: tool.id,
				name: tool.name,
				mcpEndpointUrl: tool.mcp_endpoint_url,
				description: tool.description || '',
				authentication: '',
				enabled: !!tool.enabled,
			}
			this.toolHasStoredAuth = tool.authentication === '***'
			this.toolFormVisible = true
			this.toolTestResult = ''
		},
		async saveTool() {
			if (!this.toolForm.name || !this.toolForm.mcpEndpointUrl) {
				showError('Tool name and endpoint are required')
				return
			}
			const authProvided = this.toolForm.authentication && this.toolForm.authentication.trim() !== ''
			let authPayload = null
			if (authProvided) {
				try {
					authPayload = JSON.parse(this.toolForm.authentication)
				} catch (error) {
					showError('Authentication must be valid JSON')
					return
				}
			}
			const payload = {
				name: this.toolForm.name,
				mcpEndpointUrl: this.toolForm.mcpEndpointUrl,
				description: this.toolForm.description || null,
				enabled: this.toolForm.enabled,
			}
			if (authProvided) {
				payload.authentication = authPayload
			} else if (!this.toolForm.id) {
				payload.authentication = null
			}
			this.toolSaving = true
			try {
				if (this.toolForm.id) {
					await axios.put(generateUrl(`/apps/educai/api/v1/admin/tools/${this.toolForm.id}`), payload)
				} else {
					await axios.post(generateUrl('/apps/educai/api/v1/admin/tools'), payload)
				}
				showSuccess('Tool saved')
				await this.loadTools()
				this.closeToolForm()
			} catch (error) {
				console.error('Failed to save tool', error)
				showError('Failed to save tool')
			} finally {
				this.toolSaving = false
			}
		},
		async deleteTool(tool) {
			this.toolSaving = true
			try {
				await axios.delete(generateUrl(`/apps/educai/api/v1/admin/tools/${tool.id}`))
				showSuccess('Tool deleted')
				await this.loadTools()
			} catch (error) {
				console.error('Failed to delete tool', error)
				showError('Failed to delete tool')
			} finally {
				this.toolSaving = false
			}
		},
		async toggleToolEnabled(tool) {
			this.toolSaving = true
			try {
				await axios.put(generateUrl(`/apps/educai/api/v1/admin/tools/${tool.id}`), {
					enabled: !tool.enabled,
				})
				await this.loadTools()
			} catch (error) {
				console.error('Failed to toggle tool', error)
				showError('Failed to update tool state')
			} finally {
				this.toolSaving = false
			}
		},
		async testToolConnection() {
			if (!this.toolForm.mcpEndpointUrl) {
				showError('Endpoint URL required to test')
				return
			}
			const authProvided = this.toolForm.authentication && this.toolForm.authentication.trim() !== ''
			let authPayload = null
			if (authProvided) {
				try {
					authPayload = JSON.parse(this.toolForm.authentication)
				} catch (error) {
					showError('Authentication must be valid JSON')
					return
				}
			}
			this.toolTestResult = ''
			this.toolTesting = true
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/tools/test'), {
					mcpEndpointUrl: this.toolForm.mcpEndpointUrl,
					authentication: authPayload,
				})
				this.toolTestResult = JSON.stringify(resp.data?.tools ?? [], null, 2)
				showSuccess('Tool connection succeeded')
			} catch (error) {
				console.error('Tool test failed', error)
				showError('Failed to test tool connection')
			} finally {
				this.toolTesting = false
			}
		},
		hasEmbeddingConfigChange(payload) {
			const nextProvider = (payload.apiProvider || 'custom').trim()
			const nextApiEndpoint = (payload.apiEndpoint || '').trim()
			const nextEmbeddingEndpoint = (payload.embeddingApiEndpoint || '').trim()
			const nextEmbeddingModel = (payload.embeddingModel || '').trim()
			const nextEffectiveEndpoint = this.resolveEffectiveEmbeddingEndpoint(nextApiEndpoint, nextEmbeddingEndpoint)

			const baseProvider = (this.embeddingConfigBaseline.apiProvider || 'custom').trim()
			const baseApiEndpoint = (this.embeddingConfigBaseline.apiEndpoint || '').trim()
			const baseEmbeddingEndpoint = (this.embeddingConfigBaseline.embeddingApiEndpoint || '').trim()
			const baseEmbeddingModel = (this.embeddingConfigBaseline.embeddingModel || '').trim()
			const baseEffectiveEndpoint = this.resolveEffectiveEmbeddingEndpoint(baseApiEndpoint, baseEmbeddingEndpoint)

			const embeddingKeyUpdated = !!(payload.embeddingApiKey && payload.embeddingApiKey.trim() !== '')
			const mainKeyUpdated = !!(payload.apiKey && payload.apiKey.trim() !== '')
			const usesMainKeyForEmbeddings = !this.embeddingConfigBaseline.hasEmbeddingApiKey && !embeddingKeyUpdated

			return (
				nextProvider !== baseProvider
				|| nextEmbeddingModel !== baseEmbeddingModel
				|| nextEffectiveEndpoint !== baseEffectiveEndpoint
				|| embeddingKeyUpdated
				|| (usesMainKeyForEmbeddings && mainKeyUpdated)
			)
		},
		resolveEffectiveEmbeddingEndpoint(apiEndpoint, embeddingApiEndpoint) {
			const embeddingEndpoint = (embeddingApiEndpoint || '').trim()
			if (embeddingEndpoint !== '') {
				return embeddingEndpoint
			}
			return (apiEndpoint || '').trim()
		},
		async reindexAllEmbeddings() {
			this.globalEmbeddingReindexing = true
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/embeddings/reindex-all'))
				if (resp.data?.success) {
					const catalogueJobs = resp.data?.queued_catalogue_jobs ?? 0
					const ragSources = resp.data?.queued_rag_sources ?? 0
					showSuccess(`Queued reindex jobs: catalogue=${catalogueJobs}, bot sources=${ragSources}`)
					this.loadCatalogueStatus()
				} else {
					showError(resp.data?.error || 'Failed to queue global embedding reindex')
				}
			} catch (error) {
				console.error('Failed to queue global embedding reindex', error)
				showError(error.response?.data?.error || 'Failed to queue global embedding reindex')
			} finally {
				this.globalEmbeddingReindexing = false
			}
		},
		formatTimestamp(timestamp) {
			if (!timestamp) return 'Never'
			const date = new Date(timestamp * 1000)
			const now = new Date()
			const diffMs = now - date
			const diffHours = Math.floor(diffMs / (1000 * 60 * 60))
			if (diffHours < 1) {
				const diffMins = Math.floor(diffMs / (1000 * 60))
				return `${diffMins} min ago`
			}
			if (diffHours < 24) {
				return `${diffHours}h ago`
			}
			const diffDays = Math.floor(diffHours / 24)
			return `${diffDays}d ago`
		},
		async testDoclingConnection() {
			this.doclingTesting = true
			this.doclingTestResult = null
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/docling/test'), {
					doclingApiEndpoint: this.settings.doclingApiEndpoint || null,
					doclingApiKey: this.settings.doclingApiKey
						|| (!this.doclingHasStoredApiKey ? this.settings.apiKey : null)
						|| null,
				})
				this.doclingTestResult = {
					success: resp.data?.success ?? false,
					error: resp.data?.error ?? null,
				}
				if (this.doclingTestResult.success) {
					showSuccess('Docling connection successful')
				} else {
					showError(this.doclingTestResult.error || 'Connection test failed')
				}
			} catch (error) {
				console.error('Docling test failed', error)
				this.doclingTestResult = {
					success: false,
					error: error.response?.data?.error || error.message || 'Connection failed',
				}
				showError('Failed to test Docling connection')
			} finally {
				this.doclingTesting = false
			}
		},
		async testVisionConnection() {
			this.visionTesting = true
			this.visionTestResult = null
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/vision/test'), {
					visionApiEndpoint: this.settings.visionApiEndpoint || null,
					visionApiKey: this.settings.visionApiKey || null,
					visionModel: (this.settings.visionModel || '').trim() || null,
				})
				this.visionTestResult = {
					success: resp.data?.success ?? false,
					error: resp.data?.error ?? null,
				}
				if (this.visionTestResult.success) {
					showSuccess('Vision connection successful')
				} else {
					showError(this.visionTestResult.error || 'Vision connection test failed')
				}
			} catch (error) {
				console.error('Vision test failed', error)
				this.visionTestResult = {
					success: false,
					error: error.response?.data?.error || error.message || 'Connection failed',
				}
				showError('Failed to test vision connection')
			} finally {
				this.visionTesting = false
			}
		},
		async testSpeechConnection() {
			this.speechTesting = true
			this.speechTestResult = null
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/speech/test'), {
					speechApiEndpoint: this.settings.speechApiEndpoint || null,
					speechApiKey: this.settings.speechApiKey || null,
					speechModel: (this.settings.speechModel || '').trim() || null,
				})
				this.speechTestResult = {
					success: resp.data?.success ?? false,
					error: resp.data?.error ?? null,
				}
				if (this.speechTestResult.success) {
					showSuccess('Speech connection successful')
				} else {
					showError(this.speechTestResult.error || 'Speech connection test failed')
				}
			} catch (error) {
				console.error('Speech test failed', error)
				this.speechTestResult = {
					success: false,
					error: error.response?.data?.error || error.message || 'Connection failed',
				}
				showError('Failed to test speech connection')
			} finally {
				this.speechTesting = false
			}
		},
		async loadRateLimitStatus() {
			this.rateLimitLoading = true
			try {
				const resp = await axios.get(generateUrl('/apps/educai/api/v1/admin/ratelimit/status'))
				this.rateLimitStatus = resp.data ?? null
			} catch (error) {
				console.error('Failed to load rate limit status', error)
				this.rateLimitStatus = null
			} finally {
				this.rateLimitLoading = false
			}
		},
		async processQueueNow() {
			this.queueProcessing = true
			try {
				const resp = await axios.post(generateUrl('/apps/educai/api/v1/admin/ratelimit/process'))
				if (resp.data?.success) {
					const processed = resp.data.processed || 0
					const remaining = resp.data.remaining || 0
					showSuccess(`Processed ${processed} request(s). ${remaining} remaining.`)
					// Refresh status
					this.loadRateLimitStatus()
				} else {
					showError(resp.data?.error || 'Failed to process queue')
				}
			} catch (error) {
				console.error('Failed to process queue', error)
				showError(error.response?.data?.error || 'Failed to process queue')
			} finally {
				this.queueProcessing = false
			}
		},
		async loadAllBots() {
			this.loadingAllBots = true
			try {
				const resp = await axios.get(generateUrl('/apps/educai/api/v1/admin/bots'))
				this.allBots = Array.isArray(resp.data) ? resp.data : []
			} catch (error) {
				console.error('Failed to load all bots', error)
				showError('Failed to load bots')
			} finally {
				this.loadingAllBots = false
			}
		},
		editBotAdmin(bot) {
			this.adminEditingBot = bot
		},
		closeAdminEditor() {
			this.adminEditingBot = null
		},
		async saveBotAdmin(botData) {
			if (!botData?.id) {
				showError('Bot id is required')
				return
			}
			try {
				await axios.put(generateUrl(`/apps/educai/api/v1/admin/bots/${botData.id}`), botData)
				showSuccess('Bot updated')
				this.adminEditingBot = null
				this.loadAllBots()
			} catch (error) {
				console.error('Failed to update bot as admin', error)
				showError(error.response?.data?.error || 'Failed to update bot')
			}
		},
		async deleteBotAdmin(bot) {
			if (!bot || !bot.id) {
				return
			}
			if (!confirm(`Delete bot "${bot.bot_name}"? This cannot be undone.`)) {
				return
			}
			try {
				await axios.delete(generateUrl(`/apps/educai/api/v1/bots/${bot.id}`))
				showSuccess('Bot deleted')
				this.loadAllBots()
			} catch (error) {
				console.error('Failed to delete bot as admin', error)
				showError(error.response?.data?.error || 'Failed to delete bot')
			}
		},
		formatVisibility(visibility) {
			if (visibility === 'global') {
				return 'Global'
			}
			if (visibility === 'personal') {
				return 'Personal'
			}
			if (visibility === 'teams') {
				return 'Teams'
			}
			return 'Groups'
		},
	},
}
</script>

<style scoped>
.admin-settings {
	margin-top: 40px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
}

.settings-title {
	margin: 0;
	padding: 16px 20px;
	background: var(--color-background-dark);
	font-size: 16px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
}

.settings-body {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.accordion-section {
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.settings-title + .settings-body .accordion-section:first-child,
.admin-settings > .accordion-section:first-of-type {
	border-top: 0;
}

.accordion-header {
	width: 100%;
	padding: 16px 20px;
	border: 0;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto auto;
	align-items: center;
	gap: 16px;
	text-align: left;
	font: inherit;
}

.accordion-header:hover,
.accordion-header:focus-visible {
	background: var(--color-background-hover);
}

.accordion-header:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: -2px;
}

.accordion-heading {
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.accordion-title {
	font-size: 15px;
	font-weight: 700;
}

.accordion-description {
	font-size: 13px;
	color: var(--color-text-lighter);
	line-height: 1.35;
}

.accordion-meta {
	max-width: 220px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	border-radius: 999px;
	padding: 3px 9px;
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
	font-size: 12px;
	font-weight: 600;
}

.accordion-meta--warning {
	background: rgba(236, 167, 0, 0.15);
	color: var(--color-main-text);
}

.accordion-chevron {
	font-size: 22px;
	line-height: 1;
	color: var(--color-text-lighter);
	transform: rotate(0deg);
	transition: transform 120ms ease;
}

.accordion-section--open .accordion-chevron {
	transform: rotate(90deg);
}

.accordion-panel {
	padding: 4px 20px 20px;
}

.settings-body .accordion-header,
.settings-body .accordion-panel {
	padding-left: 0;
	padding-right: 0;
}

.settings-body .accordion-section {
	border-radius: 6px;
	border: 1px solid var(--color-border);
}

.settings-body > .button.primary {
	align-self: flex-start;
	margin-top: 8px;
}

.form-group {
	margin-bottom: 20px;
}

.form-group label {
	display: block;
	margin-bottom: 6px;
	font-weight: 600;
}

.form-group input,
.form-group select {
	width: 100%;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: 3px;
	font-family: inherit;
	font-size: 14px;
}

.form-group input:focus,
.form-group select:focus {
	outline: none;
	border-color: var(--color-primary);
}

.hint {
	margin: 6px 0 0 0;
	font-size: 13px;
	color: var(--color-text-lighter);
}

.button.primary {
	background-color: var(--color-primary);
	color: white;
	border: none;
	padding: 10px 20px;
	border-radius: 3px;
	cursor: pointer;
	font-size: 14px;
}

.button.primary:hover:not(:disabled) {
	background-color: var(--color-primary-element-light);
}

.button.primary:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.app-icon-config {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.icon-mode-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.icon-mode-tab {
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 8px 12px;
	font: inherit;
	font-weight: 600;
	cursor: pointer;
}

.icon-mode-tab:hover,
.icon-mode-tab:focus-visible {
	border-color: var(--color-primary);
}

.icon-mode-tab--active {
	background: var(--color-primary);
	border-color: var(--color-primary);
	color: #fff;
}

.app-icon-preview-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
}

.app-icon-preview-card {
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 12px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.app-icon-preview-card--light {
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.app-icon-preview-card--dark {
	background: #111;
	color: #fff;
}

.app-icon-preview-card__label {
	font-size: 13px;
	font-weight: 700;
}

.app-icon-preview-card__frame {
	width: 52px;
	height: 52px;
	border-radius: 6px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: rgba(127, 127, 127, 0.12);
	flex: 0 0 auto;
}

.app-icon-preview-card__frame img {
	display: block;
	max-width: 34px;
	max-height: 34px;
	object-fit: contain;
}

.app-icon-preview-card__missing {
	font-size: 11px;
	font-weight: 700;
	opacity: 0.7;
}

.app-icon-mode-panel {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.app-icon-input-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	gap: 8px;
	align-items: center;
}

.app-icon-file-input {
	display: none;
}

.app-icon-actions {
	margin-bottom: 0;
}

/* Align checkbox with label text and standardize size */
.form-group label.checkbox {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
.form-group label.checkbox input[type="checkbox"] {
	width: 16px;
	height: 16px;
	margin: 0;
	vertical-align: middle;
}

/* Make multi-select taller so more models are visible */
.form-group select[multiple] {
	min-height: 240px;
}

.section-divider {
	border-top: 1px solid var(--color-border);
	margin: 28px 0 20px;
}

.section-heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 12px;
	margin: 0 0 16px;
}

.section-heading h4 {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.section-heading .hint {
	flex-basis: 100%;
	margin: 0;
}

.form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 20px;
}

.section-body {
	padding: 0 0 20px;
}

.tool-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.tool-row {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 24px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 16px;
	background: var(--color-background-dark);
}

.tool-row--disabled {
	opacity: 0.7;
}

.tool-info {
	flex: 1;
	min-width: 0;
}

.tool-name {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 6px;
}

.tool-name__label {
	font-weight: 600;
	font-size: 15px;
}

.badge {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
}

.badge-success {
	background: rgba(0, 173, 127, 0.12);
	color: #009966;
}

.badge-muted {
	background: rgba(120, 120, 120, 0.12);
	color: #555;
}

.tool-actions {
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-width: 160px;
	align-items: flex-end;
}

.button.danger {
	background: var(--color-error);
	color: #fff;
}

.button-row {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.tool-form {
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
	padding: 20px;
}

.tool-form form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.tool-form textarea {
	font-family: monospace;
}

.tool-test-result {
	margin: 16px 20px 20px;
	padding: 12px;
	border-radius: 6px;
	background: var(--color-background-dark);
	color: var(--color-text);
	max-height: 240px;
	overflow: auto;
	font-size: 12px;
}

code {
	font-family: monospace;
	font-size: 12px;
	background: rgba(0, 0, 0, 0.05);
	padding: 1px 4px;
	border-radius: 4px;
}

.success-text {
	color: var(--color-success, #46ba61);
	font-size: 13px;
}

.error-text {
	color: var(--color-error, #e53e3e);
	font-size: 13px;
}

.button-row {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 20px;
}

.supported-formats {
	margin-top: 8px;
	margin-bottom: 16px;
}

.supported-formats .hint strong {
	color: var(--color-text);
}

.embedding-warning {
	margin: -4px 0 20px;
	padding: 12px;
	border: 1px solid var(--color-warning, #eca700);
	border-radius: 6px;
	background: rgba(236, 167, 0, 0.12);
}

.embedding-warning p {
	margin: 6px 0 0;
	font-size: 13px;
	color: var(--color-main-text);
}

.rate-limit-status {
	margin: 20px 0;
}

.rate-limit-hint {
	margin-top: 16px;
}

.rate-limit-subsection {
	margin-top: 20px;
}

.rate-limit-subsection__header h5 {
	margin: 0 0 6px;
	font-size: 14px;
}

.rate-limit-subsection__header .hint {
	margin: 0;
}

.status-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 16px;
	margin-top: 16px;
}

.status-grid--compact {
	margin-top: 12px;
}

.status-card {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	text-align: center;
	background: var(--color-background-dark);
}

.status-card.status-ok {
	border-color: var(--color-success, #46ba61);
	background: rgba(70, 186, 97, 0.1);
}

.status-card.status-limited {
	border-color: var(--color-warning, #eca700);
	background: rgba(236, 167, 0, 0.1);
}

.status-value {
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 4px;
}

.status-label {
	font-size: 12px;
	color: var(--color-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.bot-table {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.bot-row {
	display: grid;
	grid-template-columns: 1.2fr 1fr 1fr 1fr 1fr 1fr;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
}

.bot-row--header {
	font-weight: 700;
	background: var(--color-background-dark);
}

.actions-col {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.temperature-presets {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 10px;
}

.status-pill {
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 12px;
	text-transform: capitalize;
	display: inline-block;
}

.status-pill.status-draft {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
}

.status-pill.status-pending {
	background: var(--color-warning);
	color: #000;
}

.status-pill.status-approved {
	background: var(--color-success);
	color: #000;
}

.status-pill.status-personal {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-dark);
}

@media (max-width: 700px) {
	.accordion-header {
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 10px;
	}

	.accordion-meta {
		grid-column: 1 / -1;
		max-width: 100%;
		width: fit-content;
	}

	.tool-row,
	.bot-row {
		grid-template-columns: 1fr;
	}

	.tool-row {
		flex-direction: column;
	}

	.app-icon-input-row {
		grid-template-columns: 1fr;
	}

	.tool-actions,
	.actions-col {
		width: 100%;
		align-items: stretch;
		justify-content: flex-start;
	}
}
</style>
