import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

test('mention names use a valid HTML Unicode Sets pattern', () => {
	const template = readFileSync(new URL('../../src/components/BotForm.vue', import.meta.url), 'utf8')
	const input = template.match(/<input\s[^>]*id="mention-name"[^>]*>/)[0]
	const pattern = input.match(/pattern="([^"]+)"/)[1]
	// HTML pattern uses the v flag and matches the entire value.
	const valid = new RegExp(`^(?:${pattern})$`, 'v')
	for (const name of ['supportbot', 'Support_42', 'support-bot']) {
		assert.equal(valid.test(name), true, name)
	}
	for (const name of ['bad name!', '@supportbot', 'bot/name', 'bot[1]', 'böt']) {
		assert.equal(valid.test(name), false, name)
	}
})
