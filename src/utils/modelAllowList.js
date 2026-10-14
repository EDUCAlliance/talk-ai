import { splitModelReference, modelReference } from './modelSelection.js'

export const NEW_MODEL_SECONDS = 14 * 24 * 60 * 60
const ENDPOINTS = ['primary', 'secondary']

function byModel(a, b) {
	return a.model.localeCompare(b.model, undefined, { sensitivity: 'base', numeric: true })
}

/**
 * @param {Array} options Normalized catalogue options
 * @param {string[]} selected Released model references
 * @param {object} config Display options
 * @param {string} config.filter Case-insensitive model filter
 * @param {string} config.defaultModel Default model reference
 * @param {string} config.fallbackModel Fallback model reference
 * @param {number} config.now Current time in seconds
 * @param {number} config.collapsedLimit Unreleased models shown before "Show all"
 * @param {number} config.filterLimit Maximum matches shown while filtering
 * @param {object} config.expanded Providers whose full list is shown
 * @param {string[]} config.unlistedEndpoints Providers that do not list their models
 * @return {Array}
 */
export function buildAllowListGroups(options, selected, {
	filter = '',
	defaultModel = '',
	fallbackModel = '',
	now = Math.floor(Date.now() / 1000),
	collapsedLimit = 8,
	filterLimit = 100,
	expanded = {},
	unlistedEndpoints = [],
} = {}) {
	const released = new Set((Array.isArray(selected) ? selected : []).map(modelReference).filter(Boolean))
	const query = String(filter ?? '').trim().toLowerCase()
	const items = new Map()
	for (const option of Array.isArray(options) ? options : []) {
		const parts = splitModelReference(option?.id)
		if (!parts || items.has(option.id)) continue
		const firstSeen = Number(option.first_seen_at)
		items.set(option.id, {
			id: option.id,
			model: parts.model,
			endpoint: parts.endpoint,
			availability: option.availability === 'stale' ? 'stale' : 'available',
			isNew: Number.isFinite(firstSeen) && firstSeen > 0 && now - firstSeen < NEW_MODEL_SECONDS,
		})
	}
	for (const id of released) {
		if (items.has(id)) continue
		const parts = splitModelReference(id)
		const availability = parts && unlistedEndpoints.includes(parts.endpoint) ? 'manual' : 'missing'
		items.set(id, { id, model: parts?.model || id, endpoint: parts?.endpoint || 'unbound', availability, isNew: false })
	}

	const groups = []
	for (const endpoint of [...ENDPOINTS, 'unbound']) {
		const all = [...items.values()].filter(item => item.endpoint === endpoint)
		if (all.length === 0) continue
		const decorated = all.map(item => {
			const isSelected = released.has(item.id)
			const tags = []
			if (item.id === modelReference(defaultModel)) tags.push('default')
			if (item.id === modelReference(fallbackModel)) tags.push('fallback')
			if (item.availability === 'missing') tags.push('missing')
			else if (item.availability === 'stale') tags.push('stale')
			if (item.isNew && !isSelected) tags.push('new')
			return { ...item, selected: isSelected, tags }
		})
		const matches = query ? decorated.filter(item => item.model.toLowerCase().includes(query)) : decorated
		if (query && matches.length === 0) continue
		const chosen = matches.filter(item => item.selected).sort(byModel)
		const others = matches.filter(item => !item.selected).sort(byModel)
		let visible
		if (query) {
			visible = [...chosen, ...others].slice(0, filterLimit)
		} else if (expanded[endpoint]) {
			visible = [...chosen, ...others]
		} else {
			visible = [...chosen, ...others.slice(0, collapsedLimit)]
		}
		const listed = decorated.filter(item => item.availability === 'available' || item.availability === 'stale')
		groups.push({
			endpoint,
			total: listed.length,
			selectedCount: listed.filter(item => item.selected).length,
			missingCount: decorated.filter(item => item.selected && item.availability === 'missing').length,
			manualCount: decorated.filter(item => item.selected && item.availability === 'manual').length,
			matchIds: matches.map(item => item.id),
			items: visible,
			hiddenCount: matches.length - visible.length,
		})
	}
	return groups
}

/**
 * @param {string[]} selected Current selection
 * @param {string[]} ids References to change
 * @param {boolean} release Whether the references should be released
 * @return {string[]}
 */
export function setReleased(selected, ids, release) {
	const current = Array.isArray(selected) ? [...selected] : []
	const changing = new Set(ids)
	if (!release) return current.filter(id => !changing.has(id))
	const present = new Set(current)
	const additions = []
	for (const id of ids) {
		if (present.has(id)) continue
		present.add(id)
		additions.push(id)
	}
	return [...current, ...additions]
}
