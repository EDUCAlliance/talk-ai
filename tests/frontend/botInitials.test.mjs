import assert from 'node:assert/strict'
import test from 'node:test'

import { initialsOf } from '../../src/utils/botInitials.js'

test('two words give their first letters', () => {
	assert.equal(initialsOf('Study FAQ IKM'), 'SF')
	assert.equal(initialsOf('thesis coach'), 'TC')
	assert.equal(initialsOf('Project memory EDUC Dev'), 'PM')
	assert.equal(initialsOf('Mensa-Bot'), 'MB')
})

test('a leading abbreviation and a single word give two letters of the first word', () => {
	assert.equal(initialsOf('IT support'), 'IT')
	assert.equal(initialsOf('Canteen'), 'CA')
	assert.equal(initialsOf('x'), 'X')
})

test('letters outside ASCII count, symbols do not', () => {
	assert.equal(initialsOf('Übungs Önologe'), 'ÜÖ')
	assert.equal(initialsOf('@canteen'), 'CA')
	assert.equal(initialsOf('42 answers'), '4A')
	assert.equal(initialsOf('🤖 Robo helper'), 'RH')
})

test('a name without letters or digits gives nothing', () => {
	assert.equal(initialsOf(''), '')
	assert.equal(initialsOf(' 🤖 '), '')
	assert.equal(initialsOf(undefined), '')
})
