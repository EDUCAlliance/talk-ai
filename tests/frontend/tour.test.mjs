import assert from 'node:assert/strict'
import test from 'node:test'

import { placeTourCard, tour, tourSteps } from '../../src/utils/tour.js'

const ids = options => tourSteps({ appName: 'Talk AI', ...options }).map(step => step.id)

test('the tour is closed until someone starts it', () => {
	assert.equal(tour.open, false)
})

test('a member with bots and an invitation gets the whole tour, without "To review"', () => {
	assert.deepEqual(ids({ usableBots: 4, assistantInvitation: true }),
		['welcome', 'discover', 'assistant', 'find', 'tile', 'new', 'my-bots', 'activity', 'end'])
})

test('people with approval rights also get "To review", after "My bots"', () => {
	assert.deepEqual(ids({ usableBots: 4, assistantInvitation: true, hasApprovalRights: true }),
		['welcome', 'discover', 'assistant', 'find', 'tile', 'new', 'my-bots', 'review', 'activity', 'end'])
})

test('steps for things the user does not have are left out from the start', () => {
	assert.deepEqual(ids({}), ['welcome', 'discover', 'new', 'my-bots', 'activity', 'end'], 'nobody shared a bot, the assistant is unknown')
	assert.deepEqual(ids({ usableBots: 1, pinnedAssistant: true }),
		['welcome', 'discover', 'assistant', 'find', 'new', 'my-bots', 'activity', 'end'], 'the only bot is the pinned assistant: no second tile to explain')
	assert.deepEqual(ids({ usableBots: 2, pinnedAssistant: true }),
		['welcome', 'discover', 'assistant', 'find', 'tile', 'new', 'my-bots', 'activity', 'end'])
	assert.deepEqual(ids({ usableBots: 3 }),
		['welcome', 'discover', 'find', 'tile', 'new', 'my-bots', 'activity', 'end'], 'bots, but neither invitation nor assistant on the page')
})

test('every step has a title and a text, and only the first one has no element', () => {
	const steps = tourSteps({ appName: 'Talk AI', usableBots: 4, assistantInvitation: true, hasApprovalRights: true })
	for (const step of steps) {
		assert.ok(step.title.length > 3 && step.text.length > 20, step.id)
		assert.equal('shown' in step, false, step.id)
	}
	assert.equal(steps[0].title, 'Welcome to Talk AI')
	assert.deepEqual(steps.filter(step => !step.target).map(step => step.id), ['welcome'])
	assert.deepEqual(steps.filter(step => step.guide).map(step => step.id), ['welcome', 'end'], 'the guide is offered at both ends')
	assert.equal(new Set(steps.map(step => step.id)).size, steps.length)
})

test('pages are opened for the steps that explain them', () => {
	const routes = Object.fromEntries(tourSteps({ appName: 'Talk AI', usableBots: 4, assistantInvitation: true, hasApprovalRights: true })
		.map(step => [step.id, step.route]))
	assert.equal(routes.discover, '/discover')
	assert.equal(routes.tile, '/discover')
	assert.equal(routes['my-bots'], '/my-bots')
	assert.equal(routes.review, '/review')
	assert.equal(routes.activity, '/activity')
	assert.equal(routes.welcome, undefined, 'the tour starts on the page the user is on')
	assert.equal(routes.new, undefined, 'the button is explained, the page for a new bot is not opened')
})

const viewport = { width: 1440, height: 900 }
const card = { width: 360, height: 200 }

test('the card goes to the preferred side when there is room, level with the element', () => {
	const navEntry = { top: 90, left: 10, width: 296, height: 46 }
	assert.deepEqual(placeTourCard({ target: navEntry, card, viewport, placement: 'right' }), { side: 'right', top: 90, left: 320 })

	const banner = { top: 185, left: 343, width: 1055, height: 129 }
	assert.deepEqual(placeTourCard({ target: banner, card, viewport, placement: 'bottom' }), { side: 'bottom', top: 328, left: 343 })
})

test('the card takes another side when the preferred one has no room', () => {
	const wide = { top: 185, left: 343, width: 1055, height: 129 }
	assert.equal(placeTourCard({ target: wide, card, viewport, placement: 'right' }).side, 'bottom')

	const atBottom = { top: 760, left: 343, width: 400, height: 120 }
	assert.equal(placeTourCard({ target: atBottom, card, viewport, placement: 'bottom' }).side, 'right')

	const bottomRight = { top: 700, left: 1000, width: 420, height: 180 }
	assert.deepEqual(placeTourCard({ target: bottomRight, card, viewport, placement: 'bottom' }), { side: 'top', top: 486, left: 1000 })
})

test('the card never leaves the window', () => {
	const lastNavEntry = { top: 844, left: 10, width: 296, height: 46 }
	assert.deepEqual(placeTourCard({ target: lastNavEntry, card, viewport, placement: 'right' }), { side: 'right', top: 688, left: 320 })

	const farRight = { top: 100, left: 1300, width: 120, height: 60 }
	const below = placeTourCard({ target: farRight, card, viewport, placement: 'bottom' })
	assert.equal(below.side, 'bottom')
	assert.equal(below.left, 1440 - 360 - 12)
})

test('without an element, or without room on any side, the card is centred', () => {
	assert.deepEqual(placeTourCard({ target: null, card, viewport }), { side: 'center', top: 350, left: 540 })

	const everything = { top: 20, left: 20, width: 1400, height: 860 }
	assert.equal(placeTourCard({ target: everything, card, viewport, placement: 'right' }).side, 'center')

	const tiny = placeTourCard({ target: null, card, viewport: { width: 320, height: 180 } })
	assert.deepEqual(tiny, { side: 'center', top: 12, left: 12 }, 'a window smaller than the card keeps the margin at the top and the start')
})
