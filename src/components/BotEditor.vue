<template>
	<div class="talkai-editor"
		@input.capture="arm"
		@change.capture="arm"
		@pointerdown.capture="arm"
		@keydown.capture="arm">
		<!-- ============ Basics ============ -->
		<div v-if="section === 'basics'" :class="{ 'talkai-section': mode === 'page' }">
			<div class="talkai-field">
				<label class="talkai-label" for="talkai-bot-name">{{ t('educai', 'Name') }}</label>
				<input id="talkai-bot-name"
					v-model="formData.botName"
					class="talkai-input"
					type="text"
					maxlength="100"
					autocomplete="off"
					@input="onNameInput">
			</div>
			<div class="talkai-field">
				<label class="talkai-label" for="talkai-bot-mention">{{ t('educai', 'Mention in Talk') }}</label>
				<div class="talkai-prefix" :class="{ 'talkai-prefix--disabled': isEditing }">
					<span class="talkai-prefix__sign">@</span>
					<input id="talkai-bot-mention"
						v-model="formData.mentionName"
						class="talkai-prefix__input"
						type="text"
						maxlength="64"
						autocomplete="off"
						:disabled="isEditing"
						aria-describedby="talkai-bot-mention-hint"
						@input="mentionTouched = true">
				</div>
				<p id="talkai-bot-mention-hint" class="talkai-hint" :class="{ 'talkai-hint--error': mentionProblem }">
					<template v-if="mentionProblem">
						{{ mentionProblem }}
					</template>
					<template v-else-if="isEditing">
						{{ t('educai', 'This is how people mention the bot in a chat. It cannot be changed after creation.') }}
					</template>
					<template v-else>
						{{ t('educai', 'This is how people mention the bot in a chat: letters, numbers, hyphens and underscores. It cannot be changed after creation.') }}
					</template>
				</p>
			</div>
			<div class="talkai-field">
				<label class="talkai-label" for="talkai-bot-description">{{ t('educai', 'Short description') }}</label>
				<textarea id="talkai-bot-description"
					v-model="formData.description"
					class="talkai-input talkai-input--short"
					rows="2" />
				<p class="talkai-hint">
					{{ t('educai', 'Shown under “Discover” and in the approval.') }}
				</p>
			</div>
			<div class="talkai-field">
				<label class="talkai-label" for="talkai-bot-instruction">{{ t('educai', 'What should the bot do, and how?') }}</label>
				<textarea id="talkai-bot-instruction"
					v-model="formData.systemPrompt"
					class="talkai-input"
					rows="7" />
				<div v-if="openSuggestions.length > 0" class="talkai-suggest">
					<button v-for="suggestion in openSuggestions"
						:key="suggestion.label"
						type="button"
						class="talkai-suggest__btn"
						@click="addSuggestion(suggestion)">
						+ {{ suggestion.label }}
					</button>
				</div>
				<p class="talkai-hint">
					{{ t('educai', 'This is the instruction for the language model (system prompt). Write it the way you would explain the job to a new colleague.') }}
				</p>
			</div>
		</div>

		<!-- ============ Who uses it ============ -->
		<div v-if="section === 'audience'" :class="{ 'talkai-section': mode === 'page' }">
			<template v-if="mode === 'page'">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Who uses the bot?') }}
				</h3>
				<p class="talkai-section__lead">
					{{ t('educai', 'This decides who can use the bot and whether changes are reviewed first. It also sets the colour of the bot.') }}
				</p>
			</template>
			<div class="talkai-rcards" role="group" :aria-label="t('educai', 'Who uses the bot?')">
				<button v-for="option in audienceOptions"
					:key="option.value"
					type="button"
					class="talkai-rcard"
					:aria-pressed="String(formData.visibility === option.value)"
					@click="formData.visibility = option.value">
					<span class="talkai-rcard__icon" :class="`talkai-rcard__icon--${option.scope}`">
						<TalkAiIcon :name="option.icon" />
					</span>
					<span>
						<b class="talkai-rcard__title">{{ option.title }}</b>
						<span class="talkai-rcard__text">{{ option.text }}</span>
						<span class="talkai-rcard__tag">{{ option.tag }}</span>
					</span>
				</button>
			</div>

			<div v-if="formData.visibility === 'groups'" class="talkai-field talkai-editor__picker">
				<label class="talkai-label" for="talkai-bot-groups">{{ t('educai', 'Groups') }}</label>
				<NcSelect v-model="selectedGroups"
					input-id="talkai-bot-groups"
					class="talkai-editor__select"
					label="displayName"
					:options="groupOptions"
					:multiple="true"
					:close-on-select="false"
					:label-outside="true"
					:placeholder="t('educai', 'Search group …')" />
				<p class="talkai-hint">
					{{ t('educai', 'Only members of these groups can use the bot.') }}
				</p>
			</div>
			<div v-if="formData.visibility === 'teams'" class="talkai-field talkai-editor__picker">
				<label class="talkai-label" for="talkai-bot-teams">{{ t('educai', 'Teams') }}</label>
				<NcSelect v-model="selectedTeams"
					input-id="talkai-bot-teams"
					class="talkai-editor__select"
					label="displayName"
					:options="teamOptions"
					:multiple="true"
					:close-on-select="false"
					:label-outside="true"
					:placeholder="t('educai', 'Search team …')" />
				<p class="talkai-hint">
					{{ teamOptions.length === 0
						? t('educai', 'You are not a member of a team yet.')
						: t('educai', 'Only members of these teams can use the bot.') }}
				</p>
			</div>

			<div class="talkai-note talkai-note--info talkai-editor__audience-note">
				<TalkAiIcon name="info" />
				<div>
					<div v-if="willRequireApproval && !isEditing" class="talkai-flow talkai-editor__flow">
						<span class="talkai-flow__step is-current">{{ t('educai', 'Create') }}</span>
						<span class="talkai-flow__arrow" aria-hidden="true">→</span>
						<span class="talkai-flow__step">{{ t('educai', 'Test') }}</span>
						<span class="talkai-flow__arrow" aria-hidden="true">→</span>
						<span class="talkai-flow__step">{{ t('educai', 'Submit') }}</span>
						<span class="talkai-flow__arrow" aria-hidden="true">→</span>
						<span class="talkai-flow__step">{{ t('educai', 'Approval') }}</span>
					</div>
					<span>{{ audienceText }}</span>
				</div>
			</div>
		</div>

		<!-- ============ Knowledge ============ -->
		<div v-if="section === 'knowledge'" :class="{ 'talkai-section': mode === 'page' }">
			<div v-if="mode === 'page'" class="talkai-spread talkai-editor__knowledge-head">
				<div>
					<h3 class="talkai-section__title">
						{{ t('educai', 'Knowledge') }}
					</h3>
					<p class="talkai-muted talkai-editor__small">
						{{ knowledgeSummary }}
					</p>
				</div>
			</div>
			<div v-if="knowledgePaused && sourceCount > 0" class="talkai-note talkai-note--warn talkai-editor__gap">
				<TalkAiIcon name="info" />
				<span>
					{{ t('educai', 'The knowledge search is paused. The sources stay stored, but the bot does not use them.') }}
					<button type="button" class="talkai-linkish" @click="setKnowledgePaused(false)">{{ t('educai', 'Resume') }}</button>
				</span>
			</div>
			<div class="talkai-drop">
				<div class="talkai-drop__text">
					<span class="talkai-drop__icon"><TalkAiIcon name="upload" :size="34" /></span>
					<div>
						<b>{{ t('educai', 'Files or folders from your Nextcloud') }}</b>
						<div class="talkai-muted talkai-editor__small">
							{{ t('educai', 'PDF, Word, PowerPoint, Markdown, text · folders are kept up to date') }}
						</div>
					</div>
				</div>
				<div class="talkai-row">
					<button type="button"
						class="talkai-btn"
						:disabled="rag.adding"
						@click="chooseSources">
						<TalkAiIcon name="folder" :size="16" />
						{{ rag.adding ? t('educai', 'Adding…') : t('educai', 'Choose') }}
					</button>
					<button type="button"
						class="talkai-btn talkai-btn--ghost"
						:aria-expanded="String(urlRowOpen)"
						@click="urlRowOpen = !urlRowOpen">
						<TalkAiIcon name="link" :size="16" />
						{{ t('educai', 'Web page') }}
					</button>
				</div>
			</div>
			<form v-if="urlRowOpen" class="talkai-url-row" @submit.prevent="addUrl">
				<input v-model="urlInput"
					class="talkai-input"
					type="url"
					placeholder="https://…"
					:aria-label="t('educai', 'Web page address')"
					:disabled="rag.addingUrl">
				<button type="submit" class="talkai-btn talkai-btn--filled" :disabled="!isValidUrl || rag.addingUrl">
					{{ rag.addingUrl ? t('educai', 'Adding…') : t('educai', 'Add') }}
				</button>
			</form>

			<p v-if="isEditing && rag.loading && rag.sources.length === 0" class="talkai-hint">
				{{ t('educai', 'Loading sources…') }}
			</p>
			<ul v-else-if="sourceRows.length > 0" class="talkai-sources">
				<li v-for="row in sourceRows" :key="row.key" class="talkai-src">
					<span class="talkai-src__icon"><TalkAiIcon :name="row.icon" /></span>
					<span class="talkai-src__main">
						<b class="talkai-src__name">{{ row.name }}</b>
						<span class="talkai-src__sub" :class="{ 'talkai-warn-text': row.problem }">{{ row.sub }}</span>
						<span v-if="row.progress !== null" class="talkai-bar">
							<i class="talkai-bar__fill" :style="{ width: `${row.progress}%` }" />
						</span>
					</span>
					<span class="talkai-chip talkai-chip--dot" :class="`talkai-chip--${row.tone}`">{{ row.status }}</span>
					<span class="talkai-src__actions">
						<a v-if="row.openUrl"
							class="talkai-btn talkai-btn--icon talkai-btn--small"
							:href="row.openUrl"
							target="_blank"
							rel="noopener noreferrer"
							:aria-label="t('educai', 'Open {name}', { name: row.name })"
							:title="t('educai', 'Open')">
							<TalkAiIcon name="eye" :size="16" />
						</a>
						<button v-if="row.source"
							type="button"
							class="talkai-btn talkai-btn--icon talkai-btn--small"
							:disabled="!!rag.reindexing[row.source.id]"
							:aria-label="t('educai', 'Read {name} again', { name: row.name })"
							:title="t('educai', 'Read again')"
							@click="reindexSource(row.source)">
							<TalkAiIcon name="refresh" :size="16" />
						</button>
						<button type="button"
							class="talkai-btn talkai-btn--icon talkai-btn--small"
							:disabled="row.source ? !!rag.removing[row.source.id] : false"
							:aria-label="t('educai', 'Remove {name}', { name: row.name })"
							:title="t('educai', 'Remove')"
							@click="row.source ? removeSource(row.source) : removePendingSource(row.index)">
							<TalkAiIcon name="close" :size="16" />
						</button>
					</span>
				</li>
			</ul>
			<p v-else class="talkai-hint talkai-editor__gap">
				{{ t('educai', 'No sources yet. Without sources the bot answers from the instruction and from files in the chat.') }}
			</p>

			<div class="talkai-note talkai-note--info talkai-editor__gap">
				<TalkAiIcon name="lock" />
				<span>{{ t('educai', 'The files stay in your Nextcloud. The bot only reads what you select here. People who use the bot see its answers with the source, but get no access to the files themselves.') }}</span>
			</div>
		</div>

		<!-- ============ Capabilities ============ -->
		<div v-if="section === 'capabilities'" :class="{ 'talkai-section': mode === 'page' }">
			<template v-if="mode === 'page'">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Capabilities') }}
				</h3>
				<p class="talkai-section__lead">
					{{ t('educai', 'What the bot is allowed to do in the chat.') }}
				</p>
			</template>
			<p v-if="toolsPending" class="talkai-hint" role="status">
				{{ t('educai', 'Loading tools…') }}
			</p>
			<div v-else class="talkai-capcards">
				<div class="talkai-capc" :class="{ 'is-on': knowledgeOn }">
					<span class="talkai-capc__icon"><TalkAiIcon name="book" /></span>
					<span class="talkai-capc__text">
						<b class="talkai-capc__title">{{ knowledgeTexts.title }}</b>
						<span class="talkai-capc__sub">{{ knowledgeCardText }}</span>
					</span>
					<button type="button"
						class="talkai-switch"
						role="switch"
						disabled
						:aria-checked="String(knowledgeOn)"
						:aria-label="knowledgeTexts.title" />
				</div>
				<div v-for="card in capabilityCards"
					:key="card.key"
					class="talkai-capc"
					:class="{ 'is-on': card.on, 'is-off': !card.offered }">
					<span class="talkai-capc__icon"><TalkAiIcon :name="card.icon" /></span>
					<span class="talkai-capc__text">
						<b class="talkai-capc__title">{{ card.title }}</b>
						<span class="talkai-capc__sub">{{ card.offered ? card.text : t('educai', 'Not set up by the administration yet.') }}</span>
						<span v-if="card.recommended && card.offered" class="talkai-capc__rec">{{ t('educai', 'Recommended') }}</span>
					</span>
					<button type="button"
						class="talkai-switch"
						role="switch"
						:disabled="!card.offered"
						:aria-checked="String(card.on)"
						:aria-label="card.title"
						@click="toggleCapability(card)" />
				</div>
				<div v-for="card in toolCards"
					:key="card.key"
					class="talkai-capc"
					:class="{ 'is-on': card.on, 'is-off': card.disabled }">
					<span class="talkai-capc__icon"><TalkAiIcon name="tool" /></span>
					<span class="talkai-capc__text">
						<b class="talkai-capc__title">{{ card.tool.name }}</b>
						<span v-if="card.tool.description" class="talkai-capc__sub">{{ card.tool.description }}</span>
						<span v-if="card.blocked" class="talkai-capc__sub talkai-warn-text">
							{{ card.blocked }}
							<template v-if="card.on">{{ t('educai', 'Remove it or choose another audience before saving.') }}</template>
						</span>
						<span v-if="card.tool.api" class="talkai-capc__rec talkai-capc__rec--plain">{{ card.tool.api.connection }}</span>
					</span>
					<button type="button"
						class="talkai-switch"
						role="switch"
						:disabled="card.disabled"
						:aria-checked="String(card.on)"
						:aria-label="card.tool.name"
						@click="toggleTool(card.key)" />
				</div>
				<div v-for="tool in unavailableSelectedTools" :key="`gone-${tool.key}`" class="talkai-capc is-off">
					<span class="talkai-capc__icon"><TalkAiIcon name="alert" /></span>
					<span class="talkai-capc__text">
						<b class="talkai-capc__title">{{ tool.label }}</b>
						<span class="talkai-capc__sub">{{ t('educai', 'Still assigned, but the bot cannot use it until an administrator offers it again.') }}</span>
					</span>
					<button type="button" class="talkai-btn talkai-btn--small" @click="removeSelectedTool(tool.key)">
						{{ t('educai', 'Remove') }}
					</button>
				</div>
			</div>
			<p v-if="mode === 'wizard'" class="talkai-hint talkai-editor__gap">
				{{ t('educai', 'You find the exact tool list later on the bot page under “Advanced”.') }}
			</p>
		</div>

		<!-- ============ Test and finish (new bot) ============ -->
		<div v-if="section === 'finish'">
			<div class="talkai-sum">
				<div v-for="row in summaryRows" :key="row.step" class="talkai-sum__row">
					<span class="talkai-sum__tick" :class="{ 'talkai-sum__tick--warn': row.warn }">
						<TalkAiIcon :name="row.warn ? 'alert' : 'check'" :size="14" />
					</span>
					<span class="talkai-sum__key">{{ row.label }}</span>
					<b class="talkai-sum__value">{{ row.value }}</b>
					<button type="button" class="talkai-btn talkai-btn--ghost talkai-btn--small" @click="$emit('go-to-step', row.step)">
						{{ t('educai', 'Change') }}
					</button>
				</div>
			</div>
		</div>

		<!-- ============ Advanced: model and creativity ============ -->
		<component :is="mode === 'wizard' ? 'details' : 'div'"
			v-if="section === 'advanced' || section === 'finish'"
			:class="mode === 'wizard' ? 'talkai-disclosure' : 'talkai-section'">
			<summary v-if="mode === 'wizard'" class="talkai-disclosure__summary">
				{{ t('educai', 'Advanced') }}
				<span class="talkai-disclosure__aside">· {{ t('educai', 'Model, creativity and guests. Not needed for most bots.') }}</span>
			</summary>
			<h3 v-else class="talkai-section__title">
				{{ t('educai', 'Model') }}
			</h3>
			<div class="talkai-field talkai-model">
				<label class="talkai-label" :class="{ 'hidden-visually': mode === 'page' }" for="talkai-bot-model">{{ t('educai', 'Model') }}</label>
				<ModelSelector id="talkai-bot-model"
					v-model="formData.model"
					:options="selectableModelOptions"
					:unlisted-endpoints="unlistedEndpoints"
					:empty-label="t('educai', 'Recommended by the administration')" />
				<p v-if="!settings.allowMultipleModels" class="talkai-hint">
					{{ t('educai', 'The administration has set one model for all bots.') }}
				</p>
				<p v-else-if="settings.allowedModels.length === 0" class="talkai-hint">
					{{ t('educai', 'No additional models have been released by the administrator.') }}
				</p>
				<p v-if="modelLoadError" class="talkai-hint" role="alert">
					{{ modelLoadError }}
				</p>
			</div>
			<div class="talkai-field">
				<span id="talkai-bot-creativity" class="talkai-label">{{ t('educai', 'Creativity') }}</span>
				<div class="talkai-seg" role="group" aria-labelledby="talkai-bot-creativity">
					<button v-for="level in creativityLevels"
						:key="level.key"
						type="button"
						class="talkai-seg__btn"
						:aria-pressed="String(creativity === level.key)"
						@click="setCreativity(level)">
						{{ level.label }}
						<small v-if="level.value !== undefined" class="talkai-seg__aside">{{ formatTemperature(level.value) }}</small>
					</button>
				</div>
				<div v-if="creativity === 'custom'" class="talkai-editor__custom">
					<label class="talkai-label" for="talkai-bot-temperature">{{ t('educai', 'Temperature') }}</label>
					<input id="talkai-bot-temperature"
						v-model.number="formData.temperature"
						class="talkai-input talkai-input--narrow"
						type="number"
						min="0"
						max="1"
						step="0.05">
					<p v-if="temperatureValidationError" class="talkai-hint talkai-hint--error" role="alert">
						{{ temperatureValidationError }}
					</p>
				</div>
				<p class="talkai-hint">
					{{ creativity === 'default'
						? t('educai', 'Uses the default of the administration: {temperature}.', { temperature: inheritedTemperatureLabel })
						: t('educai', 'For bots that answer from sources, “Precise” is usually best.') }}
				</p>
			</div>
			<!-- A new bot can answer guests from the start; an existing bot has its own section below. -->
			<div v-if="mode === 'wizard'" class="talkai-field">
				<div class="talkai-spread">
					<span>
						{{ t('educai', 'Also answer guests') }}
						<span class="talkai-checks__aside">{{ t('educai', 'People who join a public conversation without an account.') }}</span>
					</span>
					<button type="button"
						class="talkai-switch"
						role="switch"
						data-talkai-guests
						:aria-checked="String(formData.allowGuests)"
						:aria-label="t('educai', 'Also answer guests')"
						@click="formData.allowGuests = !formData.allowGuests" />
				</div>
				<p class="talkai-hint">
					{{ t('educai', 'The bot answers guests in conversations where a signed-in person has activated it. Guests get the same answers as everyone else, including what the bot knows from its sources.') }}
				</p>
			</div>
		</component>

		<template v-if="section === 'advanced'">
			<!-- Exact tools -->
			<div class="talkai-section">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Exact tools') }}
				</h3>
				<p class="talkai-section__lead">
					{{ t('educai', 'Technical view of the capabilities. A change here changes the switches under “Capabilities” as well.') }}
				</p>
				<p v-if="toolsPending" class="talkai-hint" role="status">
					{{ t('educai', 'Loading tools…') }}
				</p>
				<p v-else-if="visibleTools.length === 0 && unavailableSelectedTools.length === 0" class="talkai-hint">
					{{ t('educai', 'No tools are currently enabled by the administrator.') }}
				</p>
				<div v-else class="talkai-techtools">
					<label v-for="tool in visibleTools" :key="getToolKey(tool)" class="talkai-techtools__row">
						<input v-model="formData.selectedTools"
							class="talkai-check"
							type="checkbox"
							:value="getToolKey(tool)"
							:disabled="isToolDisabled(tool)">
						<span>
							{{ tool.name }}
							<span v-if="toolVisibilityBlock(tool)" class="talkai-checks__aside talkai-warn-text">{{ toolVisibilityBlock(tool) }}</span>
						</span>
						<span class="talkai-mono talkai-muted">{{ tool.is_builtin ? tool.builtin_name : (tool.mcp_endpoint_url || 'MCP') }}</span>
					</label>
				</div>
			</div>

			<!-- Knowledge search -->
			<div class="talkai-section">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Knowledge search') }}
				</h3>
				<div class="talkai-spread talkai-editor__gap">
					<span>
						{{ t('educai', 'Pause temporarily') }}
						<span class="talkai-checks__aside">{{ sourceCount > 0
							? t('educai', 'The sources stay stored.')
							: t('educai', 'There are no sources yet. Add them under “Knowledge”.') }}</span>
					</span>
					<button type="button"
						class="talkai-switch"
						role="switch"
						:disabled="sourceCount === 0"
						:aria-checked="String(knowledgePaused && sourceCount > 0)"
						:aria-label="t('educai', 'Pause knowledge search')"
						@click="setKnowledgePaused(!knowledgePaused)" />
				</div>
			</div>

			<!-- Guests -->
			<div class="talkai-section">
				<h3 class="talkai-section__title">
					{{ t('educai', 'Guests') }}
				</h3>
				<div class="talkai-spread talkai-editor__gap">
					<span>
						{{ t('educai', 'Also answer guests') }}
						<span class="talkai-checks__aside">{{ t('educai', 'People who join a public conversation without an account.') }}</span>
					</span>
					<button type="button"
						class="talkai-switch"
						role="switch"
						data-talkai-guests
						:aria-checked="String(formData.allowGuests)"
						:aria-label="t('educai', 'Also answer guests')"
						@click="formData.allowGuests = !formData.allowGuests" />
				</div>
				<p class="talkai-hint">
					{{ t('educai', 'The bot answers guests in conversations where a signed-in person has activated it. Guests get the same answers as everyone else, including what the bot knows from its sources.') }}
				</p>
			</div>

			<!-- Bot wiki -->
			<div v-if="isWikiCapableBot" class="talkai-section">
				<div class="talkai-spread">
					<h3 class="talkai-section__title">
						{{ t('educai', 'Bot wiki') }}
					</h3>
					<button type="button"
						class="talkai-switch"
						role="switch"
						:aria-checked="String(formData.personalWikiEnabled)"
						:aria-label="t('educai', 'Bot wiki')"
						@click="toggleWiki" />
				</div>
				<p class="talkai-section__lead">
					{{ isTeamBot
						? t('educai', 'Let this team bot use a Markdown wiki in a Collective from one of the selected teams.')
						: t('educai', 'Let this personal bot maintain a visible Markdown wiki in your Nextcloud files for durable personal or bot knowledge. The wiki can be read, edited, or deleted from Files.') }}
				</p>
				<template v-if="formData.personalWikiEnabled">
					<div class="talkai-field">
						<label class="talkai-label" for="talkai-wiki-location">{{ t('educai', 'Wiki location') }}</label>
						<select id="talkai-wiki-location"
							v-model="formData.personalWikiLocation"
							class="talkai-input"
							:disabled="isTeamBot"
							@change="onPersonalWikiLocationChange">
							<option v-if="isPersonalBot" value="personal_files">
								{{ t('educai', 'Personal Files: {path}', { path: defaultPersonalWikiPath }) }}
							</option>
							<option value="collective">
								{{ t('educai', 'Existing Collective') }}
							</option>
						</select>
					</div>
					<div v-if="formData.personalWikiLocation === 'personal_files'" class="talkai-field">
						<label class="talkai-label" for="talkai-wiki-path">{{ t('educai', 'Custom path') }} <span class="talkai-label__aside">· {{ t('educai', 'optional') }}</span></label>
						<input id="talkai-wiki-path"
							v-model="formData.personalWikiPath"
							class="talkai-input"
							type="text"
							:placeholder="WIKI_ROOT_FOLDER + '/Personal Wikis/my-bot'"
							@input="markPersonalWikiChanged">
						<p class="talkai-hint">
							{{ t('educai', 'Optional. Leave blank to reuse the existing wiki or use the default for a new wiki. Paths must be relative and start with {prefix}; existing Talk AI/ and EDUC AI/ paths are also supported.', { prefix: WIKI_ROOT_FOLDER + '/' }) }}
						</p>
					</div>
					<div v-else class="talkai-field">
						<label class="talkai-label" for="talkai-wiki-collective">{{ t('educai', 'Collective') }}</label>
						<select id="talkai-wiki-collective"
							v-model="formData.personalWikiCollectiveId"
							class="talkai-input"
							:disabled="wikiLocationsLoading || filteredWikiCollectives.length === 0"
							@change="markPersonalWikiChanged">
							<option value="">
								{{ t('educai', 'Select a collective') }}
							</option>
							<option v-if="unresolvedWikiCollectiveId" :value="unresolvedWikiCollectiveId">
								{{ t('educai', 'Saved collective #{id}', { id: unresolvedWikiCollectiveId }) }}
							</option>
							<option v-for="collective in filteredWikiCollectives" :key="collective.id" :value="String(collective.id)">
								{{ collective.display_name }}
							</option>
						</select>
						<p v-if="wikiLocationsLoading" class="talkai-hint">
							{{ t('educai', 'Loading collectives…') }}
						</p>
						<p v-else-if="wikiLocationsError" class="talkai-hint">
							{{ t('educai', 'Could not load collectives. The saved selection has been preserved.') }}
							<button type="button" class="talkai-linkish" @click="loadWikiLocations">
								{{ t('educai', 'Retry') }}
							</button>
						</p>
						<p v-else-if="availableCollectives.length === 0" class="talkai-hint">
							{{ t('educai', 'No admin-owned collectives available for this account.') }}
						</p>
						<p v-else-if="filteredWikiCollectives.length === 0" class="talkai-hint">
							{{ t('educai', 'Select a team that matches one of your admin-owned collectives.') }}
						</p>
						<p v-else class="talkai-hint">
							{{ t('educai', 'Wiki pages will be written to the selected collective and visible to its members.') }}
						</p>
					</div>
				</template>
			</div>
		</template>

		<!-- ============ Greeting and starter questions ============ -->
		<details v-if="(mode === 'wizard' && section === 'basics') || (mode === 'page' && section === 'advanced')"
			class="talkai-disclosure talkai-editor__onboarding"
			:open="hasOnboardingQuestions">
			<summary class="talkai-disclosure__summary">
				{{ t('educai', 'Greeting and starter questions') }}
				<span class="talkai-disclosure__aside">· {{ t('educai', 'optional: questions the bot asks when it joins a chat') }}</span>
			</summary>
			<p class="talkai-hint talkai-editor__gap-below">
				{{ t('educai', 'People are asked these questions when they first activate the bot in a chat. The answers are stored and given to the bot as context.') }}
			</p>
			<div v-for="(question, qIndex) in formData.onboardingQuestions.questions" :key="question.id" class="talkai-question">
				<div class="talkai-spread">
					<b>{{ t('educai', 'Question {number}', { number: formatNumber(qIndex + 1) }) }} <span class="talkai-mono talkai-muted">{{ question.id }}</span></b>
					<button type="button" class="talkai-btn talkai-btn--ghost talkai-btn--small" @click="removeOnboardingQuestion(qIndex)">
						{{ t('educai', 'Remove') }}
					</button>
				</div>
				<input v-model="question.text"
					class="talkai-input"
					type="text"
					:aria-label="t('educai', 'Question text')"
					:placeholder="t('educai', 'What do you need help with?')">
				<label class="talkai-dialog__option talkai-question__free">
					<input class="talkai-check"
						type="checkbox"
						:checked="isFreeTextQuestion(question)"
						@change="setQuestionFreeText(qIndex, $event.target.checked)">
					<span>{{ t('educai', 'Free text answer (the reply is stored)') }}</span>
				</label>
				<div v-for="(answer, aIndex) in question.answers" :key="answer.id" class="talkai-question__answer">
					<span class="talkai-question__letter">{{ isFreeTextAnswer(answer) ? 'TXT' : answer.id.toUpperCase() }}</span>
					<input v-if="!isFreeTextAnswer(answer)"
						v-model="answer.text"
						class="talkai-input"
						type="text"
						:aria-label="t('educai', 'Answer text')"
						:placeholder="t('educai', 'Answer text')">
					<input v-else
						class="talkai-input"
						type="text"
						disabled
						:value="t('educai', 'Free text (user types the answer)')">
					<select v-model="answer.next" class="talkai-input talkai-question__next" :aria-label="t('educai', 'Next question')">
						<option :value="null">
							{{ t('educai', 'End') }}
						</option>
						<option v-for="target in getAvailableNextQuestions(question.id)" :key="target.id" :value="target.id">
							→ {{ target.id }}
						</option>
					</select>
					<button v-if="!isFreeTextQuestion(question) && question.answers.length > 2"
						type="button"
						class="talkai-btn talkai-btn--icon talkai-btn--small"
						:aria-label="t('educai', 'Remove answer')"
						@click="removeAnswer(qIndex, aIndex)">
						<TalkAiIcon name="close" :size="16" />
					</button>
				</div>
				<button v-if="!isFreeTextQuestion(question) && question.answers.length < 4"
					type="button"
					class="talkai-btn talkai-btn--ghost talkai-btn--small"
					@click="addAnswer(qIndex)">
					<TalkAiIcon name="plus" :size="13" />
					{{ t('educai', 'Add answer') }}
				</button>
			</div>
			<button v-if="formData.onboardingQuestions.questions.length < 15"
				type="button"
				class="talkai-btn talkai-btn--small"
				@click="addOnboardingQuestion">
				<TalkAiIcon name="plus" :size="13" />
				{{ t('educai', 'Add question') }}
			</button>
			<p v-if="onboardingValidationError" class="talkai-hint talkai-hint--error" role="alert">
				{{ onboardingValidationError }}
			</p>
		</details>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import BotForm from './BotForm.vue'
import ModelSelector from './ModelSelector.vue'
import TalkAiIcon from './TalkAiIcon.vue'
import { n, t } from '../l10n.js'
import { store } from '../store/talkAi.js'
import { getApiErrorMessage } from '../utils/apiError.js'
import { SCOPE_ICONS, reviewersOf } from '../utils/botScope.js'
import { capabilitiesOf, capabilityTexts, groupOfferedTools } from '../utils/capabilities.js'

const MENTION_PATTERN = /^[a-zA-Z0-9_-]+$/
const CREATIVITY = [0.2, 0.4, 0.6]

// What the bot form knows and does, in the layout of the app page: one section at a time, as a step of the
// "New bot" wizard or as a tab of the bot page. State, loading and the save payload come from BotForm,
// which stays the single place for that logic (the admin dialog uses it as well).
export default {
	name: 'BotEditor',
	components: { ModelSelector, NcSelect, TalkAiIcon },
	extends: BotForm,
	props: {
		// basics, audience, knowledge, capabilities, finish (wizard only) or advanced (page only)
		section: { type: String, default: 'basics' },
		// wizard: a new bot in steps; page: an existing bot with tabs
		mode: { type: String, default: 'page' },
		// Starting values of a new bot: { systemPrompt, description, visibility, capabilities }
		preset: { type: Object, default: null },
	},
	emits: ['go-to-step', 'update:dirty', 'update:preview'],

	data() {
		return {
			dirty: false,
			// Set by the first user input. Loading and normalising data must not count as a change.
			armed: false,
			mentionTouched: false,
			urlRowOpen: false,
			// Sources of a bot that does not exist yet. They are attached right after it is created.
			pendingSources: [],
			knowledgePaused: false,
			pauseKnown: false,
			presetToolsApplied: false,
			customCreativity: false,
		}
	},

	computed: {
		isOwner() {
			return !this.bot || store.state.ownBots.some(own => own.id === this.bot.id)
		},

		// --- Basics ---
		mentionProblem() {
			const mention = this.formData.mentionName
			if (this.isEditing || mention === '') {
				return ''
			}
			if (!MENTION_PATTERN.test(mention)) {
				return t('educai', 'Use only letters, numbers, hyphens and underscores.')
			}
			const taken = [...store.state.ownBots, ...store.state.publicBots]
				.some(bot => String(bot.mention_name || '').replace(/^@+/, '').toLowerCase() === mention.toLowerCase())
			return taken ? t('educai', 'Another bot already uses this mention.') : ''
		},
		openSuggestions() {
			const prompt = this.formData.systemPrompt || ''
			return [
				{ label: t('educai', 'Always cite the source'), text: t('educai', 'Always name the source of your answer.') },
				{ label: t('educai', 'Ask when unclear'), text: t('educai', 'If a question is unclear, ask before you answer.') },
				{ label: t('educai', 'Friendly tone'), text: t('educai', 'Use a friendly, informal tone.') },
				{ label: t('educai', 'Short answers'), text: t('educai', 'Keep answers short, three sentences at most.') },
			].filter(suggestion => !prompt.includes(suggestion.text))
		},

		// --- Who uses it ---
		audienceOptions() {
			const tag = needsApproval => needsApproval ? t('educai', 'Needs approval') : t('educai', 'Active right away')
			const isAdmin = !!this.userPermissions.isAdmin
			return [
				{
					value: 'personal',
					scope: 'personal',
					title: t('educai', 'Only me'),
					text: t('educai', 'For your own chats. Nobody else sees the bot.'),
					tag: tag(false),
				},
				{
					value: 'groups',
					scope: 'group',
					title: t('educai', 'Certain groups'),
					text: t('educai', 'For example a degree programme or a department.'),
					tag: tag(this.formData.visibility === 'groups' ? this.willRequireApproval : this.requiresApprovalForGroups),
				},
				{
					value: 'teams',
					scope: 'team',
					title: t('educai', 'Certain teams'),
					text: t('educai', 'For example a project or a working group.'),
					tag: tag(this.formData.visibility === 'teams' ? this.willRequireApproval : this.requiresApprovalForTeams),
				},
				{
					value: 'global',
					scope: 'everyone',
					title: t('educai', 'Everyone in this Nextcloud'),
					text: t('educai', 'Shown to everyone under “Discover”.'),
					tag: tag(!isAdmin),
				},
			].map(option => ({ ...option, icon: SCOPE_ICONS[option.scope] }))
		},
		groupOptions() {
			return this.withUnknown(this.availableGroups, this.formData.allowedGroups)
		},
		teamOptions() {
			return this.withUnknown(this.availableTeams, this.formData.allowedTeams)
		},
		selectedGroups: {
			get() {
				return this.groupOptions.filter(group => this.formData.allowedGroups.includes(group.id))
			},
			set(groups) {
				this.formData.allowedGroups = (groups ?? []).map(group => group.id)
			},
		},
		selectedTeams: {
			get() {
				return this.teamOptions.filter(team => this.formData.allowedTeams.includes(team.id))
			},
			set(teams) {
				this.formData.allowedTeams = (teams ?? []).map(team => team.id)
			},
		},
		directory() {
			return { groups: this.availableGroups, teams: this.availableTeams }
		},
		reviewers() {
			return reviewersOf(this.previewBot, this.directory)
		},
		// Whether saving sends the changes into review instead of making them live.
		saveGoesToReview() {
			if (!this.bot || this.formData.visibility === 'personal') {
				return false
			}
			if (this.bot.has_pending_changes) {
				return true
			}
			// The owner of an approved bot never approves their own change.
			return (this.bot.approval_status || 'approved') === 'approved' && (this.isOwner || this.willRequireApproval)
		},
		audienceText() {
			if (this.formData.visibility === 'personal') {
				return t('educai', 'The bot is active right away. Only you see and use it.')
			}
			if (this.isEditing && this.saveGoesToReview) {
				return t('educai', 'Changes to this bot are reviewed first. Until approval the current version stays live. Reviewed by: {reviewers}.', { reviewers: this.reviewers })
			}
			if (this.willRequireApproval) {
				return t('educai', 'Until approval only you and the reviewers can use the bot. Reviewed by: {reviewers}.', { reviewers: this.reviewers })
			}
			return t('educai', 'You administer this audience, so the bot is live for it right away.')
		},

		// --- Knowledge ---
		sourceCount() {
			return this.isEditing ? this.rag.sources.length : this.pendingSources.length
		},
		sourceProblemCount() {
			return this.rag.sources.filter(source => source.status === 'error' || source.status === 'unavailable').length
		},
		knowledgeOn() {
			return this.sourceCount > 0 && (this.isEditing ? this.formData.ragEnabled : true)
		},
		knowledgeSummary() {
			if (this.sourceCount === 0) {
				return t('educai', 'No sources')
			}
			const sources = n('educai', '%n source', '%n sources', this.sourceCount)
			return this.sourceProblemCount > 0
				? t('educai', '{sources} · {problems}', { sources, problems: n('educai', '%n not readable', '%n not readable', this.sourceProblemCount) })
				: sources
		},
		knowledgeTexts() {
			return capabilityTexts('knowledge')
		},
		knowledgeCardText() {
			if (this.sourceCount === 0) {
				return t('educai', 'Turns on by itself as soon as you add sources.')
			}
			if (this.isEditing && !this.formData.ragEnabled) {
				return t('educai', 'Paused. The sources stay stored.')
			}
			return n('educai', 'In %n source. Stays on as long as there are sources.', 'In %n sources. Stays on as long as there are sources.', this.sourceCount)
		},
		sourceRows() {
			if (!this.isEditing) {
				return this.pendingSources.map((source, index) => ({
					key: `new-${index}`,
					index,
					source: null,
					icon: source.kind === 'url' ? 'link' : (source.nodeType === 'folder' ? 'folder' : 'file'),
					name: source.label,
					sub: source.kind === 'url'
						? t('educai', 'Web page · read after the bot is created')
						: (source.nodeType === 'folder'
							? t('educai', 'Folder · read after the bot is created')
							: t('educai', 'File · read after the bot is created')),
					problem: false,
					progress: null,
					status: t('educai', 'Waiting'),
					tone: 'off',
					openUrl: null,
				}))
			}
			return this.rag.sources.map(source => this.describeSourceRow(source))
		},

		// --- Capabilities ---
		// The switches appear once the offer and the saved selection are both known:
		// a selection that arrives later would silently undo what was switched in between.
		toolsPending() {
			return this.toolLoading || this.botToolsLoading
		},
		offered() {
			return groupOfferedTools(this.availableTools)
		},
		selectedKeys() {
			return new Set(this.formData.selectedTools.map(String))
		},
		capabilityCards() {
			return this.offered.capabilities
				// A capability the administration has not set up is only worth a card if people expect it.
				.filter(capability => capability.offered || ['documents', 'images', 'voice'].includes(capability.key))
				.map(capability => ({
					...capability,
					...capabilityTexts(capability.key),
					on: capability.toolKeys.some(key => this.selectedKeys.has(String(key))),
				}))
		},
		toolCards() {
			return this.offered.extras.map(tool => {
				const key = this.getToolKey(tool)
				return {
					key,
					tool,
					on: this.selectedKeys.has(String(key)),
					disabled: this.isToolDisabled(tool),
					blocked: this.toolVisibilityBlock(tool),
				}
			})
		},
		selectedToolEntries() {
			return this.availableTools
				.filter(tool => this.selectedKeys.has(String(this.getToolKey(tool))))
				.map(tool => ({ name: tool.name, is_builtin: !!tool.is_builtin, builtin_name: tool.builtin_name ?? null }))
		},

		// --- Creativity ---
		creativityLevels() {
			return [
				{ key: 'default', label: t('educai', 'Default') },
				{ key: 'precise', label: t('educai', 'Precise'), value: CREATIVITY[0] },
				{ key: 'balanced', label: t('educai', 'Balanced'), value: CREATIVITY[1] },
				{ key: 'creative', label: t('educai', 'Creative'), value: CREATIVITY[2] },
				{ key: 'custom', label: t('educai', 'Custom value') },
			]
		},
		creativity() {
			if (!this.formData.useCustomTemperature) {
				return 'default'
			}
			const level = this.creativityLevels.find(entry => entry.value === this.normalizeTemperatureValue(this.formData.temperature))
			return this.customCreativity || !level ? 'custom' : level.key
		},

		// --- Preview and summary ---
		previewBot() {
			return {
				id: this.bot?.id ?? null,
				bot_name: this.formData.botName,
				mention_name: this.formData.mentionName,
				description: this.formData.description,
				visibility: this.formData.visibility,
				allowed_groups: this.formData.allowedGroups,
				allowed_teams: this.formData.allowedTeams,
				rag_enabled: this.knowledgeOn,
				rag_source_count: this.sourceCount,
				tools: this.selectedToolEntries,
			}
		},
		preview() {
			const abilities = capabilitiesOf(this.previewBot).map(capability => capability.label)
			let goesLive = t('educai', 'Right after creation, only for you')
			if (this.formData.visibility !== 'personal') {
				goesLive = this.willRequireApproval
					? t('educai', 'After approval by {reviewers}', { reviewers: this.reviewers })
					: t('educai', 'Right after creation')
			}
			return {
				bot: this.previewBot,
				// What the preview chat sends: the same values as saving would
				draft: {
					systemPrompt: this.formData.systemPrompt,
					model: this.toStoredModelReference(this.formData.model) || null,
					temperature: this.formData.useCustomTemperature ? this.normalizeTemperatureValue(this.formData.temperature) : null,
				},
				directory: this.directory,
				knowledge: this.knowledgeSummary,
				abilities: abilities.length > 0 ? abilities.join(' · ') : t('educai', 'Answer in the chat'),
				goesLive,
				reviewers: this.reviewers,
				requiresApproval: this.willRequireApproval,
				saveGoesToReview: this.saveGoesToReview,
				sourceCount: this.sourceCount,
				sourceProblemCount: this.sourceProblemCount,
			}
		},
		summaryRows() {
			const scope = this.audienceOptions.find(option => option.value === this.formData.visibility)
			let audience = scope.title
			if (this.formData.visibility === 'groups') {
				audience = this.selectedGroups.map(group => group.displayName).join(', ')
			} else if (this.formData.visibility === 'teams') {
				audience = this.selectedTeams.map(team => team.displayName).join(', ')
			}
			return [
				{ step: 1, label: t('educai', 'Basics'), value: `${this.formData.botName} · @${this.formData.mentionName}`, warn: this.validateStep(1) !== '' },
				{ step: 2, label: t('educai', 'Who uses it?'), value: `${audience} · ${scope.tag.toLowerCase()}`, warn: this.validateStep(2) !== '' },
				{ step: 3, label: t('educai', 'Knowledge'), value: this.knowledgeSummary, warn: false },
				{ step: 4, label: t('educai', 'Capabilities'), value: this.preview.abilities, warn: false },
			]
		},
	},

	watch: {
		formData: {
			deep: true,
			handler() {
				this.noteChange()
			},
		},
		toolConfigs: {
			deep: true,
			handler() {
				this.noteChange()
			},
		},
		dirty(dirty) {
			this.$emit('update:dirty', dirty)
		},
		preview: {
			immediate: true,
			handler(preview) {
				this.$emit('update:preview', preview)
			},
		},
	},

	methods: {
		t,

		// --- Change tracking ---
		arm() {
			this.armed = true
		},
		noteChange() {
			if (this.armed) {
				this.dirty = true
			}
		},
		initializeForm() {
			BotForm.methods.initializeForm.call(this)
			this.pauseKnown = false
			this.knowledgePaused = false
			this.customCreativity = false
			if (!this.bot && this.preset) {
				this.formData.systemPrompt = this.preset.systemPrompt ?? ''
				this.formData.description = this.preset.description ?? ''
				this.formData.visibility = this.preset.visibility ?? 'personal'
				this.applyPresetTools()
			}
			// The watchers run after this tick; only later input counts as a change.
			this.$nextTick(() => {
				this.armed = false
				this.dirty = false
			})
		},
		/** Discard unsaved input and show the saved bot again. */
		reset() {
			this.initializeForm()
		},
		/** Stop asking the server about this bot, e.g. right before it is deleted. */
		stopLoading() {
			this.stopPolling()
			this.botToolsRequestId++
			this.ragSourcesRequestId++
		},

		// --- Loading ---
		async loadAvailableTools() {
			await BotForm.methods.loadAvailableTools.call(this)
			this.applyPresetTools()
		},
		// A new bot starts with the capabilities of its template; "Documents in chat" is on unless the template says otherwise.
		applyPresetTools() {
			if (this.bot || this.presetToolsApplied || this.availableTools.length === 0) {
				return
			}
			const wanted = this.preset?.capabilities ?? ['documents']
			const keys = this.offered.capabilities
				.filter(capability => wanted.includes(capability.key))
				.flatMap(capability => capability.toolKeys)
			this.formData.selectedTools = [...new Set([...this.formData.selectedTools, ...keys])]
			this.presetToolsApplied = true
		},
		async loadRagSources() {
			await BotForm.methods.loadRagSources.call(this)
			if (!this.pauseKnown && !this.rag.loading) {
				// Sources that the bot does not search were paused on purpose.
				this.knowledgePaused = this.rag.sources.length > 0 && !this.formData.ragEnabled
				this.pauseKnown = true
			}
		},

		// --- Basics ---
		onNameInput() {
			if (this.isEditing || this.mentionTouched) {
				return
			}
			this.formData.mentionName = this.formData.botName
				.toLowerCase()
				.normalize('NFD')
				.replace(/[̀-ͯ]/g, '')
				.replace(/[^a-z0-9]+/g, '')
				.slice(0, 20)
		},
		addSuggestion(suggestion) {
			const prompt = (this.formData.systemPrompt || '').trim()
			this.formData.systemPrompt = prompt === '' ? suggestion.text : `${prompt} ${suggestion.text}`
		},
		withUnknown(known, selectedIds) {
			const ids = new Set(known.map(entry => entry.id))
			const unknown = selectedIds.filter(id => !ids.has(id)).map(id => ({ id, displayName: id }))
			return [...known.map(entry => ({ id: entry.id, displayName: entry.displayName || entry.id })), ...unknown]
		},

		// --- Knowledge ---
		async chooseSources() {
			if (this.isEditing) {
				const before = this.rag.sources.length
				await this.promptAddSources()
				this.afterSourcesAdded(before)
				return
			}
			const nodes = await this.pickNodes()
			for (const node of nodes ?? []) {
				const nodeId = this.extractNodeId(node)
				if (nodeId && !this.pendingSources.some(source => source.nodeId === nodeId)) {
					this.pendingSources.push({ kind: 'node', nodeId, nodeType: this.deriveNodeType(node), label: node.path })
				}
			}
		},
		async addUrl() {
			if (!this.isValidUrl) {
				return
			}
			if (this.isEditing) {
				const before = this.rag.sources.length
				await this.submitUrl()
				this.afterSourcesAdded(before)
			} else {
				const sourceUrl = this.urlInput.trim()
				if (!this.pendingSources.some(source => source.sourceUrl === sourceUrl)) {
					this.pendingSources.push({ kind: 'url', sourceUrl, label: sourceUrl.replace(/^https?:\/\//, '') })
				}
				this.urlInput = ''
			}
			this.urlRowOpen = false
		},
		// BotForm keeps its own dialog open state for links; the app page shows the field inline.
		closeUrlModal() {
			this.urlInput = ''
		},
		afterSourcesAdded(before) {
			if (this.rag.sources.length > before && !this.formData.ragEnabled && !this.knowledgePaused) {
				// The first source turns the knowledge search on; the save bar tells that this still has to be saved.
				this.armed = true
				this.formData.ragEnabled = true
			}
		},
		removePendingSource(index) {
			this.pendingSources.splice(index, 1)
		},
		setKnowledgePaused(paused) {
			this.armed = true
			this.knowledgePaused = paused
			this.formData.ragEnabled = !paused && this.sourceCount > 0
		},
		/**
		 * Attach the sources chosen before the bot existed.
		 *
		 * @param {number} botId Id of the bot that was just created
		 * @return {Promise<number>} Number of sources that could not be attached
		 */
		async attachPendingSources(botId) {
			let failed = 0
			for (const source of this.pendingSources) {
				try {
					await axios.post(
						generateUrl(`/apps/educai/api/v1/bots/${botId}/sources`),
						source.kind === 'url' ? { sourceUrl: source.sourceUrl } : { nodeId: source.nodeId, nodeType: source.nodeType },
					)
				} catch (error) {
					failed++
					console.error('Failed to attach source', error)
					showError(getApiErrorMessage(error, t('educai', 'Could not add “{name}” to the knowledge.', { name: source.label })))
				}
			}
			return failed
		},
		describeSourceRow(source) {
			const kind = source.node_type === 'url'
				? t('educai', 'Web page')
				: (source.node_type === 'folder' ? t('educai', 'Folder') : t('educai', 'File'))
			const row = {
				key: source.id,
				index: -1,
				source,
				icon: source.node_type === 'url' ? 'link' : (source.node_type === 'folder' ? 'folder' : 'file'),
				name: this.describeSource(source),
				sub: kind,
				problem: false,
				progress: null,
				status: this.formatSourceStatus(source.status),
				tone: 'off',
				openUrl: null,
			}
			if (source.node_type === 'url' && source.source_url) {
				row.openUrl = source.source_url
			} else if (Number(source.node_id) > 0) {
				row.openUrl = generateUrl(`/f/${source.node_id}`)
			}
			if (source.status === 'ready') {
				row.tone = 'ok'
				row.sub = source.last_indexed_at
					? t('educai', '{kind} · last read {date}', { kind, date: this.formatTimestamp(source.last_indexed_at) })
					: kind
				if (source.notice_message) {
					row.sub = `${row.sub} · ${source.notice_message}`
				}
			} else if (source.status === 'pending') {
				row.tone = 'info'
				row.status = t('educai', 'Reading')
				row.progress = Math.max(0, Math.min(100, Number(source.progress) || 0))
				const stage = this.formatProgressStage(source.progress_stage)
				row.sub = source.progress_total > 0
					? `${stage} (${this.formatNumber(source.progress_current)}/${this.formatNumber(source.progress_total)})`
					: stage
			} else {
				row.tone = 'warn'
				row.problem = true
				row.status = source.status === 'unavailable' ? t('educai', 'Not reachable') : t('educai', 'Not readable')
				row.sub = source.error_message || source.notice_message || t('educai', 'This source could not be read.')
			}
			return row
		},

		// --- Capabilities ---
		toggleCapability(card) {
			const keys = card.toolKeys.map(String)
			if (card.on) {
				this.formData.selectedTools = this.formData.selectedTools.filter(key => !keys.includes(String(key)))
			} else {
				this.formData.selectedTools = [...this.formData.selectedTools, ...card.toolKeys.filter(key => !this.selectedKeys.has(String(key)))]
			}
		},
		toggleTool(key) {
			if (this.selectedKeys.has(String(key))) {
				this.removeSelectedTool(this.formData.selectedTools.find(selected => String(selected) === String(key)))
			} else {
				this.formData.selectedTools = [...this.formData.selectedTools, key]
			}
		},
		toggleWiki() {
			this.formData.personalWikiEnabled = !this.formData.personalWikiEnabled
			this.markPersonalWikiChanged()
		},

		// --- Creativity ---
		setCreativity(level) {
			this.customCreativity = level.key === 'custom'
			if (level.key === 'default') {
				this.formData.useCustomTemperature = false
				return
			}
			this.formData.useCustomTemperature = true
			if (level.value !== undefined) {
				this.formData.temperature = level.value
			} else if (this.normalizeTemperatureValue(this.formData.temperature) === null) {
				this.formData.temperature = this.settings.defaultTemperature
			}
		},

		// --- Saving ---
		/**
		 * Check what a step of the wizard asks for.
		 *
		 * @param {number} step 1 (basics) or 2 (who uses it); the other steps have no required input
		 * @return {string} What is missing, empty when the step is complete
		 */
		validateStep(step) {
			if (step === 1) {
				if (this.formData.botName.trim() === '') {
					return t('educai', 'Give the bot a name.')
				}
				if (!this.isEditing && this.formData.mentionName === '') {
					return t('educai', 'Choose how the bot is mentioned in Talk.')
				}
				if (this.mentionProblem) {
					return this.mentionProblem
				}
				if ((this.formData.systemPrompt || '').trim() === '') {
					return t('educai', 'Describe what the bot should do.')
				}
			}
			if (step === 2) {
				if (this.formData.visibility === 'groups' && this.formData.allowedGroups.length === 0) {
					return t('educai', 'Choose at least one group.')
				}
				if (this.formData.visibility === 'teams' && this.formData.allowedTeams.length === 0) {
					return t('educai', 'Choose at least one team.')
				}
			}
			return ''
		},
		/**
		 * Validate and hand the payload to the parent through the `save` event.
		 *
		 * @return {boolean} False when something is missing; the reason is shown to the user
		 */
		save() {
			const problem = this.validateStep(1) || this.validateStep(2)
			if (problem) {
				showError(problem)
				return false
			}
			if (!this.isEditing) {
				this.formData.ragEnabled = this.pendingSources.length > 0
			}
			this.submitForm()
			return true
		},
	},
}
</script>
