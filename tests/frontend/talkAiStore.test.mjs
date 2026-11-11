import assert from 'node:assert/strict'
import test from 'node:test'

import { createTalkAiStore } from '../../src/store/createTalkAiStore.js'

const deferred = () => {
	let resolve, reject
	const promise = new Promise((yes, no) => { resolve = yes; reject = no })
	return { promise, resolve, reject }
}

function setup(routes = {}) {
	const calls = []
	const answer = (method, url, payload) => {
		calls.push([method, url, payload])
		const route = routes[`${method} ${url}`]
		if (route === undefined) {
			return Promise.resolve({ data: {} })
		}
		return typeof route === 'function' ? route(payload) : Promise.resolve({ data: route })
	}
	const http = {
		get: url => answer('GET', url),
		post: (url, payload) => answer('POST', url, payload),
		put: (url, payload) => answer('PUT', url, payload),
		delete: url => answer('DELETE', url),
	}
	return { store: createTalkAiStore({ http, url: path => path }), calls }
}

const API = '/apps/educai/api/v1'

test('permissions accept admin groups as a list and as a keyed object', async () => {
	const keyed = setup({ [`GET ${API}/permissions`]: { permissions: { isGroupAdmin: true, hasApprovalRights: true, adminGroups: { 'ikm-students': 'ikm-students' }, adminTeams: [] } } })
	await keyed.store.loadPermissions()
	assert.deepEqual(keyed.store.state.permissions.adminGroups, ['ikm-students'])
	assert.equal(keyed.store.state.permissions.isAdmin, false)
	assert.equal(keyed.store.state.loaded.permissions, true)

	const listed = setup({ [`GET ${API}/permissions`]: { permissions: { adminGroups: ['staff'] } } })
	await listed.store.loadPermissions()
	assert.deepEqual(listed.store.state.permissions.adminGroups, ['staff'])
	assert.deepEqual(listed.store.state.permissions.adminTeams, [])
})

test('reviews are only requested with approval rights', async () => {
	const { store, calls } = setup({ [`GET ${API}/approvals`]: { bots: [{ id: 5 }] } })
	await store.loadApprovals()
	assert.deepEqual(calls, [])
	assert.deepEqual(store.state.approvals, [])
	assert.equal(store.state.loaded.approvals, true)

	store.state.permissions.hasApprovalRights = true
	await store.loadApprovals()
	assert.deepEqual(calls, [['GET', `${API}/approvals`, undefined]])
	assert.deepEqual(store.state.approvals, [{ id: 5 }])
})

test('a slow answer does not replace the result of a newer request', async () => {
	const first = deferred(), second = deferred()
	const answers = [first, second]
	const { store } = setup({ [`GET ${API}/public-bots`]: () => answers.shift().promise })
	const slow = store.loadPublicBots()
	const fast = store.loadPublicBots()
	second.resolve({ data: [{ id: 2 }] })
	await fast
	assert.deepEqual(store.state.publicBots, [{ id: 2 }])
	assert.equal(store.state.loading.publicBots, false)
	first.resolve({ data: [{ id: 1 }] })
	await slow
	assert.deepEqual(store.state.publicBots, [{ id: 2 }])
})

test('a failed list keeps the last result, reports the failure and recovers', async () => {
	let fail = false
	const { store } = setup({ [`GET ${API}/bots`]: () => fail ? Promise.reject(new Error('offline')) : Promise.resolve({ data: [{ id: 1 }] }) })
	await store.loadOwnBots()
	fail = true
	await assert.rejects(store.loadOwnBots(), /offline/)
	assert.deepEqual(store.state.ownBots, [{ id: 1 }])
	assert.equal(store.state.failed.ownBots, true)
	assert.equal(store.state.loading.ownBots, false)
	fail = false
	await store.loadOwnBots()
	assert.equal(store.state.failed.ownBots, false)
})

test('rejecting sends the reason and reloads the reviews; a failing request reloads nothing', async () => {
	const { store, calls } = setup({ [`GET ${API}/approvals`]: { bots: [] } })
	store.state.permissions.hasApprovalRights = true
	await store.rejectBot(17, 'Needs a clearer prompt')
	assert.deepEqual(calls, [
		['POST', `${API}/bots/17/reject`, { reason: 'Needs a clearer prompt' }],
		['GET', `${API}/approvals`, undefined],
	])

	const failing = setup({ [`POST ${API}/bots/17/reject`]: () => Promise.reject(new Error('forbidden')) })
	failing.store.state.permissions.hasApprovalRights = true
	await assert.rejects(failing.store.rejectBot(17, ''), /forbidden/)
	assert.equal(failing.calls.length, 1)
})

test('creating, changing and deleting a bot refresh the own list', async () => {
	const { store, calls } = setup({
		[`POST ${API}/bots`]: { id: 9, approval_status: 'draft' },
		[`PUT ${API}/bots/9`]: { id: 9, approval_status: 'pending' },
		[`GET ${API}/bots`]: [{ id: 9 }],
	})
	assert.deepEqual(await store.createBot({ botName: 'New' }), { id: 9, approval_status: 'draft' })
	assert.deepEqual(await store.updateBot(9, { botName: 'Changed' }), { id: 9, approval_status: 'pending' })
	await store.deleteBot(9)
	assert.deepEqual(calls.map(([method, url]) => `${method} ${url}`), [
		`POST ${API}/bots`, `GET ${API}/bots`,
		`PUT ${API}/bots/9`, `GET ${API}/bots`,
		`DELETE ${API}/bots/9`, `GET ${API}/bots`,
	])
	assert.deepEqual(store.state.ownBots, [{ id: 9 }])
})

test('the directory keeps what it has when one of the two lists is unavailable', async () => {
	const { store } = setup({
		[`GET ${API}/groups`]: { groups: [{ id: 'staff', displayName: 'Staff' }] },
		[`GET ${API}/teams`]: () => Promise.reject(new Error('circles disabled')),
	})
	await store.loadDirectory()
	assert.deepEqual(store.state.directory, { groups: [{ id: 'staff', displayName: 'Staff' }], teams: [] })
})
