import { t } from '../l10n.js'
import { groupOfferedTools } from './capabilities.js'

// What the assistant starts with, when the administration offers it. Knowledge follows the folders that are added later.
const ASSISTANT_CAPABILITIES = ['documents', 'images']

/**
 * The personal assistant among a user's bots: the one personal bot the server marks with `is_assistant`.
 *
 * @param {Array<object>} bots Bots as delivered by the bot lists
 * @return {object|null} The assistant, null when it is not set up
 */
export function assistantOf(bots) {
	return (Array.isArray(bots) ? bots : []).find(bot => bot?.is_assistant === true) ?? null
}

/**
 * Order for lists that pin the assistant in first place. Use it as the first criterion of a sort.
 *
 * @param {object} a One bot
 * @param {object} b Another bot
 * @return {number} Negative when `a` is the assistant, positive when `b` is, otherwise 0
 */
export function assistantFirst(a, b) {
	return Number(b?.is_assistant === true) - Number(a?.is_assistant === true)
}

/**
 * Request that creates the personal assistant: a bot only its owner can use.
 *
 * The server chooses the mention name, because mention names are unique across all bots.
 *
 * @param {Array<object>} offeredTools Tools the administration offers, as delivered by the tool list
 * @return {object} Payload for creating a bot
 */
export function assistantPayload(offeredTools) {
	const tools = groupOfferedTools(offeredTools).capabilities
		.filter(capability => ASSISTANT_CAPABILITIES.includes(capability.key))
		.flatMap(capability => capability.toolKeys)
		.map(key => ({ is_builtin: true, builtin_name: String(key).replace(/^builtin:/, '') }))

	return {
		assistant: true,
		botName: t('educai', 'Personal assistant'),
		mentionName: 'assistant',
		description: t('educai', 'Your own bot. It knows the folders you choose and helps you search, summarise and write.'),
		systemPrompt: t('educai', 'You are the personal assistant of one person. Help them search, summarise and write. When you use their files, name the file you took the information from. If you do not know something, say so.'),
		visibility: 'personal',
		allowedGroups: [],
		allowedTeams: [],
		model: null,
		temperature: null,
		ragEnabled: false,
		tools,
		onboardingQuestions: null,
	}
}
