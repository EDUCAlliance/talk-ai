import { createRenderer, h, reactive } from 'vue'

// Exercise actual Options API reactivity and teardown without rendering a UI.
// The caller controls loading explicitly, as the old unmounted Vue 2 harness did.
const renderer = createRenderer({
	createComment: () => ({}),
	insert() {},
	remove() {},
	parentNode: () => null,
	nextSibling: () => null,
})

export function mountState(definition, props = {}, listeners = {}) {
	const currentProps = reactive({ ...props })
	const component = { ...definition, mounted: undefined, render: () => null }
	let state
	const app = renderer.createApp({
		render: () => h(component, { ...currentProps, ...listeners, ref: value => { if (value) state = value } }),
	})
	app.mount({})
	let mounted = true
	return {
		state,
		setProps: values => Object.assign(currentProps, values),
		unmount() {
			if (mounted) app.unmount()
			mounted = false
		},
	}
}
