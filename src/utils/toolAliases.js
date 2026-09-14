/**
 * Resolve saved provider aliases for the editor without changing persistent data.
 *
 * @param {Array<string|number>} selected Saved tool-selection keys
 * @param {object} configs Saved per-tool configuration keyed by selection
 * @param {Array<object>} availableTools Currently available provider descriptors
 */
export function normalizeToolSelectionAliases(selected, configs, availableTools) {
	const names = new Set(availableTools.filter((tool) => tool.is_builtin).map((tool) => tool.builtin_name))
	const aliases = new Map()
	for (const tool of availableTools) {
		if (!tool.is_builtin || !tool.builtin_name || !Array.isArray(tool.aliases)) continue
		for (const alias of tool.aliases) {
			if (typeof alias === 'string' && alias !== '' && !names.has(alias) && !aliases.has(`builtin:${alias}`)) {
				aliases.set(`builtin:${alias}`, `builtin:${tool.builtin_name}`)
			}
		}
	}
	const originalKeys = new Set(selected)
	const seen = new Set()
	const normalized = []
	const normalizedConfigs = {}
	for (const key of selected) {
		const canonical = aliases.get(key) ?? key
		// An explicit canonical assignment (including its empty config) wins.
		if ((canonical !== key && originalKeys.has(canonical)) || seen.has(canonical)) continue
		seen.add(canonical)
		normalized.push(canonical)
		if (Object.prototype.hasOwnProperty.call(configs, key)) normalizedConfigs[canonical] = configs[key]
	}
	return { selected: normalized, configs: normalizedConfigs }
}
