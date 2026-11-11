const isAbbreviation = word => word.length > 1 && word === word.toUpperCase() && word !== word.toLowerCase()

/**
 * Return the letters that stand for a bot in its avatar.
 *
 * Two words give their first letters ("Thesis coach" becomes TC). A leading abbreviation stays readable
 * ("IT support" becomes IT), and a single word gives its first two letters.
 *
 * @param {string} name Bot name
 * @return {string} Uppercase letters, empty when the name has no letter or digit
 */
export function initialsOf(name) {
	const [first, second] = String(name ?? '').split(/[^\p{L}\p{N}]+/u).filter(Boolean)
	if (!first) {
		return ''
	}
	const letters = second && !isAbbreviation(first)
		? [Array.from(first)[0], Array.from(second)[0]]
		: Array.from(first).slice(0, 2)
	return letters.join('').toUpperCase()
}
