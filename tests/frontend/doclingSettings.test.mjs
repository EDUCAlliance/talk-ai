import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { getApiErrorMessage } from '../../src/utils/apiError.js'
import { t, n, getCanonicalLocale } from '../../src/l10n.js'
import * as modelSelection from '../../src/utils/modelSelection.js'
import * as adminSettings from '../../src/utils/adminSettings.js'
import { deriveOverview } from '../../src/utils/adminOverview.js'

// Exercise the real component methods without mounting unrelated admin panels.
function admin(axios) {
	const source = readFileSync(new URL('../../src/components/AdminSettings.vue', import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .+$/gm, '').replace('export default', 'globalThis.component =')
	const errors = []
	const scope = { axios, APP_DISPLAY_NAME: 'Talk AI', BotForm: {}, adminComponents: {}, modelSelection, adminSettings, deriveOverview,
		generateUrl: value => value, t, n, getCanonicalLocale, getApiErrorMessage,
		applyEducAiRuntimeIconPayload() {}, showSuccess() {}, showError() {}, confirm: () => true,
		console: { ...console, error: (...args) => errors.push(args) } }
	vm.runInNewContext(script, scope)
	const component = scope.component
	const state = component.data()
	state.errors = errors
	state.$nextTick = callback => callback()
	for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state)
	for (const [name, computed] of Object.entries(component.computed)) Object.defineProperty(state, name, { get: computed.bind(state) })
	return state
}

test('Docling loads saved choices and changing profile leaves endpoint and key alone', async () => {
	const state = admin({ get: async () => ({ data: { docling_api_profile: 'legacy', docling_auth_mode: 'bearer',
		docling_api_endpoint: 'https://convert.example/prefix/v1/documents/convert', docling_api_key: '***' } }) })
	assert.equal(state.settings.doclingApiProfile, 'docling_serve')
	assert.equal(state.settings.doclingAuthMode, 'x_api_key')
	await state.loadSettings()
	assert.equal(state.settings.doclingApiProfile, 'legacy')
	assert.equal(state.settings.doclingAuthMode, 'bearer')
	assert.equal(state.errors.length, 0)
	assert.equal(state.settings.doclingApiKey, '')
	assert.equal(state.doclingHasStoredApiKey, true)
	state.settings.doclingApiKey = 'unsaved-dedicated'
	state.settings.doclingApiProfile = 'docling_serve'
	state.changeDoclingProfile()
	assert.equal(state.settings.doclingAuthMode, 'x_api_key')
	assert.equal(state.settings.doclingApiEndpoint, 'https://convert.example/prefix/v1/documents/convert')
	assert.equal(state.settings.doclingApiKey, 'unsaved-dedicated')
	state.settings.doclingApiProfile = 'legacy'
	state.changeDoclingProfile()
	assert.equal(state.settings.doclingAuthMode, 'bearer')
})

test('unsaved Docling tests send primary credentials only for legacy Bearer fallback', async () => {
	const calls = []
	const state = admin({ post: async (url, payload) => {
		assert.equal(url, '/apps/educai/api/v1/admin/docling/test')
		calls.push(payload)
		return { data: { success: true } }
	} })
	state.settings.apiKey = 'unsaved-primary'
	for (const profile of ['legacy', 'docling_serve']) {
		for (const auth of ['bearer', 'x_api_key', 'none']) {
			for (const stored of [false, true]) {
				for (const dedicated of ['', 'unsaved-dedicated']) {
					Object.assign(state.settings, { doclingApiProfile: profile, doclingAuthMode: auth, doclingApiKey: dedicated })
					state.doclingHasStoredApiKey = stored
					await state.testDoclingConnection()
					const payload = calls.at(-1)
					const expected = auth === 'none' ? null : (dedicated || (!stored && profile === 'legacy' && auth === 'bearer' ? 'unsaved-primary' : null))
					assert.equal(payload.doclingApiKey, expected, `${profile} / ${auth} / stored=${stored}`)
					assert.equal(payload.doclingApiProfile, profile)
					assert.equal(payload.doclingAuthMode, auth)
					assert.equal(payload.doclingApiEndpoint, '', 'Cleared endpoint must override the saved URL')
					assert.equal(state.doclingTesting, false)
				}
			}
		}
	}
})

test('Docling save and reload preserve choices without sending a disabled key', async () => {
	let saved = {}
	const state = admin({ put: async (_url, payload) => {
		saved = payload
		return { data: {} }
	}, get: async () => ({ data: { default_model: 'llama-3.3-70b-instruct', docling_api_profile: saved.doclingApiProfile, docling_auth_mode: saved.doclingAuthMode,
		docling_api_endpoint: saved.doclingApiEndpoint } }) })
	await state.loadSettings()
	Object.assign(state.settings, { doclingApiProfile: 'docling_serve', doclingAuthMode: 'none',
		doclingApiKey: 'not-for-no-auth', doclingApiEndpoint: 'https://docling.example/proxy' })
	await state.saveSettings()
	assert.equal(saved.doclingApiProfile, 'docling_serve')
	assert.equal(saved.doclingAuthMode, 'none')
	assert.equal(saved.doclingApiKey, null)
	await state.loadSettings()
	assert.equal(state.settings.doclingApiProfile, 'docling_serve')
	assert.equal(state.settings.doclingAuthMode, 'none')
	assert.equal(state.settings.doclingApiEndpoint, 'https://docling.example/proxy')
	assert.equal(state.errors.length, 0)
})

test('Docling test shows the safe server diagnostic', async () => {
	const state = admin({ post: async () => {
		throw { response: { data: { success: false, errorCode: 'docling_connection_failed', error: 'Use /v1/convert/file.' } } }
	} })
	await state.testDoclingConnection()
	assert.equal(state.doclingTestResult.success, false)
	assert.equal(state.doclingTestResult.error, 'Use /v1/convert/file.')
	assert.equal(state.doclingTesting, false)
})

test('the Docling pipeline choice is loaded, sent with test and detection, and only for Docling Serve', async () => {
	const sent = []
	const state = admin({
		get: async () => ({ data: { docling_api_profile: 'docling_serve', docling_auth_mode: 'none',
			docling_api_endpoint: 'https://docling.example', docling_pipeline: 'vlm', docling_vlm_preset: 'uni-gemma' } }),
		post: async (url, payload) => {
			sent.push([url, payload])
			return { data: { success: true } }
		},
	})
	// Before anything is loaded the request stays as it always was.
	assert.deepEqual({ ...state.doclingPipelineChoice() }, { doclingPipeline: '', doclingVlmPreset: 'default' })

	await state.loadSettings()
	assert.equal(state.settings.doclingPipeline, 'vlm')
	assert.equal(state.settings.doclingVlmPreset, 'uni-gemma')

	await state.testDoclingConnection()
	await state.detectDocling()
	assert.equal(sent.length, 2)
	for (const [url, payload] of sent) {
		assert.equal(payload.doclingPipeline, 'vlm', url)
		assert.equal(payload.doclingVlmPreset, 'uni-gemma', url)
	}

	// An emptied preset field means the default preset, and the legacy endpoint never gets a pipeline.
	state.settings.doclingVlmPreset = '  '
	assert.deepEqual({ ...state.doclingPipelineChoice() }, { doclingPipeline: 'vlm', doclingVlmPreset: 'default' })
	state.settings.doclingApiProfile = 'legacy'
	assert.deepEqual({ ...state.doclingPipelineChoice() }, { doclingPipeline: '', doclingVlmPreset: 'default' })
})

test('an unknown saved pipeline falls back to the unchanged request', async () => {
	const state = admin({ get: async () => ({ data: { docling_api_profile: 'docling_serve', docling_auth_mode: 'none', docling_pipeline: 'asr' } }) })
	await state.loadSettings()
	assert.equal(state.settings.doclingPipeline, '')
	assert.equal(state.settings.doclingVlmPreset, 'default')
	assert.equal(adminSettings.FIELD_SECTIONS?.doclingPipeline ?? 'knowledge', 'knowledge')
})
