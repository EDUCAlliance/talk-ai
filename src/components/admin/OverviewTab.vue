<template>
	<div class="ta-panel">
		<p v-if="!overview" class="ta-empty">
			{{ t('educai', 'Loading settings…') }}
		</p>
		<template v-else>
			<div v-if="overview.setup" id="educai-admin-setup" class="ta-setup">
				<div class="ta-setup__head">
					<div>
						<h3>{{ t('educai', 'Set up {app}', { app: appName }) }}</h3>
						<p>{{ t('educai', 'Four steps to the first bot answer in Nextcloud Talk.') }}</p>
					</div>
					<div class="ta-progress">
						<small>{{ t('educai', '{done} of {total} done', { done: overview.setup.doneCount, total: overview.setup.steps.length }) }}</small>
						<div class="ta-progress__bar"
							role="progressbar"
							aria-valuemin="0"
							:aria-valuemax="overview.setup.steps.length"
							:aria-valuenow="overview.setup.doneCount"
							:aria-label="t('educai', 'Setup progress')">
							<span :style="{ width: `${Math.round(overview.setup.doneCount / overview.setup.steps.length * 100)}%` }" />
						</div>
					</div>
				</div>
				<ol class="ta-steps">
					<li v-for="(step, index) in overview.setup.steps" :key="step.id" :class="stepClass(step)">
						<span class="ta-step__num" aria-hidden="true">{{ step.done ? '✓' : index + 1 }}</span>
						<div class="ta-step__text">
							<strong>{{ step.title }}</strong>
							<small>{{ step.detail }}</small>
						</div>
						<button type="button"
							class="ta-btn ta-btn--small"
							:class="{ 'ta-btn--primary': step.id === currentStep, 'ta-btn--ghost': step.done }"
							@click="admin.goTo(step.action)">
							{{ step.action.label }}
						</button>
					</li>
				</ol>
				<p class="ta-setup__auto" :class="{ 'ta-setup__auto--warn': !admin.storedSecrets.webhookSecret }">
					<span aria-hidden="true">{{ admin.storedSecrets.webhookSecret ? '✓' : '!' }}</span>
					{{ admin.storedSecrets.webhookSecret
						? t('educai', 'Talk connection set up automatically · webhook secret stored')
						: t('educai', 'No webhook secret stored yet. Set one under System.') }}
				</p>
			</div>

			<div v-if="overview.attention.length"
				class="ta-attention"
				role="region"
				:aria-label="t('educai', 'Needs attention')">
				<h3>
					<svg width="18"
						height="18"
						viewBox="0 0 24 24"
						aria-hidden="true"><path class="ta-attention__triangle" d="M12 3 2 21h20L12 3Z" /><path d="M12 10v5m0 2.5v.5"
							stroke="#fff"
							stroke-width="2.2"
							stroke-linecap="round" /></svg>
					{{ t('educai', 'Needs attention ({count})', { count: overview.attention.length }) }}
				</h3>
				<ul>
					<li v-for="item in overview.attention" :key="item.id">
						<span class="ta-attention__text">
							<span class="ta-attention__marker" :class="`ta-attention__marker--${item.tone}`" aria-hidden="true" />
							{{ item.text }}
						</span>
						<button type="button" class="ta-btn ta-btn--small" @click="admin.goTo(item.action)">
							{{ item.action.label }}
						</button>
					</li>
				</ul>
			</div>

			<template v-if="overview.setup">
				<h3 class="ta-subheading">
					{{ t('educai', 'Optional') }}
				</h3>
				<div class="ta-optional">
					<button v-for="card in optionalCards"
						:key="card.id"
						type="button"
						class="ta-optional__card"
						@click="admin.goTo(card.action)">
						<strong>{{ card.title }}</strong>
						<small>{{ card.text }}</small>
						<span>{{ t('educai', 'Set up') }} →</span>
					</button>
				</div>
			</template>
			<div v-else class="ta-cards">
				<article v-for="card in overview.cards"
					:id="`educai-admin-card-${card.id}`"
					:key="card.id"
					class="ta-card">
					<div class="ta-card__top">
						<h3 class="ta-card__title">
							<span class="ta-card__icon" aria-hidden="true">
								<svg width="18"
									height="18"
									viewBox="0 0 24 24"
									fill="currentColor"><path :d="icons[card.id]" /></svg>
							</span>
							{{ card.title }}
						</h3>
						<span class="ta-chip" :class="chipClass(card.tone)">{{ card.chip }}</span>
					</div>
					<dl>
						<div v-for="row in card.rows" :key="row.label">
							<dt>{{ row.label }}</dt>
							<dd :class="row.tone ? `ta-value--${row.tone}` : ''" :title="row.value">
								{{ row.value }}
							</dd>
						</div>
					</dl>
					<button type="button" class="ta-card__go" @click="admin.goTo(card.action)">
						{{ card.action.label }} →
					</button>
				</article>
			</div>
		</template>
	</div>
</template>

<script>
import { t } from '../../l10n.js'
import { APP_DISPLAY_NAME } from '../../branding.js'

const ICONS = {
	models: 'M12 2l2.2 6.8L21 11l-6.8 2.2L12 20l-2.2-6.8L3 11l6.8-2.2L12 2Z',
	talk: 'M5 3h14a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3h-7l-5 4v-4H5a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3Z',
	knowledge: 'M4 4h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4V4Zm16 0h-6a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h6V4Z',
	documents: 'M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm7 1.5V8h4.5L13 3.5Z',
	media: 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 13h16l-5-7-4 5-3-3-4 5Zm4-9a2 2 0 1 0 0 .01Z',
	queue: 'M3 5h18v3H3V5Zm0 5.5h18v3H3v-3ZM3 16h12v3H3v-3Z',
}

export default {
	name: 'OverviewTab',
	inject: ['admin'],
	data() {
		return { appName: APP_DISPLAY_NAME, icons: ICONS }
	},
	computed: {
		overview() {
			return this.admin.overview
		},
		currentStep() {
			return this.overview?.setup?.steps.find(step => !step.done)?.id || null
		},
		optionalCards() {
			return [
				{ id: 'knowledge', title: t('educai', 'Knowledge sources'), text: t('educai', 'Bots answer questions from files and web pages.'), action: { tab: 'knowledge', target: 'educai-admin-knowledge' } },
				{ id: 'documents', title: t('educai', 'Documents'), text: t('educai', 'Convert PDF and Office files to text with Docling.'), action: { tab: 'knowledge', target: 'educai-admin-docling' } },
				{ id: 'media', title: t('educai', 'Images & audio'), text: t('educai', 'Understand images and transcribe voice messages.'), action: { tab: 'capabilities', target: 'educai-admin-vision' } },
				{ id: 'limits', title: t('educai', 'Rate limits'), text: t('educai', 'Queue requests when provider quotas are tight.'), action: { tab: 'system', target: 'educai-admin-rate-limits' } },
			]
		},
	},
	methods: {
		t,
		stepClass(step) {
			return { 'ta-step--done': step.done, 'ta-step--current': step.id === this.currentStep }
		},
		chipClass(tone) {
			return { ok: 'ta-chip--ok', warn: 'ta-chip--warn', error: 'ta-chip--error', info: 'ta-chip--info' }[tone] || ''
		},
	},
}
</script>
