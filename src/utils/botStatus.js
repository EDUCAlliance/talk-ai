import { t } from '../l10n.js'

const chip = (key, label, tone, dot = false) => ({ key, label, tone, dot })

/**
 * Describe where a bot stands between creation and approval.
 *
 * `stage` is one of `personal`, `draft`, `feedback`, `in-review`, `change-in-review` or `approved`. An approved bot
 * whose edit was rejected stays `approved` and carries the reviewer's words in `feedback`.
 *
 * @param {object} bot Bot as delivered by the API
 * @return {{ stage: string, chips: Array<{key: string, label: string, tone: string, dot: boolean}>, note: string, feedback: string }}
 */
export function statusOf(bot) {
	const status = bot?.approval_status || 'approved'
	const feedback = typeof bot?.rejection_reason === 'string' ? bot.rejection_reason.trim() : ''
	const result = { stage: 'approved', chips: [], note: '', feedback }

	if (status === 'personal') {
		result.stage = 'personal'
		result.chips.push(chip('active', t('educai', 'Active'), 'ok', true))
	} else if (status === 'draft') {
		// A rejected first submission returns to draft. The reviewer's reason is optional.
		result.stage = feedback === '' ? 'draft' : 'feedback'
		if (feedback === '') {
			result.chips.push(chip('draft', t('educai', 'Draft'), 'warn', true))
			result.note = t('educai', 'Not submitted yet')
		} else {
			result.chips.push(chip('feedback', t('educai', 'Feedback'), 'err', true))
		}
	} else if (status === 'pending' && bot?.has_pending_changes) {
		result.stage = 'change-in-review'
		result.chips.push(chip('approved', t('educai', 'Approved'), 'ok', true))
		result.chips.push(chip('change', t('educai', 'Change in review'), 'info'))
		result.note = t('educai', 'The current version stays live until approval.')
	} else if (status === 'pending') {
		result.stage = 'in-review'
		result.chips.push(chip('review', t('educai', 'In review'), 'info', true))
		result.note = t('educai', 'Until approval only you and the reviewers can use it.')
	} else {
		result.chips.push(chip('approved', t('educai', 'Approved'), 'ok', true))
		if (feedback !== '') {
			result.chips.push(chip('rejected', t('educai', 'Change rejected'), 'err'))
		}
	}

	if (bot?.is_active === false) {
		result.chips.push(chip('inactive', t('educai', 'Inactive'), 'off'))
	}
	return result
}
