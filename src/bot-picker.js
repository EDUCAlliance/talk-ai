/**
 * Smart Picker Bot Provider - Entry Point
 *
 * This script is loaded when the Smart Picker is rendered (via RenderReferenceEvent).
 * It registers a custom picker component for bot mentions.
 *
 * Note: The Talk-only restriction is handled by checking context before registering.
 * If not in Talk, the picker simply won't be registered, making it invisible.
 */

import { registerCustomPickerElement, NcCustomPickerRenderResult } from '@nextcloud/vue/dist/Components/NcRichText.js'
import Vue from 'vue'
import BotPickerElement from './components/BotPickerElement.vue'

Vue.mixin({ methods: { t, n } })

/**
 * Detect if we're currently in Nextcloud Talk context.
 *
 * Uses multiple heuristics to reliably detect Talk:
 * - OCA.Talk or OCA.Spreed objects (Talk's global namespace)
 * - DOM element with id="spreed" (Talk's main container)
 * - URL patterns for Talk routes
 *
 * @return {boolean} True if in Talk context
 */
function isTalkContext() {
	// Check for Talk's JavaScript namespace
	if (window.OCA?.Talk || window.OCA?.Spreed) {
		console.debug('[EducAI] Bot picker: Detected Talk via OCA namespace')
		return true
	}

	// Check for Talk's main DOM container
	if (document.getElementById('spreed')) {
		console.debug('[EducAI] Bot picker: Detected Talk via DOM element')
		return true
	}

	// Check URL patterns
	const path = window.location.pathname
	if (path.includes('/apps/spreed') || path.includes('/call/')) {
		console.debug('[EducAI] Bot picker: Detected Talk via URL pattern')
		return true
	}

	// Check if spreed app is in URL hash (for embedded Talk)
	if (window.location.hash.includes('spreed')) {
		console.debug('[EducAI] Bot picker: Detected Talk via URL hash')
		return true
	}

	return false
}

/**
 * Extract the room token from the current Talk URL.
 *
 * Handles various URL patterns:
 * - /call/{token}
 * - /apps/spreed/call/{token}
 * - /index.php/call/{token}
 * - /index.php/apps/spreed/call/{token}
 *
 * @return {string|null} The room token or null if not found
 */
function extractRoomToken() {
	const path = window.location.pathname

	// Pattern: /call/{token} or /apps/spreed/call/{token}
	// The token is the segment after /call/
	const callMatch = path.match(/\/call\/([a-zA-Z0-9]+)/)
	if (callMatch) {
		console.debug('[EducAI] Bot picker: Extracted room token from URL:', callMatch[1])
		return callMatch[1]
	}

	// Also check hash for embedded Talk (e.g., #/call/{token})
	const hash = window.location.hash
	const hashMatch = hash.match(/\/call\/([a-zA-Z0-9]+)/)
	if (hashMatch) {
		console.debug('[EducAI] Bot picker: Extracted room token from hash:', hashMatch[1])
		return hashMatch[1]
	}

	console.debug('[EducAI] Bot picker: Could not extract room token from URL')
	return null
}

/**
 * Register the bot picker component with the Smart Picker.
 */
function registerBotPicker() {
	console.debug('[EducAI] Bot picker: Registering custom picker element')

	try {
		registerCustomPickerElement(
			'educai-bots',
			(el, { providerId, accessible }) => {
				// Extract room token from URL at render time
				const roomToken = extractRoomToken()
				console.debug('[EducAI] Bot picker: Creating picker element', { providerId, accessible, roomToken })

				const Element = Vue.extend(BotPickerElement)
				const vueElement = new Element({
					propsData: {
						providerId,
						accessible,
						roomToken,
					},
				}).$mount(el)
				return new NcCustomPickerRenderResult(vueElement.$el, vueElement)
			},
			(el, renderResult) => {
				// Cleanup when picker is closed
				console.debug('[EducAI] Bot picker: Destroying picker element')
				renderResult.object.$destroy()
			},
		)
		console.debug('[EducAI] Bot picker: Successfully registered')
	} catch (error) {
		console.error('[EducAI] Bot picker: Failed to register', error)
	}
}

/**
 * Initialize the bot picker component.
 * Checks Talk context and registers the picker if appropriate.
 */
function initBotPicker() {
	console.debug('[EducAI] Bot picker: Initializing...')

	// Check if we're in Talk context
	const inTalk = isTalkContext()
	console.debug('[EducAI] Bot picker: Talk context detected:', inTalk)

	if (!inTalk) {
		console.debug('[EducAI] Bot picker: Not in Talk context, skipping registration')
		return
	}

	// Register the picker
	registerBotPicker()
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initBotPicker)
} else {
	// DOM already loaded, but Talk objects might not be ready yet
	// Use a small delay to allow Talk to initialize
	setTimeout(initBotPicker, 100)
}
