import { t } from '../l10n.js'

export function modelReference(value) {
	return typeof value === 'string' ? value.trim() : ''
}

// Only adopt a binding confirmed by saved server state, never guess from discovery.
export function adoptSavedModelBinding(current, saved) {
	const reference = modelReference(current)
	const savedParts = splitModelReference(saved)
	return reference && !splitModelReference(reference) && savedParts?.model === reference ? modelReference(saved) : current
}

export function adoptSavedModelBindings(current, saved) {
	if (!Array.isArray(saved)) return current
	return current.map(reference => {
		const matches = saved.filter(value => splitModelReference(value)?.model === reference)
		return matches.length === 1 ? adoptSavedModelBinding(reference, matches[0]) : reference
	})
}

export function splitModelReference(value) {
	const reference = modelReference(value)
	const match = /^(primary|secondary):(\S+)$/u.exec(reference)
	if (!match || reference.length > 512 || [...reference].some(character => character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127)) return null
	return { endpoint: match[1], model: match[2] }
}

export function modelLabel(value) {
	const reference = modelReference(value)
	const parts = splitModelReference(reference)
	if (!reference) return ''
	if (!parts) return t('educai', '{model} · provider not selected', { model: reference })
	return parts.endpoint === 'secondary'
		? t('educai', 'Secondary · {model}', { model: parts.model })
		: t('educai', 'Primary · {model}', { model: parts.model })
}

export function normalizeModelOptions(rawOptions, rawModels) {
	const entries = Array.isArray(rawOptions) ? rawOptions : (Array.isArray(rawModels) ? rawModels.map(id => ({ id })) : [])
	const seen = new Set()
	return entries.flatMap(option => {
		const id = modelReference(option?.id)
		const parts = splitModelReference(id)
		if (!id || seen.has(id)) return []
		seen.add(id)
		return [{
			...option,
			id,
			model: parts?.model || id,
			endpoint: parts?.endpoint || null,
			label: modelLabel(id),
			availability: option.availability === 'stale' ? 'stale' : 'available',
		}]
	})
}

// Keep the actual stored value selectable; discovery must never change a choice.
export function modelOptionsWithCurrent(options, current) {
	const result = options.map(option => ({ ...option }))
	const seen = new Set(result.map(option => option.id))
	for (const value of Array.isArray(current) ? current : [current]) {
		const id = modelReference(value)
		if (!id || seen.has(id)) continue
		const parts = splitModelReference(id)
		result.push({ id, model: parts?.model || id, endpoint: parts?.endpoint || null, label: modelLabel(id), availability: 'missing' })
		seen.add(id)
	}
	return result.map(option => ({
		...option,
		label: option.availability === 'stale'
			? t('educai', '{model} — last known list', { model: modelLabel(option.id) })
			: option.availability === 'missing' && option.endpoint
				? t('educai', '{model} — not in current catalogue', { model: modelLabel(option.id) })
				: modelLabel(option.id),
	}))
}

export function catalogueRefreshDelay(seconds) {
	const interval = Number(seconds)
	return Math.max(30, Number.isFinite(interval) && interval > 0 ? interval : 300) * 1000
}
