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
 * Name who reviews a bot before it is shared. A personal bot needs no approval and gets an empty string.
 *
 * The result is a nominative phrase, meant to follow a label such as "Reviewed by:".
 *
 * @param {object} bot Bot as delivered by the API
 * @param {object} [directory] Known audiences, see scopeOf()
 * @return {string} For example `the admins of the group “IKM students”`
 */
export function reviewersOf(bot, directory = {}) {
	const key = SCOPE_BY_VISIBILITY[visibilityOf(bot)] ?? 'group'
	if (key === 'personal') {
		return ''
	}
	if (key === 'everyone') {
		return t('educai', 'the administration')
	}

	const audience = audienceNames(bot, key, directory)
	const names = audience.map(name => `“${name}”`).join(', ')
	if (key === 'team') {
		return names === ''
			? t('educai', 'the admins of the team')
			: n('educai', 'the admins of the team {names}', 'the admins of the teams {names}', audience.length, { names })
	}
	return names === ''
		? t('educai', 'the admins of the group')
		: n('educai', 'the admins of the group {names}', 'the admins of the groups {names}', audience.length, { names })
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
