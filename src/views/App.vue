<template>
	<NcContent app-name="educai">
		<NcAppNavigation :aria-label="APP_DISPLAY_NAME">
			<NcAppNavigationNew :text="t('educai', 'New bot')" data-tour="new-bot" @click="$router.push('/new')">
				<template #icon>
					<IconPlus :size="20" />
				</template>
			</NcAppNavigationNew>
			<template #list>
				<NcAppNavigationItem :name="t('educai', 'Discover')"
					to="/discover"
					data-tour="nav-discover"
					:active="nav === 'discover'">
					<template #icon>
						<IconCompass :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('educai', 'My bots')"
					to="/my-bots"
					data-tour="nav-my-bots"
					:active="nav === 'my-bots'">
					<template #icon>
						<IconRobot :size="20" />
					</template>
					<template v-if="ownBotCount > 0" #counter>
						<NcCounterBubble :count="ownBotCount" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="hasApprovalRights"
					:name="t('educai', 'To review')"
					to="/review"
					data-tour="nav-review"
					:active="nav === 'review'">
					<template #icon>
						<IconShield :size="20" />
					</template>
					<template v-if="reviewCount > 0" #counter>
						<NcCounterBubble :count="reviewCount" type="highlighted" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('educai', 'Activity')"
					to="/activity"
					data-tour="nav-activity"
					:active="nav === 'activity'">
					<template #icon>
						<IconPulse :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<ul class="talkai-nav-footer">
					<!-- Starts the tour; the guide page is reached from the tour -->
					<NcAppNavigationItem :name="t('educai', 'How {appName} works', { appName: APP_DISPLAY_NAME })"
						data-tour="nav-guide"
						:active="nav === 'guide'"
						@click="startTour">
						<template #icon>
							<IconInformation :size="20" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<NcAppContent :page-heading="APP_DISPLAY_NAME">
			<div class="talkai talkai-app">
				<router-view />
			</div>
		</NcAppContent>
		<!-- Views place a sidebar here, next to the content, where Nextcloud expects it. -->
		<div id="talkai-sidebar-host" class="talkai-sidebar-host" />
		<TalkAiTour v-if="tour.open && tourSteps.length > 0" :steps="tourSteps" @close="tour.open = false" />
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import IconCompass from 'vue-material-design-icons/CompassOutline.vue'
import IconInformation from 'vue-material-design-icons/InformationOutline.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconPulse from 'vue-material-design-icons/Pulse.vue'
import IconRobot from 'vue-material-design-icons/RobotOutline.vue'
import IconShield from 'vue-material-design-icons/ShieldCheckOutline.vue'
import TalkAiTour from '../components/TalkAiTour.vue'
import { APP_DISPLAY_NAME } from '../branding.js'
import { store } from '../store/talkAi.js'
import { assistantOf } from '../utils/assistant.js'
import { tour, tourSteps } from '../utils/tour.js'

export default {
	name: 'App',

	components: {
		IconCompass,
		IconInformation,
		IconPlus,
		IconPulse,
		IconRobot,
		IconShield,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcContent,
		NcCounterBubble,
		TalkAiTour,
	},

	data() {
		return { APP_DISPLAY_NAME, tour, tourSteps: [] }
	},

	computed: {
		nav() {
			return this.$route.meta?.nav ?? ''
		},

		ownBotCount() {
			return store.state.ownBots.length
		},

		hasApprovalRights() {
			return store.state.permissions.hasApprovalRights
		},

		reviewCount() {
			return store.state.approvals.length
		},
	},

	watch: {
		// The content area scrolls, not the window.
		'$route.path'() {
			this.$el.querySelector?.('#app-content-vue')?.scrollTo?.(0, 0)
		},

		// Any view may start the tour; the frame prepares and shows it.
		'tour.open'(open) {
			if (open) {
				this.prepareTour()
			}
		},
	},

	created() {
		this.loadShared()
	},

	methods: {
		startTour(event) {
			// The entry is a link without a target of its own.
			event?.preventDefault()
			tour.open = true
		},

		// The tour walks over what this user really sees: no step for a page or an element they do not have.
		// The steps are fixed when the tour starts, so their number does not change on the way.
		prepareTour() {
			const usable = store.state.publicBots
			this.tourSteps = tourSteps({
				appName: APP_DISPLAY_NAME,
				hasApprovalRights: this.hasApprovalRights,
				usableBots: usable.length,
				pinnedAssistant: usable.some(bot => bot.is_assistant === true),
				assistantInvitation: store.state.loaded.ownBots && !store.state.failed.ownBots && assistantOf(store.state.ownBots) === null,
			})
			// On small screens the navigation covers the page; the tour needs the page.
			const navigation = document.querySelector('.app-navigation')
			if (window.innerWidth < 1024 && navigation && !navigation.classList.contains('app-navigation--closed')) {
				document.querySelector('.app-navigation-toggle')?.click()
			}
		},

		// Counters and names for the frame. Each view reports its own loading errors.
		async loadShared() {
			try {
				await store.loadPermissions()
			} catch (error) {
				console.error('Failed to load permissions', error)
			}
			store.loadOwnBots().catch(error => console.error('Failed to load own bots', error))
			// Also the directory of names and colours for views that only know a mention
			store.loadPublicBots().catch(error => console.error('Failed to load bots', error))
			store.loadApprovals().catch(error => console.error('Failed to load approvals', error))
			store.loadDirectory()
		},
	},
}
</script>

<style scoped lang="scss">
.talkai-nav-footer {
	padding: calc(var(--default-grid-baseline, 4px) * 2);
}

// The host itself takes no room: the sidebar inside it is laid out as a child of the content frame.
.talkai-sidebar-host {
	display: contents;
}
</style>
