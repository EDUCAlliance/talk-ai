<template>
	<Teleport to="body">
		<!-- The stylesheet scopes every rule below `.talkai`, so the tour sits inside a wrapper with that class -->
		<div class="talkai">
			<div class="talkai-tour" :class="{ 'talkai-tour--docked': docked }">
				<!-- Nothing below the tour reacts while it runs -->
				<div class="talkai-tour__block" :class="{ 'talkai-tour__block--dim': !hole }" />
				<div v-if="hole"
					class="talkai-tour__hole"
					:style="hole"
					aria-hidden="true" />
				<div ref="card"
					class="talkai-tour__card"
					role="dialog"
					aria-modal="true"
					:aria-labelledby="`${uid}-title`"
					:aria-describedby="`${uid}-text`"
					:style="cardStyle"
					tabindex="-1">
					<div class="talkai-tour__top">
						<span class="talkai-tour__count">{{ t('educai', 'Step {step} of {total}', { step: index + 1, total: steps.length }) }}</span>
						<button type="button"
							class="talkai-btn talkai-btn--ghost talkai-btn--icon"
							:aria-label="t('educai', 'End the tour')"
							:title="t('educai', 'End the tour')"
							@click="finish()">
							<TalkAiIcon name="close" :size="18" />
						</button>
					</div>
					<h3 :id="`${uid}-title`" class="talkai-tour__title">
						{{ step.title }}
					</h3>
					<p :id="`${uid}-text`" class="talkai-tour__text">
						{{ step.text }}
					</p>
					<div class="talkai-tour__actions">
						<button v-if="step.guide"
							type="button"
							class="talkai-tour__guide"
							@click="openGuide">
							{{ t('educai', 'Read the guide') }}
						</button>
						<span class="talkai-tour__spacer" />
						<button v-if="index > 0"
							type="button"
							class="talkai-btn"
							@click="back">
							{{ t('educai', 'Back') }}
						</button>
						<button type="button" class="talkai-btn talkai-btn--filled" @click="next">
							{{ nextLabel }}
						</button>
					</div>
				</div>
			</div>
		</div>
	</Teleport>
</template>

<script>
import TalkAiIcon from './TalkAiIcon.vue'
import { t } from '../l10n.js'
import { placeTourCard } from '../utils/tour.js'

// Room between the highlighted element and the edge of the highlight
const PADDING = 6
// Below this width the card sits at the bottom of the screen instead of next to the element
const DOCK_BELOW = 640
// How long a step waits for its element after the page changed
const WAIT_MS = 1500
// How long it waits for an element that is on the page but not visible
const HIDDEN_WAIT_MS = 250

const visible = element => {
	const rect = element.getBoundingClientRect()
	return rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < window.innerWidth
}

// A tour over the real page: each step highlights one element and explains it in a card next to it.
// Enter or the right arrow goes on, Backspace or the left arrow goes back, Escape ends the tour.
export default {
	name: 'TalkAiTour',
	components: { TalkAiIcon },
	props: {
		// Steps as built by tourSteps()
		steps: { type: Array, required: true },
	},
	emits: ['close'],
	data() {
		return {
			uid: `talkai-tour-${Math.random().toString(36).slice(2, 8)}`,
			index: 0,
			// Position of the highlight as a style object, null for a step without a visible element
			hole: null,
			cardStyle: {},
			docked: false,
			moving: false,
		}
	},
	computed: {
		step() {
			return this.steps[this.index]
		},
		nextLabel() {
			if (this.index === this.steps.length - 1) {
				return t('educai', 'Done')
			}
			return this.index === 0 ? t('educai', 'Start the tour') : t('educai', 'Next')
		},
	},
	created() {
		this.target = null
		this.timers = []
		this.polls = []
		// Counts the steps shown, so that work for an earlier step can tell it is outdated
		this.turn = 0
		this.startPath = this.$route.fullPath
		this.returnFocus = document.activeElement
	},
	mounted() {
		document.addEventListener('keydown', this.onKeydown, true)
		window.addEventListener('resize', this.measure)
		window.addEventListener('scroll', this.measure, true)
		this.show(0)
	},
	beforeUnmount() {
		document.removeEventListener('keydown', this.onKeydown, true)
		window.removeEventListener('resize', this.measure)
		window.removeEventListener('scroll', this.measure, true)
		this.turn++
		this.clearTimers()
		this.polls.forEach(timer => clearTimeout(timer))
	},
	methods: {
		t,
		clearTimers() {
			this.timers.forEach(timer => clearTimeout(timer))
			this.timers = []
		},
		later(action, delay) {
			this.timers.push(setTimeout(action, delay))
		},
		next() {
			if (this.index === this.steps.length - 1) {
				this.finish()
			} else {
				this.show(this.index + 1)
			}
		},
		back() {
			if (this.index > 0) {
				this.show(this.index - 1)
			}
		},
		async show(index) {
			if (this.moving) {
				return
			}
			const step = this.steps[index]
			if (step.route && this.$route.path !== step.route) {
				this.moving = true
				try {
					// A page that keeps the user (unsaved input) ends the tour where they are.
					const failure = await this.$router.push(step.route).catch(error => error)
					if (failure) {
						this.finish({ stay: true })
						return
					}
				} finally {
					this.moving = false
				}
			}
			this.clearTimers()
			const turn = ++this.turn
			this.index = index
			this.target = null
			this.hole = null
			await this.$nextTick()
			this.measure()
			this.$refs.card?.focus({ preventScroll: true })
			// The navigation of Nextcloud moves the focus when it closes on small screens.
			this.later(this.keepFocus, 350)
			if (step.target) {
				// Going on does not wait for this: a newer step makes the search give up.
				const element = await this.waitFor(step.target, turn)
				if (turn !== this.turn) {
					return
				}
				this.target = element
				element?.scrollIntoView?.({ block: 'center', inline: 'nearest' })
			}
			this.measure()
			// Lists and images may still settle after the page change.
			this.later(this.measure, 150)
			this.later(this.measure, 500)
		},
		keepFocus() {
			const card = this.$refs.card
			if (card && !card.contains(document.activeElement)) {
				card.focus({ preventScroll: true })
			}
		},
		// The first visible element for a selector, as soon as the page shows it. An element that is on the page
		// but out of sight, like the navigation on a small screen, is not waited for.
		waitFor(selector, turn) {
			return new Promise(resolve => {
				const started = Date.now()
				const look = () => {
					if (turn !== this.turn) {
						resolve(null)
						return
					}
					const elements = [...document.querySelectorAll(selector)]
					const element = elements.find(visible) ?? null
					const waited = Date.now() - started
					if (element || waited >= WAIT_MS || (elements.length > 0 && waited >= HIDDEN_WAIT_MS)) {
						resolve(element)
					} else {
						this.polls.push(setTimeout(look, 60))
					}
				}
				look()
			})
		},
		measure() {
			const card = this.$refs.card
			if (!card) {
				return
			}
			const viewport = { width: window.innerWidth, height: window.innerHeight }
			const shown = this.target && document.contains(this.target) && visible(this.target)
			const rect = shown ? this.target.getBoundingClientRect() : null
			this.hole = rect
				? {
					top: `${Math.round(rect.top - PADDING)}px`,
					left: `${Math.round(rect.left - PADDING)}px`,
					width: `${Math.round(rect.width + 2 * PADDING)}px`,
					height: `${Math.round(rect.height + 2 * PADDING)}px`,
				}
				: null
			this.docked = viewport.width < DOCK_BELOW
			if (this.docked) {
				this.cardStyle = {}
				return
			}
			const size = card.getBoundingClientRect()
			const place = placeTourCard({
				target: rect && { top: rect.top - PADDING, left: rect.left - PADDING, width: rect.width + 2 * PADDING, height: rect.height + 2 * PADDING },
				card: { width: size.width, height: size.height },
				viewport,
				placement: this.step.placement,
			})
			this.cardStyle = { top: `${place.top}px`, left: `${place.left}px` }
		},
		onKeydown(event) {
			if (event.metaKey || event.ctrlKey || event.altKey) {
				return
			}
			const card = this.$refs.card
			// Only a button of the card keeps Enter for itself. Focus that ended up elsewhere must not act on the page.
			const onControl = event.target instanceof Element && card.contains(event.target) && event.target.closest('button, a') !== null
			if (event.key === 'Escape') {
				event.preventDefault()
				event.stopPropagation()
				this.finish()
			} else if (event.key === 'ArrowRight' || (event.key === 'Enter' && !onControl)) {
				event.preventDefault()
				event.stopPropagation()
				this.next()
			} else if (event.key === 'ArrowLeft' || event.key === 'Backspace') {
				event.preventDefault()
				this.back()
			} else if (event.key === 'Tab') {
				// Keep the keyboard inside the card.
				const controls = [...card.querySelectorAll('button, a[href]')]
				const first = controls[0]
				const last = controls[controls.length - 1]
				const inside = card.contains(document.activeElement) && document.activeElement !== card
				if (event.shiftKey && (!inside || document.activeElement === first)) {
					event.preventDefault()
					last?.focus()
				} else if (!event.shiftKey && (!inside || document.activeElement === last)) {
					event.preventDefault()
					first?.focus()
				}
			}
		},
		async openGuide() {
			await this.$router.push('/guide').catch(() => {})
			this.finish({ stay: true })
		},
		// End the tour. The user is taken back to where they started, unless the tour ends on a page on purpose.
		async finish({ stay = false } = {}) {
			this.clearTimers()
			if (!stay && this.$route.fullPath !== this.startPath) {
				await this.$router.push(this.startPath).catch(() => {})
			}
			this.$emit('close')
			if (this.returnFocus instanceof HTMLElement && document.contains(this.returnFocus)) {
				this.returnFocus.focus({ preventScroll: true })
			}
		},
	},
}
</script>
