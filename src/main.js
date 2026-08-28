import './utils/ensureOcFilePath.js'

import App from './views/App.vue'
import Vue from 'vue'
import { installL10n } from './l10n.js'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

installL10n(Vue)

const VueApp = Vue.extend(App)
new VueApp().$mount('#content')
syncEducAiAppIconFromSettings()
