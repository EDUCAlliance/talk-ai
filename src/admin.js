/* eslint-disable no-multiple-empty-lines */
import './utils/ensureOcFilePath.js'
import '@nextcloud/dialogs/style.css'
import Vue from 'vue'
import AdminSettings from './components/AdminSettings.vue'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

const VueApp = Vue.extend(AdminSettings)
new VueApp().$mount('#educai-admin-root')
syncEducAiAppIconFromSettings()
