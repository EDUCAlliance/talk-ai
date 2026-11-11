import { reactive } from 'vue'
import { t } from '../l10n.js'

/** Whether the tour is running. The frame shows it; any view may start it. */
export const tour = reactive({ open: false })

/**
 * Steps of the tour through the app page.
 *
 * A step points at an element of the real page (`target`, a CSS selector) and may open a page first (`route`).
 * A step without a target is shown in the middle of the screen. Steps for things this user does not have are left
 * out from the start, so the numbering holds: a bot tile for someone who cannot use any bot yet, "To review" for
 * someone without approval rights.
 *
 * @param {object} options What the tour depends on
 * @param {string} options.appName Display name of the app
 * @param {boolean} [options.hasApprovalRights] Whether the user sees "To review"
 * @param {number} [options.usableBots] How many bots Discover shows
 * @param {boolean} [options.pinnedAssistant] Whether the personal assistant is one of them
 * @param {boolean} [options.assistantInvitation] Whether Discover invites to set up the personal assistant
 * @return {Array<{id: string, title: string, text: string, target?: string, route?: string, placement?: string, guide?: boolean}>}
 */
export function tourSteps({ appName, hasApprovalRights = false, usableBots = 0, pinnedAssistant = false, assistantInvitation = false }) {
	const steps = [
		{
			id: 'welcome',
			title: t('educai', 'Welcome to {appName}', { appName }),
			text: t('educai', 'Bots answer in Talk: mention one with @ in any chat, or start a conversation with it. This short tour shows you where everything is.'),
			guide: true,
		},
		{
			id: 'discover',
			route: '/discover',
			target: '[data-tour="nav-discover"]',
			placement: 'right',
			title: t('educai', 'Discover'),
			text: t('educai', 'Here you find every bot you can use: the ones shared with you and your own.'),
		},
		{
			id: 'assistant',
			route: '/discover',
			target: '.talkai-assist, .talkai-tile--pinned',
			placement: 'bottom',
			shown: pinnedAssistant || assistantInvitation,
			title: t('educai', 'Your personal assistant'),
			text: t('educai', 'A bot just for you that knows the folders you choose. The invitation stays until you set it up; afterwards the assistant is pinned in first place.'),
		},
		{
			id: 'find',
			route: '/discover',
			target: '.talkai-toolbar',
			placement: 'bottom',
			shown: usableBots > 0,
			title: t('educai', 'Find the right bot'),
			text: t('educai', 'Search by name or topic, or filter by who can use a bot.'),
		},
		{
			id: 'tile',
			route: '/discover',
			target: '.talkai-tile:not(.talkai-tile--pinned)',
			placement: 'right',
			shown: usableBots > (pinnedAssistant ? 1 : 0),
			title: t('educai', 'A bot at a glance'),
			text: t('educai', 'The colour and the line below say who can use the bot, the labels what it can do. “Use in Talk” opens a chat, a click on the name shows the details.'),
		},
		{
			id: 'new',
			target: '[data-tour="new-bot"]',
			placement: 'right',
			title: t('educai', 'Create your own bot'),
			text: t('educai', 'Pick a template and follow five steps. A bot only for you is active right away. A bot for a group, a team or everyone is reviewed first.'),
		},
		{
			id: 'my-bots',
			route: '/my-bots',
			target: '[data-tour="nav-my-bots"]',
			placement: 'right',
			title: t('educai', 'My bots'),
			text: t('educai', 'The bots you created and their state: what is live, what waits for approval and what needs your attention.'),
		},
		...(hasApprovalRights
			? [{
				id: 'review',
				route: '/review',
				target: '[data-tour="nav-review"]',
				placement: 'right',
				title: t('educai', 'To review'),
				text: t('educai', 'Bots that others submitted for your audience. You see what changes and approve it or send it back.'),
			}]
			: []),
		{
			id: 'activity',
			route: '/activity',
			target: '[data-tour="nav-activity"]',
			placement: 'right',
			title: t('educai', 'Activity'),
			text: t('educai', 'Your own conversations with bots, step by step. Only you see them.'),
		},
		{
			id: 'end',
			target: '[data-tour="nav-guide"]',
			placement: 'right',
			title: t('educai', 'That was the tour'),
			text: t('educai', 'You can start it again here at any time. The guide explains the colours and the way to a shared bot.'),
			guide: true,
		},
	]
	return steps.filter(step => step.shown !== false).map(({ shown, ...step }) => step)
}

/**
 * Where the card of a step goes: next to its element, on the first side with enough room.
 *
 * @param {object} box Measures in viewport pixels
 * @param {{top: number, left: number, width: number, height: number}|null} box.target The highlighted element, null for a step without one
 * @param {{width: number, height: number}} box.card Size of the card
 * @param {{width: number, height: number}} box.viewport Size of the window
 * @param {string} [box.placement] Preferred side: right, bottom, top or left
 * @param {number} [box.gap] Distance between element and card
 * @param {number} [box.margin] Distance the card keeps from the window edge
 * @return {{side: string, top: number, left: number}} `side` is `center` when no side has room or there is no element
 */
export function placeTourCard({ target, card, viewport, placement = 'bottom', gap = 14, margin = 12 }) {
	const clamp = (value, max) => Math.max(margin, Math.min(value, max))
	const maxLeft = viewport.width - card.width - margin
	const maxTop = viewport.height - card.height - margin
	const center = {
		side: 'center',
		top: clamp(Math.round((viewport.height - card.height) / 2), Math.max(margin, maxTop)),
		left: clamp(Math.round((viewport.width - card.width) / 2), Math.max(margin, maxLeft)),
	}
	if (!target) {
		return center
	}

	const fits = {
		right: target.left + target.width + gap + card.width <= viewport.width - margin,
		left: target.left - gap - card.width >= margin,
		bottom: target.top + target.height + gap + card.height <= viewport.height - margin,
		top: target.top - gap - card.height >= margin,
	}
	const side = [placement, 'bottom', 'right', 'top', 'left'].find(candidate => fits[candidate])
	if (!side) {
		return center
	}

	if (side === 'right' || side === 'left') {
		// The card starts level with the element and moves up only as far as the window requires.
		return {
			side,
			top: clamp(Math.round(target.top), Math.max(margin, maxTop)),
			left: Math.round(side === 'right' ? target.left + target.width + gap : target.left - gap - card.width),
		}
	}
	return {
		side,
		top: Math.round(side === 'bottom' ? target.top + target.height + gap : target.top - gap - card.height),
		left: clamp(Math.round(target.left), Math.max(margin, maxLeft)),
	}
}
