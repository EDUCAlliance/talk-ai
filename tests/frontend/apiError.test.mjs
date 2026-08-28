import assert from 'node:assert/strict'
import test from 'node:test'

import { getApiErrorMessage } from '../../src/utils/apiError.js'

test('uses a safe backend message when a stable error code is present', () => {
	const error = {
		response: {
			data: {
				error: 'Localized safe message',
				errorCode: 'stable_error_code',
			},
		},
	}

	assert.equal(getApiErrorMessage(error, 'Fallback'), 'Localized safe message')
})

test('rejects legacy raw exception messages without an error code', () => {
	const error = {
		response: {
			data: {
				error: 'SQLSTATE: secret database details',
			},
		},
	}

	assert.equal(getApiErrorMessage(error, 'Localized fallback'), 'Localized fallback')
})

test('rejects empty or malformed coded error payloads', () => {
	assert.equal(getApiErrorMessage({ errorCode: '', error: 'Unsafe' }, 'Fallback'), 'Fallback')
	assert.equal(getApiErrorMessage({ errorCode: 'code', error: '   ' }, 'Fallback'), 'Fallback')
	assert.equal(getApiErrorMessage(new Error('Internal detail'), 'Fallback'), 'Fallback')
})
