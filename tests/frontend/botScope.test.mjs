import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

import { SCOPE_KEYS, scopeOf, visibilityOf } from '../../src/utils/botScope.js'

const source = readFileSync(new URL('../../src/styles/_scope.scss', import.meta.url), 'utf8')

function mixin(name) {
	const body = source.match(new RegExp(`@mixin ${name} \\{([^}]*)\\}`))
	assert.ok(body, `mixin ${name} exists`)
	return Object.fromEntries([...body[1].matchAll(/(--[\w-]+):\s*(#[0-9a-f]{3,6});/gi)].map(([, property, colour]) => [property, colour]))
}

function luminance(colour) {
	const hex = colour.length === 4 ? colour.replace(/[0-9a-f]/gi, '$&$&') : colour
	const [red, green, blue] = [1, 3, 5]
		.map(index => parseInt(hex.slice(index, index + 2), 16) / 255)
		.map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
	return 0.2126 * red + 0.7152 * green + 0.0722 * blue
}

function contrast(first, second) {
	const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a)
	return (light + 0.05) / (dark + 0.05)
}

const light = mixin('tokens')

for (const key of SCOPE_KEYS) {
	test(`scope colour "${key}" carries its text (WCAG AA)`, () => {
		const background = light[`--talkai-scope-${key}`]
		const text = light[`--talkai-scope-${key}-text`]
		assert.ok(background && text, 'background and text colour are defined')
		assert.ok(contrast(background, text) >= 4.5, `${text} on ${background} has ${contrast(background, text).toFixed(2)}:1`)
	})
}

test('the scopes use the four colours of the EDUC logo, the same in every theme', () => {
	assert.deepEqual(
		SCOPE_KEYS.map(key => light[`--talkai-scope-${key}`]),
		['#7e4bdd', '#28be7f', '#ff9880', '#f96e99'],
		'violet for only you, green for a group, orange for a team, pink for everyone',
	)
	assert.equal(source.match(/@mixin /g).length, 1, 'no theme overrides a scope colour')
})

test('the contrast check itself matches known WCAG values', () => {
	assert.equal(contrast('#000', '#fff'), 21)
	assert.equal(contrast('#fff', '#28be7f').toFixed(2), '2.40')
})

test('the stylesheet defines a colour for every scope and nothing else', () => {
	assert.deepEqual(
		Object.keys(light).sort(),
		SCOPE_KEYS.flatMap(key => [`--talkai-scope-${key}`, `--talkai-scope-${key}-text`]).sort(),
	)
})

test('visibility falls back to the public flag for bots saved before the field existed', () => {
	assert.equal(visibilityOf({ visibility: 'teams', is_public: true }), 'teams')
	assert.equal(visibilityOf({ is_public: true }), 'global')
	assert.equal(visibilityOf({ visibility: '', is_public: false }), 'groups')
	assert.equal(visibilityOf(undefined), 'groups')
})

test('personal and public bots need no names', () => {
	assert.deepEqual(scopeOf({ visibility: 'personal' }), { key: 'personal', label: 'Only you', detail: 'Only you' })
	assert.deepEqual(scopeOf({ visibility: 'global' }), { key: 'everyone', label: 'Everyone', detail: 'Everyone' })
	assert.deepEqual(scopeOf({ is_public: true }), { key: 'everyone', label: 'Everyone', detail: 'Everyone' })
})

test('a shared bot names the groups or teams through which the viewer has access', () => {
	assert.deepEqual(
		scopeOf({ visibility: 'groups', allowed_groups: ['ikm-students', 'staff'], access_reason: { type: 'group', names: ['IKM students'] } }),
		{ key: 'group', label: 'Group', detail: 'Group IKM students' },
	)
	assert.deepEqual(
		scopeOf({ visibility: 'teams', allowed_teams: ['a1', 'b2'], access_reason: { type: 'team', names: ['EDUC Dev', 'Library'] } }),
		{ key: 'team', label: 'Team', detail: 'Teams EDUC Dev, Library' },
	)
})

test('own bots resolve their audience through the directory', () => {
	const directory = {
		groups: [{ id: 'ikm-students', displayName: 'IKM students' }, { id: 'staff', displayName: 'Staff' }],
		teams: [{ id: 'a1', displayName: 'EDUC Dev' }],
	}
	const owner = { type: 'owner', names: [] }
	assert.equal(scopeOf({ visibility: 'groups', allowed_groups: ['staff', 'ikm-students'], access_reason: owner }, directory).detail, 'Groups Staff, IKM students')
	assert.equal(scopeOf({ visibility: 'groups', allowed_groups: ['staff'] }, directory).detail, 'Group Staff')
	assert.equal(scopeOf({ visibility: 'teams', allowed_teams: ['a1'], access_reason: owner }, directory).detail, 'Team EDUC Dev')
})

test('unknown group ids stay readable, unknown team ids are left out', () => {
	assert.equal(scopeOf({ visibility: 'groups', allowed_groups: ['tutors'] }).detail, 'Group tutors')
	assert.equal(scopeOf({ visibility: 'teams', allowed_teams: ['JCGGglEAAcvvVDNo537enKVko7wcSPH'] }).detail, 'Team')
	assert.equal(
		scopeOf({ visibility: 'teams', allowed_teams: ['a1', 'JCGGglEAAcvvVDNo537enKVko7wcSPH'] }, { teams: [{ id: 'a1', displayName: 'EDUC Dev' }] }).detail,
		'Team EDUC Dev',
	)
	assert.equal(scopeOf({ visibility: 'groups' }).detail, 'Group')
})

test('an unknown visibility is treated like a group bot', () => {
	assert.equal(scopeOf({ visibility: 'department' }).key, 'group')
})

test('reviewers are named by the audience, a personal bot has none', async () => {
	const { reviewersOf, SCOPE_ICONS } = await import('../../src/utils/botScope.js')
	assert.equal(reviewersOf({ visibility: 'personal' }), '')
	assert.equal(reviewersOf({ visibility: 'global' }), 'the administration')
	assert.equal(reviewersOf({ visibility: 'groups', allowed_groups: [] }), 'the admins of the group')
	assert.equal(
		reviewersOf({ visibility: 'groups', allowed_groups: ['ikm-students'] }, { groups: [{ id: 'ikm-students', displayName: 'IKM students' }] }),
		'the admins of the group “IKM students”',
	)
	assert.equal(
		reviewersOf({ visibility: 'teams', allowed_teams: ['a'] }, { teams: [{ id: 'a', displayName: 'EDUC Dev' }] }),
		'the admins of the team “EDUC Dev”',
	)
	assert.deepEqual(Object.keys(SCOPE_ICONS), SCOPE_KEYS)
})

const TEAMS = { teams: [{ id: 'a', displayName: 'EDUC Dev' }, { id: 'b', displayName: 'Methods lab' }] }
const twoTeams = extra => ({ visibility: 'teams', allowed_teams: ['a', 'b'], ...extra })

test('a bot for several teams or groups is reviewed by one admin of each', async () => {
	const { reviewersOf } = await import('../../src/utils/botScope.js')
	assert.equal(reviewersOf(twoTeams(), TEAMS), 'one admin of each of the teams “EDUC Dev”, “Methods lab”')
	assert.equal(
		reviewersOf({ visibility: 'groups', allowed_groups: ['staff', 'ikm'] }, { groups: [{ id: 'staff', displayName: 'Staff' }] }),
		'one admin of each of the groups “Staff”, “ikm”',
	)
	// A team the reader is not in has no name to show
	assert.equal(reviewersOf(twoTeams(), {}), 'one admin of each team')
})

test('teams the submitter manages count as approved and are not named as reviewers', async () => {
	const { reviewersOf, ownPartsOf } = await import('../../src/utils/botScope.js')
	assert.equal(reviewersOf(twoTeams(), TEAMS, { teams: ['a'] }), 'the admins of the team “Methods lab”')
	assert.deepEqual(ownPartsOf(twoTeams(), TEAMS, { teams: ['a'] }), ['EDUC Dev'])
	// Group rights say nothing about teams
	assert.equal(reviewersOf(twoTeams(), TEAMS, { groups: ['a'] }), 'one admin of each of the teams “EDUC Dev”, “Methods lab”')
	assert.deepEqual(ownPartsOf(twoTeams(), TEAMS, { groups: ['a'] }), [])
	// Managing every team: nothing is approved by submitting, the submitter approves under "To review"
	assert.equal(reviewersOf(twoTeams(), TEAMS, { teams: ['a', 'b'] }), 'one admin of each of the teams “EDUC Dev”, “Methods lab”')
	assert.deepEqual(ownPartsOf(twoTeams(), TEAMS, { teams: ['a', 'b'] }), [])
	assert.deepEqual(ownPartsOf({ visibility: 'global' }, TEAMS, { teams: ['a'] }), [])
})

test('the progress of a review names what is approved and who is still asked', async () => {
	const { reviewProgressOf } = await import('../../src/utils/botScope.js')
	const waiting = twoTeams({ approval_status: 'pending', scope_approvals: [{ id: 'a', by: 'dave', by_name: 'Dave Okafor', at: 5 }] })

	const forOwner = reviewProgressOf(waiting, TEAMS)
	assert.deepEqual(forOwner.parts.map(part => [part.name, part.approved, part.byName]), [['EDUC Dev', true, 'Dave Okafor'], ['Methods lab', false, null]])
	assert.equal(forOwner.waitingFor, 'the admins of the team “Methods lab”')
	assert.equal(forOwner.needsMe, false, 'an owner list carries no reviewer rights')

	// In the review list the API says which teams this reviewer decides for
	assert.equal(reviewProgressOf({ ...waiting, reviewable_scope_ids: ['b'] }, TEAMS).needsMe, true)
	assert.equal(reviewProgressOf({ ...waiting, reviewable_scope_ids: ['a'] }, TEAMS).needsMe, false, 'the own part is approved already')
	assert.deepEqual(reviewProgressOf({ ...waiting, reviewable_scope_ids: ['a', 'b'] }, TEAMS).parts.map(part => part.mine), [true, true])

	const untouched = reviewProgressOf(twoTeams({ scope_approvals: [], reviewable_scope_ids: ['a'] }), TEAMS)
	assert.equal(untouched.waitingFor, 'one admin of each of the teams “EDUC Dev”, “Methods lab”')
	assert.equal(untouched.needsMe, true)
})

test('the progress follows the submitted version, not the live one', async () => {
	const { reviewProgressOf } = await import('../../src/utils/botScope.js')
	// An approved bot for one team whose owner adds a second team: the id lists of a waiting change are JSON text
	const owned = { visibility: 'teams', allowed_teams: ['a'], pending_changes: { visibility: 'teams', allowed_teams: '["a","b"]' }, scope_approvals: [{ id: 'a', by: 'dave', at: 5 }] }
	assert.deepEqual(reviewProgressOf(owned, TEAMS).parts.map(part => [part.id, part.approved]), [['a', true], ['b', false]])

	// The review list delivers the submitted version with the team names
	const listed = { visibility: 'teams', allowed_teams: ['a'], review_target: { visibility: 'teams', allowed_teams: ['a', 'x'], allowed_team_names: ['EDUC Dev', 'Hidden team'] }, scope_approvals: [], reviewable_scope_ids: ['x'] }
	assert.deepEqual(reviewProgressOf(listed, {}).parts.map(part => [part.name, part.mine]), [['EDUC Dev', false], ['Hidden team', true]])
})

test('a bot for everyone has no parts: the administration decides at once', async () => {
	const { reviewProgressOf } = await import('../../src/utils/botScope.js')
	const progress = reviewProgressOf({ visibility: 'global', scope_approvals: [], reviewable_scope_ids: [] })
	assert.deepEqual(progress.parts, [])
	assert.equal(progress.waitingFor, 'the administration')
	assert.equal(progress.needsMe, true)
	assert.equal(reviewProgressOf({ visibility: 'global' }).needsMe, false)
})

test('the audience names guests only when the bot answers them', async () => {
	const { audienceWithGuests } = await import('../../src/utils/botScope.js')
	const group = scopeOf({ visibility: 'groups', allowed_groups: ['staff'] })
	assert.equal(audienceWithGuests(group, true), 'Group staff, also guests')
	assert.equal(audienceWithGuests(group, false), 'Group staff')
	assert.equal(audienceWithGuests(group, undefined), 'Group staff')
	assert.equal(audienceWithGuests(scopeOf({ visibility: 'personal' }), true), 'Only you, also guests')
})
