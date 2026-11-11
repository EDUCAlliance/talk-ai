import { n, t } from '../l10n.js'

/**
 * What a bot can do, in the words of the people who use it. Each capability stands for one or more built-in tools.
 * The knowledge search is listed first and has no switch: it follows the sources.
 */
const CAPABILITIES = [
	{ key: 'knowledge', icon: 'book', tools: ['rag_search_documents'] },
	{ key: 'documents', icon: 'file', tools: ['room_search_documents'], recommended: true },
	{ key: 'images', icon: 'image', tools: ['attachment_analyze_image', 'room_search_images'] },
	{ key: 'voice', icon: 'mic', tools: ['attachment_transcribe_audio'] },
	{
		key: 'catalogue',
		icon: 'cap',
		tools: ['catalogue_search', 'catalogue_past_search', 'catalogue_get_opportunity'],
		aliases: ['catalogue_search_courses'],
	},
]

const WIKI_TOOLS = ['wiki_search', 'wiki_read_page', 'wiki_write_page', 'wiki_log_event']

export const CAPABILITY_KEYS = CAPABILITIES.map(capability => capability.key)

/**
 * Texts of a capability: `short` for chips, `title` and `text` for the card with the switch.
 *
 * @param {string} key Capability key
 * @return {{ short: string, title: string, text: string }}
 */
export function capabilityTexts(key) {
	switch (key) {
	case 'knowledge':
		return {
			short: t('educai', 'Knowledge'),
			title: t('educai', 'Search the knowledge'),
			text: t('educai', 'Answers from the sources you add and names where it found them.'),
		}
	case 'documents':
		return {
			short: t('educai', 'Documents in chat'),
			title: t('educai', 'Read documents in chat'),
			text: t('educai', 'When someone puts a file into the chat.'),
		}
	case 'images':
		return {
			short: t('educai', 'Images'),
			title: t('educai', 'Understand images'),
			text: t('educai', 'Screenshots, photos and diagrams in the chat.'),
		}
	case 'voice':
		return {
			short: t('educai', 'Voice'),
			title: t('educai', 'Understand voice messages'),
			text: t('educai', 'Voice messages and audio files in the chat.'),
		}
	case 'catalogue':
		return {
			short: t('educai', 'Course catalogue'),
			title: t('educai', 'Search the course catalogue'),
			text: t('educai', 'Current courses, dates and registration.'),
		}
	default:
		return { short: key, title: key, text: '' }
	}
}

const builtinName = tool => {
	if (typeof tool === 'string') {
		return tool.startsWith('builtin:') ? tool.slice(8) : null
	}
	return tool?.is_builtin ? (tool.builtin_name ?? null) : null
}

const capabilityOf = name => CAPABILITIES.find(capability =>
	capability.tools.includes(name) || capability.aliases?.includes(name))

/**
 * Key under which the bot form stores a selected tool: `builtin:<name>` or the numeric id of an MCP tool.
 *
 * @param {object} tool Tool as offered by the tool list
 * @return {string|number|null} Selection key, null for a built-in tool without a name
 */
export function toolKeyOf(tool) {
	if (tool?.is_builtin) {
		return tool.builtin_name ? `builtin:${tool.builtin_name}` : null
	}
	return tool?.id ?? null
}

/**
 * Sort the tools the administration offers into capabilities and single tools.
 *
 * The knowledge search and the wiki tools are left out: the first follows the sources, the second has its own
 * section. Every other tool that belongs to no capability gets a card of its own.
 *
 * @param {Array<object>} offered Tools from the tool list
 * @return {{ capabilities: Array<{key: string, icon: string, recommended: boolean, toolKeys: string[], offered: boolean}>, extras: Array<object> }}
 */
export function groupOfferedTools(offered) {
	const tools = Array.isArray(offered) ? offered : []
	const capabilities = CAPABILITIES
		.filter(capability => capability.key !== 'knowledge')
		.map(capability => {
			const toolKeys = tools
				.filter(tool => capabilityOf(builtinName(tool)) === capability)
				.map(toolKeyOf)
			return {
				key: capability.key,
				icon: capability.icon,
				recommended: capability.recommended === true,
				toolKeys,
				offered: toolKeys.length > 0,
			}
		})
	const extras = tools.filter(tool => {
		const name = builtinName(tool)
		if (name === null) {
			return toolKeyOf(tool) !== null
		}
		return !WIKI_TOOLS.includes(name) && capabilityOf(name) === undefined
	})
	return { capabilities, extras }
}

/**
 * Short list of what a bot can do, for tiles, lists and summaries.
 *
 * @param {object} bot Bot with `tools` (name, is_builtin, builtin_name), `rag_enabled` and optionally `rag_source_count`
 * @return {Array<{key: string, icon: string, label: string}>} One entry per capability or single tool
 */
export function capabilitiesOf(bot) {
	const tools = Array.isArray(bot?.tools) ? bot.tools : []
	const names = tools.map(builtinName)
	const result = []
	const sourceCount = Number.isFinite(Number(bot?.rag_source_count)) && bot?.rag_source_count !== null
		? Number(bot.rag_source_count)
		: null

	if (bot?.rag_enabled && sourceCount !== 0) {
		const short = capabilityTexts('knowledge').short
		result.push({
			key: 'knowledge',
			icon: 'book',
			label: sourceCount === null
				? short
				: n('educai', '{name} · %n source', '{name} · %n sources', sourceCount, { name: short }),
		})
	}
	for (const capability of CAPABILITIES) {
		if (capability.key !== 'knowledge' && names.some(name => capabilityOf(name) === capability)) {
			result.push({ key: capability.key, icon: capability.icon, label: capabilityTexts(capability.key).short })
		}
	}
	tools.forEach((tool, index) => {
		const name = names[index]
		const single = name === null ? !tool?.is_builtin : (!WIKI_TOOLS.includes(name) && capabilityOf(name) === undefined)
		if (single && tool?.name) {
			result.push({ key: `tool-${index}`, icon: 'tool', label: tool.name })
		}
	})
	return result
}

/**
 * Tell whether the bot keeps a wiki.
 *
 * @param {object} bot Bot with `tools`
 * @return {boolean} True when a wiki tool is assigned
 */
export function hasWiki(bot) {
	return (Array.isArray(bot?.tools) ? bot.tools : []).some(tool => WIKI_TOOLS.includes(builtinName(tool)))
}
