import { t } from '../l10n.js'

const text = value => String(value ?? '').trim()

// Sentences are the unit people change in an instruction, so they are the unit of the comparison.
const sentences = value => text(value).split(/(?<=[.!?…])\s+|\n+/).map(part => part.trim()).filter(Boolean)

const sameList = (first, second) => first.length === second.length && first.every((entry, index) => entry === second[index])

/**
 * List what a submitted version changes compared with the live one, as lines that were added or removed.
 *
 * Both versions are plain descriptions: `{ name, description, instruction, audience, tools, knowledge, model,
 * temperature, questions }`. `tools` is a list of names, or null when it is unknown for that side; `audience` is
 * the sentence the app shows for it. Fields that are undefined on either side are not compared.
 *
 * @param {object} live Version people use now
 * @param {object} next Version waiting for approval
 * @return {Array<{ kind: 'add'|'del', text: string }>} Changes in the order of the bot form
 */
export function diffVersions(live, next) {
	const changes = []
	const add = line => changes.push({ kind: 'add', text: line })
	const del = line => changes.push({ kind: 'del', text: line })
	const known = key => live?.[key] !== undefined && next?.[key] !== undefined

	if (known('name') && text(live.name) !== text(next.name)) {
		del(t('educai', 'Name: {value}', { value: text(live.name) }))
		add(t('educai', 'Name: {value}', { value: text(next.name) }))
	}
	if (known('description') && text(live.description) !== text(next.description)) {
		if (text(live.description) !== '') {
			del(t('educai', 'Description: {value}', { value: text(live.description) }))
		}
		if (text(next.description) !== '') {
			add(t('educai', 'Description: {value}', { value: text(next.description) }))
		}
	}
	if (known('instruction')) {
		const before = sentences(live.instruction)
		const after = sentences(next.instruction)
		before.filter(sentence => !after.includes(sentence)).forEach(sentence => del(sentence))
		after.filter(sentence => !before.includes(sentence)).forEach(sentence => add(sentence))
	}
	if (known('audience') && text(live.audience) !== text(next.audience)) {
		del(t('educai', 'For: {value}', { value: text(live.audience) }))
		add(t('educai', 'For: {value}', { value: text(next.audience) }))
	}
	if (Array.isArray(live?.tools) && Array.isArray(next?.tools)) {
		live.tools.filter(name => !next.tools.includes(name)).forEach(name => del(t('educai', 'Capability: {value}', { value: name })))
		next.tools.filter(name => !live.tools.includes(name)).forEach(name => add(t('educai', 'Capability: {value}', { value: name })))
	}
	if (known('knowledge') && !!live.knowledge !== !!next.knowledge) {
		if (next.knowledge) {
			add(t('educai', 'Knowledge search turned on'))
		} else {
			del(t('educai', 'Knowledge search turned off'))
		}
	}
	if (known('model') && text(live.model) !== text(next.model)) {
		add(t('educai', 'Model: {value}', { value: text(next.model) || t('educai', 'Recommended by the administration') }))
	}
	if (known('temperature') && Number(live.temperature ?? NaN) !== Number(next.temperature ?? NaN)
		&& !(live.temperature === null && next.temperature === null)) {
		add(next.temperature === null
			? t('educai', 'Creativity: default of the administration')
			: t('educai', 'Creativity: {value}', { value: String(next.temperature) }))
	}
	if (known('questions') && !sameQuestions(live.questions, next.questions)) {
		add(t('educai', 'Starter questions changed'))
	}
	return changes
}

function sameQuestions(first, second) {
	const list = tree => (Array.isArray(tree?.questions) ? tree.questions : [])
		.map(question => [question.id, text(question.text), ...(question.answers ?? []).map(answer => `${answer.id}:${text(answer.text)}:${answer.next ?? ''}:${answer.type ?? ''}`)].join('|'))
	return sameList(list(first), list(second))
}
