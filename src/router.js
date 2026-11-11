import { createRouter, createWebHashHistory } from 'vue-router'
import BotPage from './views/BotPage.vue'
import Discover from './views/Discover.vue'
import Guide from './views/Guide.vue'
import MyBots from './views/MyBots.vue'
import NewBot from './views/NewBot.vue'
import PersonalActivity from './views/PersonalActivity.vue'
import Review from './views/Review.vue'

// Hash history: the app page has one server route, so deep links need no server support.
// `nav` names the navigation entry that stays highlighted on pages below it. Creating a bot belongs to none of
// them: "New bot" is its own button, and "My bots" is for the bots that already exist.
const routes = [
	{ path: '/', redirect: '/discover' },
	{ path: '/discover', name: 'discover', component: Discover, meta: { nav: 'discover' } },
	{ path: '/my-bots', name: 'my-bots', component: MyBots, meta: { nav: 'my-bots' } },
	{ path: '/new/:step(\\d)?', name: 'new', component: NewBot, meta: { nav: 'new' } },
	{ path: '/bots/:id(\\d+)/:tab?', name: 'bot', component: BotPage, meta: { nav: 'my-bots' } },
	{ path: '/review/:id(\\d+)?', name: 'review', component: Review, meta: { nav: 'review' } },
	{ path: '/activity', name: 'activity', component: PersonalActivity, meta: { nav: 'activity' } },
	{ path: '/guide', name: 'guide', component: Guide, meta: { nav: 'guide' } },
	{ path: '/:pathMatch(.*)*', redirect: '/discover' },
]

export default createRouter({
	history: createWebHashHistory(),
	routes,
	scrollBehavior: () => ({ top: 0 }),
})
