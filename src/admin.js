/* eslint-disable no-multiple-empty-lines */
import './utils/ensureOcFilePath.js'
import '@nextcloud/dialogs/style.css'
import Vue from 'vue'
import AdminSettings from './components/AdminSettings.vue'
import { installL10n } from './l10n.js'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

installL10n(Vue)

const VueApp = Vue.extend(AdminSettings)
new VueApp().$mount('#educai-admin-root')
syncEducAiAppIconFromSettings()
