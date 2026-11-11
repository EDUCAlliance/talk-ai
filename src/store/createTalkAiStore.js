import { reactive } from 'vue'

const emptyPermissions = () => ({
	isAdmin: false,
	isGroupAdmin: false,
	isTeamAdmin: false,
	hasApprovalRights: false,
	adminGroups: [],
	adminTeams: [],
})

// PHP delivers a list as a JSON array and a keyed array as a JSON object.
const asList = value => Array.isArray(value)
	? value
	: (value && typeof value === 'object' ? Object.values(value) : [])

/**
 * Create the shared state of the app page: permissions, own bots, usable bots, open reviews and known audiences.
 *
 * The views read `state` and call the loaders and actions. Actions reject with the HTTP error, so the caller
 * decides how to tell the user.
 *
 * @param {object} deps Injected so the store runs without a browser
 * @param {object} deps.http HTTP client with `get`, `post`, `put` and `delete` (axios)
 * @param {(path: string) => string} deps.url Turns an app path into a URL (generateUrl)
 * @return {object} `state` plus loaders and actions
 */
export function createTalkAiStore({ http, url }) {
	const state = reactive({
		permissions: emptyPermissions(),
		ownBots: [],
		publicBots: [],
		approvals: [],
		directory: { groups: [], teams: [] },
		loaded: { permissions: false, ownBots: false, publicBots: false, approvals: false },
		loading: { ownBots: false, publicBots: false, approvals: false },
		failed: { ownBots: false, publicBots: false, approvals: false },
	})
	const api = path => url(`/apps/educai/api/v1/${path}`)
	const latest = { ownBots: 0, publicBots: 0, approvals: 0 }

	// A slow answer must not replace the result of a newer request for the same list.
	async function loadList(key, request) {
		const ticket = ++latest[key]
		state.loading[key] = true
		try {
			const list = await request()
			if (ticket !== latest[key]) {
				return
			}
			state[key] = Array.isArray(list) ? list : []
			state.failed[key] = false
			state.loaded[key] = true
		} catch (error) {
			if (ticket !== latest[key]) {
				return
			}
			state.failed[key] = true
			throw error
		} finally {
			if (ticket === latest[key]) {
				state.loading[key] = false
			}
		}
	}

	async function loadPermissions() {
		const response = await http.get(api('permissions'))
		const permissions = response.data?.permissions ?? {}
		state.permissions = {
			...emptyPermissions(),
			...permissions,
			adminGroups: asList(permissions.adminGroups),
			adminTeams: asList(permissions.adminTeams),
		}
		state.loaded.permissions = true
	}

	function loadOwnBots() {
		return loadList('ownBots', async () => (await http.get(api('bots'))).data)
	}

	function loadPublicBots() {
		return loadList('publicBots', async () => (await http.get(api('public-bots'))).data)
	}

	function loadApprovals() {
		if (!state.permissions.hasApprovalRights) {
			latest.approvals++
			state.approvals = []
			state.loading.approvals = false
			state.failed.approvals = false
			state.loaded.approvals = true
			return Promise.resolve()
		}
		return loadList('approvals', async () => (await http.get(api('approvals'))).data?.bots)
	}

	// Names of groups and teams. Without them the app still works, it only shows ids.
	async function loadDirectory() {
		const [groups, teams] = await Promise.allSettled([http.get(api('groups')), http.get(api('teams'))])
		if (groups.status === 'fulfilled' && Array.isArray(groups.value.data?.groups)) {
			state.directory.groups = groups.value.data.groups
		}
		if (teams.status === 'fulfilled' && Array.isArray(teams.value.data?.teams)) {
			state.directory.teams = teams.value.data.teams
		}
	}

	async function fetchBot(id) {
		return (await http.get(api(`bots/${id}`))).data
	}

	async function fetchPublicBot(id) {
		return (await http.get(api(`public-bots/${id}`))).data
	}

	async function fetchSources(id) {
		const sources = (await http.get(api(`bots/${id}/sources`))).data?.sources
		return Array.isArray(sources) ? sources : []
	}

	async function createBot(payload) {
		const bot = (await http.post(api('bots'), payload)).data
		await loadOwnBots().catch(() => {})
		return bot
	}

	// Tools the administration offers to this user. Without the list a new bot simply starts without tools.
	async function fetchOfferedTools() {
		const tools = (await http.get(api('tools'))).data?.tools
		return Array.isArray(tools) ? tools : []
	}

	// A request that never got an answer from the app: the connection failed, or a gateway in front of it gave up.
	const droppedOnTheWay = error => !error?.response || [502, 503].includes(error.response.status)

	// The personal assistant shows up in both lists: pinned on Discover and in first place under "My bots".
	// A user has at most one. So a request that was dropped on the way is sent once more: if the first one did
	// arrive after all, the second is refused with `assistant_exists` and the assistant is simply there.
	async function createAssistant(payload) {
		const send = async () => (await http.post(api('bots'), { ...payload, assistant: true })).data
		let bot = null
		try {
			try {
				bot = await send()
			} catch (error) {
				if (!droppedOnTheWay(error)) {
					throw error
				}
				try {
					bot = await send()
				} catch (again) {
					if (again?.response?.data?.errorCode !== 'assistant_exists') {
						throw again
					}
				}
			}
		} finally {
			// Also after a refusal: "already set up" means the lists on screen are out of date.
			await Promise.all([loadOwnBots().catch(() => {}), loadPublicBots().catch(() => {})])
		}
		return bot ?? state.ownBots.find(own => own?.is_assistant === true) ?? null
	}

	async function updateBot(id, payload) {
		const bot = (await http.put(api(`bots/${id}`), payload)).data
		await loadOwnBots().catch(() => {})
		return bot
	}

	async function deleteBot(id) {
		await http.delete(api(`bots/${id}`))
		await loadOwnBots().catch(() => {})
	}

	async function submitBot(id, answers) {
		const bot = (await http.post(api(`bots/${id}/submit`), answers)).data
		await Promise.all([loadOwnBots().catch(() => {}), loadApprovals().catch(() => {})])
		return bot
	}

	async function approveBot(id) {
		const bot = (await http.post(api(`bots/${id}/approve`))).data
		await loadApprovals()
		return bot
	}

	async function rejectBot(id, reason) {
		const bot = (await http.post(api(`bots/${id}/reject`), { reason })).data
		await loadApprovals()
		return bot
	}

	async function enableTest(id) {
		return (await http.post(api(`bots/${id}/enable-test`))).data
	}

	return {
		state,
		loadPermissions,
		loadOwnBots,
		loadPublicBots,
		loadApprovals,
		loadDirectory,
		fetchBot,
		fetchPublicBot,
		fetchSources,
		createBot,
		fetchOfferedTools,
		createAssistant,
		updateBot,
		deleteBot,
		submitBot,
		approveBot,
		rejectBot,
		enableTest,
	}
}
