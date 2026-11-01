import assert from 'node:assert/strict'
import test from 'node:test'
import { compileSfc, mountDom, mountModel, settle, input, change, click, button } from './helpers/vueDom.mjs'
import { t, n } from '../../src/l10n.js'
import { generateSecret } from '../../src/utils/adminSettings.js'
import { formatWhen } from '../../src/utils/adminOverview.js'
import * as api from '../../src/utils/apiConnections.js'

const plain = value => JSON.parse(JSON.stringify(value))

const config = (changes = {}) => ({
	enabled: false,
	read_confirmed: false,
	label: 'Search courses',
	description: 'Searches open courses.',
	auth: null,
	fixed: { query: [] },
	mapping: { records: '/data', id: '/id', title: '/title', fields: [], link: { field: null, template: null, origins: [] }, paging: [] },
	...changes,
})
const operation = (id, changes = {}) => ({
	id,
	tool_name: `api_1_${id}`,
	match_key: 'GET /courses',
	method: 'GET',
	path: '/courses',
	operation_id: 'searchCourses',
	summary: 'Search courses',
	description: '',
	deprecated: false,
	security: { declared: true, alternatives: [{ key: 'scheme:bearerAuth', scheme: 'bearerAuth', mode: 'bearer', supported: true }] },
	parameters: [
		{ name: 'q', in: 'query', required: true, schema: { type: 'string' } },
		{ name: 'limit', in: 'query', required: false, schema: { type: 'integer' } },
		{ name: 'state', in: 'query', required: false, schema: { type: 'string', enum: ['OPEN', 'CLOSED'] } },
		{ name: 'tags', in: 'query', required: false, schema: { type: 'array', items: { type: 'string' } } },
	],
	supported: true,
	blockers: [],
	notes: [],
	config: config(),
	problems: [],
	...changes,
})
const blocked = operation(2, {
	match_key: 'GET /books/{isbn}', path: '/books/{isbn}', summary: 'Book by ISBN', supported: false,
	blockers: [{ code: 'schema_pattern_unsupported', detail: 'isbn in path' }],
})
const connection = (changes = {}) => ({
	id: 1, name: 'Courses', description: '', enabled: false, base_url: 'https://api.courses.example/v1',
	auth_mode: 'bearer', auth_header: null, credential_set: true, data_visibility: 'shared_for_bot_audience',
	assign_policy: 'admins', assign_groups: [], bot_visibilities: ['personal', 'groups', 'teams', 'global'],
	active_revision_id: null, approval_current: false, last_check: null, draft_revision_id: 10, approved_operations: 0,
	...changes,
})
const revision = (id, operations, changes = {}) => ({
	id, connection_id: 1, status: 'draft', sha256: 'a'.repeat(64), source_kind: 'file', source_url: null, oas_version: '3.0.3',
	info: { title: 'Courses API', version: '1.2' }, servers: [{ url: 'https://api.courses.example/v1', resolved: 'https://api.courses.example/v1' }],
	security_schemes: {}, diagnostics: [], operations, approved_base_url: null, approved_by: null, approved_at: null,
	created_by: 'admin', created_at: 1_790_000_000, ...changes,
})
const detail = (changes = {}) => ({
	connection: connection(),
	active: null,
	draft: { ...revision(10, [operation(1), blocked]), diff: { base_revision_id: null, added: [], removed: [], changed: [], unchanged: 0 }, approval_problems: {} },
	...changes,
})

const ToggleSwitch = compileSfc('components/admin/ToggleSwitch')
const TestAction = compileSfc('components/admin/TestAction')
const AdminDialog = compileSfc('components/admin/AdminDialog')
const AdminSection = compileSfc('components/admin/AdminSection')
const SecretField = compileSfc('components/admin/SecretField', { t, generateSecret })
const ApiOperationReview = compileSfc('components/admin/ApiOperationReview', { ...api, t, n, TestAction, ToggleSwitch })
const ApiConnectionDialog = compileSfc('components/admin/ApiConnectionDialog', { ...api, t, AdminDialog, SecretField })

function section(routes, { confirm = () => true } = {}) {
	const calls = []
	const successes = []
	const handle = method => async (url, body) => {
		calls.push([method, url, body === undefined ? undefined : plain(body)])
		const route = routes[`${method} ${url}`]
		if (route === undefined) {
			throw new Error(`Unexpected ${method} ${url}`)
		}
		return { data: typeof route === 'function' ? route(body) : route }
	}
	const axios = { get: handle('GET'), post: handle('POST'), put: handle('PUT'), delete: handle('DELETE') }
	const component = compileSfc('components/admin/ApiConnectionsSection', {
		...api, t, n, formatWhen, axios, generateUrl: url => url, showSuccess: message => successes.push(message), confirm,
		AdminSection, ApiConnectionDialog, ApiOperationReview,
	})
	return { component, calls, successes }
}
const rejection = (status, errorCode, error, details = {}) => Object.assign(new Error(error), { response: { status, data: { error, errorCode, details } } })
const LIST = `GET ${api.CONNECTIONS_URL}`
const DETAIL = `GET ${api.CONNECTIONS_URL}/1`

test('connection payloads only touch the stored credential when asked and never echo it', () => {
	const stored = api.connectionForm(connection({ auth_mode: 'api_key_header', auth_header: 'X-Api-Key', data_visibility: null, bot_visibilities: ['global', 'personal'] }))
	assert.equal(stored.credential, '')
	assert.equal(stored.credentialSet, true)
	assert.equal(stored.sharedForBotAudience, false)

	const untouched = api.connectionPayload(stored)
	assert.equal('credential' in untouched, false)
	assert.equal(untouched.auth_header, 'X-Api-Key')
	assert.equal(untouched.data_visibility, null)
	assert.deepEqual(untouched.bot_visibilities, ['personal', 'global'], 'Visibilities keep the server order')

	assert.deepEqual(api.connectionPayload({ ...stored, credential: '  new-key ' }).credential, { action: 'replace', value: 'new-key' })
	assert.deepEqual(api.connectionPayload({ ...stored, removeCredential: true }).credential, { action: 'remove' })

	const anonymous = api.connectionPayload({ ...stored, authMode: 'none', credential: 'ignored', sharedForBotAudience: true, assignPolicy: 'groups', assignGroups: ['staff'] })
	assert.equal('credential' in anonymous, false)
	assert.equal('auth_header' in anonymous, false)
	assert.equal(anonymous.data_visibility, api.DATA_VISIBILITY)
	assert.deepEqual(anonymous.assign_groups, ['staff'])
	assert.deepEqual(api.connectionPayload({ ...stored, assignGroups: ['staff'] }).assign_groups, [], 'Groups only count for the group policy')
})

test('review state round-trips the server configuration and reports unreadable lines', () => {
	const saved = operation(1, { config: config({
		enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth',
		fixed: { query: { state: 'OPEN', limit: 5, tags: ['a', 'b'], exact: true } },
		mapping: {
			records: '/data', id: '/id', title: '/title',
			fields: [{ key: 'summary', pointer: '/summary' }],
			link: { field: null, template: 'https://courses.example/c/{id}', origins: ['https://courses.example'] },
			paging: { has_more: '/has_more', total: '/total' },
		},
	}) })
	const state = api.reviewState(saved)
	assert.equal(state.fixed, 'state = OPEN\nlimit = 5\ntags = ["a","b"]\nexact = true')
	assert.equal(state.fields, 'summary = /summary')
	const { edit, errors } = api.reviewEdit(state)
	assert.deepEqual(errors, [])
	assert.deepEqual(edit.fixed.query, { state: 'OPEN', limit: 5, tags: ['a', 'b'], exact: true })
	assert.deepEqual(edit.mapping.paging, { has_more: '/has_more', total: '/total' })
	assert.equal(edit.auth, 'scheme:bearerAuth')
	assert.equal(api.reviewChanged(state, saved), false)
	assert.equal(api.reviewChanged({ ...state, label: 'Find courses' }, saved), true)

	const broken = api.reviewEdit({ ...state, fixed: 'state OPEN', fields: '= /summary' })
	assert.equal(broken.errors.length, 2)
	assert.deepEqual(broken.edit.fixed.query, {}, 'Unreadable lines are reported, not sent')
	assert.equal('auth' in api.reviewEdit({ ...state, auth: '' }).edit, false, 'An unchosen authentication is not sent')
})

test('test-call arguments are typed like model arguments and fixed values cannot be entered', () => {
	const state = { ...api.reviewState(operation(1)), fixed: 'state = OPEN' }
	const fields = api.testFields(operation(1), state)
	assert.deepEqual(fields.map(field => field.name), ['q', 'limit', 'tags'])

	assert.deepEqual(api.testArguments(fields, { 'query:q': ' math ', 'query:limit': '3', 'query:tags': 'a, b' }), {
		arguments: { path: {}, query: { q: 'math', limit: 3, tags: ['a', 'b'] } },
		errors: [],
	})
	const invalid = api.testArguments(fields, { 'query:limit': '2.5' })
	assert.deepEqual(invalid.arguments.query, {})
	assert.equal(invalid.errors.length, 2)
	assert.match(invalid.errors[0], /"q" is required/)
	assert.match(invalid.errors[1], /"limit" does not have the expected type/)
})

test('server codes are localized and unknown failures fall back safely', () => {
	assert.equal(api.errorText(rejection(422, 'document_fetch_failed', 'raw', { reason: 'timeout' })), 'The document could not be downloaded. The API did not answer in time.')
	assert.equal(api.errorText(rejection(409, 'something_new', 'Server text')), 'Server text')
	assert.equal(api.errorText(new Error('Network Error'), 'Fallback'), 'Fallback')
	assert.equal(api.codeText('no_such_code'), null)
})

test('authentication choices explain unsupported schemes', () => {
	assert.deepEqual(plain(api.authChoices(operation(1, { security: { declared: false, alternatives: [] } }))).map(choice => choice.key), ['none'])
	const choices = api.authChoices(operation(1, { security: { declared: true, alternatives: [
		{ key: 'anonymous', mode: 'none', supported: true },
		{ key: 'scheme:oauth', scheme: 'oauth', mode: 'oauth2', supported: false, reason: 'security_unsupported' },
	] } }))
	assert.equal(choices[0].supported, true)
	assert.equal(choices[1].supported, false)
	assert.match(choices[1].reason, /not supported/)
})

test('the semantic diff marks removed tools that bots use today', () => {
	const active = revision(9, [operation(1, { config: config({ enabled: true, label: 'Search courses' }) }), operation(3, { match_key: 'GET /rooms' })], { status: 'approved' })
	const entries = api.diffEntries({ base_revision_id: 9, added: [4], removed: [{ id: 1, match_key: 'GET /courses' }, { id: 3, match_key: 'GET /rooms' }], changed: [{ id: 5, match_key: 'GET /x', aspects: ['parameters', 'response'] }], unchanged: 2 }, active)
	assert.deepEqual(plain(entries), [
		{ kind: 'removed', id: 1, matchKey: 'GET /courses', label: 'Search courses', aspects: [], changes: [], wasOffered: true },
		{ kind: 'removed', id: 3, matchKey: 'GET /rooms', label: 'Search courses', aspects: [], changes: [], wasOffered: false },
		{ kind: 'changed', id: 5, matchKey: 'GET /x', label: 'GET /x', aspects: ['parameters', 'response'], changes: [], wasOffered: false },
		{ kind: 'added', id: 4, matchKey: '', label: '', aspects: [], changes: [], wasOffered: false },
	])
	assert.deepEqual(api.diffEntries({ base_revision_id: null, added: [1], removed: [], changed: [] }, null), [])
})

test('semantic review diff carries every changed reviewed value and no audit metadata', () => {
	const before = config({ enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth' })
	const after = config({
		enabled: false, read_confirmed: false, auth: 'anonymous', label: 'New label', description: 'New description',
		fixed: { query: { state: 'CLOSED' } },
		mapping: { records: '/private', id: '/key', title: '/name', fields: [{ key: 'language', pointer: '/lang' }], link: { field: '/url', template: 'https://example.test/{id}', origins: [] }, paging: { next_cursor: '/cursor' } },
	})
	const active = revision(9, [operation(1, { config: before })])
	const diff = { base_revision_id: 9, changed: [], added: [], removed: [], unchanged: 1 }
	const [entry] = api.diffEntries(diff, active, [operation(1, { config: after })])
	assert.deepEqual(entry.aspects, ['result mapping', 'chosen authentication', 'fixed values', 'offered to bots', 'read confirmation', 'reviewed label', 'reviewed description'])
	const mapping = entry.changes.find(value => value.aspect === 'review_mapping')
	assert.equal(JSON.parse(mapping.before).records, '/data')
	assert.deepEqual(JSON.parse(mapping.after), after.mapping)
	assert.equal(entry.changes.find(value => value.aspect === 'review_enabled').before, 'true')
	assert.equal(entry.changes.find(value => value.aspect === 'review_enabled').after, 'false')
	assert.deepEqual(api.diffEntries(diff, active, [operation(1, { config: { ...before, confirmed_by: 'other', confirmed_at: 2 } })]), [])
})

test('bot forms learn why API tools are blocked and keep unavailable ones visible', () => {
	const tool = { api: { connection: 'Courses', bot_visibilities: ['personal', 'groups'] } }
	assert.equal(api.apiToolBlockReason(tool, 'groups'), null)
	assert.equal(api.apiToolBlockReason(tool, 'global'), 'Only for bots with visibility: Personal, Groups.')
	assert.equal(api.apiToolBlockReason({ name: 'MCP' }, 'global'), null)
	assert.equal(api.isApiToolName('api_1_2'), true)
	assert.equal(api.isApiToolName('api_0_2'), false)
	assert.deepEqual(api.unavailableSelections(['builtin:api_1_2', 'builtin:api_4_1', 'builtin:x', 7], new Set(['builtin:api_1_2']), { 'builtin:x': 'X tool' }), [
		{ key: 'builtin:api_4_1', label: 'Removed API tool' },
		{ key: 'builtin:x', label: 'X tool' },
	])
})

test('rendered operation review needs an explicit read confirmation and only tests saved settings', async ctx => {
	const calls = []
	const runTest = async args => {
		calls.push(args)
		return calls.length === 1
			? { success: true, output: '{"records":[{"id":"1","title":"Algebra"}]}', error: null }
			: { success: false, output: 'Timed out', error: { code: 'timeout', message: 'Timed out' } }
	}
	const { container, model } = mountModel(ctx, ApiOperationReview, api.reviewState(operation(1)), { operation: operation(1), runTest })

	assert.equal(container.querySelector('.ta-api-op__body'), null, 'Settings appear once the operation is offered')
	await change(container.querySelector('input[role="switch"]'), true)
	assert.equal(model.value.enabled, true)
	assert.equal(model.value.auth, 'scheme:bearerAuth', 'The only supported scheme is preselected')
	assert.equal(model.value.readConfirmed, false, 'Reading is never confirmed implicitly')

	const confirmation = container.querySelector('.ta-api-op__confirm input')
	await change(confirmation, true)
	assert.equal(model.value.readConfirmed, true)
	await input(container.querySelector('#api-op-1-fixed'), 'state = OPEN')
	assert.equal(container.querySelector('#api-op-1-test-query-state'), null, 'Fixed values are not test inputs')

	const settled = operation(5, { config: config({ enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth' }) })
	const { container: saved } = mountModel(ctx, ApiOperationReview, api.reviewState(settled), { operation: settled, runTest })
	await click(button(saved, 'Run test call'))
	assert.equal(calls.length, 0)
	assert.match(saved.textContent, /"q" is required/)
	await input(saved.querySelector('#api-op-5-test-query-q'), 'algebra')
	await input(saved.querySelector('#api-op-5-test-query-limit'), '2')
	await click(button(saved, 'Run test call'))
	assert.deepEqual(calls[0], { path: {}, query: { q: 'algebra', limit: 2 } })
	assert.match(saved.textContent, /The call worked/)
	assert.match(saved.querySelector('.ta-pre').textContent, /"title": "Algebra"/)
	await click(button(saved, 'Run test call'))
	assert.match(saved.textContent, /The API did not answer in time/)
	assert.equal(saved.querySelector('.ta-pre'), null)
})

test('rendered operation review explains unsupported operations and blocks tests while unsaved', async ctx => {
	const { container } = mountModel(ctx, ApiOperationReview, api.reviewState(blocked), { operation: blocked, runTest: async () => assert.fail('No call') })
	assert.equal(container.querySelector('input[role="switch"]'), null)
	assert.match(container.textContent, /Not supported/)
	assert.match(container.textContent, /Parameter patterns are not supported.*\(isbn in path\)/)

	const enabled = operation(6, { config: config({ enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth' }) })
	const { container: dirty } = mountModel(ctx, ApiOperationReview, api.reviewState(enabled), { operation: enabled, dirty: true, runTest: async () => assert.fail('No call') })
	assert.equal(button(dirty, 'Run test call').disabled, true)
	assert.match(dirty.textContent, /Save the review first/)

	const { container: problems } = mountModel(ctx, ApiOperationReview, api.reviewState(enabled), { operation: enabled, problems: ['credential_missing'], runTest: async () => assert.fail('No call') })
	assert.match(problems.textContent, /The connection has no stored credential/)
	assert.equal(button(problems, 'Run test call').disabled, true)
})

test('rendered connection dialog never shows the stored credential and keeps input after a failed save', async ctx => {
	const saved = []
	const host = mountDom(ctx, ApiConnectionDialog, { connection: connection({ active_revision_id: 9 }), groups: [{ id: 'staff', displayName: 'Staff' }], error: '', onSave: payload => saved.push(payload) })
	const dialog = host.container
	assert.equal(dialog.querySelector('input#api-connection-credential'), null)
	assert.match(dialog.textContent, /Stored/)

	await input(dialog.querySelector('#api-connection-base'), 'https://api.courses.example/v2')
	assert.match(dialog.textContent, /pauses the approved tools/)
	await click(button(dialog, 'Replace'))
	await input(dialog.querySelector('#api-connection-credential'), 'new-token')
	await change(dialog.querySelector('input[value="groups"]'), true)
	await change(dialog.querySelector('input[value="staff"]'), true)
	await click(button(dialog, 'Save'))
	assert.deepEqual(plain(saved[0]), {
		name: 'Courses', description: '', base_url: 'https://api.courses.example/v2', auth_mode: 'bearer',
		data_visibility: api.DATA_VISIBILITY, assign_policy: 'groups', assign_groups: ['staff'],
		bot_visibilities: ['personal', 'groups', 'teams', 'global'], credential: { action: 'replace', value: 'new-token' },
	})

	host.props.error = 'Enter the API base address as an absolute HTTPS address without query or fragment.'
	await settle()
	assert.match(dialog.querySelector('[role="alert"]').textContent, /absolute HTTPS address/)
	assert.equal(dialog.querySelector('#api-connection-base').value, 'https://api.courses.example/v2', 'The draft survives the error')
	assert.equal(dialog.querySelector('#api-connection-credential').value, 'new-token')
})

test('rendered section imports, reviews, saves only changed operations and approves without enabling', async ctx => {
	let current = detail({ connection: connection({ draft_revision_id: null }), draft: null })
	const enabledConfig = config({ enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth' })
	const { component, calls, successes } = section({
		[LIST]: { connections: [connection({ draft_revision_id: null })] },
		[DETAIL]: () => current,
		[`POST ${api.CONNECTIONS_URL}/1/import`]: () => (current = detail()),
		[`PUT ${api.REVISIONS_URL}/10/operations`]: () => (current = detail({ draft: { ...detail().draft, operations: [operation(1, { config: enabledConfig }), blocked] } })),
		[`POST ${api.REVISIONS_URL}/10/approve`]: () => (current = detail({
			connection: connection({ active_revision_id: 10, approval_current: true, draft_revision_id: null, approved_operations: 1 }),
			active: revision(10, [operation(1, { config: enabledConfig }), blocked], { status: 'approved', approved_by: 'admin', approved_at: 1_790_000_100 }),
			draft: null,
		})),
	})
	const { container } = mountDom(ctx, component)
	await settle()
	assert.match(container.textContent, /Courses/)
	assert.match(container.textContent, /Not approved/)

	await click(button(container, 'Review'))
	assert.match(container.textContent, /Upload or import the OpenAPI document/)
	await input(container.querySelector('input[type="url"]'), 'https://api.courses.example/openapi.json')
	await click(button(container, 'Import'))
	assert.deepEqual(calls.at(-1), ['POST', `${api.CONNECTIONS_URL}/1/import`, { url: 'https://api.courses.example/openapi.json' }])
	assert.match(container.textContent, /1 operation can be offered/)
	assert.match(container.textContent, /1 is not supported/)
	assert.match(container.textContent, /Address actually used/)
	assert.equal(button(container, 'Approve revision').disabled, true, 'Nothing is offered yet')

	await change(container.querySelector('#api-op-1-enabled'), true)
	await change(container.querySelector('.ta-api-op__confirm input'), true)
	assert.match(container.textContent, /Unsaved/)
	await click(button(container, 'Save and approve'))

	const saves = calls.filter(([method]) => method === 'PUT')
	assert.equal(saves.length, 1)
	assert.deepEqual(Object.keys(saves[0][2].operations), ['1'], 'Only the changed operation is sent')
	assert.equal(saves[0][2].operations[1].read_confirmed, true)
	assert.deepEqual(calls.at(-1), ['POST', `${api.REVISIONS_URL}/10/approve`, undefined])
	assert.match(successes.at(-1), /Enable the connection/)
	assert.match(container.textContent, /The connection is disabled/)
	assert.equal(calls.some(([method, url]) => url.endsWith('/enabled')), false, 'Approval never enables the connection')
	assert.match(container.textContent, /Approved tools/)
	assert(button(container, 'Revoke'))
})

test('rendered section keeps review edits when saving fails', async ctx => {
	const { component } = section({
		[LIST]: { connections: [connection()] },
		[DETAIL]: detail(),
		[`PUT ${api.REVISIONS_URL}/10/operations`]: () => { throw rejection(409, 'revision_not_editable', 'Only drafts can be edited.') },
	})
	const { container } = mountDom(ctx, component)
	await settle()
	await click(button(container, 'Review'))
	await change(container.querySelector('#api-op-1-enabled'), true)
	await input(container.querySelector('#api-op-1-label'), 'Find courses')
	await click(button(container, 'Save review'))
	assert.match(container.querySelector('[role="alert"]').textContent, /Only drafts can be edited. Reload the page./)
	assert.equal(container.querySelector('#api-op-1-label').value, 'Find courses')
	assert.equal(container.querySelector('#api-op-1-enabled').checked, true)
})

test('rendered section shows changes against the approved revision and lets a paused approval be reviewed again', async ctx => {
	const offered = config({ enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth', label: 'Search courses' })
	const active = revision(9, [operation(1, { config: offered }), operation(3, { match_key: 'GET /rooms', config: config({ enabled: true, read_confirmed: true, label: 'Rooms' }) })], { status: 'approved', approved_by: 'admin', approved_at: 1_790_000_000 })
	const paused = detail({ connection: connection({ active_revision_id: 9, approval_current: false, draft_revision_id: null, approved_operations: 2 }), active, draft: null })
	const reopened = detail({
		connection: connection({ active_revision_id: 9, approval_current: false, draft_revision_id: 11, approved_operations: 2 }),
		active,
		draft: { ...revision(11, [operation(1, { config: offered })]), diff: { base_revision_id: 9, added: [], removed: [{ id: 3, match_key: 'GET /rooms' }], changed: [], unchanged: 1 }, approval_problems: {} },
	})
	const { component, calls } = section({
		[LIST]: { connections: [paused.connection] },
		[DETAIL]: paused,
		[`POST ${api.CONNECTIONS_URL}/1/reopen`]: reopened,
	})
	const { container } = mountDom(ctx, component)
	await settle()
	assert.match(container.textContent, /Paused: settings changed/)
	await click(button(container, 'Open'))
	await click(button(container, 'Review the approved document again'))
	assert.deepEqual(calls.at(-1), ['POST', `${api.CONNECTIONS_URL}/1/reopen`, undefined])
	assert.match(container.textContent, /Changes compared to the approved revision/)
	assert.match(container.textContent, /Removed\s*Rooms/)
	assert.match(container.textContent, /bots that use it will see it as unavailable after approval/)
	assert.equal(button(container, 'Approve revision').disabled, false)
})

test('rendered semantic diff shows approved and unsaved draft values before activation', async ctx => {
	const offered = config({ enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth' })
	const active = revision(9, [operation(1, { config: offered })], { status: 'approved', approved_by: 'admin', approved_at: 1_790_000_000 })
	const reviewed = detail({
		connection: connection({ active_revision_id: 9, draft_revision_id: 11 }), active,
		draft: { ...revision(11, [operation(1, { config: offered })]), diff: { base_revision_id: 9, added: [], removed: [], changed: [], unchanged: 1 }, approval_problems: {} },
	})
	const { component, calls } = section({ [LIST]: { connections: [reviewed.connection] }, [DETAIL]: reviewed })
	const { container } = mountDom(ctx, component)
	await settle()
	await click(button(container, 'Review'))
	const records = [...container.querySelectorAll('input')].find(element => element.value === '/data')
	assert.ok(records, container.innerHTML)
	await input(records, '/private')
	const values = container.querySelector('.ta-api-diff__values')
	assert.match(values.textContent, /result mapping/)
	assert.match(values.textContent, /Approved value/)
	assert.match(values.textContent, /Draft value/)
	const rendered = values.querySelectorAll('pre')
	assert.equal(JSON.parse(rendered[0].textContent).records, '/data')
	assert.equal(JSON.parse(rendered[1].textContent).records, '/private')
	assert.equal(calls.some(([method]) => method !== 'GET'), false, 'The values appear before any save or activation request')
	assert.equal(button(container, 'Save and approve').disabled, false)
	await input(records, '/data')
	assert.equal(container.querySelector('.ta-api-diff__values'), null, 'Reverting local edits removes the review-only change')
})

test('revocation invalidates an unsaved draft confirmation while retaining its mapping edit', async ctx => {
	const offered = config({ enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth' })
	const active = revision(9, [operation(1, { config: offered })], { status: 'approved', approved_by: 'admin', approved_at: 1_790_000_000 })
	const reviewed = detail({
		connection: connection({ active_revision_id: 9, draft_revision_id: 11 }), active,
		draft: { ...revision(11, [operation(1, { config: offered })]), diff: { base_revision_id: 9, added: [], removed: [], changed: [], unchanged: 1 }, approval_problems: {} },
	})
	const revokedConfig = { ...offered, read_confirmed: false, revoked_at: 2, revoked_by: 'admin' }
	const revoked = {
		...reviewed, active: { ...active, operations: [operation(1, { config: revokedConfig })] },
		draft: { ...reviewed.draft, operations: [operation(1, { config: revokedConfig })], approval_problems: { 1: ['operation_revoked', 'read_not_confirmed'] } },
	}
	const { component } = section({
		[LIST]: { connections: [reviewed.connection] }, [DETAIL]: reviewed,
		[`POST ${api.REVISIONS_URL}/9/operations/1/revoke`]: revoked,
	})
	const { container } = mountDom(ctx, component)
	await settle()
	await click(button(container, 'Review'))
	await input(container.querySelector('#api-op-1-records'), '/private')
	await click(button(container, 'Revoke'))
	assert.equal(container.querySelector('#api-op-1-records').value, '/private')
	assert.equal(container.querySelector('.ta-api-op__confirm input').checked, false)
})

test('rendered section blocks approval until the data audience is confirmed', async ctx => {
	const { component } = section({
		[LIST]: { connections: [connection({ data_visibility: null })] },
		[DETAIL]: detail({
			connection: connection({ data_visibility: null }),
			draft: { ...detail().draft, operations: [operation(1, { config: config({ enabled: true, read_confirmed: true, auth: 'scheme:bearerAuth' }) })] },
		}),
	})
	const { container } = mountDom(ctx, component)
	await settle()
	assert.match(container.textContent, /Data visibility not confirmed/)
	await click(button(container, 'Review'))
	assert.match(container.textContent, /Confirm who may see the API data/)
	assert.equal(button(container, 'Approve revision').disabled, true)
})

test('rendered section creates a disabled connection from a preset and opens its review', async ctx => {
	const preset = {
		id: 'educ-catalogue', revision: 1, name: 'EDUC course catalogue', description: 'Public courses.',
		base_url: 'https://catalogue.educalliance.eu/api', auth_mode: 'none', operations: ['GET /course/list', 'GET /course/view', 'GET /version'],
		upstream: { url: 'https://catalogue.educalliance.eu/api/', version: '3.0.2', sha256: 'b'.repeat(64), retrieved_at: '2026-10-30' },
	}
	const created = detail({
		connection: connection({ id: 1, name: 'EDUC course catalogue', auth_mode: 'none', credential_set: false, data_visibility: null }),
		draft: { ...revision(10, [operation(1, { config: config({ enabled: true }) })], { source_kind: 'preset' }), diff: { base_revision_id: null, added: [], removed: [], changed: [], unchanged: 0 }, approval_problems: { 1: ['read_not_confirmed'] } },
	})
	const { component, calls, successes } = section({
		[LIST]: { connections: [] },
		[`GET ${api.PRESETS_URL}`]: { presets: [preset] },
		[`POST ${api.PRESETS_URL}/educ-catalogue/connections`]: created,
	})
	const { container } = mountDom(ctx, component)
	await settle()

	await click(button(container, 'Add from preset'))
	assert.match(container.textContent, /Nothing is sent to the API/)
	assert.match(container.textContent, /3 read operations/)
	assert.match(container.textContent, /upstream version 3\.0\.2 of 2026-10-30/)
	await click(button(container, 'Create connection'))

	assert.deepEqual(calls.map(([method, url]) => `${method} ${url}`), [LIST, `GET ${api.PRESETS_URL}`, `POST ${api.PRESETS_URL}/educ-catalogue/connections`])
	assert.match(successes.at(-1), /Review its operations before enabling it/)
	assert.match(container.textContent, /All API connections/)
	assert.equal(container.querySelector('#api-op-1-enabled').checked, true)
	assert.equal(container.querySelector('.ta-api-op__confirm input').checked, false, 'The read confirmation stays with the admin')
	assert.equal(button(container, 'Approve revision').disabled, true)
})

test('rendered catalogue source switch only offers ready connections and saves the choice separately', async ctx => {
	const calls = []
	const successes = []
	let state = {
		connection_id: null,
		selected_found: true,
		candidates: [
			{ id: 3, name: 'EDUC course catalogue', enabled: true, operations: { 'GET /course/list': null, 'GET /version': 'operation_not_enabled' } },
			{ id: 4, name: 'Draft copy', enabled: false, operations: { 'GET /course/list': 'no_approved_revision', 'GET /version': 'no_approved_revision' } },
		],
	}
	const axios = {
		get: async url => {
			calls.push(['GET', url])
			return { data: state }
		},
		put: async (url, body) => {
			calls.push(['PUT', url, plain(body)])
			state = { ...state, connection_id: body.connectionId }
			return { data: state }
		},
	}
	const component = compileSfc('components/admin/CatalogueSourceSwitch', { ...api, t, axios, generateUrl: url => url, showSuccess: message => successes.push(message) })
	const { container } = mountDom(ctx, component)
	await settle()

	const radios = [...container.querySelectorAll('input[name="catalogue-source"]')]
	assert.equal(radios.length, 3)
	assert.equal(radios[0].checked, true, 'The legacy endpoint is the default')
	assert.equal(radios[1].disabled, false)
	assert.equal(radios[2].disabled, true, 'A connection without an approved course list cannot be chosen')
	assert.match(container.textContent, /Course list: ready/)
	assert.match(container.textContent, /Version check \(optional\): Not offered\./)
	assert.match(container.textContent, /Course list: Approve a document revision/)
	assert.equal(button(container, 'Use this source').disabled, true)
	assert.doesNotMatch(container.textContent, /Only an API connection released for personal, group, team and global bots/)

	await change(radios[1], true)
	assert.match(container.textContent, /Only an API connection released for personal, group, team and global bots/)
	await click(button(container, 'Use this source'))
	assert.deepEqual(calls.at(-1), ['PUT', api.CATALOGUE_SOURCE_URL, { connectionId: 3 }])
	assert.match(successes.at(-1), /through the API connection/)
	assert.equal(button(container, 'Use this source').disabled, true)

	state = { ...state, candidates: [{ ...state.candidates[0], operations: { 'GET /course/list': 'connection_disabled', 'GET /version': null } }] }
	await click(container.querySelector('input[name="catalogue-source"]'))
	await settle()
	await click(button(container, 'Use this source'))
	assert.deepEqual(calls.at(-1), ['PUT', api.CATALOGUE_SOURCE_URL, { connectionId: null }])
	assert.match(successes.at(-1), /from its API endpoint again/)
})

test('rendered catalogue source switch warns when the selected connection stopped working', async ctx => {
	const state = {
		connection_id: 3,
		selected_found: true,
		candidates: [{ id: 3, name: 'EDUC course catalogue', enabled: false, operations: { 'GET /course/list': 'connection_disabled', 'GET /version': 'connection_disabled' } }],
	}
	const axios = { get: async () => ({ data: state }), put: async () => ({ data: state }) }
	const component = compileSfc('components/admin/CatalogueSourceSwitch', { ...api, t, axios, generateUrl: url => url, showSuccess: () => {} })
	const { container } = mountDom(ctx, component)
	await settle()

	const radios = [...container.querySelectorAll('input[name="catalogue-source"]')]
	assert.equal(radios[1].checked, true)
	assert.equal(radios[1].disabled, false, 'The current choice stays visible even when it is unusable')
	assert.match(container.textContent, /Course list: The API connection is disabled\./)
	assert.match(container.textContent, /existing index is kept until the connection works again/)
})

test('rendered catalogue source switch refuses connections not released for every bot visibility', async ctx => {
	const state = {
		connection_id: null,
		selected_found: true,
		candidates: [{ id: 4, name: 'Restricted catalogue', enabled: true, operations: { 'GET /course/list': 'audience_not_instance_wide', 'GET /version': 'audience_not_instance_wide' } }],
	}
	const axios = { get: async () => ({ data: state }), put: async () => ({ data: state }) }
	const component = compileSfc('components/admin/CatalogueSourceSwitch', { ...api, t, axios, generateUrl: url => url, showSuccess: () => {} })
	const { container } = mountDom(ctx, component)
	await settle()

	const radios = [...container.querySelectorAll('input[name="catalogue-source"]')]
	assert.equal(radios[1].disabled, true, 'A connection limited to some bot visibilities cannot serve the catalogue')
	assert.match(container.textContent, /Course list: Not released for every bot visibility\./)
})
