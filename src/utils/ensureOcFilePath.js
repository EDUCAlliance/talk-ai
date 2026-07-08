const ensureOcFilePath = () => {
	if (typeof window === 'undefined') {
		return
	}

	const oc = window.OC || (window.OC = {})

	if (typeof oc.filePath === 'function') {
		return
	}

	const trimSlashes = (value) => value.replace(/^\/+|\/+$/g, '')

	const ensureLeadingSlash = (value) => {
		if (value.startsWith('/')) {
			return value
		}

		return `/${value}`
	}

	const detectWebroot = () => {
		if (typeof oc.webroot === 'string' && oc.webroot.length > 0) {
			return oc.webroot
		}

		const meta = typeof document !== 'undefined'
			? document.querySelector('meta[name="oc:webroot"]')
			: null

		if (meta && typeof meta.content === 'string') {
			return meta.content
		}

		return ''
	}

	const joinSegments = (...segments) => {
		const filtered = segments.filter((segment) => typeof segment === 'string' && segment.length > 0)
		if (filtered.length === 0) {
			return ''
		}

		const [first, ...rest] = filtered
		const normalized = [first.replace(/\/+$/g, ''), ...rest.map((segment) => trimSlashes(segment))]
		const combined = normalized.filter(Boolean).join('/')
		return combined.length > 0 ? ensureLeadingSlash(combined) : combined
	}

	oc.filePath = (app, type, file) => {
		if (!app) {
			return file || ''
		}

		const base = detectWebroot()
		return joinSegments(base, 'apps', app, type, file)
	}
}

ensureOcFilePath()
