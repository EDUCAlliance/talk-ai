import assert from 'node:assert/strict'
import test from 'node:test'
import { Vue, compileSfc, mountDom, mountModel, settle, input, change, click, button } from './helpers/vueDom.mjs'
import { t, n } from '../../src/l10n.js'
import { generateSecret, RATE_LIMIT_WINDOWS } from '../../src/utils/adminSettings.js'
import { buildAllowListGroups, setReleased } from '../../src/utils/modelAllowList.js'
import * as modelSelection from '../../src/utils/modelSelection.js'

const SecretField = compileSfc('components/admin/SecretField', { t, generateSecret })
const ToggleSwitch = compileSfc('components/admin/ToggleSwitch')
const ServiceSource = compileSfc('components/admin/ServiceSource')
const ModelSelector = compileSfc('components/ModelSelector', { t, modelSelection })
const ModelAllowList = compileSfc('components/admin/ModelAllowList', { t, n, buildAllowListGroups, setReleased, splitModelReference: modelSelection.splitModelReference })
const RateLimitFields = compileSfc('components/admin/RateLimitFields', { t, RATE_LIMIT_WINDOWS })
const AdminDialog = compileSfc('components/admin/AdminDialog')

test('rendered secret replacement, cancel and reset keep the parent model in sync without exposing the stored secret', async ctx => {
	const { container, model, changes } = mountModel(ctx, SecretField, '', { id: 'api-key', label: 'API key', stored: true, removable: true })
	assert.equal(container.querySelector('input'), null)
	assert.match(container.textContent, /Stored/)
	await click(button(container, 'Replace'))
	const field = container.querySelector('input')
	assert.equal(field.type, 'password')
	assert.equal(document.activeElement, field)
	await input(field, 'replacement-value')
	assert.equal(model.value, 'replacement-value')
	await click(button(container, 'Cancel'))
	assert.equal(model.value, '')
	assert.deepEqual(changes, ['replacement-value', ''])
	assert.equal(container.querySelector('input'), null)
	model.value = 'externally-reset-draft'
	await settle()
	assert.equal(container.querySelector('input').value, 'externally-reset-draft')
})

test('rendered generated secret is a Vue3 model update and can be hidden again', async ctx => {
	const { container, model } = mountModel(ctx, SecretField, '', { id: 'webhook-key', label: 'Webhook key', generate: true })
	await click(button(container, 'Generate'))
	assert.match(model.value, /^[a-f\d]{64}$/)
	assert.equal(container.querySelector('input').value, model.value)
	assert.equal(container.querySelector('input').type, 'text')
	await click(button(container, 'Hide'))
	assert.equal(container.querySelector('input').type, 'password')
})

test('rendered toggle and service-source radio controls round-trip booleans and source changes', async ctx => {
	const toggle = mountModel(ctx, ToggleSwitch, false, { id: 'feature', label: 'Enabled' })
	await click(toggle.container.querySelector('input'))
	assert.equal(toggle.model.value, true)
	assert.deepEqual(toggle.changes, [true])
	toggle.model.value = false
	await settle()
	assert.equal(toggle.container.querySelector('input').checked, false)

	const source = mountModel(ctx, ServiceSource, 'inherit', {
		name: 'embedding-source', legend: 'Embedding provider', inheritTitle: 'Primary', customTitle: 'Custom',
	})
	assert.equal(source.container.querySelector('[value="inherit"]').checked, true)
	await click(source.container.querySelector('[value="custom"]'))
	assert.equal(source.model.value, 'custom')
	assert.equal(source.container.querySelector('[value="inherit"]').checked, false)
	source.model.value = 'inherit'
	await settle()
	assert.equal(source.container.querySelector('[value="inherit"]').checked, true)
})

test('rendered model selector preserves a saved missing model, changes provider, and clears to inheritance', async ctx => {
	const { container, model, changes } = mountModel(ctx, ModelSelector, 'secondary:saved-model', {
		id: 'model', emptyLabel: 'Use administrator default', allowManual: true,
		options: [{ id: 'primary:listed-model', model: 'listed-model', availability: 'available' }],
	})
	assert.equal(container.querySelector('#model').value, 'secondary:saved-model')
	assert.match(container.querySelector('[role="status"]').textContent, /selection is preserved/)
	await change(container.querySelector('#model'), 'primary:listed-model')
	assert.equal(model.value, 'primary:listed-model')
	await change(container.querySelector('#model-provider'), 'secondary')
	await input(container.querySelector('#model-manual'), 'my/model:version')
	await click(button(container, 'Use model'))
	assert.equal(model.value, 'secondary:my/model:version')
	assert.equal(container.querySelector('#model').value, model.value)
	await input(container.querySelector('#model-manual'), 'invalid model')
	await click(button(container, 'Use model'))
	assert.equal(changes.length, 2, 'Invalid manual input is not emitted')
	assert.match(container.querySelector('[role="alert"]').textContent, /without spaces/)
	await change(container.querySelector('#model'), '')
	assert.equal(model.value, '')
})

test('rendered allowlist supports expansion, checkbox changes, filtered group actions and manual entries', async ctx => {
	const options = Array.from({ length: 25 }, (_, i) => ({ id: `primary:model-${i}`, availability: 'available' }))
	const { container, model } = mountModel(ctx, ModelAllowList, ['primary:model-24', 'secondary:saved'], { options })
	const listed = () => container.querySelectorAll('input[type="checkbox"]')
	assert(listed().length < 26)
	await click(button(container, 'Show all'))
	assert.equal(listed().length, 26)
	await change(container.querySelector('[data-model="primary:model-3"]'), true)
	assert.deepEqual([...model.value], ['primary:model-24', 'secondary:saved', 'primary:model-3'])
	await input(container.querySelector('input[type="search"]'), 'model-1')
	await click(button(container, 'All'))
	assert(model.value.includes('primary:model-19'))
	assert(model.value.includes('secondary:saved'), 'Filtering does not discard unrelated saved entries')
	await click(button(container, 'None'))
	assert(!model.value.includes('primary:model-19'))
	assert(model.value.includes('primary:model-3'))
	await change(container.querySelector('#manual-allowed-provider'), 'secondary')
	await input(container.querySelector('#manual-allowed-model'), 'custom/model')
	await click(button(container, 'Add'))
	assert(model.value.includes('secondary:custom/model'))
	assert.equal(container.querySelector('#manual-allowed-model').value, '')
})

test('rendered rate limits emit the selected window and preserve the empty optional value', async ctx => {
	const changes = []
	const values = Vue.reactive({ second: null, minute: 25, hour: 300, day: 1000 })
	const { container } = mountDom(ctx, RateLimitFields, {
		idPrefix: 'chat-limit', values, onUpdate: (window, value) => { changes.push([window, value]); values[window] = value },
	})
	assert.equal(container.querySelector('#chat-limit-second').value, '')
	await input(container.querySelector('#chat-limit-minute'), '30')
	await input(container.querySelector('#chat-limit-second'), '4')
	await input(container.querySelector('#chat-limit-second'), '')
	assert.deepEqual(changes, [['minute', 30], ['second', 4], ['second', null]])
	assert.equal(values.minute, 30)
})

test('rendered dialog focuses its field and restores focus when Vue3 unmounts it', async ctx => {
	const opener = document.createElement('button')
	document.body.append(opener)
	ctx.after(() => opener.remove())
	opener.focus()
	const { container, unmount } = mountDom(ctx, AdminDialog, { id: 'confirm', title: 'Confirm' }, {
		default: () => Vue.h('input', { id: 'dialog-field' }),
	})
	await settle()
	assert.equal(document.activeElement, container.querySelector('input'))
	unmount()
	assert.equal(document.activeElement, opener)
})
