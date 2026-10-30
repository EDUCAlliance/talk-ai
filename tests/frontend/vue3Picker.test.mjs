import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { Vue, compileSfc, mountDom, settle, input, click, button } from './helpers/vueDom.mjs'
import { t, installL10n } from '../../src/l10n.js'
import { getApiErrorMessage } from '../../src/utils/apiError.js'

// External UI fixtures implement the public Vue 3 component contracts. Talk AI's
// own picker and dialog templates, lifecycle, state, DOM and event handlers are real.
const NcTextField = {
	props: ['modelValue', 'showTrailingButton'],
	emits: ['update:modelValue', 'trailing-button-click'],
	methods: { focus() { this.$el.querySelector('input').focus() } },
	render() {
		return Vue.h('label', [
			Vue.h('input', { value: this.modelValue, onInput: event => this.$emit('update:modelValue', event.target.value) }),
			this.showTrailingButton && Vue.h('button', { onClick: () => this.$emit('trailing-button-click') }, 'Clear search'),
		])
	},
}
const NcButton = {
	props: ['variant', 'disabled'],
	render() { return Vue.h('button', { disabled: this.disabled }, this.$slots.default?.()) },
}
const visual = { props: ['text'], render() { return Vue.h('span', [this.text, this.$slots.default?.()]) } }
const quietConsole = { debug() {}, error() {}, warn() {} }
const bots = [
	{ id: 1, bot_name: 'Alpha assistant', mention_name: '@alpha', visibility: 'global' },
	{ id: 2, bot_name: 'Beta assistant', mention_name: 'beta', visibility: 'global' },
]

function picker(axios) {
	const common = {
		axios, generateUrl: path => path, APP_DISPLAY_NAME: 'Talk AI', t, getApiErrorMessage,
		showError() {}, showSuccess() {}, console: quietConsole,
		NcButton, NcChip: visual, NcNoteCard: visual, NcLoadingIcon: visual,
		IconRobot: visual, IconClose: visual, IconBookOpenVariant: visual,
	}
	const BotPickerModal = compileSfc('components/BotPickerModal', common)
	return compileSfc('components/BotPickerElement', { ...common, BotPickerModal, NcTextField, NcEmptyContent: visual })
}

function backend({ enabled = true, moderator = false } = {}) {
	const calls = []
	return {
		calls,
		async get(url) {
			calls.push(['get', url])
			if (url.includes('/bot-status/')) return { data: { botEnabled: enabled, isModerator: moderator } }
			return { data: url.endsWith('/public-bots') ? bots : [] }
		},
		async post(url) { calls.push(['post', url]); return { data: {} } },
	}
}

async function keyboard(element, key) {
	element.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
	await settle()
}

test('rendered Smart Picker searches, focuses list refs, and bubbles a single mention from its real modal', async ctx => {
	const axios = backend()
	const { container } = mountDom(ctx, picker(axios), { providerId: 'educai-bots', roomToken: 'room123' })
	await settle()
	const search = container.querySelector('input')
	assert.equal(document.activeElement, search)
	assert.equal(container.querySelectorAll('[role="option"]').length, 2)
	await keyboard(search, 'ArrowDown')
	assert.equal(document.activeElement, container.querySelectorAll('[role="option"]')[0])
	await keyboard(search, 'ArrowDown')
	assert.equal(document.activeElement, container.querySelectorAll('[role="option"]')[1])
	await keyboard(search, 'ArrowUp')
	assert.equal(document.activeElement, container.querySelectorAll('[role="option"]')[0])
	await input(search, 'beta')
	assert.equal(container.querySelectorAll('[role="option"]').length, 1)
	const submissions = []
	container.addEventListener('submit', event => submissions.push({ detail: event.detail, target: event.target }))
	await keyboard(search, 'Enter')
	assert.match(container.querySelector('.bot-picker-modal').textContent, /Beta assistant/)
	await click(button(container, 'Use Bot'))
	assert.equal(submissions.length, 1)
	assert.equal(submissions[0].detail, '@beta')
	assert.equal(submissions[0].target, container.querySelector('.bot-picker'))
	assert.equal(container.querySelector('.bot-picker-modal'), null)
	await click(button(container, 'Clear search'))
	assert.equal(container.querySelectorAll('[role="option"]').length, 2)
	assert.equal(axios.calls.filter(([method]) => method === 'post').length, 0)
})

test('rendered Smart Picker enables a moderator room before emitting its mention', async ctx => {
	const axios = backend({ enabled: false, moderator: true })
	const { container } = mountDom(ctx, picker(axios), { providerId: 'educai-bots', roomToken: 'room123' })
	await settle()
	const mentions = []
	container.addEventListener('submit', event => mentions.push(event.detail))
	await click(container.querySelector('[role="option"]'))
	await click(button(container, 'Enable & Use Bot'))
	assert.deepEqual(axios.calls.filter(([method]) => method === 'post'), [['post', '/apps/educai/api/v1/talk/enable-bot/room123']])
	assert.deepEqual(mentions, ['@alpha'])
})

test('Smart Picker entrypoint returns a host container, unmounts once, and can reopen without duplicated handlers', async ctx => {
	const axios = backend()
	const BotPickerElement = picker(axios)
	const registrations = []
	const apps = []
	const unmounted = []
	const errors = []
	class NcCustomPickerRenderResult {
		constructor(element, object) { this.element = element; this.object = object }
	}
	const source = readFileSync(new URL('../../src/bot-picker.js', import.meta.url), 'utf8').replace(/^import .+$/gm, '')
	vm.runInNewContext(source, {
		window, document, BotPickerElement, installL10n, NcCustomPickerRenderResult,
		markRaw: Vue.markRaw,
		createApp(...args) {
			const app = Vue.createApp(...args)
			app.mixin({ unmounted() { unmounted.push(this.$options.name) } })
			app.config.warnHandler = warning => errors.push(warning)
			app.config.errorHandler = error => errors.push(error)
			apps.push(app)
			return app
		},
		registerCustomPickerElement: (...args) => registrations.push(args),
		setTimeout: callback => callback(), console: quietConsole,
	})
	if (registrations.length === 0) document.dispatchEvent(new Event('DOMContentLoaded'))
	assert.equal(registrations.length, 1)
	const [provider, render, destroy] = registrations[0]
	assert.equal(provider, 'educai-bots')
	const host = document.createElement('div')
	document.body.append(host)
	ctx.after(() => host.remove())
	const mentions = []
	host.addEventListener('submit', event => mentions.push(event.detail))
	for (let opening = 0; opening < 2; opening++) {
		const result = render(host, { providerId: provider, accessible: true })
		await settle()
		assert.equal(result.element, host)
		assert.equal(result.object, apps[opening])
		assert.equal(Vue.reactive(result).object, apps[opening], 'The host cannot proxy the opaque app instance')
		await click(host.querySelector('[role="option"]'))
		await click(button(host, 'Use Bot'))
		assert.equal(mentions.length, opening + 1)
		const oldSearch = host.querySelector('input')
		const instance = apps[opening]._instance
		destroy(host, result)
		await settle()
		assert.equal(instance.isUnmounted, true)
		assert.equal(unmounted.filter(name => name === 'BotPickerElement').length, opening + 1)
		assert.equal(host.childElementCount, 0)
		await keyboard(oldSearch, 'Enter')
		assert.equal(host.childElementCount, 0, 'Detached old controls cannot recreate the picker')
	}
	assert.deepEqual(mentions, ['@alpha', '@alpha'])
	assert.deepEqual(errors, [])
	assert(axios.calls.some(([, url]) => url.endsWith('/bot-status/room123')), 'Room token comes from the current Talk URL')
})
