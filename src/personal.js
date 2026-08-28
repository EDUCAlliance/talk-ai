/* eslint-disable no-multiple-empty-lines */
import './utils/ensureOcFilePath.js'
import '@nextcloud/dialogs/style.css'
import Vue from 'vue'
import PersonalSettings from './views/PersonalSettings.vue'
import { installL10n } from './l10n.js'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

installL10n(Vue)
const VueApp = Vue.extend(PersonalSettings)
new VueApp().$mount('#educai-personal-root')
syncEducAiAppIconFromSettings()
