/* eslint-disable no-multiple-empty-lines */
import './utils/ensureOcFilePath.js'
import '@nextcloud/dialogs/style.css'
import Vue from 'vue'
import PersonalSettings from './views/PersonalSettings.vue'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

Vue.mixin({ methods: { t, n } })
const VueApp = Vue.extend(PersonalSettings)
new VueApp().$mount('#educai-personal-root')
syncEducAiAppIconFromSettings()
