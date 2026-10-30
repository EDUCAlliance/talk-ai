import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { JSDOM } from 'jsdom'
import { parse, compileTemplate } from '@vue/compiler-sfc'

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://nextcloud.test/apps/spreed/call/room123' })
for (const name of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node', 'Document', 'ShadowRoot', 'Event', 'CustomEvent', 'KeyboardEvent']) {
	globalThis[name] = dom.window[name]
}

// runtime-dom captures document at import time, so install the DOM first.
export const Vue = await import('vue')
const { installL10n } = await import('../../../src/l10n.js')

export function compileSfc(path, imports = {}) {
	const filename = new URL(`../../../src/${path}.vue`, import.meta.url)
	const { descriptor, errors } = parse(readFileSync(filename, 'utf8'))
	assert.deepEqual(errors, [], `${path}: SFC parse errors`)
	const compiled = compileTemplate({ source: descriptor.template.content, filename: filename.pathname, id: path })
	assert.deepEqual(compiled.errors, [], `${path}: template compilation errors`)
	const script = descriptor.script.content.replace(/^import .+$/gm, '').replace('export default', 'globalThis.component =')
	const template = compiled.code
		.replace(/^import \{(.+)\} from ["']vue["']/gm, (_, names) => `const {${names.replace(/\bas\b/g, ':')}} = Vue`)
		.replace('export function render', 'function render')
	const scope = { Vue, window, document, CustomEvent, URL, setTimeout, clearTimeout, setInterval, clearInterval, console, ...imports }
	vm.runInNewContext(`${script}\n${template}\ncomponent.render = render`, scope, { filename: filename.pathname })
	return scope.component
}

export function mountDom(ctx, component, props = {}, slots = {}) {
	const container = document.createElement('div')
	document.body.append(container)
	const state = Vue.reactive({ ...props })
	let instance
	const errors = []
	const app = Vue.createApp({
		render: () => Vue.h(component, { ...state, ref: value => { if (value) instance = value } }, slots),
	})
	installL10n(app)
	app.config.warnHandler = warning => errors.push(warning)
	app.config.errorHandler = error => errors.push(error)
	app.mount(container)
	let mounted = true
	const unmount = () => {
		if (mounted) app.unmount()
		mounted = false
		container.remove()
	}
	ctx.after(() => {
		unmount()
		assert.deepEqual(errors, [], 'No Vue render warnings or errors')
	})
	return { container, state: instance, props: state, unmount, app }
}

export function mountModel(ctx, component, value, props = {}) {
	const model = Vue.ref(value)
	const changes = []
	const mounted = mountDom(ctx, {
		render: () => Vue.h(component, {
			...props,
			modelValue: model.value,
			'onUpdate:modelValue': value => { changes.push(value); model.value = value },
		}),
	})
	return { ...mounted, model, changes }
}

export async function settle() {
	await Vue.nextTick()
	await new Promise(resolve => setImmediate(resolve))
	await Vue.nextTick()
}

export async function input(element, value) {
	assert(element, 'Input must exist')
	element.value = value
	element.dispatchEvent(new Event('input', { bubbles: true }))
	await settle()
}

export async function change(element, value) {
	assert(element, 'Control must exist')
	if (typeof value === 'boolean') element.checked = value
	else if (value !== undefined) element.value = value
	element.dispatchEvent(new Event('change', { bubbles: true }))
	await settle()
}

export async function click(element) {
	assert(element, 'Button must exist')
	element.click()
	await settle()
}

export const button = (container, label) => [...container.querySelectorAll('button')].find(element => element.textContent.trim() === label)
