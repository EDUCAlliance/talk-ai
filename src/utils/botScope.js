import { n, t } from '../l10n.js'

/**
 * Sharing scopes in the order the app lists them. Each one has its own colour in src/styles/_scope.scss.
 */
export const SCOPE_KEYS = ['personal', 'group', 'team', 'everyone']

/**
 * Icon name per scope (see components/TalkAiIcon.vue). The icon and the words carry the meaning, the colour supports it.
 */
export const SCOPE_ICONS = { personal: 'lock', group: 'group', team: 'team', everyone: 'globe' }

const SCOPE_BY_VISIBILITY = {
	personal: 'personal',
	groups: 'group',
	teams: 'team',
	global: 'everyone',
}

/**
 * Return the stored visibility of a bot, including the fallback for bots saved before the field existed.
 *
 * @param {object} bot Bot as delivered by the API
 * @return {string} `personal`, `groups`, `teams` or `global`
 */
export function visibilityOf(bot) {
	return bot?.visibility || (bot?.is_public ? 'global' : 'groups')
}

/**
 * Describe who can use a bot: the scope decides the colour and the icon, the detail names the audience.
 *
 * In lists of other people's bots the API names the viewer's own matching groups or teams. For own bots it only
 * delivers ids, which the directory resolves. A team id says nothing to a reader, so an unknown one is left out.
 *
 * @param {object} bot Bot as delivered by the API
 * @param {object} [directory] Known audiences: `{ groups, teams }`, each a list of `{ id, displayName }`
 * @return {{ key: string, label: string, detail: string }} Scope key, generic label and label with names
 */
export function scopeOf(bot, directory = {}) {
	const key = SCOPE_BY_VISIBILITY[visibilityOf(bot)] ?? 'group'
	if (key === 'personal') {
		return { key, label: t('educai', 'Only you'), detail: t('educai', 'Only you') }
	}
	if (key === 'everyone') {
		return { key, label: t('educai', 'Everyone'), detail: t('educai', 'Everyone') }
	}

	const audience = audienceNames(bot, key, directory)
	const names = audience.join(', ')
	if (key === 'team') {
		const label = t('educai', 'Team')
		return { key, label, detail: names === '' ? label : n('educai', 'Team {names}', 'Teams {names}', audience.length, { names }) }
	}
	const label = t('educai', 'Group')
	return { key, label, detail: names === '' ? label : n('educai', 'Group {names}', 'Groups {names}', audience.length, { names }) }
}

/**
 * Name who gets answers from a bot: its audience, and guests when the bot answers them too.
 * Guests are people who take part in a public conversation without an account.
 *
 * @param {{ detail: string }} scope Result of scopeOf()
 * @param {boolean} allowGuests Whether the bot also answers guests
 * @return {string} For example `Group IKM students, also guests`
 */
export function audienceWithGuests(scope, allowGuests) {
	return allowGuests ? t('educai', '{audience}, also guests', { audience: scope.detail }) : scope.detail
}

/**
 * Name who reviews a bot before it is shared. A personal bot needs no approval and gets an empty string.
 *
 * A bot for several groups or teams is approved part by part: one admin of each of them. Groups or teams the
 * submitter manages count as approved when the bot is submitted, unless the submitter manages all of them, so
 * `own` leaves them out of the phrase.
 *
 * The result is a nominative phrase, meant to follow a label such as "Reviewed by:".
 *
 * @param {object} bot Bot as delivered by the API
 * @param {object} [directory] Known audiences, see scopeOf()
 * @param {{ groups?: string[], teams?: string[] }} [own] Ids of the groups and teams the submitter manages
 * @return {string} For example `the admins of the group “IKM students”`
 */
export function reviewersOf(bot, directory = {}, own = {}) {
	const key = SCOPE_BY_VISIBILITY[visibilityOf(bot)] ?? 'group'
	if (key === 'personal') {
		return ''
	}
	if (key === 'everyone') {
		return t('educai', 'the administration')
	}

	// Other people's bots come with the names of the viewer's own matching groups or teams.
	const reason = bot?.access_reason
	if (reason?.type === key && Array.isArray(reason.names) && reason.names.length > 0) {
		return adminsOf(key, reason.names.map(name => ({ name })))
	}

	const parts = partsOf(bot, key, directory)
	const managed = (key === 'team' ? own.teams : own.groups) ?? []
	const others = parts.filter(part => !managed.includes(part.id))
	return adminsOf(key, others.length > 0 ? others : parts)
}

/**
 * Name the groups or teams of a bot that count as approved the moment it is submitted, because the submitter
 * manages them. Empty when the submitter manages none of them, or all: then they approve under "To review".
 *
 * @param {object} bot Bot as delivered by the API
 * @param {object} [directory] Known audiences, see scopeOf()
 * @param {{ groups?: string[], teams?: string[] }} [own] Ids of the groups and teams the submitter manages
 * @return {string[]} Names, for example `['EDUC Dev']`
 */
export function ownPartsOf(bot, directory = {}, own = {}) {
	const key = SCOPE_BY_VISIBILITY[visibilityOf(bot)] ?? 'group'
	if (key !== 'group' && key !== 'team') {
		return []
	}
	const parts = partsOf(bot, key, directory)
	const managed = (key === 'team' ? own.teams : own.groups) ?? []
	const mine = parts.filter(part => managed.includes(part.id))
	return mine.length > 0 && mine.length < parts.length ? mine.map(part => part.name ?? part.id) : []
}

/**
 * Describe how far the review of a waiting bot is: its groups or teams in the submitted version, and which of
 * them already approved. A personal bot and a bot for everyone have no parts.
 *
 * @param {object} bot Waiting bot as delivered by the API, with `scope_approvals` and, in the review list,
 *                     `review_target` and `reviewable_scope_ids`
 * @param {object} [directory] Known audiences, see scopeOf()
 * @return {{ key: string, parts: Array<{ id: string, name: string|null, approved: boolean, byName: string|null, mine: boolean }>, waitingFor: string, needsMe: boolean }}
 *         `waitingFor` names who still has to approve, `needsMe` says whether this reviewer has a part left
 */
export function reviewProgressOf(bot, directory = {}) {
	const target = submittedVersionOf(bot)
	const key = SCOPE_BY_VISIBILITY[visibilityOf(target)] ?? 'group'
	const reviewable = Array.isArray(bot?.reviewable_scope_ids) ? bot.reviewable_scope_ids : null
	if (key !== 'group' && key !== 'team') {
		return { key, parts: [], waitingFor: reviewersOf(target, directory), needsMe: reviewable !== null }
	}

	const approvals = new Map((Array.isArray(bot?.scope_approvals) ? bot.scope_approvals : []).map(entry => [entry.id, entry]))
	const parts = partsOf(target, key, directory).map(part => {
		const approval = approvals.get(part.id)
		return {
			...part,
			approved: approval !== undefined,
			byName: approval ? (approval.by_name ?? approval.by ?? null) : null,
			mine: reviewable !== null && reviewable.includes(part.id),
		}
	})
	const open = parts.filter(part => !part.approved)
	return {
		key,
		parts,
		waitingFor: parts.length === 0 ? reviewersOf(target, directory) : adminsOf(key, open),
		needsMe: reviewable !== null && (parts.length === 0 || open.some(part => part.mine)),
	}
}

/**
 * The groups and teams whose approval a user gives by submitting a bot: the ones they manage. A Nextcloud
 * administrator decides for every group or team and therefore approves under "To review" instead.
 *
 * @param {{ isAdmin?: boolean, adminGroups?: string[], adminTeams?: string[] }} permissions Permissions of the user
 * @return {{ groups: string[], teams: string[] }} For reviewersOf() and ownPartsOf()
 */
export function ownScopesOf(permissions) {
	return permissions?.isAdmin
		? { groups: [], teams: [] }
		: { groups: permissions?.adminGroups ?? [], teams: permissions?.adminTeams ?? [] }
}

// The version under review: a waiting change may give the bot another audience than the live one.
function submittedVersionOf(bot) {
	if (bot?.review_target) {
		return bot.review_target
	}
	const pending = bot?.pending_changes
	if (!pending || typeof pending !== 'object') {
		return bot
	}
	return {
		...bot,
		visibility: pending.visibility || bot.visibility,
		is_public: pending.is_public ?? bot.is_public,
		allowed_groups: idList(pending.allowed_groups) ?? bot.allowed_groups,
		allowed_teams: idList(pending.allowed_teams) ?? bot.allowed_teams,
	}
}

// Waiting changes store their id lists as JSON text.
function idList(value) {
	if (Array.isArray(value)) {
		return value
	}
	if (typeof value !== 'string' || value === '') {
		return null
	}
	try {
		const parsed = JSON.parse(value)
		return Array.isArray(parsed) ? parsed : null
	} catch {
		return null
	}
}

// The groups or teams of a bot with their names. A team id says nothing to a reader, so an unknown team has no name.
function partsOf(bot, key, directory) {
	const ids = (key === 'team' ? bot?.allowed_teams : bot?.allowed_groups) ?? []
	const names = key === 'team' ? bot?.allowed_team_names : null
	const known = new Map(((key === 'team' ? directory.teams : directory.groups) ?? [])
		.map(entry => [entry.id, entry.displayName]))
	return [...new Set(ids)].map(id => ({
		id,
		name: known.get(id) ?? (Array.isArray(names) ? names[ids.indexOf(id)] : null) ?? (key === 'team' ? null : id),
	}))
}

// "the admins of the team “A”" for one group or team, "one admin of each of the teams “A”, “B”" for several.
function adminsOf(key, parts) {
	const names = parts.map(part => part.name).filter(name => typeof name === 'string' && name !== '').map(name => `“${name}”`).join(', ')
	if (key === 'team') {
		return names === ''
			? (parts.length > 1 ? t('educai', 'one admin of each team') : t('educai', 'the admins of the team'))
			: n('educai', 'the admins of the team {names}', 'one admin of each of the teams {names}', parts.length, { names })
	}
	return names === ''
		? (parts.length > 1 ? t('educai', 'one admin of each group') : t('educai', 'the admins of the group'))
		: n('educai', 'the admins of the group {names}', 'one admin of each of the groups {names}', parts.length, { names })
}

function audienceNames(bot, key, directory) {
	const reason = bot?.access_reason
	if (reason?.type === key && Array.isArray(reason.names) && reason.names.length > 0) {
		return reason.names
	}

	const ids = (key === 'team' ? bot?.allowed_teams : bot?.allowed_groups) ?? []
	const known = new Map(((key === 'team' ? directory.teams : directory.groups) ?? [])
		.map(entry => [entry.id, entry.displayName]))
	return ids
		.map(id => known.get(id) ?? (key === 'team' ? null : id))
		.filter(name => typeof name === 'string' && name !== '')
}
