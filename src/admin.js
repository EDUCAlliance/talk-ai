/* eslint-disable no-multiple-empty-lines */
import './utils/ensureOcFilePath.js'
import '@nextcloud/dialogs/style.css'
import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'
import { installL10n } from './l10n.js'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

const app = createApp(AdminSettings)
installL10n(app)
app.mount('#educai-admin-root')
syncEducAiAppIconFromSettings()
