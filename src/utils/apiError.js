/**
 * Return a user-safe API error message.
 *
 * Backend responses are trusted only when they carry the stable `errorCode`
 * contract. Legacy responses could contain raw exception messages, so callers
 * must provide a localized fallback for those payloads.
 *
 * @param {unknown} error Axios error, response, or response payload
 * @param {string} fallbackMessage Localized fallback owned by the caller
 * @return {string} Safe message for display
 */
export function getApiErrorMessage(error, fallbackMessage) {
	const payload = error?.response?.data ?? error?.data ?? error
	if (
		payload
		&& typeof payload === 'object'
		&& typeof payload.errorCode === 'string'
		&& payload.errorCode !== ''
		&& typeof payload.error === 'string'
		&& payload.error.trim() !== ''
	) {
		return payload.error
	}

	return fallbackMessage
}
