import assert from 'node:assert/strict'
import test from 'node:test'

import { statusOf } from '../../src/utils/botStatus.js'

const labels = bot => statusOf(bot).chips.map(chip => chip.label)

test('a personal bot is active and needs no approval', () => {
	const status = statusOf({ approval_status: 'personal', visibility: 'personal' })
	assert.equal(status.stage, 'personal')
	assert.deepEqual(labels({ approval_status: 'personal' }), ['Active'])
	assert.equal(status.note, '')
})

test('a draft waits for the owner, a returned draft shows the feedback instead', () => {
	assert.equal(statusOf({ approval_status: 'draft' }).stage, 'draft')
	assert.deepEqual(labels({ approval_status: 'draft' }), ['Draft'])
	assert.equal(statusOf({ approval_status: 'draft' }).note, 'Not submitted yet')

	const returned = statusOf({ approval_status: 'draft', rejection_reason: ' Please add the source. ' })
	assert.equal(returned.stage, 'feedback')
	assert.deepEqual(returned.chips.map(chip => chip.label), ['Feedback'])
	assert.equal(returned.feedback, 'Please add the source.')
	assert.equal(returned.note, '')
})

test('a first submission is in review, a change to an approved bot keeps the approved state visible', () => {
	const first = statusOf({ approval_status: 'pending', has_pending_changes: false })
	assert.equal(first.stage, 'in-review')
	assert.deepEqual(first.chips.map(chip => chip.label), ['In review'])

	const change = statusOf({ approval_status: 'pending', has_pending_changes: true, approved_at: 1 })
	assert.equal(change.stage, 'change-in-review')
	assert.deepEqual(change.chips.map(chip => chip.label), ['Approved', 'Change in review'])
	assert.equal(change.note, 'The current version stays live until approval.')
})

test('an approved bot whose change was rejected stays approved and carries the reason', () => {
	const status = statusOf({ approval_status: 'approved', rejection_reason: 'Too broad' })
	assert.equal(status.stage, 'approved')
	assert.deepEqual(status.chips.map(chip => chip.label), ['Approved', 'Change rejected'])
	assert.equal(status.feedback, 'Too broad')
})

test('bots saved before the approval flow count as approved, an inactive bot says so', () => {
	assert.equal(statusOf({}).stage, 'approved')
	assert.deepEqual(labels({ is_active: false }), ['Approved', 'Inactive'])
	assert.deepEqual(labels({ is_active: true }), ['Approved'])
	assert.equal(statusOf(undefined).stage, 'approved')
})

test('the status dot is reserved for the state itself, not for the additions', () => {
	const change = statusOf({ approval_status: 'pending', has_pending_changes: true })
	assert.deepEqual(change.chips.map(chip => [chip.tone, chip.dot]), [['ok', true], ['info', false]])
})
