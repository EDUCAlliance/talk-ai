import assert from 'node:assert/strict'
import test from 'node:test'

import { diffVersions } from '../../src/utils/botDiff.js'

const lines = (live, next) => diffVersions(live, next).map(change => `${change.kind === 'add' ? '+' : '-'} ${change.text}`)

test('an instruction is compared sentence by sentence', () => {
	assert.deepEqual(lines(
		{ instruction: 'You help students. Answer in a friendly and concise way.' },
		{ instruction: 'You help students. Answer in a friendly and concise way and always name where you found the answer.' },
	), [
		'- Answer in a friendly and concise way.',
		'+ Answer in a friendly and concise way and always name where you found the answer.',
	])
	assert.deepEqual(lines({ instruction: 'One.\nTwo.' }, { instruction: 'One. Two.' }), [])
})

test('changed fields are listed in the order of the form', () => {
	assert.deepEqual(lines(
		{ name: 'Study FAQ', description: '', audience: 'Group IKM students', tools: ['Documents in chat'], knowledge: false, temperature: null, model: '' },
		{ name: 'Study FAQ IKM', description: 'Answers questions.', audience: 'Everyone', tools: ['Documents in chat', 'Images'], knowledge: true, temperature: 0.4, model: 'primary:qwen' },
	), [
		'- Name: Study FAQ',
		'+ Name: Study FAQ IKM',
		'+ Description: Answers questions.',
		'- For: Group IKM students',
		'+ For: Everyone',
		'+ Capability: Images',
		'+ Knowledge search turned on',
		'+ Model: primary:qwen',
		'+ Creativity: 0.4',
	])
})

test('removed tools and a paused knowledge search are removals', () => {
	assert.deepEqual(lines({ tools: ['Images', 'Voice'], knowledge: true }, { tools: ['Images'], knowledge: false }), [
		'- Capability: Voice',
		'- Knowledge search turned off',
	])
})

test('what is unknown on one side is not compared', () => {
	assert.deepEqual(lines({ name: 'A', tools: null }, { tools: ['Images'] }), [])
	assert.deepEqual(lines({}, {}), [])
	assert.deepEqual(lines(undefined, undefined), [])
})

test('equal versions have no changes, whitespace alone is no change', () => {
	const version = { name: 'A', description: 'B', instruction: 'C.', audience: 'Everyone', tools: ['Images'], knowledge: true, model: null, temperature: null, questions: null }
	assert.deepEqual(lines(version, { ...version, name: ' A ', description: 'B ', model: '' }), [])
})

test('starter questions are reported as changed, not spelled out', () => {
	const tree = text => ({ start: 'q1', questions: [{ id: 'q1', text, answers: [{ id: 'a', text: 'Yes', next: null }] }] })
	assert.deepEqual(lines({ questions: tree('Old?') }, { questions: tree('New?') }), ['+ Starter questions changed'])
	assert.deepEqual(lines({ questions: tree('Same?') }, { questions: tree('Same?') }), [])
	assert.deepEqual(lines({ questions: null }, { questions: tree('New?') }), ['+ Starter questions changed'])
})

test('going back to the default creativity is named', () => {
	assert.deepEqual(lines({ temperature: 0.6 }, { temperature: null }), ['+ Creativity: default of the administration'])
})
