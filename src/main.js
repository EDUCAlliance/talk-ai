import './utils/ensureOcFilePath.js'

import App from './views/App.vue'
import Vue from 'vue'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

Vue.mixin({ methods: { t, n } })

const VueApp = Vue.extend(App)
new VueApp().$mount('#content')
syncEducAiAppIconFromSettings()
