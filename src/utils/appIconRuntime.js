import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'

const APP_ID = 'educai'
const APP_ROUTE_FRAGMENT = '/apps/educai'

let currentRuntimeIcons = null
let iconObserver = null
let patchScheduled = false

export async function syncEducAiAppIconFromSettings() {
	try {
		const response = await axios.get(generateUrl('/apps/educai/api/v1/settings'))
		applyEducAiRuntimeIconPayload(response.data)
	} catch {
		// Keep the bundled Nextcloud icon state if settings cannot be loaded.
	}
}

export function applyEducAiRuntimeIconPayload(payload = {}, options = {}) {
	const runtimeIcons = resolveRuntimeIcons(payload)
	applyEducAiRuntimeIcons(runtimeIcons)

	if (options.refreshNavigation) {
		refreshNextcloudNavigation().finally(() => {
			applyEducAiRuntimeIcons(runtimeIcons)
		})
	}
}

function resolveRuntimeIcons(payload) {
	const runtimeUrls = payload?.app_icon_runtime_urls || {}
	return {
		black: normalizeIconUrl(runtimeUrls.black)
			|| resolveConfiguredIconUrl(payload, 'black')
			|| imagePath(APP_ID, 'app-dark.svg'),
		white: normalizeIconUrl(runtimeUrls.white)
			|| resolveConfiguredIconUrl(payload, 'white')
			|| imagePath(APP_ID, 'app.svg'),
	}
}

function resolveConfiguredIconUrl(payload, variant) {
	const mode = payload?.app_icon_mode === 'custom' || payload?.app_icon_url ? 'custom' : 'default'
	if (mode !== 'custom') {
		return ''
	}

	const variantKey = variant === 'white' ? 'app_icon_white_url' : 'app_icon_black_url'
	const source = String(payload?.[variantKey] || payload?.app_icon_url || '').trim()
	if (/^educai-upload:\/\/(black|white)$/i.test(source)) {
		return generateUrl(`/apps/educai/api/v1/app-icon/${variant}`)
	}

	return normalizeIconUrl(source)
}

function normalizeIconUrl(value) {
	const url = String(value || '').trim()
	if (!url) {
		return ''
	}

	if (/^https?:\/\//i.test(url)) {
		try {
			const parsed = new URL(url)
			return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? url : ''
		} catch {
			return ''
		}
	}

	return url.startsWith('/') && !url.startsWith('//') ? url : ''
}

function applyEducAiRuntimeIcons(runtimeIcons) {
	currentRuntimeIcons = runtimeIcons
	patchEducAiNavigationIcons(runtimeIcons.white || runtimeIcons.black)
	patchEducAiFavicon(runtimeIcons.black || runtimeIcons.white)
	startIconObserver()
}

function patchEducAiNavigationIcons(iconUrl) {
	if (!iconUrl || typeof document === 'undefined') {
		return
	}

	const appLinks = document.querySelectorAll(`a[href*="${APP_ROUTE_FRAGMENT}"]`)
	for (const link of appLinks) {
		const href = link.getAttribute('href') || ''
		if (!href.includes(APP_ROUTE_FRAGMENT)) {
			continue
		}

		for (const image of link.querySelectorAll('img')) {
			if (image.getAttribute('src') !== iconUrl) {
				image.setAttribute('src', iconUrl)
			}
		}
	}
}

function patchEducAiFavicon(iconUrl) {
	if (!iconUrl || typeof document === 'undefined' || !isEducAiSurface()) {
		return
	}

	upsertHeadIconLink('favicon', 'icon', iconUrl, {
		type: 'image/svg+xml',
	})
	upsertHeadIconLink('mask', 'mask-icon', iconUrl, {
		color: getComputedStyle(document.documentElement).getPropertyValue('--color-primary-element').trim() || '#000000',
	})
}

function upsertHeadIconLink(key, rel, href, attributes = {}) {
	let link = document.head.querySelector(`link[data-educai-runtime-icon="${key}"]`)
	if (!link) {
		link = document.createElement('link')
		link.setAttribute('data-educai-runtime-icon', key)
		document.head.appendChild(link)
	}

	link.setAttribute('rel', rel)
	link.setAttribute('href', href)
	for (const [name, value] of Object.entries(attributes)) {
		link.setAttribute(name, value)
	}
}

function startIconObserver() {
	if (iconObserver || typeof MutationObserver === 'undefined' || !document.body) {
		return
	}

	iconObserver = new MutationObserver(scheduleIconPatch)
	iconObserver.observe(document.body, {
		childList: true,
		subtree: true,
	})
}

function scheduleIconPatch() {
	if (patchScheduled || !currentRuntimeIcons) {
		return
	}

	patchScheduled = true
	window.requestAnimationFrame(() => {
		patchScheduled = false
		patchEducAiNavigationIcons(currentRuntimeIcons.white || currentRuntimeIcons.black)
	})
}

async function refreshNextcloudNavigation() {
	if (typeof window === 'undefined') {
		return
	}

	const rebuildNavigation = window.OC?.Settings?.rebuildNavigation
	if (typeof rebuildNavigation === 'function') {
		await rebuildNavigation()
	}
}

function isEducAiSurface() {
	if (typeof window === 'undefined') {
		return false
	}

	const path = window.location.pathname
	return path.includes('/apps/educai')
		|| path.includes('/settings/user/educai')
		|| path.includes('/settings/admin/educai')
}
